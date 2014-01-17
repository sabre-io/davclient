<?php

namespace Sabre\DAVClient\RequestBuilder;

use Sabre\HTTP;

class AddressBookMultiGetRequestBuilder implements RequestBuilderInterface
{
    protected $contacts;

    protected $headers = ['Content-Type' => 'application/xml'];

    protected $method = 'REPORT';

    protected $url;

    public function __construct($url, array $contacts)
    {
        $this->url = $url;
        $this->contacts = $contacts;
    }

    public function build()
    {
        return new HTTP\Request($this->method, $this->url, $this->headers, $this->writeXML());
    }

    protected function writeXML()
    {
        $xml = new \XMLWriter;
        $xml->openMemory();
        $xml->setIndent(4);
        $xml->startDocument('1.0', 'utf-8');
            $xml->startElement('a:addressbook-multiget');
                $xml->writeAttribute('xmlns:d', 'DAV:');
                $xml->writeAttribute('xmlns:a', 'urn:ietf:params:xml:ns:carddav');
                $xml->writeElement('d:sync-token');
                $xml->startElement('d:prop');
                    $xml->writeElement('d:getetag');
                    $xml->writeElement('a:address-data');
                $xml->endElement();

                foreach ($this->contacts as $contact) {
                    $xml->writeElement('d:href', $contact);
                }

            $xml->endElement();
        $xml->endDocument();

        return $xml->outputMemory();
    }
}
