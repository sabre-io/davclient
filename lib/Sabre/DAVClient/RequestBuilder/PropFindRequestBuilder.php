<?php

namespace Sabre\DAVClient\RequestBuilder;

use Sabre\HTTP,
    Sabre\DAV\XMLUtil;

class PropFindRequestBuilder implements RequestBuilderInterface
{
    protected $depth = 0;

    protected $headers = ['Content-Type' => 'text/xml'];

    protected $method = 'PROPFIND';

    protected $properties;

    protected $url;

    public function __construct($url, array $properties, $depth = 0)
    {
        $this->url = $url;
        $this->properties = $properties;
        $this->depth = $depth;
    }

    public function build()
    {
        $headers = array_merge($this->headers, ['Depth' => $this->depth]);

        return new HTTP\Request($this->method, $this->url, $headers, $this->writeXML());
    }

    protected function writeXML()
    {
        $dom = new \DOMDocument('1.0', 'UTF-8');
        $dom->formatOutput = true;
        $root = $dom->createElementNS('DAV:', 'd:propfind');
        $prop = $dom->createElement('d:prop');

        foreach ($this->properties as $property) {
            list($namespace, $elementName) = XMLUtil::parseClarkNotation($property);

            if ($namespace === 'DAV:') {
                $element = $dom->createElement('d:' . $elementName);
            } else {
                $element = $dom->createElementNS($namespace, 'x:' . $elementName);
            }

            $prop->appendChild($element);
        }

        $dom->appendChild($root)->appendChild($prop);

        return $dom->saveXML();
    }
}
