<?php

namespace Connector\Integrations\Salesforce;

use Connector\Schema\Builder;
use Connector\Schema\Builder\RecordType;
use Connector\Schema\IntegrationSchema;
use Connector\Type\JsonSchemaFormats;
use Connector\Type\JsonSchemaTypes;
use GuzzleHttp\Client;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Promise\Utils;
use GuzzleHttp\Psr7\Request;

class SalesforceSchema extends IntegrationSchema
{

    /**
     * @var \GuzzleHttp\Client
     */
    private Client $client;

    /**
     * @var bool|mixed  Prevents use of concurrency when making API calls. Only needed for unit tests in order to
     *                  request responses in a predictable order.
     */
    private bool $disableConcurrentRequests =  false;

    /**
     * @param \GuzzleHttp\Client $httpClient
     *
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \Throwable
     */
    public function __construct(Client $httpClient)
    {
        $this->client = $httpClient;

        //  Concurrency should only be disabled for unit tests (to ensure predictable order of execution).
        $this->disableConcurrentRequests = $this->client->getConfig()['config']['no_concurrent_requests'] ?? false;

        // Initialize the schema builder
        $builder = new Builder("http://formassembly.com/integrations/salesforce", "Salesforce");

        // Retrieve the list of all Salesforce objects (called sObjects)
        $recordTypes = $this->getSalesforceSObjects();

        // For each object, get its properties
        foreach ($this->describe($recordTypes) as $i => $properties) {
            foreach($properties as $property) {
                $recordTypes[$i]->addProperty($property);
            }
            // Set a tag to identify this Record Type as configurable in the Salesforce Connector UI.
            $recordTypes[$i]->setTags(['sobject']);
            $builder->addRecordType($recordTypes[$i]);
        }

        // Add a Record Type to manage a list of valid API versions. Salesforce release new versions multiple times a
        // year, and which version is available may depend on a Salesforce instance by instance basis.
        $recordType = new RecordType("SalesforceApiVersion");
        $recordType->title = "Salesforce API Versions";
        $recordType->setDescription("List of versions supported by this Salesforce account.");
        $recordType->setTags(['api']);
        $recordType->addProperty('version', [
            "name"   => 'version',
            "title"  => 'Version',
            "type"   => JsonSchemaTypes::String,
            "format" => "",
            'oneOf'  => $this->getAPIVersions()
        ]);
        $builder->addRecordType($recordType);

        // Add a Result Record to the schema. This is used to retrieve information about successful create/update operations.
        // The name of the Record Type is arbitrary, but it must be unique enough to not collide with other objects in the schema.
        $recordType = new RecordType( "FormAssemblyConnectorResult");
        $recordType->title = "Connector Operation Result";
        $recordType->setDescription("Available data after a record is selected, created, or updated.");
        // Make this Record Type available in UI for mapping results by setting the 'result' tag.
        $recordType->setTags(['result']);
        // For created or updated records, we're interested in getting back the ID of the record and its URL.
        $recordType->addProperty("Id", ["title" => "ID" , "type"=> JsonSchemaTypes::String, "description" => "Record ID"]);
        $recordType->addProperty("Url", ["title" => "URL" , "type"=> JsonSchemaTypes::String, "format" => JsonSchemaFormats::Uri, "description" => "Record URL"]);
        $builder->addRecordType($recordType);

        parent::__construct($builder->toArray());
    }

    /**
     * Iterator that returns the fields defined for each sObject in $recordTypes.
     * Salesforce API requests are batched and parallelized to improve performance.
     * @param array $recordTypes
     *
     * @return Builder\RecordProperty[]
     * @throws \Throwable
     */
    private function describe(array $recordTypes): Iterable
    {
        $i = 0;
        $promises  = [];
        $responses = [];
        foreach ($this->batchDescribe($recordTypes) as $batch) {
            if(count($batch) > 0) {

                $promise = $this->batchRequests($batch);

                if($this->disableConcurrentRequests) {
                    // Concurrent HTTP requests are disabled, so we resolve the promise right away.
                    $responses[] = $promise->wait();
                } else {
                    // Concurrency is allowed, so we can move to the next batch without waiting for the promise to resolve.
                    $promises[] = $promise;
                }
            }
        }

        // Resolve all outstanding promises.
        if(count($promises)>0) {
            $responses = array_merge($responses, Utils::unwrap($promises));
        }

        // Parse responses from all batched requests.
        foreach ($responses as $response) {
            $results = json_decode($response->getBody())->results;
            foreach($results as $result) {
                yield $i++ => $this->getSalesforceSObjectFields($result->result);
            }
        }
    }

    /**
     * Iterator that returns batches of 25 Describe API requests.
     * 25 is the max supported by Salesforce composite/batch API.
     *
     * TODO: Consider using If-Modified-Since header
     *       https://developer.salesforce.com/docs/atlas.en-us.api_rest.meta/api_rest/intro_rest_conditional_requests.htm
     * @param array $recordTypes
     *
     * @return iterable
     */
    private function batchDescribe(array $recordTypes): Iterable
    {
        $batchSize = 25;
        $batchRequests = [];
        $basePath = parse_url($this->client->getConfig('base_uri'), PHP_URL_PATH);
        foreach ($recordTypes as $recordType) {
            if(count($batchRequests) < $batchSize) {
                // Note: Request is not actually executed, it's replaced by a composite request in batchRequests()
                $batchRequests[] = new Request('GET',  $basePath . "sobjects/$recordType->name/describe");
            }
            if(count($batchRequests) === $batchSize) {
                yield $batchRequests;
                $batchRequests = [];
            }
        }
        yield $batchRequests;
    }

    /**
     * Takes a number of HTTP requests (up to 25) and wraps them as a single composite/batch request.
     * The composite request is returned as a promise, so that all batches can be executed in parallel.
     * See: https://developer.salesforce.com/docs/atlas.en-us.api_rest.meta/api_rest/resources_composite_batch.htm
     * @param \GuzzleHttp\Psr7\Request[] $requests
     *
     * @return \GuzzleHttp\Promise\Promise
     */
    private function batchRequests(array $requests): PromiseInterface
    {
        $batch = array_map(function(Request $request) {
            return [
                "method" => $request->getMethod(),
                "url"    => (string) $request->getUri()
            ];
        }, $requests);
        return $this->client->postAsync("composite/batch", [
            'json' => [ "batchRequests" => $batch
        ]]);
    }

    /**
     * Queries Salesforce for the list of sObjects available on the instance and build a new RecordType for each one.
     * Some sObjects that we don't intend to interact with are filtered out.
     * See: https://developer.salesforce.com/docs/atlas.en-us.api_rest.meta/api_rest/resources_describeGlobal.htm
     * @return Builder\RecordType[]
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    private function getSalesforceSObjects(): array
    {
        $response = $this->client->get("sobjects");
        $json = json_decode($response->getBody());
        return array_map(function($sObject) {
            $recordType = new Builder\RecordType($sObject->name);
            $recordType->setTitle($sObject->label);
            return $recordType;
        }, array_values(array_filter($json->sobjects, function(\stdClass $sObject): bool {
            return
                !str_ends_with($sObject->name,"__Share") &&
                !str_ends_with($sObject->name,"__Tag") &&
                ($sObject->createable || $sObject->updateable || ($sObject->queryable && isset($sObject->retrievable) && $sObject->retrievable));
        })));
    }

    /**
     * Map the result of a sObject Describe call to a list of Record Properties
     * https://developer.salesforce.com/docs/atlas.en-us.api_rest.meta/api_rest/resources_sobject_describe.htm
     * @param $json
     *
     * @return Builder\RecordProperty[]
     */
    private function getSalesforceSObjectFields($json): array
    {
        return array_map(function($sField) {
            $attributes = [
                "name"  => $sField->name,
                "title" => $sField->label,
                "type"  => $this->getDataTypeFromSField($sField),
                "format"=> $this->getFormatFromSField($sField)
            ];

            if($sField->length && is_int($sField->length)) {
                $attributes['maxLength'] = $sField->length;
            }
            if(!$sField->createable && !$sField->updateable) {
                $attributes['readOnly'] = 1;
            }
            if($sField->type === 'id') {
                $attributes['pk'] = 1;
            }
            if($sField->type === 'reference') {
                $attributes['fk'] = $sField->referenceTo;
            }
            if($sField->type=== 'picklist') {
                $attributes['oneOf'] = array_map(function($choice) {
                    return [
                      'const' => $choice->value,
                      'title' => $choice->label
                    ];
                }, array_filter($sField->picklistValues, function($choice) {
                    return $choice->active;
                }));
            }
            if($sField->type=== 'multipicklist') {
                $attributes['anyOf'] = array_map(function($choice) {
                    return [
                        'const' => $choice->value,
                        'title' => $choice->label
                    ];
                }, array_filter($sField->picklistValues, function($choice) {
                    return $choice->active;
                }));
            }
            return new Builder\RecordProperty($sField->name,$attributes);
        }, array_values(array_filter($json->fields, function($sField) {
            return ($sField->createable || $sField->updateable || $sField->idLookup);
        })));
    }

    private function getDataTypeFromSField(\stdClass $sField): JsonSchemaTypes
    {
        return match ($sField->soapType) {
            'xsd:double', 'xsd:float', 'xsd:decimal' => JsonSchemaTypes::Number,
            'xsd:int'     => JsonSchemaTypes::Integer,
            'xsd:boolean' => JsonSchemaTypes::Boolean,
            // 'xsd:string', 'xsd:datetime', 'xsd:date', 'xsd:time', 'tns:ID' default to 'string'.
            default       => JsonSchemaTypes::String,
        };

    }

    private function getFormatFromSField(\stdClass $sField): JsonSchemaFormats
    {
        $format = JsonSchemaFormats::None;
        switch($sField->soapType) {
            case 'xsd:date':
                $format = JsonSchemaFormats::Date;
                break;
            case 'xsd:dateTime':
                $format = JsonSchemaFormats::DateTime;
                break;
            case 'xsd:time':
                $format = JsonSchemaFormats::Time;
                break;
            case 'xsd:base64Binary':
                $format = JsonSchemaFormats::Base64Binary;
        }
        return $format;
    }

    /**
     * https://developer.salesforce.com/docs/atlas.en-us.api_rest.meta/api_rest/resources_versions.htm
     * @return mixed
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function getAPIVersions() : array {

        $response = $this->client->get("/services/data");
        $json = json_decode($response->getBody());
        return array_map(function($version) {
            return [ 'const' => $version->version, 'title' => $version->label . ' (v'. $version->version.')'];
        }, $json);
    }
}
