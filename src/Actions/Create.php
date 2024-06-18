<?php

namespace Connector\Integrations\Salesforce\Actions;

use Connector\Exceptions\AbortedOperationException;
use Connector\Integrations\Salesforce\Config;
use Connector\Integrations\Salesforce\SalesforceRecordLocator;
use Connector\Mapping;
use Connector\Operation\Result;
use Connector\Record\RecordKey;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

class Create implements BatchableActionInterface
{
    private array $log = [];

    /**
     * @var \Connector\Integrations\Salesforce\SalesforceRecordLocator
     */
    private SalesforceRecordLocator $recordLocator;
    /**
     * @var \Connector\Mapping
     */
    private Mapping $mapping;
    /**
     * @var \Connector\Record\RecordKey|null
     */
    private ?RecordKey $scope;

    public function __construct(SalesforceRecordLocator $recordLocator, Mapping $mapping, ?RecordKey $scope)
    {
        $this->recordLocator = $recordLocator;
        $this->mapping = $mapping;
        $this->scope = $scope;
    }

    public function isBatchable(): bool
    {
        return true;
    }

    /**
     * @throws \Connector\Exceptions\AbortedOperationException
     */
    public function execute(Client $httpClient): Result
    {
        try {
            $response = $httpClient->post('sobjects/' . $this->recordLocator->recordType, [
                "contentType" => "application/json",
                "json"        => $this->mappingAsArray(),
                "headers"     => $this->getHeaders()
            ]);

            $response = json_decode($response->getBody());
        } catch (GuzzleException $exception) {
            throw new AbortedOperationException($exception->getMessage());
        }

        // Return the ID of the created record.
        return (new Result())->setLoadedRecordKey(new RecordKey($response->id, $this->recordLocator->recordType));
    }

    public function batch(array & $batch): Result
    {
        // We'll be using Salesforce's Composite Graph API
        // https://developer.salesforce.com/docs/atlas.en-us.api_rest.meta/api_rest/resources_composite_graph.htm
        // If index is set in the record locator, this means we're looping through a record set, and each
        // iteration is independent. This is processed as separate graphs, all to be executed in a single query,
        // with rollback support.

        if(isset($this->recordLocator->index)) {
            // TODO: consider nested repeats and whether index would be unique.
            $graphId = $this->recordLocator->index;
        } else {
            $graphId = 0;
        }

        if(!isset($batch[$graphId])) {
            $batch[$graphId] = [];
        }

        $deferredId = "fa" . count($batch[$graphId]);

        $batch[$graphId][] = [
            'method'      => "POST",
            'url'         => "/services/data/v" . Config::API_VERSION . "/sobjects/" . $this->recordLocator->recordType,
            'referenceId' => $deferredId,
            'body'        => $this->mappingAsArray(),
            'sObject'     => $this->recordLocator->recordType,
            "description" => 'Create ' . $this->recordLocator->recordType
        ];

        return (new Result())->setLoadedRecordKey(new RecordKey("@{".$deferredId.".id}", $this->recordLocator->recordType));
    }

    private function mappingAsArray(): array
    {
        $map = [];
        foreach($this->mapping as $item) {
            $map[$item->key] = $item->value;
        }
        return $map;
    }

    private function getHeaders(): array
    {
        $headers = [];

        // Assignment Rule Header - defaults to true.
        // https://developer.salesforce.com/docs/atlas.en-us.api_rest.meta/api_rest/headers_autoassign.htm
        if(!$this->recordLocator->autoAssign) {
            $headers['Sforce-Auto-Assign'] = 'false';
        }

        // Duplicate Rule Header - defaults to false.
        // https://developer.salesforce.com/docs/atlas.en-us.api_rest.meta/api_rest/headers_duplicaterules.htm
        if($this->recordLocator->autoAcknowledgeDuplicates) {
            $headers['Sforce-Duplicate-Rule-Header'] = 'allowSave=true';
        }

        // Most Recently Used Header - defaults to true. Recommended value is false.
        // https://developer.salesforce.com/docs/atlas.en-us.api_rest.meta/api_rest/headers_mru.htm
        $headers['Sforce-Mru'] = 'updateMru=false';

        return $headers;
    }

    public function getLog(): array
    {
        return $this->log;
    }

}
