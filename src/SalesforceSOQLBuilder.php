<?php

namespace Connector\Integrations\Salesforce;

use Connector\Integrations\Database\GenericOrderByClause;
use Connector\Integrations\Database\GenericWhereClause;
use Connector\Integrations\Database\GenericWhereOperator;
use Connector\Integrations\Salesforce\Enumerations\ManyResultsOptions;
use Connector\Integrations\Salesforce\Exceptions\InvalidQueryException;
use Connector\Record\RecordKey;

class SalesforceSOQLBuilder
{

    /**
     * @param array                                                              $select
     * @param string                                                             $from
     * @param \Connector\Integrations\Salesforce\SalesforceSOQLWhereClause|null  $where
     * @param \Connector\Integrations\Database\GenericOrderByClause|null         $orderBy
     * @param \Connector\Integrations\Salesforce\Enumerations\ManyResultsOptions $onManyResults
     * @param \Connector\Record\RecordKey|null                                   $scope
     *
     * @return string
     * @throws \Connector\Integrations\Salesforce\Exceptions\InvalidQueryException
     */
    static public function toSoql(array $select, string $from,
                                  SalesforceSOQLWhereClause $where = null,
                                  GenericOrderByClause $orderBy = null,
                                  ManyResultsOptions $onManyResults = ManyResultsOptions::SelectAll,
                                  RecordKey $scope = null): string
    {
        // Make sure all requests retrieve the record ID, as it's needed to return the RecordKey.
        if(!in_array('Id', $select)) {
            $select[] = 'Id';
        }
        $select = self::sanitizeSelect($select);
        $select = implode(", ", $select);
        $from   = self::sanitizeFrom($from);

        $soql   = "SELECT $select FROM $from";

        //  TODO: Test scope
        if($scope) {
            $scopeClause = new GenericWhereClause($scope->recordType . "Id", GenericWhereOperator::EQ, $scope->recordId);
            if($where) {
                $where = new GenericWhereClause ( $where, GenericWhereOperator::AND, $scopeClause );
            } else {
                $where = $scopeClause;
            }
        }

        if($where && !$where->isEmpty()) {
            $soql .= " " . $where->toSoql();
        }

        if($orderBy && !$orderBy->isEmpty()) {
            $soql .= " " . SalesforceSOQLOrderByClause::copyFrom($orderBy)->toSoql();
        }

        if($onManyResults === ManyResultsOptions::SelectOne) {
            $soql .= " LIMIT 1";
        }

        return $soql;
    }

    /**
     * @param array $select
     *
     * @return array
     * @throws \Connector\Integrations\Salesforce\Exceptions\InvalidQueryException
     */
    public static function sanitizeSelect(array $select): array
    {
        if(!count($select)) {
            throw new InvalidQueryException("Cannot build SOQL with no selected fields.");
        }
        foreach($select as $field) {
            self::sanitizeField($field);
        }
        return $select;
    }

    /**
     * @param string $field
     *
     * @return string
     * @throws \Connector\Integrations\Salesforce\Exceptions\InvalidQueryException
     */
    public static function sanitizeField(string $field): string
    {
        // Replace record-type separator (":") to the SOQL equivalent (".")
        $field = str_replace(":",".", $field);

        if(preg_match("/[^a-zA-Z_\.]/", $field)) {
            throw new InvalidQueryException("Cannot build SOQL with invalid field: " . print_r($field,1));
        }
        return $field;
    }

    /**
     * @param string $from
     *
     * @return string
     * @throws \Connector\Integrations\Salesforce\Exceptions\InvalidQueryException
     */
    public static function sanitizeFrom(string $from): string
    {
        if(preg_match("/[^a-zA-Z_\.]/", $from)) {
            throw new InvalidQueryException("Cannot build SOQL with invalid sObject: " . print_r($from,1));
        }
        return $from;
    }

    /**
     * @param string $str
     *
     * @return string
     */
    public static function escapeString(string $str): string
    {
        $search  = ["\\", "\0", "\n", "\r", "\x1a", "'", '"'];
        $replace = ["\\\\", "\\0", "\\n", "\\r", "\Z", "\'", '\"'];

        return str_replace($search, $replace, $str);
    }


}
