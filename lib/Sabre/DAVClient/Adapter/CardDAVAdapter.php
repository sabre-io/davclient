<?php

namespace Sabre\DAVClient\Adapter;

use Sabre\HTTP,
    Sabre\DAVClient\Client,
    Sabre\DAVClient\RequestBuilder;

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

    public function syncCollectionReport($uri, $sync_token = null, $sync_level = 'infinite')
    {
        $builder = new RequestBuilder\SyncCollectionReportRequestBuilder($uri, $sync_token, $sync_level);

        $request = $builder->build();

        return $this->client->send($request);
    }
}
