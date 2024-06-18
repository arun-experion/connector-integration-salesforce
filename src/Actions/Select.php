<?php

namespace Connector\Integrations\Salesforce\Actions;

use Connector\Exceptions\AbortedOperationException;
use Connector\Exceptions\NotImplemented;
use Connector\Integrations\Salesforce\SalesforceRecordLocator;
use Connector\Integrations\Salesforce\SalesforceSOQLBuilder;
use Connector\Mapping;
use Connector\Operation\Result;
use Connector\Record;
use Connector\Record\RecordKey;
use Connector\Record\Recordset;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

class Select implements BatchableActionInterface
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
        // TODO: Could return true if only one result is expected. (E.g. use unique key, or most recent setting)
        //       If only one result, the query can be included in the Composite Graph and executed later.

        return false;
    }

    /**
     * @param \GuzzleHttp\Client $httpClient
     *
     * @return \Connector\Operation\Result
     * @throws \Connector\Exceptions\AbortedOperationException
     * @throws \Connector\Integrations\Salesforce\Exceptions\InvalidQueryException
     */
    public function execute(Client $httpClient): Result
    {
        $result = new Result();
        $recordset = new Recordset();

        // $mapping contains a list of Mapping\Item, where the Item key is the Salesforce field name.
        $selectFields = array_map(function(Mapping\Item $item) { return $item->key; }, $this->mapping->items);

        $soqlQuery = SalesforceSOQLBuilder::toSoql($selectFields,
                                                   $this->recordLocator->recordType,
                                                   $this->recordLocator->query,
                                                   $this->recordLocator->orderByClause,
                                                   $this->recordLocator->onManyResults,
                                                   $this->scope);
        if($soqlQuery) {

            try {
                $response = $httpClient->get('query/?q=' . urlencode($soqlQuery), [
                    "contentType" => "application/json",
                ]);
                $response = json_decode($response->getBody());
            } catch (GuzzleException $exception) {
                throw new AbortedOperationException($exception->getMessage());
            }
            $more     = !$response->done;
            $next     = basename($response->nextRecordsUrl ?? '');

            foreach($response->records as $record) {
                $key  = new RecordKey($record->Id, $record->attributes->type);
                $attr = (array) $record;
                unset($attr['attributes']);
                $recordset[] = new Record($key, $attr);
            }

            // https://developer.salesforce.com/docs/atlas.en-us.api_rest.meta/api_rest/resources_query_more_results.htm
            while($more) {
                try {
                    $response = $httpClient->get('query/' . $next, [
                        "contentType" => "application/json",
                    ]);

                    $response = json_decode($response->getBody());
                } catch (GuzzleException $exception) {
                    throw new AbortedOperationException($exception->getMessage());
                }
                $more     = !$response->done;
                $next     = basename($response->nextRecordsUrl ?? '');

                foreach($response->records as $record) {
                    $key  = new RecordKey($record->Id, $record->attributes->type);
                    $attr = (array) $record;
                    unset($attr['attributes']);
                    $recordset[] = new Record($key, $attr);
                }
            }
            $this->log[] = 'Query found ' . count($recordset) . ' ' . $this->recordLocator->recordType . ' record' . (count($recordset)>1?'s':'');
        }
        return $result
            ->setExtractedRecordSet($recordset)
            ->setLoadedRecordKey($recordset[0]->getKey() ?? null);
    }

    public function batch(array & $batch): Result
    {
        throw new NotImplemented('Batch Select is not supported');
    }

    public function getLog(): array
    {
        return $this->log;
    }
}
