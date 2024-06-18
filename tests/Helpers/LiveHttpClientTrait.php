<?php /** @noinspection PhpAccessingStaticMembersOnTraitInspection */

namespace Tests\Helpers;

use Connector\Integrations\Salesforce\Integration;
use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\MessageFormatter;
use GuzzleHttp\Middleware;
use League\OAuth2\Client\Token\AccessToken;
use Monolog\Handler\Handler;
use Monolog\Handler\TestHandler;
use Monolog\Logger;

defined("MOCK_FOLDER") || define("MOCK_FOLDER", "mocks/http");

/**
 * Provide valid tokens in order to run tests with a live HTTP client.
 * Do not commit valid tokens to repository.
 *
 * To get a new token, fork the "Salesforce Platform APIs" workspace on Postman.com
 * https://github.com/forcedotcom/postman-salesforce-apis/blob/master/README.md
 *  - Grant type: "Authorization Code"
 *  - Scope: "full access_token"
 *  - Client ID:  see /src/Config.php
 *  - Client Secret:  see /src/Config.php
 */
define("OAUTH_ACCESS_TOKEN", "00D3000000066PX!AQ8AQFQThPCFz1CSgLVAh482XClELkSDlQ_m1NFe.7pqnj1IwNFNB8nSP.reScCJCNMR92zB4jFkZBQt3.eTQZIR_7RfXjCM");
define("OAUTH_REFRESH_TOKEN","5Aep8619juAXTkx27acNuxGgZ6XnRIX0ODBMTEn_mrDqP9SFAkqF8cRPyAE1ow8IFMcWbIet_Er1NSruuWuGsr3");
define("OAUTH_EXPIRES", 1601592098);
define("INSTANCE_URL", "https://veerwest-dev-ed.my.salesforce.com");

define("AUTHORIZATION",[ "accessToken" => OAUTH_ACCESS_TOKEN, "refreshToken" => OAUTH_REFRESH_TOKEN, "expires" => OAUTH_EXPIRES]);

/**
 * Provides an HTTP Client that logs every request.
 * Use to capture HTTP requests and create mock GuzzleHttp/Psr7/Response (see MockHttpClientTrait).
 * For development only. Do not commit unit tests with live integrations.
 *
 */
trait LiveHttpClientTrait
{
    public string $httpClientMode = 'live';
    public Handler $httpLogHandler;
    protected static AccessToken $accessToken;

    public static function setUpBeforeClass():void
    {
        static::$accessToken = static::getValidAccessToken();
    }

    private static function getValidAccessToken(): AccessToken
    {
        $token = new AccessToken([
                                     'access_token'  => OAUTH_ACCESS_TOKEN,
                                     'refresh_token' => OAUTH_REFRESH_TOKEN,
                                     'expires'       => 1349067601
                                 ]);

        if ($token->hasExpired()) {
            $token = (new Integration())->getAuthorizationProvider()->getAccessToken('refresh_token', [
                'refresh_token' => $token->getRefreshToken()
            ]);
        }
        return $token;
    }

    public function getHttpClient(): Client
    {
        $this->httpLogHandler = new TestHandler();
        $logger = new Logger('Logger', [$this->httpLogHandler]);
        $stack = HandlerStack::create();
        $stack->push(
            Middleware::log(
                $logger,
                new MessageFormatter('{method}|$|{uri}|$|{req_body}|$|{code}|$|{res_headers}|$|{res_body}')
            )
        );

        $options = [
            'handler' => $stack,
        ];

        if(static::$accessToken) {
            $options['headers']['Authorization'] = 'Bearer ' . static::$accessToken->getToken();
        }

        // Custom configuration settings. Disables concurrency when making API calls.
        // This is needed in order to mock requests & responses in a predictable order.
        $options['config'] = ['no_concurrent_requests' => true];

        return new Client($options);
    }

    public function writeRequestMocks(string $testDir, string $testName = ""): void
    {
        $mockFolder = rtrim($testDir . "/" . MOCK_FOLDER . "/" . $testName, "/");
        if (! is_dir($mockFolder)) {
            mkdir($mockFolder, 0777, true);
        }

        foreach ($this->httpLogHandler->getRecords() as $i => $requestLog) {
            [
                $httpRequestMethod,
                $httpRequestURI,
                $httpRequestBody,
                $httpResponseCode,
                $httpResponseHeaders,
                $httpResponseBody,
            ]
                = explode("|$|", $requestLog['message']);
            $fileName = preg_replace(
                '/[^a-zA-Z0-9_-]+/',
                '-',
                $i . "-" . $httpRequestMethod . "_" . explode("?", $httpRequestURI)[0]
            );

            $fileName = preg_replace('/[^a-zA-Z0-9_-]+/', '-', $fileName . "_" . $httpResponseCode . "-response");
            file_put_contents($mockFolder . "/" . $fileName . ".json", json_encode(json_decode($httpResponseBody), JSON_PRETTY_PRINT));
        }
    }
}
