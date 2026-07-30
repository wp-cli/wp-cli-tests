<?php

declare(strict_types=1);

namespace WP_CLI\Tests\PHPStan;

use PhpParser\Node\Expr\StaticCall;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\MethodReflection;
use PHPStan\Type\ArrayType;
use PHPStan\Type\BooleanType;
use PHPStan\Type\Constant\ConstantArrayType;
use PHPStan\Type\Constant\ConstantBooleanType;
use PHPStan\Type\Constant\ConstantStringType;
use PHPStan\Type\DynamicStaticMethodReturnTypeExtension;
use PHPStan\Type\IntegerType;
use PHPStan\Type\NullType;
use PHPStan\Type\StringType;
use PHPStan\Type\Type;
use PHPStan\Type\TypeCombinator;

use function count;
use function array_values;

final class WPCliGetConfigDynamicReturnTypeExtension implements DynamicStaticMethodReturnTypeExtension {

	public function getClass(): string {
		return 'WP_CLI';
	}

	public function isStaticMethodSupported( MethodReflection $methodReflection ): bool {
		return $methodReflection->getName() === 'get_config';
	}

	public function getTypeFromStaticMethodCall(
		MethodReflection $methodReflection,
		StaticCall $methodCall,
		Scope $scope
	): Type {
		$args = $methodCall->getArgs();

		if ( count( $args ) === 0 ) {
			return $this->getGlobalConfigArrayType();
		}

		$keyType = $scope->getType( $args[0]->value );

		if ( $keyType->isNull()->yes() ) {
			return $this->getGlobalConfigArrayType();
		}

		$constantStrings = $keyType->getConstantStrings();
		if ( count( $constantStrings ) > 0 ) {
			$types     = [];
			$configMap = $this->getConfigMap();

			foreach ( $constantStrings as $constantString ) {
				$key = $constantString->getValue();
				if ( isset( $configMap[ $key ] ) ) {
					$types[] = $configMap[ $key ];
				} else {
					$types[] = new NullType();
				}
			}

			if ( count( $types ) > 0 ) {
				$returnType = TypeCombinator::union( ...$types );
				if ( $keyType->isNull()->maybe() ) {
					$returnType = TypeCombinator::union( $returnType, $this->getGlobalConfigArrayType() );
				}
				return $returnType;
			}
		}

		// Fallback for non-constant string or unknown types
		$fallback = TypeCombinator::addNull( $this->getFallbackValueType() );
		if ( $keyType->isNull()->maybe() ) {
			return TypeCombinator::union( $fallback, $this->getGlobalConfigArrayType() );
		}

		return $fallback;
	}

	/**
	 * @return array<string, Type>
	 */
	private function getConfigMap(): array {
		$stringType       = new StringType();
		$stringOrNull     = TypeCombinator::addNull( $stringType );
		$stringList       = new ArrayType( new IntegerType(), $stringType );
		$boolType         = new BooleanType();
		$trueOrStringList = TypeCombinator::union( new ConstantBooleanType( true ), $stringList );
		$stringOrTrue     = TypeCombinator::union( $stringType, new ConstantBooleanType( true ) );
		$stringOrFalse    = TypeCombinator::union( $stringType, new ConstantBooleanType( false ) );

		return [
			'path'              => $stringOrNull,
			'ssh'               => $stringOrNull,
			'ssh-args'          => $stringList,
			'http'              => $stringOrNull,
			'url'               => $stringOrNull,
			'user'              => $stringOrNull,
			'skip-plugins'      => $trueOrStringList,
			'skip-themes'       => $trueOrStringList,
			'skip-packages'     => $boolType,
			'require'           => $stringList,
			'exec'              => $stringList,
			'context'           => $stringType,
			'debug'             => $stringOrTrue,
			'prompt'            => $stringOrFalse,
			'quiet'             => $boolType,
			'apache_modules'    => $stringList,
			'assume-https'      => $boolType,
			'color'             => TypeCombinator::union( $stringType, $boolType ),
			'disabled_commands' => $stringList,
			'locale'            => $stringType,
			'allow-root'        => $boolType,
			'alias'             => $stringType,
		];
	}

	private function getGlobalConfigArrayType(): Type {
		$keyTypes   = [];
		$valueTypes = [];

		foreach ( $this->getConfigMap() as $key => $type ) {
			$keyTypes[]   = new ConstantStringType( $key );
			$valueTypes[] = $type;
		}

		return new ConstantArrayType( $keyTypes, $valueTypes );
	}

	private function getFallbackValueType(): Type {
		$types = array_values( $this->getConfigMap() );
		return TypeCombinator::union( ...$types );
	}
}
