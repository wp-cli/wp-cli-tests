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
	private $reflection_provider;

	/** @var TypeNodeResolver */
	private $type_node_resolver;

	/** @var ConstantArrayType|null */
	private $global_config_type = null;

	public function __construct(
		ReflectionProvider $reflection_provider,
		TypeNodeResolver $type_node_resolver
	) {
		$this->reflection_provider = $reflection_provider;
		$this->type_node_resolver  = $type_node_resolver;
	}

	public function getClass(): string {
		return 'WP_CLI';
	}

	public function isStaticMethodSupported( MethodReflection $method_reflection ): bool {
		return $method_reflection->getName() === 'get_config';
	}

	public function getTypeFromStaticMethodCall(
		MethodReflection $method_reflection,
		StaticCall $method_call,
		Scope $scope
	): Type {
		$args = $method_call->getArgs();

		if ( count( $args ) === 0 ) {
			return $this->get_global_config_array_type();
		}

		$key_type = $scope->getType( $args[0]->value );

		if ( $key_type->isNull()->yes() ) {
			return $this->get_global_config_array_type();
		}

		$constant_strings = $key_type->getConstantStrings();
		if ( count( $constant_strings ) > 0 ) {
			$types              = [];
			$global_config_type = $this->get_global_config_array_type();

			foreach ( $constant_strings as $constant_string ) {
				$value_type = $global_config_type->getOffsetValueType( $constant_string );
				if ( $value_type instanceof ErrorType ) {
					$types[] = new NullType();
				} else {
					$types[] = $value_type;
				}
			}

			if ( count( $types ) > 0 ) {
				$return_type = TypeCombinator::union( ...$types );
				if ( $key_type->isNull()->maybe() ) {
					$return_type = TypeCombinator::union( $return_type, $global_config_type );
				}
				return $return_type;
			}
		}

		// Fallback for non-constant string or unknown types
		$fallback = TypeCombinator::addNull( $this->get_global_config_array_type()->getItemType() );
		if ( $key_type->isNull()->maybe() ) {
			return TypeCombinator::union( $fallback, $this->get_global_config_array_type() );
		}

		return $fallback;
	}

	private function get_global_config_array_type(): ConstantArrayType {
		if ( null === $this->global_config_type ) {
			$class_reflection = $this->reflection_provider->getClass( 'WP_CLI' );
			$type_aliases     = $class_reflection->getTypeAliases();
			$global_config    = $type_aliases['GlobalConfig'] ?? null;

			if ( null === $global_config ) {
				throw new \PHPStan\ShouldNotHappenException( 'GlobalConfig type alias not found on WP_CLI class.' );
			}

			/** @phpstan-ignore phpstanApi.method */
			$resolved        = $global_config->resolve( $this->type_node_resolver );
			$constant_arrays = $resolved->getConstantArrays();

			if ( count( $constant_arrays ) === 0 ) {
				throw new \PHPStan\ShouldNotHappenException( 'GlobalConfig type alias on WP_CLI must resolve to a ConstantArrayType.' );
			}

			$this->global_config_type = $constant_arrays[0];
		}

		return $this->global_config_type;
	}
}
