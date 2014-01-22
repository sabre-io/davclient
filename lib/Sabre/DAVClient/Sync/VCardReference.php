<?php

namespace Sabre\DAVClient\Sync;

use Sabre,
    Sabre\DAVClient\ClarkNotation\CardDAV,
    Sabre\DAVClient\ClarkNotation\DAV,
    Sabre\DAVClient\Client;

class VCardReference
{
    protected $uri;

    protected $etag;

    protected $vcard;

    public function __construct($uri, array $properties = [])
    {
        $this->uri = $uri;

        if (array_key_exists(CardDAV::ADDRESS_DATA, $properties)) {
            $this->vcard = Sabre\VObject\Reader::read($properties[CardDAV::ADDRESS_DATA]);
        }

        if (array_key_exists(DAV::ETAG, $properties)) {
            $this->etag = $properties[DAV::ETAG];
        }
    }

    public function __toString()
    {
        return (string) $this->getUri();
    }

    public function getUri()
    {
        return $this->uri;
    }

    public function getVCard()
    {
        return $this->vcard;
    }

    public function getETag()
    {
        return $this->etag;
    }
}
