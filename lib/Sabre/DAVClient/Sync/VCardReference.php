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

    protected $properties;

    protected $vcard;

    public function __construct($uri, array $properties = [])
    {
        $this->setUri($uri);
        $this->setProperties($properties);
    }

    public function __toString()
    {
        return (string) $this->getUri();
    }

    public function getUri()
    {
        return $this->uri;
    }

    public function setUri($uri)
    {
        $this->uri = $uri;

        return $this;
    }

    public function getETag()
    {
        return $this->etag;
    }

    public function setETag($etag)
    {
        $this->etag = $etag;

        return $this;
    }

    public function getProperties()
    {
        return $this->properties;
    }

    public function setProperties($properties)
    {
        $this->properties = $properties;

        $this->parseProperties($properties);

        return $this;
    }

    public function getVCard()
    {
        return $this->vcard;
    }

    public function setVCard($vcard)
    {
        $this->vcard = $vcard;

        return $this;
    }

    protected function parseProperties()
    {
        if (array_key_exists(CardDAV::ADDRESS_DATA, $this->properties)) {
            $this->setVCard(Sabre\VObject\Reader::read($this->properties[CardDAV::ADDRESS_DATA]));
        }

        if (array_key_exists(DAV::ETAG, $this->properties)) {
            $this->setETag($this->properties[DAV::ETAG]);
        }

        return $this;
    }
}
