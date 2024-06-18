<?php

namespace Connector\Integrations\Salesforce\Actions;

use Connector\Operation\Result;
use GuzzleHttp\Client;

interface ActionInterface
{
    /**
     * @param \GuzzleHttp\Client $httpClient
     *
     * @return Result | Result[]
     */
    public function execute(Client $httpClient): mixed;

    /**
     * @return string[]
     */
    public function getLog(): array;

}
