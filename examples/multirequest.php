<?php

include __DIR__ . '/../vendor/autoload.php';

$client = new Sabre\DAVClient\Client();

$urls = [
    'http://www.google.com/',
    'http://www.facebook.com/',
    'http://www.yahoo.com/',
    'http://www.fruux.com/',
    'http://www.example.org/',
    'http://www.amazon.com/',
    'http://www.sabredav.org/',
    'http://www.evertpot.com/',
    'http://www.google.ca/',
    'http://www.reddit.com/',
    'http://www.ebay.com/',
    'http://www.bing.com/',
    'http://www.apple.com/',
    'http://www.ubuntu.org/',

];

foreach($urls as $url) {

    $request = new \Sabre\HTTP\Request('GET', $url);
    echo "add $url\n";

    $client->addToRequestQueue(
        $request,
        function(\Sabre\HTTP\ResponseInterface $response) use ($url) {

            echo $response->getStatus() . " $url\n";

        },
        function($errorType, array $errorInfo) {

            echo "error $url\n";

        }
    );

    // You can totally take out this sleep statement. It's just added to show
    // that this is truly asynchronous.
    usleep(500000); // 200ms

}
$client->wait();
