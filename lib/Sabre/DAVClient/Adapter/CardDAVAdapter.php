<?php

namespace Sabre\DAVClient\Adapter;

use Sabre\HTTP,
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
