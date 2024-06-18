<?php

namespace Connector\Integrations\Salesforce\Actions;

use Connector\Exceptions\AbortedOperationException;
use Connector\Integrations\Salesforce\Exceptions\InvalidQueryException;
use Connector\Integrations\Salesforce\SalesforceRecordLocator;
use Connector\Mapping;
use Connector\Record\RecordKey;
use Connector\Operation\Result;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

class Update implements BatchableActionInterface
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
        // Could be set to true when using upsert keys.
        return false;
    }

    /**
     * @param \GuzzleHttp\Client $httpClient
     *
     * @return \Connector\Operation\Result;
     * @throws \Connector\Exceptions\AbortedOperationException
     * @throws \Connector\Integrations\Salesforce\Exceptions\InvalidQueryException
     */
    public function execute(Client $httpClient): Result
    {
        $result = new Result();
        $recordCount = count($this->recordLocator->recordIds);

        if($recordCount > 0) {
            // Multiple records to update, 200 max at a time.
            // https://developer.salesforce.com/docs/atlas.en-us.api_rest.meta/api_rest/resources_composite_sobjects_collections_update.htm
            $chunks  = array_chunk($this->recordLocator->recordIds, 200);
            foreach($chunks as $chunk) {
                try {
                    $response = $httpClient->patch('composite/sobjects', [
                        "contentType" => "application/json",
                        "json"        => [
                            "allOrNone" => true,
                            'records'   => array_map(function (string $id) {
                                return array_merge(
                                    $this->mappingAsArray(),
                                    [
                                        'attributes' => ['type' => $this->recordLocator->recordType],
                                        "id"         => $id
                                    ]
                                );
                            }, $chunk)
                        ]
                    ]);
                }  catch (GuzzleException $exception) {
                    throw new AbortedOperationException($exception->getMessage());
                }
            }
            $key = $this->recordLocator->recordIds[0];
            $msg = 'Updated ' . $this->recordLocator->recordType . ' ' . $key;
            if($recordCount>1) {
                $msg .= " and " . ($recordCount - 1) . ' other record' . ($recordCount > 2 ? 's' : '');
            }
            $this->log[] =$msg;

        } elseif($this->recordLocator->recordId) {
            // A single record to update
            try {
                $response = $httpClient->patch(
                    'sobjects/' . $this->recordLocator->recordType . "/" . $this->recordLocator->recordId,
                    [
                        "contentType" => "application/json",
                        "json"        => $this->mappingAsArray(),
                        "headers"     => $this->getHeaders(),
                    ]
                );
            } catch (GuzzleException $exception) {
                throw new AbortedOperationException($exception->getMessage());
            }
            $key = $this->recordLocator->recordId;
            $this->log[] ='Updated ' . $this->recordLocator->recordType . ' ' . $key;

        } elseif($this->recordLocator->upsertKey) {
            // TODO TEST
            // A single record to update
            try {
                $response = $httpClient->patch(
                    'sobjects/' . $this->recordLocator->recordType . "/" . $this->recordLocator->upsertKey->name . "/"
                    . $this->recordLocator->upsertKey->value,
                    [
                        "contentType" => "application/json",
                        "json"        => $this->mappingAsArray(),
                        "headers"     => $this->getHeaders(),
                    ]
                );
                $response = json_decode($response->getBody(), true);
            } catch (GuzzleException $exception) {
                throw new AbortedOperationException($exception->getMessage());
            }
            $key = $response['id'];
            $this->log[] = 'Updated ' . $this->recordLocator->recordType . ' ' . $key;
        } else {
            throw new InvalidQueryException("Missing data to update record");
        }

        // TODO: Error handling
        // Expecting an empty response on a patch (204 status).
        // https://developer.salesforce.com/docs/atlas.en-us.api_rest.meta/api_rest/dome_update_fields.htm?q=patch
        // $response->getStatusCode()

        // Return the ID of the updated record. If multiple records were updated, only the first id is returned.
        return $result->setLoadedRecordKey(new RecordKey($key, $this->recordLocator->recordType));
    }

    /**
     * @param array $batch
     *
     * @return \Connector\Operation\Result
     */
    public function batch(array & $batch): Result
    {
        return (new Result())->setLoadedRecordKey(new RecordKey("TBD - deferred", $this->recordLocator->recordType));
    }

    private function mappingAsArray(): array
    {
        $map = [];
        foreach($this->mapping as $item) {
            if($this->recordLocator->noBlankFieldsOnUpdate) {
                if(trim($item->value) === '' || $item->value === null) {
                    continue;
                }
            }
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
