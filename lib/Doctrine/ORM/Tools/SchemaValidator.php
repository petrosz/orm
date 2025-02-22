<?php

declare(strict_types=1);

namespace Doctrine\ORM\Tools;

use BackedEnum;
use Doctrine\DBAL\Types\AsciiStringType;
use Doctrine\DBAL\Types\BigIntType;
use Doctrine\DBAL\Types\BooleanType;
use Doctrine\DBAL\Types\DecimalType;
use Doctrine\DBAL\Types\FloatType;
use Doctrine\DBAL\Types\GuidType;
use Doctrine\DBAL\Types\IntegerType;
use Doctrine\DBAL\Types\JsonType;
use Doctrine\DBAL\Types\SimpleArrayType;
use Doctrine\DBAL\Types\SmallIntType;
use Doctrine\DBAL\Types\StringType;
use Doctrine\DBAL\Types\TextType;
use Doctrine\DBAL\Types\Type;
use Doctrine\Deprecations\Deprecation;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Mapping\ClassMetadataInfo;
use ReflectionEnum;
use ReflectionNamedType;

use function array_diff;
use function array_filter;
use function array_key_exists;
use function array_map;
use function array_push;
use function array_search;
use function array_values;
use function assert;
use function class_exists;
use function class_parents;
use function count;
use function get_class;
use function implode;
use function in_array;
use function is_a;
use function sprintf;

use const PHP_VERSION_ID;

/**
 * Performs strict validation of the mapping schema
 *
 * @link        www.doctrine-project.com
 *
 * @psalm-import-type FieldMapping from ClassMetadata
 */
class SchemaValidator
{
    /** @var EntityManagerInterface */
    private $em;

    /**
     * It maps built-in Doctrine types to PHP types
     */
    private const BUILTIN_TYPES_MAP = [
        AsciiStringType::class => 'string',
        BigIntType::class => 'string',
        BooleanType::class => 'bool',
        DecimalType::class => 'string',
        FloatType::class => 'float',
        GuidType::class => 'string',
        IntegerType::class => 'int',
        JsonType::class => 'array',
        SimpleArrayType::class => 'array',
        SmallIntType::class => 'int',
        StringType::class => 'string',
        TextType::class => 'string',
    ];

    public function __construct(EntityManagerInterface $em)
    {
        $this->em = $em;
    }

    /**
     * Checks the internal consistency of all mapping files.
     *
     * There are several checks that can't be done at runtime or are too expensive, which can be verified
     * with this command. For example:
     *
     * 1. Check if a relation with "mappedBy" is actually connected to that specified field.
     * 2. Check if "mappedBy" and "inversedBy" are consistent to each other.
     * 3. Check if "referencedColumnName" attributes are really pointing to primary key columns.
     *
     * @psalm-return array<string, list<string>>
     */
    public function validateMapping()
    {
        $errors  = [];
        $cmf     = $this->em->getMetadataFactory();
        $classes = $cmf->getAllMetadata();

        foreach ($classes as $class) {
            $ce = $this->validateClass($class);
            if ($ce) {
                $errors[$class->name] = $ce;
            }
        }

        return $errors;
    }

    /**
     * Validates a single class of the current.
     *
     * @return string[]
     * @psalm-return list<string>
     */
    public function validateClass(ClassMetadataInfo $class)
    {
        if (! $class instanceof ClassMetadata) {
            Deprecation::trigger(
                'doctrine/orm',
                'https://github.com/doctrine/orm/pull/249',
                'Passing an instance of %s to %s is deprecated, please pass a ClassMetadata instance instead.',
                get_class($class),
                __METHOD__,
                ClassMetadata::class
            );
        }

        $ce  = [];
        $cmf = $this->em->getMetadataFactory();

        foreach ($class->fieldMappings as $fieldName => $mapping) {
            if (! Type::hasType($mapping['type'])) {
                $ce[] = "The field '" . $class->name . '#' . $fieldName . "' uses a non-existent type '" . $mapping['type'] . "'.";
            }
        }

        // PHP 7.4 introduces the ability to type properties, so we can't validate them in previous versions
        if (PHP_VERSION_ID >= 70400) {
            array_push($ce, ...$this->validatePropertiesTypes($class));
        }

        if ($class->isEmbeddedClass && count($class->associationMappings) > 0) {
            $ce[] = "Embeddable '" . $class->name . "' does not support associations";

            return $ce;
        }

        foreach ($class->associationMappings as $fieldName => $assoc) {
            if (! class_exists($assoc['targetEntity']) || $cmf->isTransient($assoc['targetEntity'])) {
                $ce[] = "The target entity '" . $assoc['targetEntity'] . "' specified on " . $class->name . '#' . $fieldName . ' is unknown or not an entity.';

                return $ce;
            }

            $targetMetadata = $cmf->getMetadataFor($assoc['targetEntity']);

            if ($targetMetadata->isMappedSuperclass) {
                $ce[] = "The target entity '" . $assoc['targetEntity'] . "' specified on " . $class->name . '#' . $fieldName . ' is a mapped superclass. This is not possible since there is no table that a foreign key could refer to.';

                return $ce;
            }

            if ($assoc['mappedBy'] && $assoc['inversedBy']) {
                $ce[] = 'The association ' . $class . '#' . $fieldName . ' cannot be defined as both inverse and owning.';
            }

            if (isset($assoc['id']) && $targetMetadata->containsForeignIdentifier) {
                $ce[] = "Cannot map association '" . $class->name . '#' . $fieldName . ' as identifier, because ' .
                        "the target entity '" . $targetMetadata->name . "' also maps an association as identifier.";
            }

            if ($assoc['mappedBy']) {
                if ($targetMetadata->hasField($assoc['mappedBy'])) {
                    $ce[] = 'The association ' . $class->name . '#' . $fieldName . ' refers to the owning side ' .
                            'field ' . $assoc['targetEntity'] . '#' . $assoc['mappedBy'] . ' which is not defined as association, but as field.';
                }

                if (! $targetMetadata->hasAssociation($assoc['mappedBy'])) {
                    $ce[] = 'The association ' . $class->name . '#' . $fieldName . ' refers to the owning side ' .
                            'field ' . $assoc['targetEntity'] . '#' . $assoc['mappedBy'] . ' which does not exist.';
                } elseif ($targetMetadata->associationMappings[$assoc['mappedBy']]['inversedBy'] === null) {
                    $ce[] = 'The field ' . $class->name . '#' . $fieldName . ' is on the inverse side of a ' .
                            'bi-directional relationship, but the specified mappedBy association on the target-entity ' .
                            $assoc['targetEntity'] . '#' . $assoc['mappedBy'] . ' does not contain the required ' .
                            "'inversedBy=\"" . $fieldName . "\"' attribute.";
                } elseif ($targetMetadata->associationMappings[$assoc['mappedBy']]['inversedBy'] !== $fieldName) {
                    $ce[] = 'The mappings ' . $class->name . '#' . $fieldName . ' and ' .
                            $assoc['targetEntity'] . '#' . $assoc['mappedBy'] . ' are ' .
                            'inconsistent with each other.';
                }
            }

            if ($assoc['inversedBy']) {
                if ($targetMetadata->hasField($assoc['inversedBy'])) {
                    $ce[] = 'The association ' . $class->name . '#' . $fieldName . ' refers to the inverse side ' .
                            'field ' . $assoc['targetEntity'] . '#' . $assoc['inversedBy'] . ' which is not defined as association.';
                }

                if (! $targetMetadata->hasAssociation($assoc['inversedBy'])) {
                    $ce[] = 'The association ' . $class->name . '#' . $fieldName . ' refers to the inverse side ' .
                            'field ' . $assoc['targetEntity'] . '#' . $assoc['inversedBy'] . ' which does not exist.';
                } elseif ($targetMetadata->associationMappings[$assoc['inversedBy']]['mappedBy'] === null) {
                    $ce[] = 'The field ' . $class->name . '#' . $fieldName . ' is on the owning side of a ' .
                            'bi-directional relationship, but the specified inversedBy association on the target-entity ' .
                            $assoc['targetEntity'] . '#' . $assoc['inversedBy'] . ' does not contain the required ' .
                            "'mappedBy=\"" . $fieldName . "\"' attribute.";
                } elseif ($targetMetadata->associationMappings[$assoc['inversedBy']]['mappedBy'] !== $fieldName) {
                    $ce[] = 'The mappings ' . $class->name . '#' . $fieldName . ' and ' .
                            $assoc['targetEntity'] . '#' . $assoc['inversedBy'] . ' are ' .
                            'inconsistent with each other.';
                }

                // Verify inverse side/owning side match each other
                if (array_key_exists($assoc['inversedBy'], $targetMetadata->associationMappings)) {
                    $targetAssoc = $targetMetadata->associationMappings[$assoc['inversedBy']];
                    if ($assoc['type'] === ClassMetadata::ONE_TO_ONE && $targetAssoc['type'] !== ClassMetadata::ONE_TO_ONE) {
                        $ce[] = 'If association ' . $class->name . '#' . $fieldName . ' is one-to-one, then the inversed ' .
                                'side ' . $targetMetadata->name . '#' . $assoc['inversedBy'] . ' has to be one-to-one as well.';
                    } elseif ($assoc['type'] === ClassMetadata::MANY_TO_ONE && $targetAssoc['type'] !== ClassMetadata::ONE_TO_MANY) {
                        $ce[] = 'If association ' . $class->name . '#' . $fieldName . ' is many-to-one, then the inversed ' .
                                'side ' . $targetMetadata->name . '#' . $assoc['inversedBy'] . ' has to be one-to-many.';
                    } elseif ($assoc['type'] === ClassMetadata::MANY_TO_MANY && $targetAssoc['type'] !== ClassMetadata::MANY_TO_MANY) {
                        $ce[] = 'If association ' . $class->name . '#' . $fieldName . ' is many-to-many, then the inversed ' .
                                'side ' . $targetMetadata->name . '#' . $assoc['inversedBy'] . ' has to be many-to-many as well.';
                    }
                }
            }

            if ($assoc['isOwningSide']) {
                if ($assoc['type'] === ClassMetadata::MANY_TO_MANY) {
                    $identifierColumns = $class->getIdentifierColumnNames();
                    foreach ($assoc['joinTable']['joinColumns'] as $joinColumn) {
                        if (! in_array($joinColumn['referencedColumnName'], $identifierColumns, true)) {
                            $ce[] = "The referenced column name '" . $joinColumn['referencedColumnName'] . "' " .
                                "has to be a primary key column on the target entity class '" . $class->name . "'.";
                            break;
                        }
                    }

                    $identifierColumns = $targetMetadata->getIdentifierColumnNames();
                    foreach ($assoc['joinTable']['inverseJoinColumns'] as $inverseJoinColumn) {
                        if (! in_array($inverseJoinColumn['referencedColumnName'], $identifierColumns, true)) {
                            $ce[] = "The referenced column name '" . $inverseJoinColumn['referencedColumnName'] . "' " .
                                "has to be a primary key column on the target entity class '" . $targetMetadata->name . "'.";
                            break;
                        }
                    }

                    if (count($targetMetadata->getIdentifierColumnNames()) !== count($assoc['joinTable']['inverseJoinColumns'])) {
                        $ce[] = "The inverse join columns of the many-to-many table '" . $assoc['joinTable']['name'] . "' " .
                                "have to contain to ALL identifier columns of the target entity '" . $targetMetadata->name . "', " .
                                "however '" . implode(', ', array_diff($targetMetadata->getIdentifierColumnNames(), array_values($assoc['relationToTargetKeyColumns']))) .
                                "' are missing.";
                    }

                    if (count($class->getIdentifierColumnNames()) !== count($assoc['joinTable']['joinColumns'])) {
                        $ce[] = "The join columns of the many-to-many table '" . $assoc['joinTable']['name'] . "' " .
                                "have to contain to ALL identifier columns of the source entity '" . $class->name . "', " .
                                "however '" . implode(', ', array_diff($class->getIdentifierColumnNames(), array_values($assoc['relationToSourceKeyColumns']))) .
                                "' are missing.";
                    }
                } elseif ($assoc['type'] & ClassMetadata::TO_ONE) {
                    $identifierColumns = $targetMetadata->getIdentifierColumnNames();
                    foreach ($assoc['joinColumns'] as $joinColumn) {
                        if (! in_array($joinColumn['referencedColumnName'], $identifierColumns, true)) {
                            $ce[] = "The referenced column name '" . $joinColumn['referencedColumnName'] . "' " .
                                    "has to be a primary key column on the target entity class '" . $targetMetadata->name . "'.";
                        }
                    }

                    if (count($identifierColumns) !== count($assoc['joinColumns'])) {
                        $ids = [];

                        foreach ($assoc['joinColumns'] as $joinColumn) {
                            $ids[] = $joinColumn['name'];
                        }

                        $ce[] = "The join columns of the association '" . $assoc['fieldName'] . "' " .
                                "have to match to ALL identifier columns of the target entity '" . $targetMetadata->name . "', " .
                                "however '" . implode(', ', array_diff($targetMetadata->getIdentifierColumnNames(), $ids)) .
                                "' are missing.";
                    }
                }
            }

            if (isset($assoc['orderBy']) && $assoc['orderBy'] !== null) {
                foreach ($assoc['orderBy'] as $orderField => $orientation) {
                    if (! $targetMetadata->hasField($orderField) && ! $targetMetadata->hasAssociation($orderField)) {
                        $ce[] = 'The association ' . $class->name . '#' . $fieldName . ' is ordered by a foreign field ' .
                                $orderField . ' that is not a field on the target entity ' . $targetMetadata->name . '.';
                        continue;
                    }

                    if ($targetMetadata->isCollectionValuedAssociation($orderField)) {
                        $ce[] = 'The association ' . $class->name . '#' . $fieldName . ' is ordered by a field ' .
                                $orderField . ' on ' . $targetMetadata->name . ' that is a collection-valued association.';
                        continue;
                    }

                    if ($targetMetadata->isAssociationInverseSide($orderField)) {
                        $ce[] = 'The association ' . $class->name . '#' . $fieldName . ' is ordered by a field ' .
                                $orderField . ' on ' . $targetMetadata->name . ' that is the inverse side of an association.';
                        continue;
                    }
                }
            }
        }

        if (
            ! $class->isInheritanceTypeNone()
            && ! $class->isRootEntity()
            && ($class->reflClass !== null && ! $class->reflClass->isAbstract())
            && ! $class->isMappedSuperclass
            && array_search($class->name, $class->discriminatorMap, true) === false
        ) {
            $ce[] = "Entity class '" . $class->name . "' is part of inheritance hierarchy, but is " .
                "not mapped in the root entity '" . $class->rootEntityName . "' discriminator map. " .
                'All subclasses must be listed in the discriminator map.';
        }

        foreach ($class->subClasses as $subClass) {
            if (! in_array($class->name, class_parents($subClass), true)) {
                $ce[] = "According to the discriminator map class '" . $subClass . "' has to be a child " .
                        "of '" . $class->name . "' but these entities are not related through inheritance.";
            }
        }

        return $ce;
    }

    /**
     * Checks if the Database Schema is in sync with the current metadata state.
     *
     * @return bool
     */
    public function schemaInSyncWithMetadata()
    {
        return count($this->getUpdateSchemaList()) === 0;
    }

    /**
     * Returns the list of missing Database Schema updates.
     *
     * @return array<string>
     */
    public function getUpdateSchemaList(): array
    {
        $schemaTool = new SchemaTool($this->em);

        $allMetadata = $this->em->getMetadataFactory()->getAllMetadata();

        return $schemaTool->getUpdateSchemaSql($allMetadata, true);
    }

    /** @return list<string> containing the found issues */
    private function validatePropertiesTypes(ClassMetadataInfo $class): array
    {
        return array_values(
            array_filter(
                array_map(
                    /** @param FieldMapping $fieldMapping */
                    function (array $fieldMapping) use ($class): ?string {
                        $fieldName = $fieldMapping['fieldName'];
                        assert(isset($class->reflFields[$fieldName]));
                        $propertyType = $class->reflFields[$fieldName]->getType();

                        // If the field type is not a built-in type, we cannot check it
                        if (! Type::hasType($fieldMapping['type'])) {
                            return null;
                        }

                        // If the property type is not a named type, we cannot check it
                        if (! ($propertyType instanceof ReflectionNamedType) || $propertyType->getName() === 'mixed') {
                            return null;
                        }

                        $metadataFieldType = $this->findBuiltInType(Type::getType($fieldMapping['type']));

                        //If the metadata field type is not a mapped built-in type, we cannot check it
                        if ($metadataFieldType === null) {
                            return null;
                        }

                        $propertyType = $propertyType->getName();

                        // If the property type is the same as the metadata field type, we are ok
                        if ($propertyType === $metadataFieldType) {
                            return null;
                        }

                        if (
                            is_a($propertyType, BackedEnum::class, true)
                            && $metadataFieldType === (string) (new ReflectionEnum($propertyType))->getBackingType()
                        ) {
                            if (! isset($fieldMapping['enumType']) || $propertyType === $fieldMapping['enumType']) {
                                return null;
                            }

                            return sprintf(
                                "The field '%s#%s' has the property type '%s' that differs from the metadata enumType '%s'.",
                                $class->name,
                                $fieldName,
                                $propertyType,
                                $fieldMapping['enumType']
                            );
                        }

                        if (
                            $fieldMapping['type'] === 'json'
                            && in_array($propertyType, ['string', 'int', 'float', 'bool', 'true', 'false', 'null'], true)
                        ) {
                            return null;
                        }

                        return sprintf(
                            "The field '%s#%s' has the property type '%s' that differs from the metadata field type '%s' returned by the '%s' DBAL type.",
                            $class->name,
                            $fieldName,
                            $propertyType,
                            $metadataFieldType,
                            $fieldMapping['type']
                        );
                    },
                    $class->fieldMappings
                )
            )
        );
    }

    /**
     * The exact DBAL type must be used (no subclasses), since consumers of doctrine/orm may have their own
     * customization around field types.
     */
    private function findBuiltInType(Type $type): ?string
    {
        $typeName = get_class($type);

        return self::BUILTIN_TYPES_MAP[$typeName] ?? null;
    }
}
