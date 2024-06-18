<?php

namespace Connector\Integrations\Salesforce\Actions;

use Connector\Integrations\Salesforce\SalesforceRecordLocator;
use Connector\Mapping;
use Connector\Operation\Result;
use Connector\Record\RecordKey;

interface BatchableActionInterface extends ActionInterface
{
    public function __construct(SalesforceRecordLocator $recordLocator, Mapping $mapping, ?RecordKey $scope);

    /**
     * @param array $batch
     *
     * @return \Connector\Operation\Result
     */
    public function batch(array & $batch): Result;

    /**
     * Indicates if the action supports the Composite Graph API, and therefore should be batched.
     * @return bool
     */
    public function isBatchable(): bool;


}
