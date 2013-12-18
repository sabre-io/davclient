<?php

namespace Sabre\DAVClient;

use
    Sabre\HTTP\Client as BaseClient,
    Sabre\HTTP\RequestInterface,
    Sabre\HTTP\Response;

/**
 * The base client class.
 *
 * @copyright Copyright (C) 2013 fruux GmbH. All rights reserved.
 * @author Evert Pot (http://evertpot.com/)
 * @license http://code.google.com/p/sabredav/wiki/License Modified BSD License
 */
class Client {

    const ERR_CURL = 1; // Curl error. Could not complete the HTTP request
    const ERR_HTTP = 2; // HTTP error, such as a 4xx or 5xx

    protected $curlMultiHandle;

    /**
     * A list of open http request and all the associated information.
     *
     * @var array
     */
    protected $requestMap = [];

    public function __construct() {

        $this->curlMultiHandle = curl_multi_init();

    }

    public function addToRequestQueue(RequestInterface $request, callable $success = null, callable $error = null) {

        $settings = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_POSTREDIR => 3,
        ];
        switch($request->getMethod()) {
            case 'HEAD' :
                $settings[CURLOPT_NOBODY] = true;
                $settings[CURLOPT_CUSTOMREQUEST] = 'HEAD';
                $settings[CURLOPT_POSTFIELDS] = '';
                $settings[CURLOPT_PUT] = false;
                break;
            case 'GET' :
                $settings[CURLOPT_CUSTOMREQUEST] = 'GET';
                $settings[CURLOPT_POSTFIELDS] = '';
                $settings[CURLOPT_PUT] = false;
                break;
            default :
                $body = $request->getBody(MessageInterface::BODY_RAW);
                if (is_resource($body)) {
                    // This needs to be set to PUT, regardless of the actual
                    // method used. Without it, INFILE will be ignored for some
                    // reason.
                    $settings[CURLOPT_PUT] = true;
                    $settings[CURLOPT_INFILE] = $request->getBody();
                } else {
                    // Else, it's a string.
                    $settings[CURLOPT_POSTFIELDS] = $body;
                }
                $settings[CURLOPT_CUSTOMREQUEST] = $request->getMethod();
                break;

        }
        $nHeaders = [];
        foreach($request->getHeaders() as $key=>$value) {

            $nHeaders[] = $key . ': ' . $value;

        }
        $settings[CURLOPT_HTTPHEADER] = $nHeaders;
        $settings[CURLOPT_URL] = $request->getUrl();

        $curl = curl_init();
        curl_setopt_array($curl, $settings);

        curl_multi_add_handle($this->curlMultiHandle, $curl);
        $this->requestMap[intval($curl)] = [$request, $success, $error];
        $this->processQueue();

    }

    /**
     * Processes every HTTP request in the queue, and waits till they are all
     * completed.
     *
     * @return void
     */
    public function wait() {

        do {
            curl_multi_select($this->curlMultiHandle);
            $stillRunning = $this->processQueue();
        } while ($stillRunning);

    }

    protected function processQueue() {

        do {
            $r = curl_multi_exec($this->curlMultiHandle, $stillRunning);
        } while ($r === CURLM_CALL_MULTI_PERFORM);
        do {
            $status = curl_multi_info_read($this->curlMultiHandle, $messagesInQueue);
            if ($status && $status['msg'] === CURLMSG_DONE) {
                $this->processResult($status['handle']);
            }
        } while ($messagesInQueue > 0);

        return $stillRunning;

    }

    protected function processResult($curlHandle) {

        $response   = curl_multi_getcontent($curlHandle);
        $curlInfo   = curl_getinfo($curlHandle);
        $curlErrNo  = curl_errno($curlHandle);
        $curlErrMsg = curl_error($curlHandle);

        list(
            $request,
            $success,
            $error
        ) = $this->requestMap[intval($curlHandle)];

        if ($curlErrNo) {
            $error(self::ERR_CURL, [
                'curl_errno'  => $curlErrNo,
                'curl_errmsg' => $curlErrMsg,
                'request'     => $request,
            ]);
        }
        $headerBlob = substr($response, 0, $curlInfo['header_size']);
        $responseBody = substr($response, $curlInfo['header_size']);

        unset($response);


        // In the case of 100 Continue, or redirects we'll have multiple lists
        // of headers for each separate HTTP response. We can easily split this
        // because they are separated by \r\n\r\n
        $headerBlob = explode("\r\n\r\n", trim($headerBlob, "\r\n"));

        // We only care about the last set of headers
        $headerBlob = $headerBlob[count($headerBlob)-1];

        // Splitting headers
        $headerBlob = explode("\r\n", $headerBlob);

        $headers = array();
        foreach($headerBlob as $header) {
            $parts = explode(':', $header, 2);
            if (count($parts)==2) {
                $headers[trim($parts[0])] = trim($parts[1]);
            }
        }

        $response = new Response();
        $response->setStatus($curlInfo['http_code']);
        $response->setHeaders($headers);
        $response->setBody($responseBody);

        if(intval($response->getStatus())>=400) {
            $error(self::ERR_HTTP, [
                'request'     => $request,
                'response'    => $response,
                'http_code'   => intval($response->getStatus()),
            ]);
        } else {
            $success($response);
        }

    }

}
