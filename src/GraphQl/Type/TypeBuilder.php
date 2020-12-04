<?php

/*
 * This file is part of the API Platform project.
 *
 * (c) Kévin Dunglas <dunglas@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace ApiPlatform\Core\GraphQl\Type;

use ApiPlatform\Core\DataProvider\Pagination;
use ApiPlatform\Core\GraphQl\Serializer\ItemNormalizer;
use ApiPlatform\Core\Metadata\Resource\ResourceMetadata;
use GraphQL\Type\Definition\InputObjectType;
use GraphQL\Type\Definition\InterfaceType;
use GraphQL\Type\Definition\NonNull;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type as GraphQLType;
use GraphQL\Type\Definition\WrappingType;
use Psr\Container\ContainerInterface;
use Symfony\Component\PropertyInfo\Type;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Builds the GraphQL types.
 *
 * @experimental
 *
 * @author Alan Poulain <contact@alanpoulain.eu>
 */
final class TypeBuilder implements TypeBuilderInterface
{
    public const INTERFACE_POSTFIX = 'Interface';
    public const ITEM_POSTFIX = 'Item';
    public const COLLECTION_POSTFIX = 'Collection';
    public const DATA_POSTFIX = 'Data';

    private $typesContainer;
    private $defaultFieldResolver;
    private $fieldsBuilderLocator;
    private $translator;
    private $pagination;

    public function __construct(TypesContainerInterface $typesContainer, callable $defaultFieldResolver, ContainerInterface $fieldsBuilderLocator, Pagination $pagination, TranslatorInterface $translator)
    {
        $this->typesContainer = $typesContainer;
        $this->defaultFieldResolver = $defaultFieldResolver;
        $this->fieldsBuilderLocator = $fieldsBuilderLocator;
        $this->pagination = $pagination;
        $this->translator = $translator;
    }

    /**
     * {@inheritdoc}
     */
    public function getResourceObjectType(?string $resourceClass, ResourceMetadata $resourceMetadata, bool $input, ?string $queryName, ?string $mutationName, ?string $subscriptionName, bool $wrapped = false, int $depth = 0): GraphQLType
    {
        $shortName = $resourceMetadata->getShortName();

        if (null !== $mutationName) {
            $shortName = $mutationName.ucfirst($shortName);
        }
        if (null !== $subscriptionName) {
            $shortName = $subscriptionName.ucfirst($shortName).'Subscription';
        }
        if ($input) {
            $shortName .= 'Input';
        } elseif (null !== $mutationName || null !== $subscriptionName) {
            if ($depth > 0) {
                $shortName .= 'Nested';
            }
            $shortName .= 'Payload';
        }

        if ($resourceMetadata->isInterface()) {
            $shortName .= self::INTERFACE_POSTFIX;
        }

        if (!$resourceMetadata->isInterface() && ('item_query' === $queryName || 'collection_query' === $queryName)
            && $resourceMetadata->getGraphqlAttribute('item_query', 'normalization_context', [], true) !== $resourceMetadata->getGraphqlAttribute('collection_query', 'normalization_context', [], true)) {
            if ('item_query' === $queryName) {
                $shortName .= self::ITEM_POSTFIX;
            }
            if ('collection_query' === $queryName) {
                $shortName .= self::COLLECTION_POSTFIX;
            }
        }
        if ($wrapped && (null !== $mutationName || null !== $subscriptionName)) {
            $shortName .= self::DATA_POSTFIX;
        }

        if ($this->typesContainer->has($shortName)) {
            $resourceObjectType = $this->typesContainer->get($shortName);
            if (!($resourceObjectType instanceof ObjectType || $resourceObjectType instanceof NonNull || $resourceObjectType instanceof InterfaceType)) {
                throw new \LogicException(sprintf(
                    'Expected GraphQL type "%s" to be %s.',
                    $shortName,
                    implode('|', [ObjectType::class, NonNull::class, InterfaceType::class])
                ));
            }

            return $resourceObjectType;
        }

        $resourceObjectType = $resourceMetadata->isInterface()
            ? $this->buildResourceInterfaceType($resourceClass, $shortName, $resourceMetadata, $input, $queryName, $mutationName, $subscriptionName, $wrapped, $depth)
            : $this->buildResourceObjectType($resourceClass, $shortName, $resourceMetadata, $input, $queryName, $mutationName, $subscriptionName, $wrapped, $depth);
        $this->typesContainer->set($shortName, $resourceObjectType);

        return $resourceObjectType;
    }

    /**
     * {@inheritdoc}
     */
    public function getNodeInterface(): InterfaceType
    {
        if ($this->typesContainer->has('Node')) {
            $nodeInterface = $this->typesContainer->get('Node');
            if (!$nodeInterface instanceof InterfaceType) {
                throw new \LogicException(sprintf('Expected GraphQL type "Node" to be %s.', InterfaceType::class));
            }

            return $nodeInterface;
        }

        $nodeInterface = new InterfaceType([
            'name' => 'Node',
            'description' => 'A node, according to the Relay specification.',
            'fields' => [
                'id' => [
                    'type' => GraphQLType::nonNull(GraphQLType::id()),
                    'description' => 'The id of this node.',
                ],
            ],
            'resolveType' => function ($value) {
                if (!isset($value[ItemNormalizer::ITEM_RESOURCE_CLASS_KEY])) {
                    return null;
                }

                $shortName = (new \ReflectionClass($value[ItemNormalizer::ITEM_RESOURCE_CLASS_KEY]))->getShortName();

                return $this->typesContainer->has($shortName) ? $this->typesContainer->get($shortName) : null;
            },
        ]);

        $this->typesContainer->set('Node', $nodeInterface);

        return $nodeInterface;
    }

    /**
     * {@inheritdoc}
     */
    public function getResourcePaginatedCollectionType(GraphQLType $resourceType, string $resourceClass, string $operationName): GraphQLType
    {
        $shortName = $resourceType->name;

        if ($this->typesContainer->has("{$shortName}Connection")) {
            return $this->typesContainer->get("{$shortName}Connection");
        }

        $paginationType = $this->pagination->getGraphQlPaginationType($resourceClass, $operationName);

        $fields = 'cursor' === $paginationType ?
            $this->getCursorBasedPaginationFields($resourceType) :
            $this->getPageBasedPaginationFields($resourceType);

        $configuration = [
            'name' => "{$shortName}Connection",
            'description' => "Connection for $shortName.",
            'fields' => $fields,
        ];

        $resourcePaginatedCollectionType = new ObjectType($configuration);
        $this->typesContainer->set("{$shortName}Connection", $resourcePaginatedCollectionType);

        return $resourcePaginatedCollectionType;
    }

    /**
     * {@inheritdoc}
     */
    public function isCollection(Type $type): bool
    {
        return ($type->isCollection() && ($collectionValueType = $type->getCollectionValueType()) && null !== $collectionValueType->getClassName()) || $this->isArrayOfObjects($type);
    }

    private function getCursorBasedPaginationFields(GraphQLType $resourceType): array
    {
        $shortName = $resourceType->name;

        $edgeObjectTypeConfiguration = [
            'name' => "{$shortName}Edge",
            'description' => "Edge of $shortName.",
            'fields' => [
                'node' => $resourceType,
                'cursor' => GraphQLType::nonNull(GraphQLType::string()),
            ],
        ];
        $edgeObjectType = new ObjectType($edgeObjectTypeConfiguration);
        $this->typesContainer->set("{$shortName}Edge", $edgeObjectType);

        $pageInfoObjectTypeConfiguration = [
            'name' => "{$shortName}PageInfo",
            'description' => 'Information about the current page.',
            'fields' => [
                'endCursor' => GraphQLType::string(),
                'startCursor' => GraphQLType::string(),
                'hasNextPage' => GraphQLType::nonNull(GraphQLType::boolean()),
                'hasPreviousPage' => GraphQLType::nonNull(GraphQLType::boolean()),
            ],
        ];
        $pageInfoObjectType = new ObjectType($pageInfoObjectTypeConfiguration);
        $this->typesContainer->set("{$shortName}PageInfo", $pageInfoObjectType);

        return [
            'edges' => GraphQLType::listOf($edgeObjectType),
            'pageInfo' => GraphQLType::nonNull($pageInfoObjectType),
            'totalCount' => GraphQLType::nonNull(GraphQLType::int()),
        ];
    }

    private function getPageBasedPaginationFields(GraphQLType $resourceType): array
    {
        $shortName = $resourceType->name;

        $paginationInfoObjectTypeConfiguration = [
            'name' => "{$shortName}PaginationInfo",
            'description' => 'Information about the pagination.',
            'fields' => [
                'itemsPerPage' => GraphQLType::nonNull(GraphQLType::int()),
                'lastPage' => GraphQLType::nonNull(GraphQLType::int()),
                'totalCount' => GraphQLType::nonNull(GraphQLType::int()),
            ],
        ];
        $paginationInfoObjectType = new ObjectType($paginationInfoObjectTypeConfiguration);
        $this->typesContainer->set("{$shortName}PaginationInfo", $paginationInfoObjectType);

        return [
            'collection' => GraphQLType::listOf($resourceType),
            'paginationInfo' => GraphQLType::nonNull($paginationInfoObjectType),
        ];
    }

    private function isArrayOfObjects(Type $type): bool
    {
        return Type::BUILTIN_TYPE_ARRAY === $type->getBuiltinType() &&
            (null !== $collectionValue = $type->getCollectionValueType()) &&
            Type::BUILTIN_TYPE_OBJECT === $collectionValue->getBuiltinType();
    }

    private function buildResourceObjectType(?string $resourceClass, string $shortName, ResourceMetadata $resourceMetadata, bool $input, ?string $queryName, ?string $mutationName, ?string $subscriptionName, bool $wrapped, int $depth)
    {
        $ioMetadata = $resourceMetadata->getGraphqlAttribute($subscriptionName ?? $mutationName ?? $queryName, $input ? 'input' : 'output', null, true);
        if (null !== $ioMetadata && \array_key_exists('class', $ioMetadata) && null !== $ioMetadata['class']) {
            $resourceClass = $ioMetadata['class'];
        }

        $wrapData = !$wrapped && (null !== $mutationName || null !== $subscriptionName) && !$input && $depth < 1;

        $interfaceTypes = ($interfaces = $resourceMetadata->getImplements())
            ? $this->getInterfaceTypes($interfaces)
            : [];

        $configuration = [
            'name' => $shortName,
            'description' => $this->translator->trans($resourceMetadata->getDescription()),
            'resolveField' => $this->defaultFieldResolver,
            'fields' => function () use ($resourceClass, $resourceMetadata, $input, $queryName, $mutationName, $subscriptionName, $wrapData, $depth, $ioMetadata) {
                if ($wrapData) {
                    $queryNormalizationContext = $resourceMetadata->getGraphqlAttribute($queryName ?? '', 'normalization_context', [], true);
                    $mutationNormalizationContext = $resourceMetadata->getGraphqlAttribute($mutationName ?? $subscriptionName ?? '', 'normalization_context', [], true);
                    // Use a new type for the wrapped object only if there is a specific normalization context for the mutation.
                    // If not, use the query type in order to ensure the client cache could be used.
                    $useWrappedType = $queryNormalizationContext !== $mutationNormalizationContext;

                    $fields = [
                        lcfirst($resourceMetadata->getShortName()) => $useWrappedType ?
                            $this->getResourceObjectType($resourceClass, $resourceMetadata, $input, $queryName, $mutationName, $subscriptionName, true, $depth) :
                            $this->getResourceObjectType($resourceClass, $resourceMetadata, $input, $queryName ?? 'item_query', null, null, true, $depth),
                    ];

                    if (null !== $subscriptionName) {
                        $fields['clientSubscriptionId'] = GraphQLType::string();
                        if ($resourceMetadata->getAttribute('mercure', false)) {
                            $fields['mercureUrl'] = GraphQLType::string();
                        }

                        return $fields;
                    }

                    return $fields + ['clientMutationId' => GraphQLType::string()];
                }


                $fieldsBuilder = $this->fieldsBuilderLocator->get('api_platform.graphql.fields_builder');

                $fields = $fieldsBuilder->getResourceObjectTypeFields($resourceClass, $resourceMetadata, $input, $queryName, $mutationName, $subscriptionName, $depth, $ioMetadata);

                if ($input && null !== $mutationName && null !== $mutationArgs = $resourceMetadata->getGraphql()[$mutationName]['args'] ?? null) {
                    return $fieldsBuilder->resolveResourceArgs($mutationArgs, $mutationName, $resourceMetadata->getShortName()) + ['clientMutationId' => $fields['clientMutationId']];
                }

                return $fields;
            },
            'interfaces' => $wrapData ? [] : \array_merge([$this->getNodeInterface()], $interfaceTypes),
        ];

        return $input ? GraphQLType::nonNull(new InputObjectType($configuration)) : new ObjectType($configuration);
    }

    private function buildResourceInterfaceType(?string $resourceClass, string $shortName, ResourceMetadata $resourceMetadata, bool $input, ?string $queryName, ?string $mutationName, ?string $subscriptionName, bool $wrapped, int $depth): ?InterfaceType
    {
        static $fieldsBuilder;

        $ioMetadata = $resourceMetadata->getGraphqlAttribute($subscriptionName ?? $mutationName ?? $queryName, $input ? 'input' : 'output', null, true);
        if (null !== $ioMetadata && \array_key_exists('class', $ioMetadata) && null !== $ioMetadata['class']) {
            $resourceClass = $ioMetadata['class'];
        }

        $wrapData = !$wrapped && null !== $mutationName && !$input && $depth < 1;

        if ($this->typesContainer->has($shortName)) {
            $resourceInterface = $this->typesContainer->get($shortName);
            if (!$resourceInterface instanceof InterfaceType) {
                throw new \UnexpectedValueException(sprintf('Expected GraphQL type "%s" to be %s.', $shortName, InterfaceType::class));
            }

            return $resourceInterface;
        }

        $fieldsBuilder = $fieldsBuilder ?? $this->fieldsBuilderLocator->get('api_platform.graphql.fields_builder');

        $resourceInterface = new InterfaceType([
            'name' => $shortName,
            'description' => $this->translator->trans($resourceMetadata->getDescription()),
            'fields' => function () use ($resourceClass, $resourceMetadata, $input, $mutationName, $queryName, $subscriptionName, $wrapData, $depth, $ioMetadata, $fieldsBuilder) {
                if ($wrapData) {
                    return [
                        lcfirst($resourceMetadata->getShortName()) => $this->getResourceObjectType($resourceClass, $resourceMetadata, $input, $queryName, null, $subscriptionName, true, $depth),
                    ];
                }

                return $fieldsBuilder->getResourceObjectTypeFields($resourceClass, $resourceMetadata, $input, $queryName, null, $depth, $ioMetadata);
            },
            'resolveType' => function ($value, $context, $info) {
                if (!isset($value[ItemNormalizer::ITEM_RESOURCE_CLASS_KEY])) {
                    throw new \UnexpectedValueException('Resource class was not passed. Interface type can not be used.');
                }

                $shortName = (new \ReflectionClass($value[ItemNormalizer::ITEM_RESOURCE_CLASS_KEY]))->getShortName();

                if (!$this->typesContainer->has($shortName)) {
                    $shortName .= self::ITEM_POSTFIX;
                    if (!$this->typesContainer->has($shortName)) {
                        throw new \UnexpectedValueException("Type with name $shortName can not be found");
                    }
                }

                $type = $this->typesContainer->get($shortName);
                if (!isset($type->config['interfaces'])) {
                    throw new \UnexpectedValueException("Type \"$shortName\" doesn't implement any interface.");
                }

                foreach ($type->config['interfaces'] as $interface) {
                    $returnType = $info->returnType instanceof WrappingType
                        ? $info->returnType->getWrappedType()
                        : $info->returnType;

                    if ($interface === $returnType) {
                        return $type;
                    }
                }

                throw new \UnexpectedValueException("Type \"$type\" must implement interface \"$info->returnType\"");
            },
        ]);

        $this->typesContainer->set($shortName, $resourceInterface);

        return $resourceInterface;
    }

    private function getInterfaceTypes(array $resources): array
    {
        $interfaceTypes = [];
        foreach ($resources as $resourceClass) {
            try {
                $reflection = new \ReflectionClass($resourceClass);
            } catch (\ReflectionException $e) {
                throw new \UnexpectedValueException("Class $resourceClass can't be found.");
            }

            $typeName = $reflection->getShortName().self::INTERFACE_POSTFIX;
            $interfaceTypes[] = $this->typesContainer->has($typeName) ? [$this->typesContainer->get($typeName)] : [];
        }

        return \array_merge(...$interfaceTypes);
    }
}
