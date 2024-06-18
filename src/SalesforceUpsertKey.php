<?php

namespace Connector\Integrations\Salesforce;

class SalesforceUpsertKey
{
    public function __construct(public string $name, public string $value) {}

    public function fromJson(string $json): void
    {
        $array = json_decode($json, true);

        if(!$array || !isset($array['name']) || !isset($array['value'])) {
            throw new \InvalidArgumentException("Expected JSON with name and value keys");
        }

        $this->name  = $array['name'];
        $this->value  = $array['value'];
    }
}
