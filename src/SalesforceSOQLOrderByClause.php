<?php

namespace Connector\Integrations\Salesforce;

use Connector\Integrations\Database\GenericOrderByClause;

class SalesforceSOQLOrderByClause extends GenericOrderByClause
{
    public static function copyFrom(GenericOrderByClause $generic): SalesforceSOQLOrderByClause
    {
        $where  = new SalesforceSOQLOrderByClause();
        $where->fromJson($generic->toJson());
        return $where;
    }

    /**
     * @return string
     * @throws \Connector\Integrations\Salesforce\Exceptions\InvalidQueryException
     */
    public function toSoql(): string
    {
        $soql = "ORDER BY " . SalesforceSOQLBuilder::sanitizeField( (string) $this->column) . " ";
        if($this->ascending) {
            $soql .= "ASC";
        } else {
            $soql .= "DESC";
        }

        return $soql;
    }

}
