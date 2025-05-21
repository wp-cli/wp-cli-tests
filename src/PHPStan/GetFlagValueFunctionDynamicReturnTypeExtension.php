<?php

/**
 * Set return type of \WP_CLI\Utils\get_flag_value().
 */

declare(strict_types=1);

namespace WP_CLI\Tests\PHPStan;

use PhpParser\Node\Expr\FuncCall;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\FunctionReflection;
use PHPStan\Type\Constant\ConstantStringType;
use PHPStan\Type\Constant\ConstantArrayType;
use PHPStan\Type\Type;
use PHPStan\Type\TypeCombinator;

use function count;

final class GetFlagValueFunctionDynamicReturnTypeExtension implements \PHPStan\Type\DynamicFunctionReturnTypeExtension {

	public function isFunctionSupported( FunctionReflection $functionReflection ): bool {
		return $functionReflection->getName() === 'WP_CLI\Utils\get_flag_value';
	}

	public function getTypeFromFunctionCall(
		FunctionReflection $functionReflection,
		FuncCall $functionCall,
		Scope $scope
	): Type {
		$args = $functionCall->getArgs();

		// Ensure we have at least two arguments: $assoc_args and $flag
		if ( count( $args ) < 2 ) {
			// Not enough arguments, fall back to the function's declared return type or mixed
			return $functionReflection->getVariants()[0]->getReturnType();
		}

		$assocArgsType = $scope->getType( $args[0]->value );
		$flagArgType   = $scope->getType( $args[1]->value );

		// Determine the default type
		$defaultType = isset( $args[2] ) ? $scope->getType( $args[2]->value ) : new \PHPStan\Type\NullType();

		// We can only be precise if $flag is a constant string
		if ( ! $flagArgType->isConstantValue()->yes() || ( ! $flagArgType->toInteger() instanceof ConstantIntegerType && ! $flagArgType->toString() instanceof ConstantStringType ) ) {
			// If $flag is not a constant string, we cannot know which key to check.
			// The return type will be a union of the array's possible value types and the default type.
			if ( $assocArgsType instanceof ConstantArrayType ) {
				$valueTypes = [];
				foreach ( $assocArgsType->getValueTypes() as $valueType ) {
					$valueTypes[] = $valueType;
				}
				if ( count( $valueTypes ) > 0 ) {
					return TypeCombinator::union( ...$valueTypes );
				}
				return $defaultType; // Array is empty or has no predictable value types
			} elseif ( $assocArgsType instanceof \PHPStan\Type\ArrayType ) {
				return TypeCombinator::union( $assocArgsType->getItemType(), $defaultType );
			}
			// Fallback if $assocArgsType isn't a well-defined array type
			return new MixedType();
		}

		$flagValue = $flagArgType->getValue();

		// If $assoc_args is a constant array, we can check if the key exists
		if ( $assocArgsType->isConstantValue()->yes() && $assocArgsType->toArray() instanceof ConstantArrayType ) {
			$keyTypes          = $assocArgsType->getKeyTypes();
			$valueTypes        = $assocArgsType->getValueTypes();
			$resolvedValueType = null;

			foreach ( $keyTypes as $index => $keyType ) {
				if ( $keyType->isConstantValue()->yes() && $keyType->toString() instanceof ConstantStringType && $keyType->getValue() === $flagValue ) {
					$resolvedValueType = $valueTypes[ $index ];
					break;
				}
			}

			if ( null !== $resolvedValueType ) {
				// Key definitely exists, return its type
				return $resolvedValueType;
			} else {
				// Key definitely does not exist, return default type
				return $defaultType;
			}
		}

		// If $assocArgsType is a general ArrayType, we can't be sure if the specific flag exists.
		// The function's logic is: isset( $assoc_args[ $flag ] ) ? $assoc_args[ $flag ] : $default;
		// So, it's a union of the potential value type from the array and the default type.
		if ( $assocArgsType->isArray()->yes() ) {
			// We don't know IF the key $flagValue exists.
			// PHPStan's ArrayType has an itemType which represents the type of values in the array.
			// This is the best we can do for a generic array.
			return TypeCombinator::union( $assocArgsType->getItemType(), $defaultType );
		}

		// Fallback for other types of $assocArgsType or if we can't determine.
		// This should ideally be the union of what the array could contain for that key and the default.
		// For simplicity, if not a ConstantArrayType or ArrayType, return mixed or a broad union.
		// In a real-world scenario with more complex types, you might query $assocArgsType->getOffsetValueType(new ConstantStringType($flagValue))
		// and then union with $defaultType.
		$offsetValueType = $assocArgsType->getOffsetValueType( new ConstantStringType( $flagValue ) );
		if ( ! $offsetValueType instanceof MixedType || $offsetValueType->isExplicitMixed() ) {
			return TypeCombinator::union( $offsetValueType, $defaultType );
		}

		return new MixedType(); // Default fallback
	}
}
