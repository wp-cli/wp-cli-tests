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
use PHPStan\Type\Type;
use PHPStan\Type\MixedType;
use PHPStan\Type\NullType;
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

		if ( count( $args ) < 2 ) {
			// Not enough arguments, fall back to the function's declared return type.
			return $functionReflection->getVariants()[0]->getReturnType();
		}

		$assocArgsType = $scope->getType( $args[0]->value );
		$flagArgType   = $scope->getType( $args[1]->value );

		// 2. Determine the default type
		$defaultType = isset( $args[2] ) ? $scope->getType( $args[2]->value ) : new NullType();

		$flagConstantStrings = $flagArgType->getConstantStrings();

		if ( count( $flagConstantStrings ) !== 1 ) {
			// Flag name is dynamic or not a string.
			// Return type is a union of all possible values in $assoc_args + default type.
			return $this->getDynamicFlagFallbackType( $assocArgsType, $defaultType );
		}

		// 4. Flag is a single constant string.
		$flagValue = $flagConstantStrings[0]->getValue();

		// 4.a. If $assoc_args is a single ConstantArray:
		$assocConstantArrays = $assocArgsType->getConstantArrays();
		if ( count( $assocConstantArrays ) === 1 ) {
			$assocArgsConstantArray = $assocConstantArrays[0];
			$keyTypes               = $assocArgsConstantArray->getKeyTypes();
			$valueTypes             = $assocArgsConstantArray->getValueTypes();
			$resolvedValueType      = null;

			foreach ( $keyTypes as $index => $keyType ) {
				$keyConstantStrings = $keyType->getConstantStrings();
				if ( count( $keyConstantStrings ) === 1 && $keyConstantStrings[0]->getValue() === $flagValue ) {
					$resolvedValueType = $valueTypes[ $index ];
					break;
				}
			}

			if ( null !== $resolvedValueType ) {
				// Key definitely exists and has a resolved type.
				return $resolvedValueType;
			} else {
				// Key definitely does not exist in this constant array.
				return $defaultType;
			}
		}

		// 4.b. $assoc_args is not a single ConstantArray (but $flagValue is known):
		// Use getOffsetValueType for other array-like types.
		$valueForKeyType = $assocArgsType->getOffsetValueType( new ConstantStringType( $flagValue ) );

		// The key might exist, or its presence is unknown.
		// The function returns $assoc_args[$flag] if set, otherwise $default.
		return TypeCombinator::union( $valueForKeyType, $defaultType );
	}

	/**
	 * Handles the case where the flag name is not a single known constant string.
	 * The return type is a union of all possible values in $assocArgsType and $defaultType.
	 */
	private function getDynamicFlagFallbackType( Type $assocArgsType, Type $defaultType ): Type {
		$possibleValueTypes = [];

		$assocConstantArrays = $assocArgsType->getConstantArrays();
		if ( count( $assocConstantArrays ) === 1 ) { // It's one specific constant array
			$constantArray = $assocConstantArrays[0];
			if ( count( $constantArray->getValueTypes() ) > 0 ) {
				$possibleValueTypes = $constantArray->getValueTypes();
			}
		} else {
			$possibleValueTypes[] = new MixedType();
		}

		if ( empty( $possibleValueTypes ) ) {
			return $defaultType;
		}

		return TypeCombinator::union( $defaultType, ...$possibleValueTypes );
	}
}
