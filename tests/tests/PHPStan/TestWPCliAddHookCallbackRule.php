<?php

declare(strict_types=1);

namespace WP_CLI\Tests\Tests\PHPStan;

use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use WP_CLI\Tests\PHPStan\WPCliAddHookCallbackRule;

/**
 * @extends RuleTestCase<WPCliAddHookCallbackRule>
 */
class TestWPCliAddHookCallbackRule extends RuleTestCase {

	protected function getRule(): Rule {
		return new WPCliAddHookCallbackRule();
	}

	public function testRule(): void {
		$this->analyse(
			[ __DIR__ . '/../../data/add_hook_rule.php' ],
			[
				[
					'Callback for hook "before_wp_load" expects 1 required argument, but only 0 arguments are passed by WP_CLI::do_hook().',
					30,
				],
				[
					'Callback for hook "before_invoke:user list" expects 2 required arguments, but only 1 argument is passed by WP_CLI::do_hook().',
					46,
				],
				[
					'Parameter #2 $callback of WP_CLI::add_hook() expects a valid callable, string given.',
					54,
				],
			]
		);
	}
}
