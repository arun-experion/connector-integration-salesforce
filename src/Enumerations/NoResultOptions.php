<?php

namespace Connector\Integrations\Salesforce\Enumerations;

enum NoResultOptions: string {
    case Skip       = 'skip';       // Skip current action and its dependent actions.
    case Abort      = 'abort';      // End operation, report an error.
    case Create     = 'create';     // Create a new record instead (when updating records)
}
