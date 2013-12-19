<?php

namespace Sabre\DAV;

use Sabre\HTTP;

/**
 * SabreDAV DAV client
 *
 * This client wraps around Curl to provide a convenient API to a WebDAV
 * server.
 *
 * NOTE: This class is experimental, it's api will likely change in the future.
 *
 * @copyright Copyright (C) 2007-2013 fruux GmbH (https://fruux.com/).
 * @author Evert Pot (http://evertpot.com/)
 * @license http://code.google.com/p/sabredav/wiki/License Modified BSD License
 */
class Client extends HTTP\Client
{
    /**
     * Basic authentication
     */
    const AUTH_BASIC = 1;

    /**
     * Digest authentication
     */
    const AUTH_DIGEST = 2;

    /**
     * Identity encoding, which basically does not nothing.
     */
    const ENCODING_IDENTITY = 1;

    /**
     * Deflate encoding
     */
    const ENCODING_DEFLATE = 2;

    /**
     * Gzip encoding
     */
    const ENCODING_GZIP = 4;

    /**
     * Sends all encoding headers.
     */
    const ENCODING_ALL = 7;

    /**
     * Curl error. Could not complete the HTTP request
     */
    const ERR_CURL = 1;

    /**
     * HTTP error, such as a 4xx or 5xx
     */
    const ERR_HTTP = 2;

    /**
     * The propertyMap is a key-value array.
     *
     * If you use the propertyMap, any {DAV:}multistatus responses with the
     * properties listed in this array, will automatically be mapped to a
     * respective class.
     *
     * The {DAV:}resourcetype property is automatically added. This maps to
     * Sabre\DAV\Property\ResourceType
     *
     * @var array
     */
    public $propertyMap = [];

    /**
     * Base URI
     *
     * @var string
     */
    protected $baseUri;

    /**
     * OAuth2 accessToken
     *
     * @var string
     */
    protected $accessToken;

    /**
     * OAuth2 tokenType
     *
     * @var string
     */
    protected $tokenType;

    /**
     * Content-encoding
     *
     * @var int
     */
    protected $encoding = self::ENCODING_IDENTITY;

    protected $curlMultiHandle;

    /**
     * A list of open http request and all the associated information.
     *
     * @var array
     */
    protected $requestMap = [];

    /**
     * Constructor
     *
     * Settings are provided through the 'settings' argument. The following
     * settings are supported:
     *
     *   * baseUri
     *   * userName (optional)
     *   * password (optional)
     *   * proxy (optional)
     *   * authType (optional)
     *   * encoding (optional)
     *
     *  authType must be a bitmap, using self::AUTH_BASIC and
     *  self::AUTH_DIGEST. If you know which authentication method will be
     *  used, it's recommended to set it, as it will save a great deal of
     *  requests to 'discover' this information.
     *
     *  Encoding is a bitmap with one of the ENCODING constants.
     *
     * @param array $settings
     */
    public function __construct(array $settings)
    {
        if (!isset($settings['baseUri'])) {
            throw new \InvalidArgumentException('A baseUri must be provided');
        }

        parent::__construct();

        $this->baseUri = $settings['baseUri'];

        if (isset($settings['proxy'])) {
            $this->addCurlSetting(CURLOPT_PROXY, $settings['proxy']);
        }

        // basic/digest auth
        if (isset($settings['userName'])) {
            $userName = $settings['userName'];
            $password = isset($settings['password'])?$settings['password']:'';

            if (isset($settings['authType'])) {
                $curlType = 0;

                if ($settings['authType'] & self::AUTH_BASIC) {
                    $curlType |= CURLAUTH_BASIC;
                }

                if ($settings['authType'] & self::AUTH_DIGEST) {
                    $curlType |= CURLAUTH_DIGEST;
                }
            } else {
                $curlType = CURLAUTH_BASIC | CURLAUTH_DIGEST;
            }

            $this->addCurlSetting(CURLOPT_HTTPAUTH, $curlType);
            $this->addCurlSetting(CURLOPT_USERPWD, $userName . ':' . $password);
        }

        // oauth2
        if (isset($settings['accessToken'], $settings['tokenType'])) {
            $this->accessToken = $settings['accessToken'];
            $this->tokenType = $settings['tokenType'];
        }

        // encoding
        if (isset($settings['encoding'])) {
            $encoding = $settings['encoding'];

            $encodings = [];

            if ($encoding & self::ENCODING_IDENTITY) {
                $encodings[] = 'identity';
            }

            if ($encoding & self::ENCODING_DEFLATE) {
                $encodings[] = 'deflate';
            }

            if ($encoding & self::ENCODING_GZIP) {
                $encodings[] = 'gzip';
            }

            $this->addCurlSetting(CURLOPT_ENCODING, implode(',', $encodings));
        }

        $this->propertyMap['{DAV:}resourcetype'] = 'Sabre\\DAV\\Property\\ResourceType';

        $this->curlMultiHandle = curl_multi_init();
    }


    /**
     * Add trusted root certificates to the webdav client.
     *
     * The parameter certificates should be a absolute path to a file
     * which contains all trusted certificates
     *
     * @param string $certificates
     * @return void
     */
    public function addTrustedCertificates($certificates)
    {
        $this->addCurlSetting(CURLOPT_CAINFO, $certificates);
    }

    /**
     * Enables/disables SSL peer verification
     *
     * @param bool $value
     * @return void
     */
    public function setVerifyPeer($value)
    {
        $this->addCurlSetting(CURLOPT_SSL_VERIFYPEER, $value);
    }

    /**
     * Does a PROPFIND request
     *
     * The list of requested properties must be specified as an array, in clark
     * notation.
     *
     * The returned array will contain a list of filenames as keys, and
     * properties as values.
     *
     * The properties array will contain the list of properties. Only properties
     * that are actually returned from the server (without error) will be
     * returned, anything else is discarded.
     *
     * Depth should be either 0 or 1. A depth of 1 will cause a request to be
     * made to the server to also return all child resources.
     *
     * @param string $url
     * @param array $properties
     * @param int $depth
     * @return array
     */
    public function propFind($url, array $properties, $depth = 0)
    {
        $dom = new \DOMDocument('1.0', 'UTF-8');
        $dom->formatOutput = true;
        $root = $dom->createElementNS('DAV:', 'd:propfind');
        $prop = $dom->createElement('d:prop');

        foreach ($properties as $property) {
            list(
                $namespace,
                $elementName
            ) = XMLUtil::parseClarkNotation($property);

            if ($namespace === 'DAV:') {
                $element = $dom->createElement('d:'.$elementName);
            } else {
                $element = $dom->createElementNS($namespace, 'x:'.$elementName);
            }

            $prop->appendChild( $element );
        }

        $dom->appendChild($root)->appendChild( $prop );
        $body = $dom->saveXML();

        $url = $this->getAbsoluteUrl($url);

        $request = $this->createRequest('PROPFIND', $url, [
            'Depth' => $depth,
            'Content-Type' => 'application/xml'
        ], $body);

        $response = $this->send($request);

        if ((int)$response->getStatus() >= 400) {
            throw new Exception('HTTP error: ' . $response->getStatus());
        }

        $result = $this->parseMultiStatus($response->getBody(true));

        // If depth was 0, we only return the top item
        if ($depth === 0) {
            reset($result);
            $result = current($result);
            return isset($result[200]) ? $result[200] : [];
        }

        $newResult = [];

        foreach ($result as $href => $statusList) {
            $newResult[$href] = isset($statusList[200]) ? $statusList[200] : [];
        }

        return $newResult;
    }

    /**
     * Updates a list of properties on the server
     *
     * The list of properties must have clark-notation properties for the keys,
     * and the actual (string) value for the value. If the value is null, an
     * attempt is made to delete the property.
     *
     * @param string $url
     * @param array $properties
     * @return void
     */
    public function propPatch($url, array $properties)
    {
        $dom = new \DOMDocument('1.0', 'UTF-8');
        $dom->formatOutput = true;
        $root = $dom->createElementNS('DAV:', 'd:propertyupdate');

        foreach ($properties as $propName => $propValue) {
            list(
                $namespace,
                $elementName
            ) = XMLUtil::parseClarkNotation($propName);

            if ($propValue === null) {
                $remove = $dom->createElement('d:remove');
                $prop = $dom->createElement('d:prop');

                if ($namespace === 'DAV:') {
                    $element = $dom->createElement('d:'.$elementName);
                } else {
                    $element = $dom->createElementNS($namespace, 'x:'.$elementName);
                }

                $root->appendChild( $remove )->appendChild( $prop )->appendChild( $element );
            } else {

                $set = $dom->createElement('d:set');
                $prop = $dom->createElement('d:prop');

                if ($namespace === 'DAV:') {
                    $element = $dom->createElement('d:'.$elementName);
                } else {
                    $element = $dom->createElementNS($namespace, 'x:'.$elementName);
                }

                if ( $propValue instanceof Property ) {
                    $propValue->serialize( new Server, $element );
                } else {
                    $element->nodeValue = htmlspecialchars($propValue, ENT_NOQUOTES, 'UTF-8');
                }

                $root->appendChild( $set )->appendChild( $prop )->appendChild( $element );
            }
        }

        $dom->appendChild($root);
        $body = $dom->saveXML();

        $url = $this->getAbsoluteUrl($url);
        $request = $this->createRequest('PROPPATCH', $url, [
            'Content-Type' => 'application/xml',
        ], $body);
        $this->send($request);
    }

    /**
     * Performs an HTTP options request
     *
     * This method returns all the features from the 'DAV:' header as an array.
     * If there was no DAV header, or no contents this method will return an
     * empty array.
     *
     * @return array
     */
    public function options()
    {
        $request = $this->createRequest('OPTIONS', $this->getAbsoluteUrl(''));
        $response = $this->send($request);

        $dav = $response->getHeader('Dav');

        if (!$dav) {
            return [];
        }

        $features = explode(',', $dav);

        foreach ($features as &$v) {
            $v = trim($v);
        }

        return $features;
    }

    /**
     * Performs an actual HTTP request, and returns the result.
     *
     * If the specified url is relative, it will be expanded based on the base
     * url.
     *
     * The returned array contains 3 keys:
     *   * body - the response body
     *   * httpCode - a HTTP code (200, 404, etc)
     *   * headers - a list of response http headers. The header names have
     *     been lowercased.
     *
     * For large uploads, it's highly recommended to specify body as a stream
     * resource. You can easily do this by simply passing the result of
     * fopen(..., 'r').
     *
     * This method will throw an exception if an HTTP error was received. Any
     * HTTP status code above 399 is considered an error.
     *
     * Note that it is no longer recommended to use this method, use the send()
     * method instead.
     *
     * @param string $method
     * @param string $url
     * @param string|resource|null $body
     * @param array $headers
     * @throws ClientException, in case a curl error occurred.
     * @return array
     */
    public function request($method, $url = '', $body = null, array $headers = [])
    {
        $url = $this->getAbsoluteUrl($url);

        $request = $this->createRequest($method, $url, $headers, $body);
        $response = $this->send($request);

        return [
            'body' => $response->getBody($asString = true),
            'statusCode' => (int)$response->getStatus(),
            'headers' => array_change_key_case($response->getHeaders())
        ];
    }

    /**
     * Returns the full url based on the given url (which may be relative). All
     * urls are expanded based on the base url as given by the server.
     *
     * @param string $url
     * @return string
     */
    public function getAbsoluteUrl($url)
    {
        // If the url starts with http:// or https://, the url is already absolute.
        if (preg_match('/^http(s?):\/\//', $url)) {
            return $url;
        }

        // If the url starts with a slash, we must calculate the url based off
        // the root of the base url.
        if (strpos($url,'/') === 0) {
            $parts = parse_url($this->baseUri);
            return $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port'])?':' . $parts['port']:'') . $url;
        }

        // Otherwise...
        return $this->baseUri . $url;
    }

    /**
     * Parses a WebDAV multistatus response body
     *
     * This method returns an array with the following structure
     *
     * array(
     *   'url/to/resource' => array(
     *     '200' => array(
     *        '{DAV:}property1' => 'value1',
     *        '{DAV:}property2' => 'value2',
     *     ),
     *     '404' => array(
     *        '{DAV:}property1' => null,
     *        '{DAV:}property2' => null,
     *     ),
     *   )
     *   'url/to/resource2' => array(
     *      .. etc ..
     *   )
     * )
     *
     *
     * @param string $body xml body
     * @return array
     */
    public function parseMultiStatus($body)
    {
        try {
            $dom = XMLUtil::loadDOMDocument($body);
        } catch (Exception\BadRequest $e) {
            throw new \InvalidArgumentException('The body passed to parseMultiStatus could not be parsed. Is it really xml?');
        }

        $responses = Property\ResponseList::unserialize(
            $dom->documentElement,
            $this->propertyMap
        );

        $result = [];

        foreach ($responses->getResponses() as $response) {
            $result[$response->getHref()] = $response->getResponseProperties();
        }

        return $result;
    }

    public function addToRequestQueue(HTTP\RequestInterface $request, callable $success = null, callable $error = null)
    {
        $settings = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_POSTREDIR => 3
        ];

        switch ($request->getMethod()) {
            case 'HEAD':
                $settings[CURLOPT_NOBODY] = true;
                $settings[CURLOPT_CUSTOMREQUEST] = 'HEAD';
                $settings[CURLOPT_POSTFIELDS] = '';
                $settings[CURLOPT_PUT] = false;
                break;
            case 'GET':
                $settings[CURLOPT_CUSTOMREQUEST] = 'GET';
                $settings[CURLOPT_POSTFIELDS] = '';
                $settings[CURLOPT_PUT] = false;
                break;
            default:
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
        }

        $nHeaders = [];

        foreach ($request->getHeaders() as $key=>$value) {
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
    public function wait()
    {
        do {
            curl_multi_select($this->curlMultiHandle);
            $stillRunning = $this->processQueue();
        } while ($stillRunning);
    }

    protected function createRequest($method, $url, $headers, $body)
    {
        if (!array_key_exists('Authorization', $headers) && $this->accessToken && $this->tokenType) {
            $headers['Authorization'] = $this->tokenType . ' ' . $this->accessToken;
        }

        return new HTTP\Request($method, $url, $headers, $body);
    }

    protected function processQueue()
    {
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

    protected function processResult($curlHandle)
    {
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

        $headers = [];

        foreach ($headerBlob as $header) {
            $parts = explode(':', $header, 2);

            if (count($parts) == 2) {
                $headers[trim($parts[0])] = trim($parts[1]);
            }
        }

        $response = new HTTP\Response;
        $response->setStatus($curlInfo['http_code']);
        $response->setHeaders($headers);
        $response->setBody($responseBody);

        if (intval($response->getStatus()) >= 400) {
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
