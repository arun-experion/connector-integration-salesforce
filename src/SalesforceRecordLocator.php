<?php

namespace Connector\Integrations\Salesforce;
use Connector\Integrations\Database\GenericWhereOperator;
use Connector\Integrations\Salesforce\Enumerations\ManyResultsOptions;
use Connector\Integrations\Salesforce\Enumerations\NoResultOptions;
use Connector\Integrations\Salesforce\Enumerations\OperationTypes;
use Connector\Record\RecordLocator;
use Connector\Schema\IntegrationSchema;

/**
 *
 */
class SalesforceRecordLocator extends RecordLocator
{
    /**
     * @var string $recordType  Salesforce sObject Name
     */
    public string $recordType  = '';

    /***
     * @var string $recordId The Salesforce sObject ID
     */
    public string $recordId = '';

    /**
     * @var string[]
     */
    public array $recordIds = [];

    /**
     * @var SalesforceSOQLWhereClause|null A Where clause to identify records to be pulled or updated.
     */
    public ?SalesforceSOQLWhereClause $query;

    /**
     * @var SalesforceSOQLOrderByClause $orderByClause  An Order By clause to sort selected records.
     */
    public SalesforceSOQLOrderByClause $orderByClause;

    public ?SalesforceUpsertKey $upsertKey   = null;
    public NoResultOptions $onNoResult       = NoResultOptions::Skip;
    public ManyResultsOptions $onManyResults = ManyResultsOptions::SelectOne;

    /**
     * @var OperationTypes $type Type of Operation (create, update, select)
     */
    public OperationTypes $type = OperationTypes::Create;

    /**
     * Auto-Assignment option
     * https://developer.salesforce.com/docs/atlas.en-us.api_rest.meta/api_rest/headers_autoassign.htm
     * @var bool True to apply enabled assignment rules
     */
    public bool $autoAssign = true;

    /**
     * Duplicate Rule
     * https://developer.salesforce.com/docs/atlas.en-us.api_rest.meta/api_rest/headers_duplicaterules.htm
     * @var bool    True to automatically acknowledge a duplicate rule alert
     */
    public bool $autoAcknowledgeDuplicates = true;

    /**
     * Legacy connector v2 setting. Added for backward compatibility. Prevents empty values from overwriting existing
     * data when updating a field.
     * @var bool True to skip empty strings and null values in data mapping.
     */
    public bool $noBlankFieldsOnUpdate = false;


    /**
     * @param mixed|null                               $params
     * @param \Connector\Schema\IntegrationSchema|null $schema
     *
     * @throws \Connector\Exceptions\InvalidSchemaException
     */
    public function __construct(mixed $params = null, IntegrationSchema $schema = null)
    {
        $this->query = new SalesforceSOQLWhereClause();
        $this->orderByClause = new SalesforceSOQLOrderByClause();
        parent::__construct($params);
        $this->setUpsertKeyFromWhereClause($schema);
    }

    /**
     * @throws \Connector\Integrations\Salesforce\Exceptions\InvalidQueryException
     */
    protected function copyFrom(RecordLocator $locator): void
    {
        foreach($locator->getProperties() as $propertyName => $propertyValue) {

            if($this->$propertyName instanceof SalesforceSOQLWhereClause && !empty($propertyValue['where'])) {
                $this->$propertyName = SalesforceSOQLWhereClause::fromArray($propertyValue['where']);
            }
            elseif($this->$propertyName instanceof SalesforceSOQLOrderByClause) {
                $this->$propertyName->fromJson($propertyValue);
            }
            elseif ($this->$propertyName instanceof NoResultOptions) {
                $this->$propertyName = NoResultOptions::from($propertyValue);
            }
            elseif ($this->$propertyName instanceof ManyResultsOptions) {
                $this->$propertyName = ManyResultsOptions::from($propertyValue);
            }
            elseif ($this->$propertyName instanceof SalesforceUpsertKey) {
                $this->$propertyName = $this->$propertyName->fromJson($propertyValue);
            }
            elseif ($this->$propertyName instanceof OperationTypes) {
                $this->$propertyName = OperationTypes::from($propertyValue);
            }
            elseif (gettype($this->$propertyName) === 'boolean') {
                $this->$propertyName = is_string($propertyValue) ? filter_var($propertyValue, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) : (bool) $propertyValue;
            }
            else {
                $this->$propertyName = $propertyValue;
            }
        }

        $this->recordType = $locator->recordType;
    }

    /**
     * Where clause must an equality between a column defined as primary or unique key in the schema, and a value.
     * @param \Connector\Schema\IntegrationSchema $schema
     *
     * @return bool
     * @throws \Connector\Exceptions\InvalidSchemaException
     */
    private function hasUpsertKey(IntegrationSchema $schema): bool
    {
        // TODO TEST
        if($this->query && is_string($this->query->getLeft())) {
            $property = $this->query->getLeft();
            if($schema->isFullyQualifiedName($property)) {
                $property = $schema->getPropertyNameFromFQN($property);
            }
            $property = $schema->getProperty($this->recordType, $property);
            if(isset($property['pk']) && $property['pk'] === 1) {
                if($this->query->getOperator() === GenericWhereOperator::EQ) {
                    if(is_string($this->query->getRight())) {
                        return true;
                    }
                }
            }
        }
        return false;
    }

    /**
     * @param \Connector\Schema\IntegrationSchema $schema
     *
     * @return void
     * @throws \Connector\Exceptions\InvalidSchemaException
     */
    private function setUpsertKeyFromWhereClause(IntegrationSchema $schema): void
    {
        if($this->hasUpsertKey($schema)) {
            $this->upsertKey = new SalesforceUpsertKey($this->query->getLeft(), $this->query->getRight());
        }
    }

    public function isCreate(): bool
    {
        return $this->type === OperationTypes::Create;
    }

    public function isUpdate(): bool
    {
        return $this->type === OperationTypes::Update;
    }

    public function isSelect(): bool
    {
        return $this->type === OperationTypes::Select;
    }

}

