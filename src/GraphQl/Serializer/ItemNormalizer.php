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

namespace ApiPlatform\Core\GraphQl\Serializer;

use ApiPlatform\Core\Metadata\Property\PropertyMetadata;
use ApiPlatform\Core\Serializer\ItemNormalizer as BaseItemNormalizer;

/**
 * GraphQL normalizer.
 *
 * @author Kévin Dunglas <dunglas@gmail.com>
 */
final class ItemNormalizer extends BaseItemNormalizer
{
    const FORMAT = 'graphql';
    const ITEM_KEY = '#item';

    /**
     * {@inheritdoc}
     */
    public function supportsNormalization($data, $format = null)
    {
        return self::FORMAT === $format && parent::supportsNormalization($data, $format);
    }

    /**
     * {@inheritdoc}
     */
    public function normalize($object, $format = null, array $context = [])
    {
        $data = parent::normalize($object, $format, $context);
        $data[self::ITEM_KEY] = \serialize($this->cloneToEmptyObject($object)); // calling serialize prevent weird normalization process done by Webonyx's GraphQL PHP

        return $data;
    }

    /**
     * {@inheritdoc}
     */
    protected function normalizeCollectionOfRelations(PropertyMetadata $propertyMetadata, $attributeValue, string $resourceClass, string $format = null, array $context): array
    {
        // to-many are handled directly by the GraphQL resolver
        return [];
    }

    /**
     * {@inheritdoc}
     */
    public function supportsDenormalization($data, $type, $format = null)
    {
        return self::FORMAT === $format && parent::supportsDenormalization($data, $type, $format);
    }

    /**
     * {@inheritdoc}
     */
    protected function getAllowedAttributes($classOrObject, array $context, $attributesAsString = false)
    {
        $allowedAttributes = parent::getAllowedAttributes($classOrObject, $context, $attributesAsString);

        if (($context['api_denormalize'] ?? false) && false !== ($indexId = \array_search('id', $allowedAttributes, true))) {
            $allowedAttributes[] = '_id';
            \array_splice($allowedAttributes, (int) $indexId, 1);
        }

        return $allowedAttributes;
    }

    /**
     * {@inheritdoc}
     */
    protected function setAttributeValue($object, $attribute, $value, $format = null, array $context = [])
    {
        if ('_id' === $attribute) {
            $attribute = 'id';
        }

        parent::setAttributeValue($object, $attribute, $value, $format, $context);
    }

	/**
	 * Return object of passed type with all empty fields except id.
	 * Necessary to speed up serialization/deserialization on Webonyx side.
	 *
	 * @param object $originalObject
	 *
	 * @return object
	 */
	private function cloneToEmptyObject($originalObject)
	{
		$class = \get_class($originalObject);
		$emptyObject = new $class;

		try {
			$reflectionClass = new \ReflectionClass($class);
			$idProperty = $reflectionClass->getProperty('id');

			// For audit entities
			if ($reflectionClass->hasProperty('revision')) {
				$revProperty = $reflectionClass->getProperty('revision');
			}
		} catch (\ReflectionException $e) {
			return $originalObject;
		}

		$idProperty->setAccessible(true);
		$idProperty->setValue($emptyObject, $idProperty->getValue($originalObject));

		if (isset($revProperty)) {
			$revProperty->setAccessible(true);
			$revProperty->setValue($emptyObject, $revProperty->getValue($originalObject));
		}

		return $emptyObject;
	}
}
