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
        "lint-gherkin": "run-gherkin-lint-tests",
        "phpcs": "run-phpcs-tests",
        "phpcbf": "run-phpcbf-cleanup",
        "phpunit": "run-php-unit-tests",
        "prepare-tests": "install-package-tests",
        "test": [
            "@lint",
            "@lint-gherkin",
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
    ```yaml
    default:
      suites:
        default:
          contexts:
            - WP_CLI\Tests\Context\FeatureContext
          paths:
            - features
    ```
    This will make sure that the automated Behat system works across all platforms. This is needed on Windows.

5. Optionally add a `phpcs.xml.dist` file to the package root to enable code style and best practice checks using PHP_CodeSniffer.

    Example of a minimal custom ruleset based on the defaults set in the WP-CLI testing framework:
    ```xml
    <?xml version="1.0"?>
    <ruleset name="WP-CLI-PROJECT-NAME">
    <description>Custom ruleset for WP-CLI PROJECT NAME</description>

        <!-- What to scan. -->
        <file>.</file>

        <!-- Show progress. -->
        <arg value="p"/>

        <!-- Strip the filepaths down to the relevant bit. -->
        <arg name="basepath" value="./"/>

        <!-- Check up to 8 files simultaneously. -->
        <arg name="parallel" value="8"/>

        <!-- For help understanding the `testVersion` configuration setting:
             https://github.com/PHPCompatibility/PHPCompatibility#sniffing-your-code-for-compatibility-with-specific-php-versions -->
        <config name="testVersion" value="5.4-"/>

        <!-- Rules: Include the base ruleset for WP-CLI projects. -->
        <rule ref="WP_CLI_CS"/>

    </ruleset>
    ```

    All other [PHPCS configuration options](https://github.com/PHPCSStandards/PHP_CodeSniffer/wiki/Annotated-Ruleset) are, of course, available.
6. Update your composer dependencies and regenerate your autoloader and binary folders:
    ```bash
    composer update
    ```

You are now ready to use the testing framework from within your package.

### Launching the tests

You can use the following commands to control the tests:

* `composer prepare-tests` - Set up the database that is needed for running the functional tests. This is only needed once.
* `composer test` - Run all test suites.
* `composer lint` - Run only the linting test suite.
* `composer lint-gherkin` - Run only the Gherkin linter over the feature files.
* `composer phpcs` - Run only the code sniffer test suite.
* `composer phpcbf` - Run only the code sniffer cleanup.
* `composer phpunit` - Run only the unit test suite.
* `composer behat` - Run only the functional test suite.

### Controlling what to test

To send one or more arguments to one of the test tools, prepend the argument(s) with a double dash. As an example, here's how to run the functional tests for a specific feature file only:
```bash
composer behat -- features/cli-info.feature
```

Prepending with the double dash is needed because the arguments would otherwise be sent to Composer itself, not the tool that Composer executes.

The same mechanism works for narrowing a run down further, or for bailing out early:
```bash
# A single scenario, identified by the line it starts on.
composer behat -- features/cli-info.feature:12

# Every scenario carrying a given tag.
composer behat -- --tags=@require-wp-5.0

# Stop at the first failing scenario instead of running the whole suite.
composer behat -- --stop-on-failure

# Re-run only the scenarios that failed the last time.
composer behat-rerun
```

### Linting the feature files

`composer lint-gherkin` checks `features/` with
[gherkin-lint-plus](https://www.npmjs.com/package/gherkin-lint-plus), against the
`.gherkin-lintrc` ruleset shipped with this package. A project that needs
different rules can override it by committing its own `.gherkin-lintrc`.

The linter is a Node package, so it is run through `npx` and needs Node.js 20 or
later. Where `npx` is not available the check reports that it is skipping, rather
than failing a suite that is otherwise entirely PHP. Its version is pinned in
this package's `package.json`, which exists only to hold that pin.

### Controlling the amount of output

Two environment variables make the test tools less chatty. Both are unset by default, which leaves the output exactly as it has always been.

  - `NO_COLOR` (the [no-color.org](https://no-color.org/) convention) stops the runners from forcing ANSI color codes on, and leaves the decision to each tool's own terminal detection. Set this when capturing output to a file or a pipe, where the escape sequences are noise.
  - `WP_CLI_TEST_QUIET` switches the reporters to their most compact form: PHP_CodeSniffer reports one `file:line:col` line per violation with no progress ticker, PHPStan reports one `file:line:message` line per error with no progress bar and no result table. Behat's own output is already minimal, so it is unaffected.

`NO_COLOR` also covers the Gherkin linter, which colors its report unconditionally and has no plain output format of its own.

```bash
NO_COLOR=1 WP_CLI_TEST_QUIET=1 composer phpstan
```

This is worth setting permanently in environments that read the output back rather than display it, such as an AI coding agent's shell:

```bash
export NO_COLOR=1
export WP_CLI_TEST_QUIET=1
```

### Controlling the test environment

#### WordPress Version

You can run the tests against a specific version of WordPress by setting the `WP_VERSION` environment variable.

This variable understands any numeric version, as well as the special terms `latest` and `trunk`.

Note: This only applies to the Behat functional tests. All other tests never load WordPress.

Here's how to run your tests against the latest trunk version of WordPress:
```bash
WP_VERSION=trunk composer behat
```

Resolving `latest`, or a `X.Y` version without a patch number, needs the
WordPress versions data, which is fetched once and cached in the system temp
directory for a day. Repeated runs do not repeat the request, and a run without
connectivity falls back to the last known copy.
`WP_CLI_TEST_WP_VERSION_CACHE_TTL` sets the lifetime of that cache in seconds;
`0` fetches it every time.

#### WordPress Archive

Instead of downloading WordPress from WordPress.org, you can run the tests against an arbitrary
WordPress ZIP archive by setting the `WP_CLI_TEST_CORE_ZIP` environment variable. It accepts either
a path to a local archive or an HTTP(S) URL.

This is useful to test against a WordPress build that has not been released, such as the ZIP file
produced by the WordPress core build process.

```bash
WP_CLI_TEST_CORE_ZIP=~/Downloads/wordpress.zip composer behat
```

The archive may contain WordPress at its root, or wrapped in a single folder — both `wordpress/`
(as used by WordPress.org releases) and `build/` (as used by some WordPress core build artifacts)
work. Archives are extracted once and then cached, keyed by their contents.

`WP_VERSION` still determines which version-specific tags (`@require-wp-6.4`, `@less-than-wp-6.4`)
are filtered out, since the version of a development build cannot be compared meaningfully. It
defaults to `trunk` when an archive is set, which runs every scenario. Set it explicitly when the
archive holds a specific release:

```bash
WP_VERSION=6.4.2 WP_CLI_TEST_CORE_ZIP=~/Downloads/wordpress-6.4.2.zip composer behat
```

Note that steps requesting an explicit version, such as `Given a WP 6.4.2 installation`, keep
downloading that version from WordPress.org and ignore the archive.

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

You can point the tests to a specific version of WP-CLI through the `WP_CLI_BIN_DIR` constant:
```bash
WP_CLI_BIN_DIR=~/my-custom-wp-cli/bin composer behat
```

#### WordPress version

If you want to run the feature tests against a specific WordPress version, you can use the `WP_VERSION` constant:
```bash
WP_VERSION=4.2 composer behat
```

The `WP_VERSION` constant also understands the `latest` and `trunk` as valid version targets.

#### The database credentials

By default, the tests are run in a database named `wp_cli_test` with the user also named `wp_cli_test` with password `password1`.
This should be set up via the `composer prepare-tests` command.

The following environment variables can be set to override the default database credentials.

  - `WP_CLI_TEST_DBHOST` is the host to use and can include a port, i.e "127.0.0.1:33060" (defaults to "localhost")
  - `WP_CLI_TEST_DBROOTUSER` is the user that has permission to administer databases and users (defaults to "root").
  - `WP_CLI_TEST_DBROOTPASS` is the password to use for the above user (defaults to an empty password).
  - `WP_CLI_TEST_DBNAME` is the database that the tests run under (defaults to "wp_cli_test").
  - `WP_CLI_TEST_DBUSER` is the user that the tests run under (defaults to "wp_cli_test").
  - `WP_CLI_TEST_DBPASS` is the password to use for the above user (defaults to "password1").
  - `WP_CLI_TEST_DBTYPE` is the database engine type to use, i.e. "sqlite" for running tests on SQLite instead of MySQL (defaults to "mysql").
  - `WP_CLI_TEST_OBJECT_CACHE` is the persistent object cache backend to use. Only supports "sqlite".

Environment variables can be set for the whole session via the following syntax: `export WP_CLI_TEST_DBNAME=custom_db`.

They can also be set for a single execution by prepending them before the Behat command: `WP_CLI_TEST_DBNAME=custom_db composer behat`.

