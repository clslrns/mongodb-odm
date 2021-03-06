<?php

namespace Doctrine\ODM\MongoDB\Aggregation\Stage;

use Doctrine\Common\Persistence\Mapping\MappingException as BaseMappingException;
use Doctrine\ODM\MongoDB\Aggregation\Builder;
use Doctrine\ODM\MongoDB\Aggregation\Expr;
use Doctrine\ODM\MongoDB\Aggregation\Stage;
use Doctrine\ODM\MongoDB\DocumentManager;
use Doctrine\ODM\MongoDB\Mapping\ClassMetadata;
use Doctrine\ODM\MongoDB\Mapping\ClassMetadataInfo;
use Doctrine\ODM\MongoDB\Mapping\MappingException;
use Doctrine\ODM\MongoDB\Types\Type;

class GraphLookup extends Stage
{
    /**
     * @var string
     */
    private $from;

    /**
     * @var string|Expr|array
     */
    private $startWith;

    /**
     * @var string
     */
    private $connectFromField;

    /**
     * @var string
     */
    private $connectToField;

    /**
     * @var string
     */
    private $as;

    /**
     * @var int
     */
    private $maxDepth;

    /**
     * @var string
     */
    private $depthField;

    /**
     * @var Stage\GraphLookup\Match
     */
    private $restrictSearchWithMatch;

    /**
     * @var DocumentManager
     */
    private $dm;

    /**
     * @var ClassMetadata
     */
    private $class;

    /**
     * @var ClassMetadata
     */
    private $targetClass;

    /**
     * @param Builder $builder
     * @param string $from Target collection for the $graphLookup operation to
     * search, recursively matching the connectFromField to the connectToField.
     * @param DocumentManager $documentManager
     * @param ClassMetadata $class
     */
    public function __construct(Builder $builder, $from, DocumentManager $documentManager, ClassMetadata $class)
    {
        parent::__construct($builder);

        $this->dm = $documentManager;
        $this->class = $class;
        $this->restrictSearchWithMatch = new GraphLookup\Match($this->builder, $this);
        $this->from($from);
    }

    /**
     * Name of the array field added to each output document.
     *
     * Contains the documents traversed in the $graphLookup stage to reach the
     * document.
     *
     * @param string $alias
     *
     * @return $this
     */
    public function alias($alias)
    {
        $this->as = $alias;

        return $this;
    }

    /**
     * Field name whose value $graphLookup uses to recursively match against the
     * connectToField of other documents in the collection.
     *
     * Optionally, connectFromField may be an array of field names, each of
     * which is individually followed through the traversal process.
     *
     * @param string $connectFromField
     *
     * @return $this
     */
    public function connectFromField($connectFromField)
    {
        // No targetClass mapping - simply use field name as is
        if ( ! $this->targetClass) {
            $this->connectFromField = $connectFromField;
            return $this;
        }

        // connectFromField doesn't have to be a reference - in this case, just convert the field name
        if ( ! $this->targetClass->hasReference($connectFromField)) {
            $this->connectFromField = $this->convertTargetFieldName($connectFromField);
            return $this;
        }

        // connectFromField is a reference - do a sanity check
        $referenceMapping = $this->targetClass->getFieldMapping($connectFromField);
        if ($referenceMapping['targetDocument'] !== $this->targetClass->name) {
            throw MappingException::connectFromFieldMustReferenceSameDocument($connectFromField);
        }

        $this->connectFromField = $this->getReferencedFieldName($connectFromField, $referenceMapping);
        return $this;
    }

    /**
     * Field name in other documents against which to match the value of the
     * field specified by the connectFromField parameter.
     *
     * @param string $connectToField
     *
     * @return $this
     */
    public function connectToField($connectToField)
    {
        $this->connectToField = $this->convertTargetFieldName($connectToField);
        return $this;
    }

    /**
     * Name of the field to add to each traversed document in the search path.
     *
     * The value of this field is the recursion depth for the document,
     * represented as a NumberLong. Recursion depth value starts at zero, so the
     * first lookup corresponds to zero depth.
     *
     * @param string $depthField
     *
     * @return $this
     */
    public function depthField($depthField)
    {
        $this->depthField = $depthField;

        return $this;
    }

    /**
     * Target collection for the $graphLookup operation to search, recursively
     * matching the connectFromField to the connectToField.
     *
     * The from collection cannot be sharded and must be in the same database as
     * any other collections used in the operation.
     *
     * @param string $from
     *
     * @return $this
     */
    public function from($from)
    {
        // $from can either be
        // a) a field name indicating a reference to a different document. Currently, only REFERENCE_STORE_AS_ID is supported
        // b) a Class name
        // c) a collection name
        // In cases b) and c) the local and foreign fields need to be filled
        if ($this->class->hasReference($from)) {
            return $this->fromReference($from);
        }

        // Check if mapped class with given name exists
        try {
            $this->targetClass = $this->dm->getClassMetadata($from);
        } catch (BaseMappingException $e) {
            $this->from = $from;
            return $this;
        }

        if ($this->targetClass->isSharded()) {
            throw MappingException::cannotUseShardedCollectionInLookupStages($this->targetClass->name);
        }

        $this->from = $this->targetClass->getCollection();
        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function getExpression()
    {
        $graphLookup = [
            'from' => $this->from,
            'startWith' => $this->convertExpression($this->startWith),
            'connectFromField' => $this->connectFromField,
            'connectToField' => $this->connectToField,
            'as' => $this->as,
            'restrictSearchWithMatch' => $this->restrictSearchWithMatch->getExpression(),
        ];

        foreach (['maxDepth', 'depthField'] as $field) {
            if ($this->$field === null) {
                continue;
            }

            $graphLookup[$field] = $this->$field;
        }

        return ['$graphLookup' => $graphLookup];
    }

    /**
     * Non-negative integral number specifying the maximum recursion depth.
     *
     * @param int $maxDepth
     *
     * @return $this
     */
    public function maxDepth($maxDepth)
    {
        $this->maxDepth = $maxDepth;

        return $this;
    }

    /**
     * A document specifying additional conditions for the recursive search.
     *
     * @return GraphLookup\Match
     */
    public function restrictSearchWithMatch()
    {
        return $this->restrictSearchWithMatch;
    }

    /**
     * Expression that specifies the value of the connectFromField with which to
     * start the recursive search.
     *
     * Optionally, startWith may be array of values, each of which is
     * individually followed through the traversal process.
     *
     * @param string|array|Expr $expression
     *
     * @return $this
     */
    public function startWith($expression)
    {
        $this->startWith = $expression;

        return $this;
    }

    /**
     * @param string $fieldName
     * @return $this
     * @throws MappingException
     */
    private function fromReference($fieldName)
    {
        if ( ! $this->class->hasReference($fieldName)) {
            MappingException::referenceMappingNotFound($this->class->name, $fieldName);
        }

        $referenceMapping = $this->class->getFieldMapping($fieldName);
        $this->targetClass = $this->dm->getClassMetadata($referenceMapping['targetDocument']);
        if ($this->targetClass->isSharded()) {
            throw MappingException::cannotUseShardedCollectionInLookupStages($this->targetClass->name);
        }

        $this->from = $this->targetClass->getCollection();

        $referencedFieldName = $this->getReferencedFieldName($fieldName, $referenceMapping);

        if ($referenceMapping['isOwningSide']) {
            $this
                ->startWith('$' . $referencedFieldName)
                ->connectToField('_id');
        } else {
            $this
                ->startWith('$' . $referencedFieldName)
                ->connectToField('_id');
        }

        // A self-reference indicates that we can also fill the "connectFromField" accordingly
        if ($this->targetClass->name === $this->class->name) {
            $this->connectFromField($referencedFieldName);
        }

        return $this;
    }

    private function convertExpression($expression)
    {
        if (is_array($expression)) {
            return array_map([$this, 'convertExpression'], $expression);
        } elseif (is_string($expression) && substr($expression, 0, 1) === '$') {
            return '$' . $this->getDocumentPersister($this->class)->prepareFieldName(substr($expression, 1));
        }

        return Type::convertPHPToDatabaseValue(Expr::convertExpression($expression));
    }

    private function convertTargetFieldName($fieldName)
    {
        if (is_array($fieldName)) {
            return array_map([$this, 'convertTargetFieldName'], $fieldName);
        }

        if ( ! $this->targetClass) {
            return $fieldName;
        }

        return $this->getDocumentPersister($this->targetClass)->prepareFieldName($fieldName);
    }

    /**
     * @param ClassMetadata $class
     * @return \Doctrine\ODM\MongoDB\Persisters\DocumentPersister
     */
    private function getDocumentPersister(ClassMetadata $class)
    {
        return $this->dm->getUnitOfWork()->getDocumentPersister($class->name);
    }

    private function getReferencedFieldName($fieldName, array $mapping)
    {
        if ( ! $mapping['isOwningSide']) {
            if (isset($mapping['repositoryMethod']) || ! isset($mapping['mappedBy'])) {
                throw MappingException::repositoryMethodLookupNotAllowed($this->class->name, $fieldName);
            }

            $mapping = $this->targetClass->getFieldMapping($mapping['mappedBy']);
        }

        switch ($mapping['storeAs']) {
            case ClassMetadataInfo::REFERENCE_STORE_AS_ID:
            case ClassMetadataInfo::REFERENCE_STORE_AS_REF:
                return ClassMetadataInfo::getReferenceFieldName($mapping['storeAs'], $mapping['name']);
                break;

            default:
                throw MappingException::cannotLookupDbRefReference($this->class->name, $fieldName);
        }
    }
}
