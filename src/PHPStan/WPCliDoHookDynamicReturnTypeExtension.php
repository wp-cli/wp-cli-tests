<?php

declare(strict_types=1);

namespace WP_CLI\Tests\PHPStan;

use PhpParser\Comment\Doc;
use PhpParser\Node\Expr\StaticCall;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\MethodReflection;
use PHPStan\Type\DynamicStaticMethodReturnTypeExtension;
use PHPStan\Type\FileTypeMapper;
use PHPStan\Type\NullType;
use PHPStan\Type\Type;

use function count;

final class WPCliDoHookDynamicReturnTypeExtension implements DynamicStaticMethodReturnTypeExtension {

	/**
	 * @var FileTypeMapper
	 */
	private $fileTypeMapper;

	public function __construct( FileTypeMapper $fileTypeMapper ) {
		$this->fileTypeMapper = $fileTypeMapper;
	}

	public function getClass(): string {
		return 'WP_CLI';
	}

	public function isStaticMethodSupported( MethodReflection $methodReflection ): bool {
		return $methodReflection->getName() === 'do_hook';
	}

	public function getTypeFromStaticMethodCall(
		MethodReflection $methodReflection,
		StaticCall $methodCall,
		Scope $scope
	): Type {
		$args = $methodCall->getArgs();

		if ( count( $args ) < 2 ) {
			return new NullType();
		}

		$docComment = $methodCall->getAttribute( 'latestDocComment' );
		if ( $docComment instanceof Doc ) {
			$classReflection = $scope->getClassReflection();
			$traitReflection = $scope->getTraitReflection();

			$resolvedPhpDoc = $this->fileTypeMapper->getResolvedPhpDoc(
				$scope->getFile(),
				( $scope->isInClass() && null !== $classReflection ) ? $classReflection->getName() : null,
				( $scope->isInTrait() && null !== $traitReflection ) ? $traitReflection->getName() : null,
				$scope->getFunctionName(),
				$docComment->getText()
			);

			$params = $resolvedPhpDoc->getParamTags();
			foreach ( $params as $paramTag ) {
				return $paramTag->getType();
			}
		}

		return $scope->getType( $args[1]->value );
	}
}
