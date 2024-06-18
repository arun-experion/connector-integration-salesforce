<?php

namespace Connector\Integrations\Salesforce\Enumerations;

enum OperationTypes: string
{
    case Select = 'select';
    case Create = 'create';
    case Update = 'update';
}
