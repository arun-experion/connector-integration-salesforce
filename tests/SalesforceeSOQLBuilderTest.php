<?php

namespace Tests;
use Connector\Integrations\Database\GenericOrderByClause;
use Connector\Integrations\Database\GenericWhereOperator;
use Connector\Integrations\Salesforce\SalesforceSOQLBuilder;
use Connector\Integrations\Salesforce\SalesforceSOQLWhereClause;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Connector\Integrations\Salesforce\SalesforceSOQLBuilder
 * @covers \Connector\Integrations\Salesforce\SalesforceSOQLWhereClause
 * @covers \Connector\Integrations\Salesforce\SalesforceSOQLWhereOperator
 * @covers \Connector\Integrations\Salesforce\SalesforceSOQLOrderByClause
 */
final class SalesforceeSOQLBuilderTest extends TestCase
{

    function testSimpleWhereClause()
    {
        $where = new SalesforceSOQLWhereClause("Name", GenericWhereOperator::LIKE, "%a");
        $soql = SalesforceSOQLBuilder::toSoql(['Id', 'Name'], "Account", $where);
        $this->assertEquals("SELECT Id, Name FROM Account WHERE Name LIKE '%a'",$soql);
    }

    function testComplexWhereClause()
    {
        $a     = new SalesforceSOQLWhereClause("Name", GenericWhereOperator::LIKE, "%a");
        $b     = new SalesforceSOQLWhereClause("Name", GenericWhereOperator::LIKE, "%b");
        $where = new SalesforceSOQLWhereClause($a, GenericWhereOperator::OR, $b);

        $soql = SalesforceSOQLBuilder::toSoql(['Id', 'Name'], "Account", $where);
        $this->assertEquals("SELECT Id, Name FROM Account WHERE (Name LIKE '%a') OR (Name LIKE '%b')",$soql);
    }

    function testDefaultOrderByClause()
    {
        $where = new SalesforceSOQLWhereClause("Name", GenericWhereOperator::LIKE, "%a");
        $orderBy = new GenericOrderByClause('Created');
        $soql = SalesforceSOQLBuilder::toSoql(['Id', 'Name'], "Account", $where, $orderBy);
        $this->assertEquals("SELECT Id, Name FROM Account WHERE Name LIKE '%a' ORDER BY Created ASC",$soql, "Default sort order must be ascending");
    }

    function testAscendingOrderByClause()
    {
        $where = new SalesforceSOQLWhereClause("Name", GenericWhereOperator::LIKE, "%a");
        $orderBy = new GenericOrderByClause('Created', true);
        $soql = SalesforceSOQLBuilder::toSoql(['Id', 'Name'], "Account", $where, $orderBy);
        $this->assertEquals("SELECT Id, Name FROM Account WHERE Name LIKE '%a' ORDER BY Created ASC",$soql, "Sort order must be ascending");
    }

    function testDescendingOrderByClause()
    {
        $where   = new SalesforceSOQLWhereClause("Name", GenericWhereOperator::LIKE, "%a");
        $orderBy = new GenericOrderByClause('Created', false);
        $soql = SalesforceSOQLBuilder::toSoql(['Id', 'Name'], "Account", $where, $orderBy);
        $this->assertEquals("SELECT Id, Name FROM Account WHERE Name LIKE '%a' ORDER BY Created DESC",$soql, "Sort order must be ascending");
    }
}
