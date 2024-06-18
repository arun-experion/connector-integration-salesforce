<?php

namespace Connector\Integrations\Salesforce;

use Connector\Integrations\Database\GenericWhereClause;
use Connector\Integrations\Database\GenericWhereOperator;
use Connector\Integrations\Salesforce\Exceptions\InvalidQueryException;

class SalesforceSOQLWhereClause extends GenericWhereClause
{

    /**
     * @throws \Connector\Integrations\Salesforce\Exceptions\InvalidQueryException
     */
    public function __construct(mixed $leftOperand = null, mixed $operator = null, mixed $rightOperand = null)
    {
        parent::__construct($leftOperand, $operator, $rightOperand);

        if(is_array($leftOperand) && array_key_exists('left',$leftOperand) && array_key_exists('right',$leftOperand) && array_key_exists('op',$leftOperand)) {
            $this->left = new SalesforceSOQLWhereClause($leftOperand['left'], self::translateOperator($leftOperand['op']), $leftOperand['right']);
        }

        if(is_array($rightOperand) && array_key_exists('left',$rightOperand) && array_key_exists('right',$rightOperand) && array_key_exists('op',$rightOperand)) {
            $this->right = new SalesforceSOQLWhereClause($rightOperand['left'], self::translateOperator($rightOperand['op']), $rightOperand['right']);
        }

        if(is_string($operator)) {
            $this->op = self::translateOperator($operator);
        } elseif($operator instanceof GenericWhereOperator) {
            $this->op = self::translateOperator($operator->value);
        }
    }

    /**
     * @throws \Connector\Integrations\Salesforce\Exceptions\InvalidQueryException
     */
    public static function fromArray(mixed $clause): SalesforceSOQLWhereClause
    {
        if(is_array($clause) && array_key_exists('left',$clause) && array_key_exists('right',$clause) && array_key_exists('op',$clause)) {
            return new self($clause['left'], $clause['op'], $clause['right']);
        }
        throw new \InvalidArgumentException("Expected array with left, right, and op keys");
    }

    /**
     * @return string
     * @throws \Connector\Integrations\Salesforce\Exceptions\InvalidQueryException
     */
    public function toSoql(): string
    {
        return "WHERE " . $this->toSoqlRecursive();
    }

    /**
     * @throws \Connector\Integrations\Salesforce\Exceptions\InvalidQueryException
     */
    private function toSoqlRecursive(): string
    {
        $soql = "";
        if($this->left instanceof SalesforceSOQLWhereClause) {
            $soql .= " (" . $this->left->toSoqlRecursive() . ") ";
        } elseif(is_string($this->left)) {
            $soql .= SalesforceSOQLBuilder::sanitizeField( $this->left) . " ";
        } else {
            throw new InvalidQueryException("Invalid query. " . print_r($this->left,1));
        }

        $soql .= $this->op->value;

        if($this->right instanceof SalesforceSOQLWhereClause) {
            $soql .= " (" . $this->right->toSoqlRecursive() . ") ";
        } elseif(is_string($this->right)) {
            $soql .= " '" . SalesforceSOQLBuilder::escapeString( (string) $this->right ). "' ";
        } elseif(is_bool($this->right)) {
            $soql .= $this->right?'" true "':'" false ';
        } elseif(is_null($this->right)) {
            $soql .= ' NULL ';
        } elseif(is_numeric($this->right)) {
            $soql .= ' ' . $this->right . ' ';
        } elseif(is_array($this->right)) {
            $soql .= " '" . SalesforceSOQLBuilder::escapeString( implode(",", $this->right) ). "' ";
        }
        // TODO: DateTime formatting?


        return trim($soql);
    }

    /**
     * @param string $operator
     *
     * @return \Connector\Integrations\Salesforce\SalesforceSOQLWhereOperator
     * @throws \Connector\Integrations\Salesforce\Exceptions\InvalidQueryException
     */
    protected static function translateOperator(string $operator): SalesforceSOQLWhereOperator
    {
        $operator = GenericWhereOperator::from($operator);

        switch($operator->name) {
            case "AND":
                return SalesforceSOQLWhereOperator::AND;
            case "OR":
                return SalesforceSOQLWhereOperator::OR;
            case "NEQ":
                return SalesforceSOQLWhereOperator::NEQ;
            case "EQ":
                return SalesforceSOQLWhereOperator::EQ;
            case "LTE":
                return SalesforceSOQLWhereOperator::LTE;
            case "LT" :
                return SalesforceSOQLWhereOperator::LT;
            case "GTE" :
                return SalesforceSOQLWhereOperator::GTE;
            case "GT" :
                return SalesforceSOQLWhereOperator::GT;
            case "LIKE" :
                return SalesforceSOQLWhereOperator::LIKE;
            case "IN" :
                return SalesforceSOQLWhereOperator::IN;
            case "NOTIN" :
                return SalesforceSOQLWhereOperator::NOTIN;
        }
        throw new InvalidQueryException("Unsupported operator " . $operator->name);
    }


}
