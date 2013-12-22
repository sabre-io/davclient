<?php

namespace Sabre\DAVClient;

require_once __DIR__ . '/ClientMock.php';

class ClientTest extends \PHPUnit_Framework_TestCase {

    function testConstruct() {

        $client = new ClientMock(array(
            'baseUri' => '/',
        ));
        $this->assertInstanceOf('Sabre\DAVClient\ClientMock', $client);

    }

    /**
     * @expectedException InvalidArgumentException
     */
    function testConstructNoBaseUri() {

        $client = new ClientMock(array());

    }

}
