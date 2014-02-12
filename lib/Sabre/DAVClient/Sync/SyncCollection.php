<?php

namespace Sabre\DAVClient\Sync;

use Sabre\HTTP,
    Sabre\DAVClient\Client,
    Sabre\DAVClient\RequestBuilder,
    Sabre\DAV\Property\ResponseList,
    Sabre\DAV\XMLUtil;

class SyncCollection
{
    protected $modified = [];
    protected $deleted = [];
    protected $responses;
    protected $syncToken;

    public function __construct($syncToken, array $responses)
    {
        $this->responses = $responses;
        $this->syncToken = $syncToken;

        foreach ($responses as $uri => $response) {
            if (array_key_exists(200, $response)) {
                $this->modified[] = new VCardReference($uri, $response[200]);
            } elseif (array_key_exists(404, $response)) {
                $this->deleted[] = new VCardReference($uri, $response[404]);
            }
        }
    }

    public function getModified()
    {
        return $this->modified;
    }

    public function getDeleted()
    {
        return $this->deleted;
    }

    public function getResponses()
    {
        return $this->responses;
    }

    public function getSyncToken()
    {
        return $this->syncToken;
    }
}
