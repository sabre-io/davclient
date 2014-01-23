<?php

namespace Sabre\DAVClient\Adapter;

use Sabre,
    Sabre\HTTP,
    Sabre\DAVClient\ClarkNotation\CardDAV,
    Sabre\DAVClient\ClarkNotation\DAV,
    Sabre\DAVClient\Client,
    Sabre\DAVClient\RequestBuilder,
    Sabre\DAVClient\Sync,
    Sabre\DAV\Property\ResponseList,
    Sabre\DAV\XMLUtil;

class CardDAVAdapter
{
    protected $client;

    public function __construct(Client $client)
    {
        $this->client = $client;
    }

    public function addressBookMultiGet($uri, array $uids = [])
    {
        $builder = new RequestBuilder\AddressBookMultiGetRequestBuilder($uri, $uids);

        $request = $builder->build();

        return $this->client->send($request);
    }

    public function getCTags($uri)
    {
        $ctags = $this->client->propFind($uri, [DAV::CTAG], 1);

        $ctags = array_map(
            function ($ctag) {
                return $ctag[DAV::CTAG];
            },
            $ctags
        );

        return $ctags;
    }

    public function getETags($uri)
    {
        $etags = $this->client->propFind($uri, [DAV::ETAG], 1);

        array_shift($etags); // first result references the address book

        $etags = array_map(
            function ($etag) {
                return $etag[DAV::ETAG];
            },
            $etags
        );

        return $etags;
    }

    public function getSyncToken($uri)
    {
        $syncToken = $this->client->propFind($uri, [DAV::SYNC_TOKEN], 1);

        return $syncToken[$uri][DAV::SYNC_TOKEN];
    }

    public function getSyncCollection($uri, $syncToken = null, $syncLevel = 1)
    {
        $request = (new RequestBuilder\SyncCollectionRequestBuilder($uri, $syncToken, $syncLevel))->build();

        $response = $this->client->send($request);

        if ($response->getStatus() == 207) {
            $syncToken = $this->parseMultiStatusSyncToken($response->getBody(true));
            $responses = $this->client->parseMultiStatus($response->getBody(true));
        } elseif (!$syncToken && $response->getStatus() == 400) {
            // fill in for CardDAV servers that do not support token-less sync-collection requests (e.g. Google)
            $syncToken = $this->getSyncToken($uri);

            $response = $this->addressBookMultiGet($uri, array_keys($this->getEtags($uri)));
            $responses = $this->client->parseMultiStatus($response->getBody(true));
        } else {
            throw new \Exception('HTTP error: ' . $response->getStatus());
        }

        return new Sync\SyncCollection($syncToken, $responses);
    }

    /**
     * Returns a valid and unused vCard id
     *
     * @return string $id valid vCard id
     */
    public function generateVCardID($uri)
    {
        $id = null;

        $chars = [0, 1, 2, 3, 4, 5, 6, 7, 8, 9, 'A', 'B', 'C', 'D', 'E', 'F'];

        for ($i = 0; $i <= 25; $i++) {
            if ($i == 8 || $i == 17) {
                $id .= '-';
            } else {
                $id .= $chars[mt_rand(0, (count($chars) - 1))];
            }
        }

        $response = $this->client->send(new HTTP\Request('GET', $uri . $id));

        switch ($response->getStatus()) {
            case 200:
                // id is not unique, try again
                $id = $this->generateVCardID($uri);

                break;
            case 404:
                // do nothing, id is unique

                break;
            default:
                throw new \Exception('Unexpected HTTP ' . $response->getStatus() . ' when generating new VCard ID.');
        }

        return $id;
    }

    /**
     * Returns a valid and unused vCard uri
     *
     * @return string $uri valid vCard uri
     */
    public function generateVCardUri($uri)
    {
        return $uri . $this->generateVCardID($uri);
    }

    public function parseMultiStatusSyncToken($body) {
        try {
            $dom = XMLUtil::loadDOMDocument($body);
        } catch (Exception\BadRequest $e) {
            throw new \InvalidArgumentException('The body passed to parseMultiStatusSyncToken could not be parsed. Is it really xml?');
        }

        $element = $dom->getElementsByTagNameNS('urn:DAV', 'sync-token');

        if (!$element->length) {
            throw new \Exception('No sync token found in parseMultiStatusSyncToken');
        }

        return $element->item(0)->textContent;
    }
}
