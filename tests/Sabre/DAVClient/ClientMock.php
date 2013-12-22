<?php

namespace Sabre\DAVClient;

class ClientMock extends Client {

    public $response;

    public $url;

    /**
     * Just making this method public
     *
     * @param string $url
     * @return string
     */
    public function getAbsoluteUrl($url) {

        return parent::getAbsoluteUrl($url);

    }

}
