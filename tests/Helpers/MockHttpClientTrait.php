<?php /** @noinspection PhpAccessingStaticMembersOnTraitInspection */

namespace Tests\Helpers;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use League\OAuth2\Client\Token\AccessToken;

defined("MOCK_FOLDER") || define("MOCK_FOLDER", "mocks/http");

define("OAUTH_ACCESS_TOKEN", "__MOCK_ACCESS_TOKEN__");
define("OAUTH_REFRESH_TOKEN","__MOCK_REFRESH_TOKEN__");
define("OAUTH_EXPIRES", 1601592098);
define("INSTANCE_URL", "https://formassembly.my.salesforce.com");

define("AUTHORIZATION",[ "accessToken" => OAUTH_ACCESS_TOKEN, "refreshToken" => OAUTH_REFRESH_TOKEN, "expires" => OAUTH_EXPIRES]);

/**
 * Mock HTTP Client to provide mocked API requests to unit tests.
 */
trait MockHttpClientTrait
{
    public string $httpClientMode = 'mock';
    public array $container = [];
    protected static AccessToken $accessToken;

    /**
     * @param \GuzzleHttp\Psr7\Response[] $httpResponses
     *
     * @return \GuzzleHttp\Client
     */
    function getHttpClient(array $httpResponses): Client
    {
        $this->container = [];
        $history         = Middleware::history($this->container);
        $mock            = new MockHandler($httpResponses);
        $handlerStack    = HandlerStack::create($mock);
        $handlerStack->push($history);

        // no_concurrent_request is a custom configuration settings. It disables the use of concurrent requests
        // in API calls. This is needed in order to capture requests/responses for mocking in a predictable order.
        return new Client(['handler' => $handlerStack, 'config' => ['no_concurrent_requests' => true]]);
    }
}
