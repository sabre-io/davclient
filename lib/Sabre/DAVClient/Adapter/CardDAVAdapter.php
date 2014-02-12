<?php

namespace Sabre\DAVClient\Adapter;

use Sabre,
    Sabre\HTTP,
    Sabre\DAVClient\ClarkNotation\CardDAV,
    Sabre\DAVClient\ClarkNotation\DAV,
    Sabre\DAVClient\Client,
    Sabre\DAVClient\RequestBuilder,
    Sabre\DAVClient\Sync;

class CardDAVAdapter
{
    protected $client;

    public function __construct(Client $client)
    {
        $this->client = $client;
    }

    public function addressBookMultiGet($addressBookUri, array $vcardUris = [])
    {
        $builder = new RequestBuilder\AddressBookMultiGetRequestBuilder($addressBookUri, $vcardUris);

        $request = $builder->build();

        return $this->client->send($request);
    }

    public function getCTag($uri)
    {
        return current($this->getCTags($uri));
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

    public function getETag($uri)
    {
        return current($this->getETags($uri));
    }

    public function getETags($uri)
    {
        $etags = $this->client->propFind($uri, [DAV::ETAG], 1);

        // if the uri was for an address book, the first result is the address book, with no properties
        $etags = array_filter($etags);

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

    public function getVCard($addressBookUri, $vcardUri)
    {
        return current($this->getVCards($addressBookUri, [$vcardUri]));
    }

    public function getVCards($addressBookUri, $vcardUris = [])
    {
        // if no vcardUri's are passed, request the lot and return all vcards
        $vcardUris = $vcardUris ?: $this->getVCardUris($addressBookUri);

        $response = $this->addressBookMultiGet($addressBookUri, $vcardUris);

        $multistatus = $this->client->parseMultiStatus($response->getBody(true));

        $results = [];

        foreach ($multistatus as $href => $statusList) {
            $results[$href] = isset($statusList[200]) ? $statusList[200] : [];
        }

        $vcards = [];

        foreach ($results as $uri => $result) {
            $vcard = Sabre\VObject\Reader::read($result[CardDAV::ADDRESS_DATA]);

            $vcards[$uri] = $vcard;
        }

        return $vcards;
    }

    public function getVCardUris($addressBookUri)
    {
        return array_keys($this->getEtags($addressBookUri));
    }

    /**
     * Returns a valid and unused vCard id
     *
     * @return string $id valid vCard id
     */
    public function generateVCardID($uri)
    {
        $id = Sabre\DAV\UUIDUtil::getUUID();

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
            $dom = Sabre\DAV\XMLUtil::loadDOMDocument($body);
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
