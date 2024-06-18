<?php

namespace Tests;
use Connector\Exceptions\AbortedOperationException;
use Connector\Execution;
use Connector\Integrations\Database\GenericWhereClause;
use Connector\Integrations\Database\GenericWhereOperator;
use Connector\Integrations\Salesforce\Enumerations\ManyResultsOptions;
use Connector\Integrations\Salesforce\Enumerations\OperationTypes;
use Connector\Integrations\Salesforce\Integration;
use Connector\Mapping;
use Connector\Integrations\Fake;
use Connector\Record\RecordLocator;
use Connector\Schema\IntegrationSchema;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Tests\Helpers\LiveHttpClientTrait;
use Tests\Helpers\MockHttpClientTrait;
use Connector\Plan;

/**
 * @covers \Connector\Integrations\Salesforce\Integration
 * @covers \Connector\Integrations\Salesforce\Actions\Batch
 * @covers \Connector\Integrations\Salesforce\Actions\Select
 * @covers \Connector\Integrations\Salesforce\Actions\Create
 * @covers \Connector\Integrations\Salesforce\Actions\Update
 * @covers \Connector\Integrations\Salesforce\SalesforceRecordLocator
 * @covers \Connector\Integrations\Salesforce\SalesforceSOQLBuilder
 * @covers \Connector\Integrations\Salesforce\SalesforceSOQLWhereClause
 * @covers \Connector\Integrations\Salesforce\SalesforceSOQLWhereOperator
 * @covers \Connector\Integrations\Salesforce\SalesforceSchema
 */
final class IntegrationTest extends TestCase
{
    // Note that using the liveHttpClientTrait will cause some tests to fail as they
    // check hardcoded IDs of created records, which are only going to match mocked requests.
    // use liveHttpClientTrait;
    use mockHttpClientTrait;


    /**
     * Tests that the schema produced matches what the expected schema (as previously captured).
     * @throws \Throwable
     * @throws \Connector\Exceptions\InvalidExecutionPlan
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \League\OAuth2\Client\Provider\Exception\IdentityProviderException
     */
    function testDiscover() {

        $mocks = [
            new Response(200, [], file_get_contents(__DIR__ . "/mocks/http/testDiscover/0-POST_https-login-salesforce-com-services-oauth2-token_200-response.json")),
            new Response(200, [], file_get_contents(__DIR__ . "/mocks/http/testDiscover/1-GET_https-veerwest-dev-ed-my-salesforce-com-services-data-v60-0-sobjects_200-response.json")),
            new Response(200, [], file_get_contents(__DIR__ . "/mocks/http/testDiscover/2-POST_https-veerwest-dev-ed-my-salesforce-com-services-data-v60-0-composite-batch_200-response.json")),
            new Response(200, [], file_get_contents(__DIR__ . "/mocks/http/testDiscover/3-POST_https-veerwest-dev-ed-my-salesforce-com-services-data-v60-0-composite-batch_200-response.json")),
            new Response(200, [], file_get_contents(__DIR__ . "/mocks/http/testDiscover/4-POST_https-veerwest-dev-ed-my-salesforce-com-services-data-v60-0-composite-batch_200-response.json")),
            new Response(200, [], file_get_contents(__DIR__ . "/mocks/http/testDiscover/5-POST_https-veerwest-dev-ed-my-salesforce-com-services-data-v60-0-composite-batch_200-response.json")),
            new Response(200, [], file_get_contents(__DIR__ . "/mocks/http/testDiscover/6-POST_https-veerwest-dev-ed-my-salesforce-com-services-data-v60-0-composite-batch_200-response.json")),
            new Response(200, [], file_get_contents(__DIR__ . "/mocks/http/testDiscover/7-POST_https-veerwest-dev-ed-my-salesforce-com-services-data-v60-0-composite-batch_200-response.json")),
            new Response(200, [], file_get_contents(__DIR__ . "/mocks/http/testDiscover/8-POST_https-veerwest-dev-ed-my-salesforce-com-services-data-v60-0-composite-batch_200-response.json")),
            new Response(200, [], file_get_contents(__DIR__ . "/mocks/http/testDiscover/9-POST_https-veerwest-dev-ed-my-salesforce-com-services-data-v60-0-composite-batch_200-response.json")),
            new Response(200, [], file_get_contents(__DIR__ . "/mocks/http/testDiscover/10-POST_https-veerwest-dev-ed-my-salesforce-com-services-data-v60-0-composite-batch_200-response.json")),
            new Response(200, [], file_get_contents(__DIR__ . "/mocks/http/testDiscover/11-POST_https-veerwest-dev-ed-my-salesforce-com-services-data-v60-0-composite-batch_200-response.json")),
            new Response(200, [], file_get_contents(__DIR__ . "/mocks/http/testDiscover/12-POST_https-veerwest-dev-ed-my-salesforce-com-services-data-v60-0-composite-batch_200-response.json")),
            new Response(200, [], file_get_contents(__DIR__ . "/mocks/http/testDiscover/13-POST_https-veerwest-dev-ed-my-salesforce-com-services-data-v60-0-composite-batch_200-response.json")),
            new Response(200, [], file_get_contents(__DIR__ . "/mocks/http/testDiscover/14-POST_https-veerwest-dev-ed-my-salesforce-com-services-data-v60-0-composite-batch_200-response.json")),
            new Response(200, [], file_get_contents(__DIR__ . "/mocks/http/testDiscover/15-POST_https-veerwest-dev-ed-my-salesforce-com-services-data-v60-0-composite-batch_200-response.json")),
            new Response(200, [], file_get_contents(__DIR__ . "/mocks/http/testDiscover/16-POST_https-veerwest-dev-ed-my-salesforce-com-services-data-v60-0-composite-batch_200-response.json")),
            new Response(200, [], file_get_contents(__DIR__ . "/mocks/http/testDiscover/17-GET_https-veerwest-dev-ed-my-salesforce-com-services-data_200-response.json")),
        ];
        $integration = new Integration([],$this->getHttpClient($mocks));
        $integration->setAuthorization(json_encode(AUTHORIZATION));
        $jsonSchema = $integration->discover()->json;

        // Reformat to PRETTY_PRINT for easier comparison when test fails.
        $jsonSchema   = json_encode(json_decode($jsonSchema,true), JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT );
        // file_put_contents(__DIR__."/schemas/testDiscover_actual.json", $jsonSchema);

        // Uncomment the following lines to generate mock when using liveHttpClientTrait
        // $this->writeRequestMocks(__DIR__, "testDiscover");
        // file_put_contents(__DIR__."/schemas/testDiscover.json", $jsonSchema);

        // Note assertJsonString* or assertString* takes too long to produce a diff when the test fails, due to the size of the schema.
        $this->assertJson($jsonSchema);
        $this->assertTrue(file_get_contents(__DIR__ . "/schemas/testDiscover.json") === $jsonSchema, "Schema is different than what was expected.");
    }

    /**
     * Tests that 2 operations are composited in one API call and that the 2 records are created.
     *
     * @return void
     * @throws \Connector\Exceptions\AbortedExecutionException
     * @throws \Connector\Exceptions\EmptyRecordException
     * @throws \Connector\Exceptions\InvalidExecutionPlan
     * @throws \Connector\Exceptions\InvalidMappingException
     * @throws \Connector\Exceptions\InvalidSchemaException
     * @throws \League\OAuth2\Client\Provider\Exception\IdentityProviderException
     */
    function testCompositeRequests()
    {
        $source = new Fake\Integration();
        $source->createTable("companies", ["salesforce_id text", "name text", "salesforce_url text"]);
        $companyId = $source->insertRecord("companies", ["name"=>'Acme inc.']);
        $source->createTable("people", ["last_name text", "first_name text", "email text", "companies_id number", "salesforce_id text", "name text", "salesforce_url text"]);
        $source->insertRecord("people", ["last_name"=> 'Doe', "first_name" => 'John', "email" => 'j.doe@example.org', "companies_id" => $companyId]);
        $source->createTable("variables", ["recordId text","recordUrl text"]);

        // Mocks are used only with mockHttpClientTrait.
        // Use $this->writeRequestMocks() and liveHttpClientTrait to generate mocks from live requests.
        $mocks = [
            new Response(200, [], file_get_contents(__DIR__ . "/mocks/http/testCompositeRequests/0-POST_https-login-salesforce-com-services-oauth2-token_200-response.json")),
            new Response(200, [], file_get_contents(__DIR__ . "/mocks/http/testCompositeRequests/1-POST_https-veerwest-dev-ed-my-salesforce-com-services-data-v60-0-composite-graph_200-response.json")),
        ];
        $target = new Integration([],$this->getHttpClient($mocks));
        $target->setAuthorization(json_encode(AUTHORIZATION));
        $schema = json_decode(file_get_contents(__DIR__."/schemas/testDiscover.json"),true);
        $target->setSchema(new IntegrationSchema($schema));

        $plan = Plan\Builder::create()
            ->addOperation()->with()
                ->setRecordTypes('companies','Account')
                ->setTargetRecordTypeProperty('type', OperationTypes::Create->value)
                ->mapProperty('name', 'Name')
                ->mapResult('FormAssemblyConnectorResult:Id', 'salesforce_id')
                ->mapResult('FormAssemblyConnectorResult:Url', 'salesforce_url')
            ->then()
            ->addOperation()->after(1)->with()
                ->setRecordTypes('people','Contact')
                ->setTargetRecordTypeProperty('type', OperationTypes::Create->value)
                ->mapProperty('last_name','LastName')
                ->mapProperty('first_name','FirstName')
                ->mapProperty('email','Email')
                ->mapResultReference(1, 'salesforce_id', 'AccountId')
                ->mapResult('FormAssemblyConnectorResult:Id', 'salesforce_id')
                ->mapResult('FormAssemblyConnectorResult:Url', 'salesforce_url');

        $execution = new Execution($plan->toJSON(), $source, $target);
        $execution->run();

        // Uncomment the following line to regenerate mock requests when using liveHttpClientTrait
        // Note that account_id will change when mocks are recreated from a live request.
        // $this->writeRequestMocks(__DIR__, "testCompositeRequests");

        // Checks that the source has been updated with the Account ID created in Salesforce.
        // This means that the composite requests has successfully completed (Account and Contact created, no rollback)
        // and the deferred results have been resolved.
        $records = $source->selectAllRecords('companies');
        $this->assertCount(1, $records);

        $this->assertEquals('0013y00001fTibkAAC', $records[0]['salesforce_id']);
        $this->assertEquals('https://veerwest-dev-ed.my.salesforce.com/0013y00001fTibkAAC', $records[0]['salesforce_url']);

        // Same check with the Contact ID mapped back to the people record.
        $records = $source->selectAllRecords('people');
        $this->assertCount(1, $records);

        $this->assertEquals('0033y00002Vx1Q1AAJ', $records[0]['salesforce_id']);
        $this->assertEquals('https://veerwest-dev-ed.my.salesforce.com/0033y00002Vx1Q1AAJ', $records[0]['salesforce_url']);

        // Check log entries. (Execution logs are indexed by operation ID.)
        $execLogs = $execution->getLog();

        $this->assertEquals([
                                'Selected 1 companies record(s)',
                                'Updated companies record, ID: 1', // placeholder for deferred returned record
                                'Created Account https://veerwest-dev-ed.my.salesforce.com/0013y00001fTibkAAC',
                                'Updated companies record, ID: 1', // resolved deferred returned record
                            ], $execLogs[1]);

        $this->assertEquals([
                                'Selected 1 people record(s)',
                                'Updated people record, ID: 1', // placeholder for deferred returned record
                                'Created Contact https://veerwest-dev-ed.my.salesforce.com/0033y00002Vx1Q1AAJ',
                                'Updated people record, ID: 1', // resolved deferred returned record
                            ], $execLogs[2]);

        $this->assertCount(2, $execLogs, '2 operations should have added to the log');
    }

    /**
     * Test load() interface with a simple Create Lead operation.
     *
     * @return void
     * @throws \Connector\Exceptions\AbortedOperationException
     * @throws \Connector\Exceptions\InvalidExecutionPlan
     * @throws \Connector\Exceptions\InvalidSchemaException
     * @throws \Connector\Exceptions\SkippedOperationException
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \League\OAuth2\Client\Provider\Exception\IdentityProviderException
     * @throws \Connector\Exceptions\RecordNotFound
     */
    function testCreateRecord() {
        $mocks = [
            new Response(200, [], file_get_contents(__DIR__ . "/mocks/http/testCreateRecord/0-POST_https-login-salesforce-com-services-oauth2-token_200-response.json")),
            new Response(200, [], file_get_contents(__DIR__ . "/mocks/http/testCreateRecord/1-POST_https-veerwest-dev-ed-my-salesforce-com-services-data-v60-0-composite-graph_200-response.json")),
        ];
        $integration = new Integration([],$this->getHttpClient($mocks));
        $integration->setAuthorization(json_encode(AUTHORIZATION));
        $schema = json_decode(file_get_contents(__DIR__."/schemas/testDiscover.json"),true);
        $integration->setSchema(new IntegrationSchema($schema));
        $integration->begin();

        $recordLocator = new RecordLocator(["recordType" => 'Lead']);
        $mapping = new Mapping([
            'company'   => 'Acme inc',
            'lastName'  => 'Doe',
            'email'     => 'jdoe@example.org'
        ]);

        $response = $integration->load($recordLocator, $mapping, null);
        $this->assertEquals('@{fa0.id}', $response->getRecordKey()->recordId);

        // Uses batched requests, so requests are only sent at the end() of the transaction.
        $results = $integration->end();

        // Uncomment the following line to regenerate mock requests when using liveHttpClientTrait
        // $this->writeRequestMocks(__DIR__, "testCreateRecord");

        $this->assertEquals('00Q3y00001QraiXEAR', $results[0]->getLoadedRecordKey()->recordId);
        $this->assertEquals('Lead', $results[0]->getLoadedRecordKey()->recordType);

        $logs = $results[0]->getLog();
        $this->assertEquals(['Created Lead https://veerwest-dev-ed.my.salesforce.com/00Q3y00001QraiXEAR'], $logs);
    }

    /**
     * @return void
     * @throws \Connector\Exceptions\InvalidExecutionPlan
     * @throws \Connector\Exceptions\InvalidSchemaException
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \League\OAuth2\Client\Provider\Exception\IdentityProviderException
     * @throws \Connector\Exceptions\AbortedOperationException
     */
    function testLookupRecord() {
        $mocks = [
            new Response(200, [], file_get_contents(__DIR__ . "/mocks/http/testLookupRecord/0-POST_https-login-salesforce-com-services-oauth2-token_200-response.json")),
            new Response(200, [], file_get_contents(__DIR__ . "/mocks/http/testLookupRecord/1-GET_https-veerwest-dev-ed-my-salesforce-com-services-data-v60-0-query-_200-response.json")),
        ];
        $integration = new Integration([],$this->getHttpClient($mocks));
        $integration->setAuthorization(json_encode(AUTHORIZATION));
        $schema = json_decode(file_get_contents(__DIR__."/schemas/testDiscover.json"),true);
        $integration->setSchema(new IntegrationSchema($schema));
        $integration->begin();

        // Set uo query for our lookup operation
        $query = ['where' => (new GenericWhereClause("FirstName", GenericWhereOperator::LIKE, "B%" ))->toArray()];

        // Configure the operation query and mapping
        $recordLocator = new RecordLocator(["recordType" => 'Contact', "query" => $query]);
        $mapping = new Mapping(["Id" => null, "FirstName" => null]);

        // Extract the data from Salesforce
        $response = $integration->extract($recordLocator, $mapping, null);

        // Uncomment the following line to regenerate mock requests when using liveHttpClientTrait
        // $this->writeRequestMocks(__DIR__, "testLookupRecord");

        $this->assertEquals(1, $response->getRecordset()->count());
        $this->assertEquals("Contact", $response->getRecordset()[0]->recordType);
        $this->assertEquals("0033000000TqaEPAAZ", $response->getRecordset()[0]->getKey()->recordId);
        $this->assertEquals("Bob", $response->getRecordset()[0]->data['FirstName']);

        $log = $integration->getLog();
        $this->assertEquals(['Query found 1 Contact record', 'Selected Contact 0033000000TqaEPAAZ'], $log);
    }

    /**
     * @return void
     * @throws \Connector\Exceptions\AbortedOperationException
     * @throws \Connector\Exceptions\InvalidExecutionPlan
     * @throws \Connector\Exceptions\InvalidSchemaException
     * @throws \Connector\Exceptions\SkippedOperationException
     * @throws \League\OAuth2\Client\Provider\Exception\IdentityProviderException
     * @throws \Connector\Exceptions\RecordNotFound
     */
    function testUpdateRecord() {

        $mocks = [
            new Response(200, [], file_get_contents(__DIR__ . "/mocks/http/testUpdateRecord/0-POST_https-login-salesforce-com-services-oauth2-token_200-response.json")),
            new Response(200, [], file_get_contents(__DIR__ . "/mocks/http/testUpdateRecord/1-GET_https-veerwest-dev-ed-my-salesforce-com-services-data-v60-0-query-_200-response.json")),
            new Response(200, [], file_get_contents(__DIR__ . "/mocks/http/testUpdateRecord/2-PATCH_https-veerwest-dev-ed-my-salesforce-com-services-data-v60-0-sobjects-Lead-00Q3y00001QraiIEAR_204-response.json")),
        ];
        $integration = new Integration([],$this->getHttpClient($mocks));
        $integration->setAuthorization(json_encode(AUTHORIZATION));
        $schema = json_decode(file_get_contents(__DIR__."/schemas/testDiscover.json"),true);
        $integration->setSchema(new IntegrationSchema($schema));
        $integration->begin();

        $query = ['where' => (new GenericWhereClause("Email", GenericWhereOperator::EQ, "jdoe@example.org" ))->toArray()];

        $recordLocator = new RecordLocator(["recordType" => 'Lead', "query" => $query, "type" => OperationTypes::Update->value]);
        $mapping = new Mapping(['company'   => 'Test']);

        $response = $integration->load($recordLocator, $mapping, null);

        // Uncomment the following line to regenerate mock requests when using liveHttpClientTrait
        // $this->writeRequestMocks(__DIR__, "testUpdateRecord");

        $this->assertEquals('00Q3y00001QraiIEAR', $response->getRecordKey()->recordId);
        $this->assertEquals('Lead', $response->getRecordKey()->recordType);

        $log = $integration->getLog();
        $this->assertEquals(['Query found 1 Lead record','Updated Lead 00Q3y00001QraiIEAR'], $log);
    }

    /**
     * @return void
     * @throws \Connector\Exceptions\AbortedOperationException
     * @throws \Connector\Exceptions\InvalidExecutionPlan
     * @throws \Connector\Exceptions\InvalidSchemaException
     * @throws \Connector\Exceptions\RecordNotFound
     * @throws \Connector\Exceptions\SkippedOperationException
     * @throws \League\OAuth2\Client\Provider\Exception\IdentityProviderException
     */
    function testUpdateOneOfManyRecords() {

        $mocks = [
            new Response(200, [], file_get_contents(__DIR__ . "/mocks/http/testUpdateOneOfManyRecords/0-POST_https-veerwest-dev-ed-my-salesforce-com-services-oauth2-token_200-response.json")),
            new Response(200, [], file_get_contents(__DIR__ . "/mocks/http/testUpdateOneOfManyRecords/1-GET_https-veerwest-dev-ed-my-salesforce-com-services-data-v60-0-query-_200-response.json")),
            new Response(200, [], file_get_contents(__DIR__ . "/mocks/http/testUpdateOneOfManyRecords/2-PATCH_https-veerwest-dev-ed-my-salesforce-com-services-data-v60-0-sobjects-Lead-00Q0M00001IG7qHUAT_204-response.json")),
        ];
        $integration = new Integration([],$this->getHttpClient($mocks));
        $integration->setAuthorization(json_encode(AUTHORIZATION));
        $schema = json_decode(file_get_contents(__DIR__."/schemas/testDiscover.json"),true);
        $integration->setSchema(new IntegrationSchema($schema));
        $integration->begin();

        $query = ['where' => (new GenericWhereClause("Email", GenericWhereOperator::LIKE, "a%" ))->toArray()];

        $recordLocator = new RecordLocator(["recordType" => 'Lead', "query" => $query, 'onManyResults' => ManyResultsOptions::SelectOne->value, "type" => OperationTypes::Update->value]);
        $mapping = new Mapping(['company'   => 'Test']);

        $response = $integration->load($recordLocator, $mapping, null);

        // Uncomment the following line to regenerate mock requests when using liveHttpClientTrait
        // $this->writeRequestMocks(__DIR__, "testUpdateOneOfManyRecords");

        $this->assertEquals('00Q0M00001IG7qHUAT', $response->getRecordKey()->recordId);
        $this->assertEquals('Lead', $response->getRecordKey()->recordType);

        $log = $integration->getLog();
        $this->assertEquals(['Query found 1 Lead record','Updated Lead 00Q0M00001IG7qHUAT'], $log, "Query should have a limit of 1 and select only 1 record.");
    }

    /**
     * @return void
     * @throws \Connector\Exceptions\AbortedOperationException
     * @throws \Connector\Exceptions\InvalidExecutionPlan
     * @throws \Connector\Exceptions\InvalidSchemaException
     * @throws \Connector\Exceptions\RecordNotFound
     * @throws \Connector\Exceptions\SkippedOperationException
     * @throws \League\OAuth2\Client\Provider\Exception\IdentityProviderException
     */
    function testUpdateManyRecords() {

        $mocks = [
            new Response(200, [], file_get_contents(__DIR__ . "/mocks/http/testUpdateManyRecords/0-POST_https-login-salesforce-com-services-oauth2-token_200-response.json")),
            new Response(200, [], file_get_contents(__DIR__ . "/mocks/http/testUpdateManyRecords/1-GET_https-veerwest-dev-ed-my-salesforce-com-services-data-v60-0-query-_200-response.json")),
            new Response(200, [], file_get_contents(__DIR__ . "/mocks/http/testUpdateManyRecords/2-PATCH_https-veerwest-dev-ed-my-salesforce-com-services-data-v60-0-composite-sobjects_200-response.json")),
            new Response(200, [], file_get_contents(__DIR__ . "/mocks/http/testUpdateManyRecords/3-PATCH_https-veerwest-dev-ed-my-salesforce-com-services-data-v60-0-composite-sobjects_200-response.json")),
            new Response(200, [], file_get_contents(__DIR__ . "/mocks/http/testUpdateManyRecords/4-PATCH_https-veerwest-dev-ed-my-salesforce-com-services-data-v60-0-composite-sobjects_200-response.json")),
        ];
        $integration = new Integration([],$this->getHttpClient($mocks));
        $integration->setAuthorization(json_encode(AUTHORIZATION));
        $schema = json_decode(file_get_contents(__DIR__."/schemas/testDiscover.json"),true);
        $integration->setSchema(new IntegrationSchema($schema));
        $integration->begin();

        $query = ['where' => (new GenericWhereClause("Email", GenericWhereOperator::LIKE, "an%" ))->toArray()];

        $recordLocator = new RecordLocator(["recordType" => 'Lead', "query" => $query, 'onManyResults' => ManyResultsOptions::SelectAll->value, 'type' => OperationTypes::Update->value]);
        $mapping = new Mapping(['Status'   => 'Working - Contacted']);

        $response = $integration->load($recordLocator, $mapping, null);

        // Uncomment the following line to regenerate mock requests when using liveHttpClientTrait
        // $this->writeRequestMocks(__DIR__, "testUpdateManyRecords");

        // Even if multiple records are updated, the integration must return only one record.
        $this->assertEquals(1, $response->getRecordset()->count());

        $log = $integration->getLog();
        $this->assertEquals(['Query found 600 Lead records','Updated Lead 00Q0M00001LxFLkUAN and 599 other records'], $log);
    }

    function testMissingRequiredField() {
        $mocks = [
            new Response(200, [], file_get_contents(__DIR__ . "/mocks/http/".__FUNCTION__."/0-POST_https-login-salesforce-com-services-oauth2-token_200-response.json")),
            new Response(200, [], file_get_contents(__DIR__ . "/mocks/http/".__FUNCTION__."/1-POST_https-veerwest-dev-ed-my-salesforce-com-services-data-v60-0-composite-graph_200-response.json")),
        ];
        $integration = new Integration([],$this->getHttpClient($mocks));
        $integration->setAuthorization(json_encode(AUTHORIZATION));
        $schema = json_decode(file_get_contents(__DIR__."/schemas/testDiscover.json"),true);
        $integration->setSchema(new IntegrationSchema($schema));
        $integration->begin();

        $recordLocator = new RecordLocator(["recordType" => 'Lead']);
        $mapping = new Mapping([
                                   'lastName'  => 'Doe',
                                   'email'     => 'jdoe@example.org'
                               ]);

        $response = $integration->load($recordLocator, $mapping, null);
        $results = $integration->end();

        // Uncomment the following line to regenerate mock requests when using liveHttpClientTrait
        // $this->writeRequestMocks(__DIR__, __FUNCTION__);

        $logs = $results[0]->getLog();
        $this->assertEquals(['Create Lead failed','Required fields are missing: [Company] (REQUIRED_FIELD_MISSING)'], $logs);

    }
}
