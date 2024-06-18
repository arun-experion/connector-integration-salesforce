<?php

namespace Connector\Integrations\Salesforce;

use Connector\Exceptions\AbortedOperationException;
use Connector\Exceptions\InvalidExecutionPlan;
use Connector\Exceptions\RecordNotFound;
use Connector\Exceptions\SkippedOperationException;
use Connector\Integrations\AbstractIntegration;
use Connector\Integrations\Authorizations\OAuthInterface;
use Connector\Integrations\Authorizations\OAuthTrait;
use Connector\Integrations\Salesforce\Enumerations\ManyResultsOptions;
use Connector\Integrations\Salesforce\Enumerations\NoResultOptions;
use Connector\Mapping;
use Connector\Record;
use Connector\Record\RecordKey;
use Connector\Record\RecordLocator;
use Connector\Integrations\Response;
use Connector\Record\Recordset;
use Connector\Schema\IntegrationSchema;
use GuzzleHttp;
use League\OAuth2\Client\Provider\AbstractProvider;
use League\OAuth2\Client\Provider\Exception\IdentityProviderException;
use League\OAuth2\Client\Provider\GenericProvider;
use League\OAuth2\Client\Provider\ResourceOwnerInterface;
use League\OAuth2\Client\Token\AccessToken;


class Integration extends AbstractIntegration implements OAuthInterface
{

    use OAuthTrait;

    private GuzzleHttp\Client $httpClient;

    // Salesforce REST API supports batch processing using composite queries.
    // See https://developer.salesforce.com/docs/atlas.en-us.api_rest.meta/api_rest/resources_composite_composite.htm
    static private array $batch = [];

    /**
     * @var \Connector\Operation\Result[] $deferredResults
     */
    static private array $deferredResults = [];

    private string $instanceUrl = '';
    private string $apiVersion  = Config::API_VERSION;

    /**
     * @param \GuzzleHttp\Client|null $httpClient Provide a Guzzle HTTP Client if special configuration
     *                                            is needed (e.g. mocking requests in Unit Tests).
     *                                            Leave empty otherwise.
     */
    public function __construct(array $config = [], ?GuzzleHttp\Client $httpClient = null)
    {
        $options = $httpClient ? $httpClient->getConfig() : [];
        $options['base_uri'] = Config::BASE_URI;
        $this->httpClient = new GuzzleHttp\Client($options);
    }

    public function setApiVersion(string $apiVersion): void
    {
        $this->apiVersion    = $apiVersion;
        $options             = $this->httpClient->getConfig();
        $options['base_uri'] = $this->instanceUrl . "/services/data/v" . $this->apiVersion . "/";
        $this->httpClient    = new GuzzleHttp\Client($options);
    }

    /**
     * @throws \Connector\Exceptions\InvalidExecutionPlan
     * @throws \League\OAuth2\Client\Provider\Exception\IdentityProviderException
     */
    public function setAuthorization(?string $authorization): void
    {
        $this->setOAuthCredentials($authorization);

        $authorization = json_decode($authorization, JSON_OBJECT_AS_ARRAY);
        if(isset($authorization['values']['instance_url'])) {
            $this->instanceUrl = $authorization['values']['instance_url'];
        }

        $token = new AccessToken([
                                     'access_token'  => $this->getAccessToken(),
                                     'refresh_token' => $this->getRefreshToken(),
                                     'expires'       => $this->getExpires(),
                                 ]);

        if ($token->hasExpired() || !$this->instanceUrl) {
            $token = $this->getAuthorizationProvider()->getAccessToken('refresh_token', [
                'refresh_token' => $token->getRefreshToken(),
            ]);
            if(!isset($token->getValues()['instance_url'])) {
                throw new IdentityProviderException('Salesforce Access Token did not provide the instance URL', 0, "");
            }
            $this->instanceUrl = rtrim($token->getValues()['instance_url'],"/");
        }

        $options = $this->httpClient->getConfig();
        $options['headers']['Authorization'] = 'Bearer ' . $token->getToken();
        $options['base_uri'] = $this->instanceUrl . "/services/data/v" . $this->apiVersion . "/";

        $this->httpClient = new GuzzleHttp\Client($options);
    }

    /**
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \Throwable
     */
    public function discover(): IntegrationSchema
    {
        return new SalesforceSchema($this->httpClient);
    }

    public function begin(?array $options=[]): void
    {
        $this->setApiVersion($options['apiVersion'] ?? Config::API_VERSION);
        self::$batch = [];
        self::$deferredResults = [];
    }

    /**
     * @return \Connector\Operation\Result[]|null
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function end(): ?array
    {
        $this->executeBatch();

        // TODO: Check and report on api usage
        // see https://developer.salesforce.com/docs/atlas.en-us.api_rest.meta/api_rest/headers_api_usage.htm

        // TODO: Log Warning header
        // see https://developer.salesforce.com/docs/atlas.en-us.api_rest.meta/api_rest/headers_warning.htm
        return self::$deferredResults;
    }

    /**
     * @param \Connector\Record\RecordLocator  $recordLocator
     * @param \Connector\Mapping               $mapping
     * @param \Connector\Record\RecordKey|null $scope
     *
     * @return \Connector\Integrations\Response
     * @throws \Connector\Exceptions\InvalidSchemaException
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function extract(RecordLocator $recordLocator, Mapping $mapping, ?RecordKey $scope): Response
    {
        // Recast to our child class
        $recordLocator = new SalesforceRecordLocator($recordLocator, $this->getSchema());

        $action = new Actions\Select($recordLocator, $mapping, $scope);

        // Execute immediately. Record extraction is not deferrable.
        $this->executeBatch();
        $result = $action->execute($this->httpClient);
        $this->log($action->getLog());
        $this->log('Selected ' . $recordLocator->recordType . ' ' . $result->getLoadedRecordKey()->recordId);

        return (new Response())->setRecordset($result->getExtractedRecordSet());
    }

    /**
     * @param \Connector\Record\RecordLocator  $recordLocator
     * @param \Connector\Mapping               $mapping
     * @param \Connector\Record\RecordKey|null $scope
     *
     * @return \Connector\Integrations\Response
     * @throws \Connector\Exceptions\AbortedOperationException
     * @throws \Connector\Exceptions\SkippedOperationException
     * @throws \Connector\Exceptions\InvalidSchemaException
     * @throws \Connector\Exceptions\InvalidExecutionPlan
     * @throws \Connector\Exceptions\RecordNotFound
     */
    public function load(RecordLocator $recordLocator, Mapping $mapping, ?RecordKey $scope): Response
    {
        $response = new Response();
        // Recast to our child class
        $recordLocator = new SalesforceRecordLocator($recordLocator, $this->getSchema());

        // Mapping may contain fully-qualified names (remove record type and keep only property name)
        $mapping = $this->normalizeMapping($mapping);

        if($recordLocator->isCreate()) {
            $action = new Actions\Create($recordLocator, $mapping, $scope);
        } elseif($recordLocator->isUpdate()) {
            $recordLocator = $this->lookupRecordsToUpdate($recordLocator, $scope);
            $action = new Actions\Update($recordLocator, $mapping, $scope);
        } elseif($recordLocator->isSelect()) {
            $action = new Actions\Select($recordLocator, new Mapping(['Id' => null]), $scope);
        } else {
            throw new InvalidExecutionPlan("Unknown operation type");
        }

        if ($action->isBatchable()) {
            $result = $action->batch(self::$batch);
        } else {
            try {
                $this->executeBatch();
                $result = $action->execute($this->httpClient);
            } catch (Exceptions\InvalidQueryException $e) {
                throw new InvalidExecutionPlan($e->getMessage());
            }
            $this->log($action->getLog());
        }

        $recordset   = new Recordset();
        $recordset[] = new Record($result->getLoadedRecordKey(),
                                  [
                                      'FormAssemblyConnectorResult:Id'  => $result->getLoadedRecordKey()->recordId,
                                      'FormAssemblyConnectorResult:Url' => $this->instanceUrl . "/" . $result->getLoadedRecordKey()->recordId,
                                  ]
        );

        return $response->setRecordKey($result->getLoadedRecordKey())->setRecordset($recordset);
    }

    /**
     * @param \Connector\Integrations\Salesforce\SalesforceRecordLocator $recordLocator
     * @param \Connector\Record\RecordKey|null                           $scope
     *
     * @return \Connector\Integrations\Salesforce\SalesforceRecordLocator
     * @throws \Connector\Exceptions\AbortedOperationException
     * @throws \Connector\Exceptions\SkippedOperationException
     * @throws \Connector\Exceptions\RecordNotFound
     * @throws \Connector\Integrations\Salesforce\Exceptions\InvalidQueryException
     */
    private function lookupRecordsToUpdate(SalesforceRecordLocator $recordLocator, ?RecordKey $scope): SalesforceRecordLocator
    {
        if (!$recordLocator->query->isEmpty() && !$recordLocator->recordId && !$recordLocator->upsertKey) {

            // If a query is defined, lookup the record and select its ID.
            $action = new Actions\Select($recordLocator, new Mapping(['Id' => null]), $scope);
            $result = $action->execute($this->httpClient);
            $this->log($action->getLog());

            switch ($result->getExtractedRecordSet()->count()) {
                case 0:
                    switch ($recordLocator->onNoResult) {
                        case NoResultOptions::Create:
                            throw new RecordNotFound();
                        case NoResultOptions::Skip:
                            throw new SkippedOperationException("No record found.");
                        case NoResultOptions::Abort:
                            throw new AbortedOperationException("No record found.");
                    }
                    break;
                default:
                    switch ($recordLocator->onManyResults) {
                        case ManyResultsOptions::SelectOne:
                            $recordLocator->recordId = $result->getExtractedRecordSet()->records[0]->data['Id'];
                            break;
                        case ManyResultsOptions::SelectAll:
                            $recordLocator->recordIds = array_map(function (Record $record) {
                                return $record->data['Id'];
                            }, $result->getExtractedRecordSet()->records);
                            break;
                        case ManyResultsOptions::Skip:
                            throw new SkippedOperationException("Many records found.");
                        case ManyResultsOptions::Abort:
                            throw new AbortedOperationException("Many records found.");
                        case ManyResultsOptions::Create:
                            throw new RecordNotFound();
                    }
                    break;
            }
        }

        return $recordLocator;
    }

    /**
     * Execute a batch of requests, compiled by previous calls to load().
     * Prepare a result record for each request, save them in self::deferredResults.
     *
     * @return void
     * @throws \Connector\Exceptions\AbortedOperationException
     */
    private function executeBatch(): void
    {
        $action  = new Actions\Batch(self::$batch, $this->instanceUrl);
        $results = $action->execute($this->httpClient);
        self::$batch = [];
        self::$deferredResults = array_merge(self::$deferredResults, $results);
    }


    public function getAuthorizationProvider(): AbstractProvider
    {
        return new GenericProvider(
            [
                'clientId'                => Config::CLIENT_ID,
                'clientSecret'            => Config::CLIENT_SECRET,
                'redirectUri'             => Config::REDIRECT_URI,
                'response_mode'           => 'query',
                'scopes'                  => Config::SCOPES,
                'state'                   => '',
                'urlAuthorize'            => Config::AUTH_URI,
                'urlAccessToken'          => Config::TOKEN_URI,
                'urlResourceOwnerDetails' => Config::USER_INFO_URI,
            ],
            [
                'httpClient' => $this->httpClient,
            ]
        );
    }

    public function getAuthorizedUserName(ResourceOwnerInterface $user): string
    {
        // TODO: Implement getAuthorizedUserName() method.
        return 'todo';
    }

    private function normalizeMapping(Mapping $mapping): Mapping
    {
        foreach($mapping as $item) {
            if($this->schema->isFullyQualifiedName($item->key)) {
                $item->key = $this->schema->getPropertyNameFromFQN($item->key);
            }
        }
        return $mapping;
    }
}
