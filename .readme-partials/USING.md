To make use of the WP-CLI testing framework, you need to complete the following steps from within the package you want to add them to:

1. Add the testing framework as a development requirement:
	```bash
	composer require --dev wp-cli/wp-cli-tests
	```

2. Add the required test scripts to the `composer.json` file:
	```json
	"scripts": {
        "lint": "run-linter-tests",
        "phpcs": "run-phpcs-tests",
        "phpunit": "run-php-unit-tests",
        "behat": "run-behat-tests",
        "prepare-tests": "install-package-tests",
        "test": [
            "@lint",
            "@phpcs",
            "@phpunit",
            "@behat"
        ]
	}
	```
	You can of course remove the ones you don't need.

3. Optionally add a modified process timeout to the `composer.json` file to make sure scripts can run until their work is completed:
	```json
	"config": {
		"process-timeout": 1800
	},
	```
	The timeout is expressed in seconds.

4. Update your composer dependencies and regenerate your autoloader and binary folders:
	```bash
	composer update
	```

You are now ready to use the testing framework from within your package.

You can use the following commands to control the tests:

* `composer prepare-tests` - Set up the database that is needed for running the functional tests. This is only needed once.
* `composer test` - Run all test suites.
* `composer lint` - Run only the linting test suite.
* `composer phpcs` - Run only the code sniffer test suite.
* `composer phpunit` - Run only the unit test suite.
* `composer behat` - Run only the functional test suite.

To send one or more arguments to one of the test tools, prepend the argument(s) with a double dash. As an example, here's how to run the functional tests for a specific feature file only:
```bash
composer behat -- features/cli-info.feature
```

Prepending with the double dash is needed because the arguments would otherwise be sent to Composer itself, not the tool that Composer executes.
