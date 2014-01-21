<?php

namespace Sabre\DAVClient\RequestBuilder;

use Sabre\HTTP,
    Sabre\DAV\Property,
    Sabre\DAV\XMLUtil;

class PropPatchRequestBuilder implements RequestBuilderInterface
{
    protected $headers = ['Content-Type' => 'text/xml'];

    protected $method = 'PROPPATCH';

    protected $properties;

    protected $url;

    public function __construct($url, array $properties)
    {
        $this->url = $url;
        $this->properties = $properties;
    }

    public function build()
    {
        return new HTTP\Request($this->method, $this->url, $this->headers, $this->writeXML());
    }

    protected function writeXML()
    {
        $dom = new \DOMDocument('1.0', 'UTF-8');
        $dom->formatOutput = true;
        $root = $dom->createElementNS('DAV:', 'd:propertyupdate');

        foreach ($this->properties as $propName => $propValue) {
            list($namespace, $elementName) = XMLUtil::parseClarkNotation($propName);

            if ($propValue === null) {
                $remove = $dom->createElement('d:remove');
                $prop = $dom->createElement('d:prop');

                if ($namespace === 'DAV:') {
                    $element = $dom->createElement('d:' . $elementName);
                } else {
                    $element = $dom->createElementNS($namespace, 'x:' . $elementName);
                }

                $root->appendChild($remove)->appendChild($prop)->appendChild($element);
            } else {
                $set = $dom->createElement('d:set');
                $prop = $dom->createElement('d:prop');

                if ($namespace === 'DAV:') {
                    $element = $dom->createElement('d:' . $elementName);
                } else {
                    $element = $dom->createElementNS($namespace, 'x:' . $elementName);
                }

                if ($propValue instanceof Property) {
                    $propValue->serialize( new Server, $element );
                } else {
                    $element->nodeValue = htmlspecialchars($propValue, ENT_NOQUOTES, 'UTF-8');
                }

                $root->appendChild($set)->appendChild($prop)->appendChild($element);
            }
        }

        $dom->appendChild($root);

        return $dom->saveXML();
    }
}
