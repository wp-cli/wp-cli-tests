<?php

/**
 * Test data for TestLegacyAnnotations: a context overriding a hook and a step without attributes.
 */

namespace WP_CLI\Tests\Tests;

use Behat\Behat\Hook\Scope\BeforeScenarioScope;
use WP_CLI\Tests\Context\FeatureContext;

class Overriding_Context extends FeatureContext {

	public function beforeScenario( BeforeScenarioScope $scope ): void { // phpcs:ignore Generic.CodeAnalysis.UselessOverridingMethod.Found
		parent::beforeScenario( $scope );
	}

	public function given_an_empty_directory(): void { // phpcs:ignore Generic.CodeAnalysis.UselessOverridingMethod.Found
		parent::given_an_empty_directory();
	}
}
