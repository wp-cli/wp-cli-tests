To make use of the WP-CLI testing framework, you need to complete the following steps from within the package you want to add them to:

1. Add the testing framework as a development requirement:
	```bash
	composer require --dev wp-cli/wp-cli-tests
	```

2. Add the required test scripts to the `composer.json` file:
	```json
	"scripts": {
        "behat": "run-behat-tests",
        "behat-rerun": "rerun-behat-tests",
        "lint": "run-linter-tests",
        "phpcs": "run-phpcs-tests",
        "phpunit": "run-php-unit-tests",
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

4. Optionally add a `behat.yml` file to the package root with the following content:
	```json
	default:
	  paths:
	    features: features
        bootstrap: vendor/wp-cli/wp-cli-tests/features/bootstrap
	```
	This will make sure that the automated Behat system works across all platforms. This is needed on Windows.

5. Update your composer dependencies and regenerate your autoloader and binary folders:
	```bash
	composer update
	```

You are now ready to use the testing framework from within your package.

### Launching the tests

You can use the following commands to control the tests:

* `composer prepare-tests` - Set up the database that is needed for running the functional tests. This is only needed once.
* `composer test` - Run all test suites.
* `composer lint` - Run only the linting test suite.
* `composer phpcs` - Run only the code sniffer test suite.
* `composer phpunit` - Run only the unit test suite.
* `composer behat` - Run only the functional test suite.

### Controlling what to test

To send one or more arguments to one of the test tools, prepend the argument(s) with a double dash. As an example, here's how to run the functional tests for a specific feature file only:
```bash
composer behat -- features/cli-info.feature
```

Prepending with the double dash is needed because the arguments would otherwise be sent to Composer itself, not the tool that Composer executes.

### Controlling the test environment

#### WordPress Version

You can run the tests against a specific version of WordPress by setting the `WP_VERSION` environment variable.

This variable understands any numeric version, as well as the special terms `latest` and `trunk`.

Note: This only applies to the Behat functional tests. All other tests never load WordPress.

Here's how to run your tests against the latest trunk version of WordPress:
```bash
WP_VERSION=trunk composer behat
```

#### WP-CLI Binary

You can run the tests against a specific WP-CLI binary, instead of using the one that has been built in your project's `vendor/bin` folder.

This can be useful to run your tests against a specific Phar version of WP_CLI.

To do this, you can set the `WP_CLI_BIN_DIR` environment variable to point to a folder that contains an executable `wp` binary. Note: the binary has to be named `wp` to be properly recognized.

As an example, here's how to run your tests against a specific Phar version you've downloaded.
```bash
# Prepare the binary you've downloaded into the ~/wp-cli folder first.
mv ~/wp-cli/wp-cli-1.2.0.phar ~/wp-cli/wp
chmod +x ~/wp-cli/wp

WP_CLI_BIN_DIR=~/wp-cli composer behat
```

### Setting up the tests in Travis CI

Basic rules for setting up the test framework with Travis CI:

* `composer prepare-tests` needs to be called once per environment.
* `linting and sniffing` is a static analysis, so it shouldn't depend on any specific environment. You should do this only once, as a separate stage, instead of per environment.
* `composer behat || composer behat-rerun` causes the Behat tests to run in their entirety first, and in case their were failed scenarios, a second run is done with only the failed scenarios. This usually gets around intermittent issues like timeouts or similar.

Here's a basic setup of how you can configure Travis CI to work with the test framework (extract):
```yml
install:
  - composer install
  - composer prepare-tests

script:
  - composer phpunit
  - composer behat || composer behat-rerun

jobs:
  include:
    - stage: sniff
      script:
        - composer lint
        - composer phpcs
      env: BUILD=sniff
    - stage: test
      php: 7.2
      env: WP_VERSION=latest
    - stage: test
      php: 7.2
      env: WP_VERSION=3.7.11
    - stage: test
      php: 7.2
      env: WP_VERSION=trunk
```

#### WP-CLI version

You can point the tests to a specific version ow WP-CLI through the `WP_CLI_BIN_DIR` constant:
```bash
WP_CLI_BIN_DIR=~/my-custom-wp-cli/bin composer behat
```

#### WordPress version

If you want to run the feature tests against a specific WordPress version, you can use the `WP_VERSION` constant:
```bash
WP_VERSION=4.2 composer behat
```

The `WP_VERSION` constant also understands the `latest` and `trunk` as valid version targets.
