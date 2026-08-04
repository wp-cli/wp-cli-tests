<?php

declare(strict_types=1);

namespace WP_CLI\Tests\PHPStan;

use PhpParser\Node\Expr\StaticCall;
use PHPStan\Analyser\Scope;
use PHPStan\PhpDoc\TypeNodeResolver;
use PHPStan\Reflection\MethodReflection;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Type\Constant\ConstantArrayType;
use PHPStan\Type\DynamicStaticMethodReturnTypeExtension;
use PHPStan\Type\ErrorType;
use PHPStan\Type\NullType;
use PHPStan\Type\Type;
use PHPStan\Type\TypeCombinator;

use function count;

final class WPCliGetConfigDynamicReturnTypeExtension implements DynamicStaticMethodReturnTypeExtension {

	/** @var ReflectionProvider */
	private $reflectionProvider;

	/** @var TypeNodeResolver */
	private $typeNodeResolver;

	/** @var ConstantArrayType|null */
	private $globalConfigType = null;

	public function __construct(
		ReflectionProvider $reflectionProvider,
		TypeNodeResolver $typeNodeResolver
	) {
		$this->reflectionProvider = $reflectionProvider;
		$this->typeNodeResolver   = $typeNodeResolver;
	}

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
			$types            = [];
			$globalConfigType = $this->getGlobalConfigArrayType();

			foreach ( $constantStrings as $constantString ) {
				$valueType = $globalConfigType->getOffsetValueType( $constantString );
				if ( $valueType instanceof ErrorType ) {
					$types[] = new NullType();
				} else {
					$types[] = $valueType;
				}
			}

			if ( count( $types ) > 0 ) {
				$returnType = TypeCombinator::union( ...$types );
				if ( $keyType->isNull()->maybe() ) {
					$returnType = TypeCombinator::union( $returnType, $globalConfigType );
				}
				return $returnType;
			}
		}

		// Fallback for non-constant string or unknown types
		$fallback = TypeCombinator::addNull( $this->getGlobalConfigArrayType()->getItemType() );
		if ( $keyType->isNull()->maybe() ) {
			return TypeCombinator::union( $fallback, $this->getGlobalConfigArrayType() );
		}

		return $fallback;
	}

	private function getGlobalConfigArrayType(): ConstantArrayType {
		if ( null === $this->globalConfigType ) {
			$classReflection = $this->reflectionProvider->getClass( 'WP_CLI' );
			$typeAliases     = $classReflection->getTypeAliases();
			$globalConfig    = $typeAliases['GlobalConfig'] ?? null;

			if ( null === $globalConfig ) {
				throw new \PHPStan\ShouldNotHappenException( 'GlobalConfig type alias not found on WP_CLI class.' );
			}

			/** @phpstan-ignore phpstanApi.method */
			$resolved       = $globalConfig->resolve( $this->typeNodeResolver );
			$constantArrays = $resolved->getConstantArrays();

			if ( count( $constantArrays ) === 0 ) {
				throw new \PHPStan\ShouldNotHappenException( 'GlobalConfig type alias on WP_CLI must resolve to a ConstantArrayType.' );
			}

			$this->globalConfigType = $constantArrays[0];
		}

		return $this->globalConfigType;
	}
}
