<?php

namespace Sabre\DAVClient\Adapter;

use Sabre\HTTP,
    Sabre\DAVClient\Client,
    Sabre\DAVClient\RequestBuilder,
    Sabre\DAV\Property\ResponseList,
    Sabre\DAV\XMLUtil;

class CardDAVAdapter
{
    protected $client;

    public function __construct(Client $client)
    {
        $this->client = $client;
    }

    public function addressBookMultiGetReport($uri, $uids = [])
    {
        $builder = new RequestBuilder\AddressBookMultiGetRequestBuilder($uri, $uids);

        $request = $builder->build();

        return $this->client->send($request);
    }

    public function getCTags($uri)
    {
        $ctags = $this->client->propFind($uri, ['{DAV:}getctag'], 1);

        $ctags = array_map(
            function ($ctag) {
                return $ctag['{DAV:}getctag'];
            },
            $ctags
        );

        return $ctags;
    }

    public function getETags($uri)
    {
        $etags = $this->client->propFind($uri, ['{DAV:}getetag'], 1);

        array_shift($etags); // first result references the address book

        $etags = array_map(
            function ($etag) {
                return $etag['{DAV:}getetag'];
            },
            $etags
        );

        return $etags;
    }

    public function getSyncToken($uri)
    {
        $sync_token = $this->client->propFind($uri, ['{DAV:}sync-token'], 1);

        return $sync_token[$uri]['{DAV:}sync-token'];
    }

    public function syncCollectionReport($uri, $sync_token = null, $sync_level = 1)
    {
        $builder = new RequestBuilder\SyncCollectionReportRequestBuilder($uri, $sync_token, $sync_level);

        $request = $builder->build();

        return $this->client->send($request);
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
