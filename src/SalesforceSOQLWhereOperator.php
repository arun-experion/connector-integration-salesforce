<?php
namespace Connector\Integrations\Salesforce;

enum SalesforceSOQLWhereOperator: string
{
    case AND = 'AND';
    case OR = 'OR';
    case NEQ = '!=';
    case EQ = '=';
    case LTE = '<=';
    case LT = '<';
    case GTE = '>=';
    case GT = '>';
    case LIKE = 'LIKE';
    case IN = 'IN';
    case NOTIN = 'NOTIN';
}
