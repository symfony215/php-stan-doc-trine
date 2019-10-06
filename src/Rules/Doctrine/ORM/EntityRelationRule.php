<?php declare(strict_types = 1);

namespace PHPStan\Rules\Doctrine\ORM;

use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\MissingPropertyFromReflectionException;
use PHPStan\Rules\Rule;
use PHPStan\Type\Doctrine\ObjectMetadataResolver;
use PHPStan\Type\IterableType;
use PHPStan\Type\MixedType;
use PHPStan\Type\ObjectType;
use PHPStan\Type\TypeCombinator;
use PHPStan\Type\VerbosityLevel;
use function sprintf;

class EntityRelationRule implements Rule
{

	/** @var \PHPStan\Type\Doctrine\ObjectMetadataResolver */
	private $objectMetadataResolver;

	public function __construct(ObjectMetadataResolver $objectMetadataResolver)
	{
		$this->objectMetadataResolver = $objectMetadataResolver;
	}

	public function getNodeType(): string
	{
		return Node\Stmt\PropertyProperty::class;
	}

	/**
	 * @param \PhpParser\Node\Stmt\PropertyProperty $node
	 * @param \PHPStan\Analyser\Scope $scope
	 * @return string[]
	 */
	public function processNode(Node $node, Scope $scope): array
	{
		$class = $scope->getClassReflection();
		if ($class === null) {
			return [];
		}

		$objectManager = $this->objectMetadataResolver->getObjectManager();
		if ($objectManager === null) {
			return [];
		}

		$className = $class->getName();
		if ($objectManager->getMetadataFactory()->isTransient($className)) {
			return [];
		}

		/** @var \Doctrine\ORM\Mapping\ClassMetadataInfo $metadata */
		$metadata = $objectManager->getClassMetadata($className);
		$classMetadataInfo = 'Doctrine\ORM\Mapping\ClassMetadataInfo';
		if (!$metadata instanceof $classMetadataInfo) {
			return [];
		}

		$propertyName = (string) $node->name;
		try {
			$property = $class->getNativeProperty($propertyName);
		} catch (MissingPropertyFromReflectionException $e) {
			return [];
		}

		if (!isset($metadata->associationMappings[$propertyName])) {
			return [];
		}
		$associationMapping = $metadata->associationMappings[$propertyName];

		$columnType = null;
		if ((bool) ($associationMapping['type'] & 3)) { // ClassMetadataInfo::TO_ONE
			$columnType = new ObjectType($associationMapping['targetEntity']);
			if ($associationMapping['joinColumns'][0]['nullable'] ?? true) {
				$columnType = TypeCombinator::addNull($columnType);
			}
		} elseif ((bool) ($associationMapping['type'] & 12)) { // ClassMetadataInfo::TO_MANY
			$columnType = TypeCombinator::intersect(
				new ObjectType('Doctrine\Common\Collections\Collection'),
				new IterableType(new MixedType(), new ObjectType($associationMapping['targetEntity']))
			);
		}

		$errors = [];
		if ($columnType !== null) {
			if (!$property->getWritableType()->isSuperTypeOf($columnType)->yes()) {
				$errors[] = sprintf(
					'Property %s::$%s type mapping mismatch: database can contain %s but property expects %s.',
					$className,
					$propertyName,
					$columnType->describe(VerbosityLevel::typeOnly()),
					$property->getWritableType()->describe(VerbosityLevel::typeOnly())
				);
			}
			if (!$columnType->isSuperTypeOf($property->getReadableType())->yes()) {
				$errors[] = sprintf(
					'Property %s::$%s type mapping mismatch: property can contain %s but database expects %s.',
					$className,
					$propertyName,
					$property->getReadableType()->describe(VerbosityLevel::typeOnly()),
					$columnType->describe(VerbosityLevel::typeOnly())
				);
			}
		}

		return $errors;
	}

}
