<?php

declare(strict_types=1);

namespace WP_CLI\Tests\PHPStan;

use PhpParser\Node;
use PhpParser\Node\Expr\StaticCall;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use PHPStan\Type\VerbosityLevel;

use function count;
use function sprintf;
use function strpos;

/**
 * @implements Rule<StaticCall>
 */
final class WPCliAddHookCallbackRule implements Rule {

	private const KNOWN_HOOK_ARGS = [
		'find_command_to_run_pre'            => 0,
		'before_wp_load'                     => 0,
		'before_wp_config_load'              => 0,
		'after_wp_config_load'               => 0,
		'after_wp_load'                      => 0,
		'before_ssh'                         => 0,
		'before_registering_contexts'        => 1,
		'formatter_available_formats'        => 1,
		'http_request_options'               => 5,
		'before_run_command'                 => 3,
		'search_replace_unserialize_options' => 1,
	];

	private const DYNAMIC_HOOK_PREFIXES = [
		'before_add_command:' => 1,
		'after_add_command:'  => 0,
		'before_invoke:'      => 1,
		'after_invoke:'       => 1,
	];

	public function getNodeType(): string {
		return StaticCall::class;
	}

	public function processNode( Node $node, Scope $scope ): array {
		if ( ! $node instanceof StaticCall ) {
			return [];
		}

		if ( ! $node->name instanceof Node\Identifier || 'add_hook' !== $node->name->name ) {
			return [];
		}

		if ( ! $node->class instanceof Node\Name || 'WP_CLI' !== $scope->resolveName( $node->class ) ) {
			return [];
		}

		$args = $node->getArgs();
		if ( count( $args ) < 2 ) {
			return [];
		}

		$callbackType = $scope->getType( $args[1]->value );
		if ( ! $callbackType->isCallable()->yes() ) {
			if ( $callbackType->isCallable()->no() ) {
				return [
					RuleErrorBuilder::message(
						sprintf(
							'Parameter #2 $callback of WP_CLI::add_hook() expects a valid callable, %s given.',
							$callbackType->describe( VerbosityLevel::typeOnly() )
						)
					)->identifier( 'wpCli.addHookCallback.invalidCallback' )->build(),
				];
			}
			return [];
		}

		$hookNameType    = $scope->getType( $args[0]->value );
		$hookNameStrings = $hookNameType->getConstantStrings();
		if ( count( $hookNameStrings ) !== 1 ) {
			return [];
		}

		$hookName     = $hookNameStrings[0]->getValue();
		$expectedArgs = $this->getExpectedArgCountForHook( $hookName );
		if ( null === $expectedArgs ) {
			return [];
		}

		$callableParametersAcceptors = $callbackType->getCallableParametersAcceptors( $scope );
		if ( count( $callableParametersAcceptors ) === 0 ) {
			return [];
		}

		$parametersAcceptor = $callableParametersAcceptors[0];
		$requiredParams     = 0;
		foreach ( $parametersAcceptor->getParameters() as $parameter ) {
			if ( ! $parameter->isOptional() ) {
				++$requiredParams;
			}
		}

		if ( $requiredParams > $expectedArgs ) {
			return [
				RuleErrorBuilder::message(
					sprintf(
						'Callback for hook "%s" expects %d required %s, but only %d %s passed by WP_CLI::do_hook().',
						$hookName,
						$requiredParams,
						1 === $requiredParams ? 'argument' : 'arguments',
						$expectedArgs,
						1 === $expectedArgs ? 'argument is' : 'arguments are'
					)
				)->identifier( 'wpCli.addHookCallback.insufficientParameters' )->build(),
			];
		}

		return [];
	}

	private function getExpectedArgCountForHook( string $hookName ): ?int {
		if ( isset( self::KNOWN_HOOK_ARGS[ $hookName ] ) ) {
			return self::KNOWN_HOOK_ARGS[ $hookName ];
		}

		foreach ( self::DYNAMIC_HOOK_PREFIXES as $prefix => $count ) {
			if ( 0 === strpos( $hookName, $prefix ) ) {
				return $count;
			}
		}

		return null;
	}
}
