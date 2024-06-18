<?php

namespace Connector\Integrations\Salesforce\Actions;

use Connector\Exceptions\AbortedOperationException;
use Connector\Operation\Result;
use Connector\Record\DeferredRecord;
use Connector\Record\RecordKey;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

class Batch implements ActionInterface
{
    private array $log = [];
    private array $batch;
    private string $instanceUrl;

    public function __construct(array $batch, string $instanceUrl)
    {
        $this->batch = $batch;
        $this->instanceUrl = $instanceUrl;
    }

    /**
     * Execute batched requests in a single composite request.
     * @param \GuzzleHttp\Client $httpClient
     *
     * @return array|\Connector\Operation\Result[]
     * @throws \Connector\Exceptions\AbortedOperationException
     */
    public function execute(Client $httpClient): array
    {
        $results  = [];

        if(count($this->batch)===0) {
            return $results;
        }

        $body = [ 'graphs' => [] ];

        foreach($this->batch as $graphId => $graph) {
            $body['graphs'][] = [
                'graphId' => (string) $graphId,
                'compositeRequest' => array_map(function($request) {
                    return [
                        "url"         => $request['url'],
                        "method"      => $request['method'],
                        "body"        => $request['body'],
                        "referenceId" => $request['referenceId'],
                    ];}, $graph)
            ];
        }

        try {
            $response = $httpClient->post('composite/graph', [
                "contentType" => "application/json",
                "json"        => $body
            ]);
            $response = json_decode($response->getBody(), true);
        }
        catch (GuzzleException $exception) {
            throw new AbortedOperationException($exception->getMessage());
        }

        if(isset($response['graphs'])) {

            foreach ($response['graphs'] as $graphId => $graph) {
                // Sub-request result
                // https://developer.salesforce.com/docs/atlas.en-us.api_rest.meta/api_rest/resources_composite_subrequest_result.htm
                foreach ($graph['graphResponse']['compositeResponse'] as $requestNumber => $response) {

                    $result     = new Result();

                    // Response refers to this request
                    $request           = $this->getRequestByReferenceId($response['referenceId']);

                    // And resolves this record
                    $resolvedRecordType = $request['sObject'];
                    $resolvedRecordId   = "@{" . $response['referenceId'] . ".id}";
                    $resolvedRecordKey  = new RecordKey($resolvedRecordId, $resolvedRecordType);

                    // with this deferred record
                    if (in_array($response['httpStatusCode'], [200, 201])) {

                        $deferredRecordKey = new RecordKey($response['body']['id'], $resolvedRecordType);
                        $deferredRecordId  = $response['body']['id'];
                        $deferredRecordUrl = $this->instanceUrl . "/" . $deferredRecordId;

                        $deferredRecord    = new DeferredRecord($deferredRecordKey, [
                            'FormAssemblyConnectorResult:Id'  => $deferredRecordId,
                            'FormAssemblyConnectorResult:Url' => $deferredRecordUrl
                        ]);
                        $deferredRecord->resolves = $resolvedRecordKey;

                        $result->setReturnedRecord($deferredRecord);
                        $result->setLoadedRecordKey($deferredRecordKey);

                        if($request['method']==='POST') {
                            $result->log("Created " . $resolvedRecordType . " ". $deferredRecordUrl);
                        } else {
                            $result->log($request['description'] . " successful." . $deferredRecordUrl);
                        }

                    } else {
                        // Flag the result as failed
                        $result->isSuccessful = false;
                        $result->log($request['description'] . " failed");

                        // Create an empty deferred record, so that the result can be associated back to the unresolved record.
                        $deferredRecordKey = new RecordKey(null, $resolvedRecordType);
                        $deferredRecord    = new DeferredRecord($deferredRecordKey, [
                            'FormAssemblyConnectorResult:Id'  => "",
                            'FormAssemblyConnectorResult:Url' => ""
                        ]);
                        $deferredRecord->resolves = $resolvedRecordKey;
                        $result->setReturnedRecord($deferredRecord);

                        // Add a more specific error message to the result log
                        if (is_array($response['body'])) {
                            if (isset($response['body']['errors'])) {
                                // Format unclear
                                // $response['graphs'][N]['graphResponse']['compositeResponse'][N]['body']['errors']
                                // $response['graphs'][N]['graphResponse']['compositeResponse'][N]['body']['success']
                                $result->log(print_r($response['errors'], 1));
                            } else {
                                foreach ($response['body'] as $error) {
                                    // https://developer.salesforce.com/docs/atlas.en-us.api_rest.meta/api_rest/errorcodes.htm
                                    $msg = $error['message'] . " (" . $error['errorCode'] . ")";
//                                    if (isset($error['fields']) && is_array($error['fields'])) {
//                                        $msg .= " Field(s): " . implode(', ', $error['fields']);
//                                    }
                                    $result->log($msg);
                                }
                            }
                        }
                    }
                    $results[] = $result;
                }
            }

        } else {
            throw new AbortedOperationException("Unexpected response from Salesforce API");
        }
        return $results;
    }

    /**
     * @param string $referenceId
     *
     * @return array
     * @throws \Connector\Exceptions\AbortedOperationException
     */
    private function getRequestByReferenceId(string $referenceId): array {
        foreach($this->batch as $graph) {
            foreach ($graph as $request) {
                if ($request['referenceId'] === $referenceId) {
                    return $request;
                }
            }
        }
        throw new AbortedOperationException("Unexpected response from Salesforce API. Reference unknown: ".$referenceId);
    }

    public function getLog(): array
    {
        return $this->log;
    }

}
