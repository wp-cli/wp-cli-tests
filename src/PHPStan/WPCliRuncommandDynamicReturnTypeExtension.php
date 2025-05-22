<?php

declare(strict_types=1);

namespace WP_CLI\Tests\PHPStan;

use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Arg;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\MethodReflection;
use PHPStan\Type\Type;
use PHPStan\Type\DynamicStaticMethodReturnTypeExtension;
use PHPStan\Type\NullType;
use PHPStan\Type\StringType;
use PHPStan\Type\IntegerType;
use PHPStan\Type\MixedType;
use PHPStan\Type\ArrayType;
use PHPStan\Type\ObjectWithoutClassType;
use PHPStan\Type\Constant\ConstantStringType;
use PHPStan\Type\Constant\ConstantBooleanType;
use PHPStan\Type\TypeCombinator;
use PHPStan\Type\ObjectShapeType;
use PHPStan\Type\NeverType;

class WPCliRuncommandDynamicReturnTypeExtension implements DynamicStaticMethodReturnTypeExtension {

	public function getClass(): string {
		return 'WP_CLI';
	}

	public function isStaticMethodSupported( MethodReflection $methodReflection ): bool {
		return $methodReflection->getName() === 'runcommand';
	}

	public function getTypeFromStaticMethodCall(
		MethodReflection $methodReflection,
		StaticCall $methodCall,
		Scope $scope
	): Type {
		$args = $methodCall->getArgs();

		/** @var ConstantBooleanType|ConstantStringType $returnOption */
		$returnOption = new ConstantBooleanType( true );
		/** @var ConstantBooleanType|ConstantStringType $parseOption */
		$parseOption = new ConstantBooleanType( false );
		/** @var ConstantBooleanType $exitOnErrorOption */
		$exitOnErrorOption = new ConstantBooleanType( true );

		$optionsAreStaticallyKnown = true;

		if ( isset( $args[1] ) && $args[1] instanceof Arg ) {
			$optionsNode = $args[1]->value;
			$optionsType = $scope->getType( $optionsNode );

			if ( $optionsType->isConstantArray()->yes() ) {
				$constantArrayTypes = $optionsType->getConstantArrays();
				if ( count( $constantArrayTypes ) === 1 ) {
					$constantArrayType = $constantArrayTypes[0];
					$keyTypes          = $constantArrayType->getKeyTypes();
					$valueTypes        = $constantArrayType->getValueTypes();

					foreach ( $keyTypes as $i => $keyType ) {
						$keyConstantStrings = $keyType->getConstantStrings();
						if ( count( $keyConstantStrings ) !== 1 ) {
							$optionsAreStaticallyKnown = false;
							break;
						}
						$keyName                = $keyConstantStrings[0]->getValue();
						$currentOptionValueType = $valueTypes[ $i ];

						switch ( $keyName ) {
							case 'return':
								$valueConstantStrings = $currentOptionValueType->getConstantStrings();
								if ( count( $valueConstantStrings ) === 1 && $currentOptionValueType->isScalar()->yes() ) {
									$returnOption = $valueConstantStrings[0];
								} elseif ( $currentOptionValueType->isTrue()->yes() ) {
									$returnOption = new ConstantBooleanType( true );
								} elseif ( $currentOptionValueType->isFalse()->yes() ) {
									$returnOption = new ConstantBooleanType( false );
								} else {
									$optionsAreStaticallyKnown = false;
								}
								break;
							case 'parse':
								$valueConstantStrings = $currentOptionValueType->getConstantStrings();
								$isExactlyJsonString  = ( count( $valueConstantStrings ) === 1 && $valueConstantStrings[0]->getValue() === 'json' && $currentOptionValueType->isScalar()->yes() );

								if ( $isExactlyJsonString ) {
									$parseOption = $valueConstantStrings[0];
								} elseif ( $$currentOptionValueType->isFalse()->yes() ) {
									$parseOption = new ConstantBooleanType( false );
								} else {
									// Not a single, clear constant we handle for a "known" path
									$parseOption               = new ConstantBooleanType( false ); // Default effect
									$optionsAreStaticallyKnown = false;
								}
								break;
							case 'exit_error':
								if ( $currentOptionValueType->isTrue()->yes() ) {
									$exitOnErrorOption = new ConstantBooleanType( true );
								} elseif ( $currentOptionValueType->isFalse()->yes() ) {
									$exitOnErrorOption = new ConstantBooleanType( false );
								} else {
									$optionsAreStaticallyKnown = false;
								}
								break;
						}
						if ( ! $optionsAreStaticallyKnown ) {
							break;
						}
					}
				} else {
					$optionsAreStaticallyKnown = false;
				}
			} else {
				$optionsAreStaticallyKnown = false;
			}
		}

		if ( ! $optionsAreStaticallyKnown ) {
			return TypeCombinator::union( $this->getFallbackUnionTypeWithoutNever(), new NeverType() );
		}

		$normalReturnType = $this->determineNormalReturnType( $returnOption, $parseOption );

		if ( $exitOnErrorOption->getValue() === true ) {
			if ( $normalReturnType instanceof NeverType ) {
				return $normalReturnType;
			}
			return TypeCombinator::union( $normalReturnType, new NeverType() );
		}

		return $normalReturnType;
	}

	/**
	 * @param ConstantBooleanType|ConstantStringType $returnOptionValue
	 * @param ConstantBooleanType|ConstantStringType $parseOptionValue
	 */
	private function determineNormalReturnType( Type $returnOptionValue, Type $parseOptionValue ): Type {
		$returnConstantStrings = $returnOptionValue->getConstantStrings();
		$return_val            = count( $returnConstantStrings ) === 1 ? $returnConstantStrings[0]->getValue() : null;

		$parseConstantStrings = $parseOptionValue->getConstantStrings();
		$parseIsJson          = count( $parseConstantStrings ) === 1 && $parseConstantStrings[0]->getValue() === 'json';

		if ( 'all' === $return_val ) {
			return $this->createAllObjectType();
		}
		if ( 'return_code' === $return_val ) {
			return new IntegerType();
		}
		if ( 'stderr' === $return_val ) {
			return new StringType();
		}
		if ( $returnOptionValue->isTrue()->yes() || 'stdout' === $return_val ) {
			if ( $parseIsJson ) {
				return TypeCombinator::union(
					new ArrayType( new MixedType(), new MixedType() ),
					new NullType()
				);
			}
			return new StringType();
		}
		if ( $returnOptionValue->isFalse()->yes() ) {
			return new NullType();
		}

		return new MixedType( true );
	}

	private function createAllObjectType(): Type {
		$propertyTypes      = [
			'stdout'      => new StringType(),
			'stderr'      => new StringType(),
			'return_code' => new IntegerType(),
		];
		$optionalProperties = [];
		return new ObjectShapeType( $propertyTypes, $optionalProperties );
	}

	private function getFallbackUnionTypeWithoutNever(): Type {
		return TypeCombinator::union(
			new StringType(),
			new IntegerType(),
			$this->createAllObjectType(),
			new ArrayType( new MixedType(), new MixedType() ),
			new ObjectWithoutClassType(),
			new NullType()
		);
	}
}
