<?php

namespace Connector\Integrations\Salesforce\Enumerations;

enum ManyResultsOptions: string {
    case SelectOne  = 'selectOne';  // Select one (Most recently modified).
    case SelectAll  = 'selectAll';  // Select all. Current action and its dependent is executed for all selected records.
    case Skip       = 'skip';       // Skip current action and its dependent actions.
    case Abort      = 'abort';      // End operation, report an error.
    case Create     = 'create';     // Create a new record instead (when updating records)
}
