Feature: Make sure "Given", "When", "Then" steps work as expected

  Scenario: Variable names can only contain uppercase letters, digits and underscores and cannot begin with a digit.

    When I run `echo value`
    Then save STDOUT as {VARIABLE_NAME}
    And save STDOUT as {V}
    And save STDOUT as {_VARIABLE_NAME_STARTING_WITH_UNDERSCORE}
    And save STDOUT as {_}
    And save STDOUT as {VARIABLE_NAME_WITH_DIGIT_2}
    And save STDOUT as {V2}
    And save STDOUT as {_2}
    And save STDOUT as {2_VARIABLE_NAME_STARTING_WITH_DIGIT}
    And save STDOUT as {2}
    And save STDOUT as {VARIABLE_NAME_WITH_lowercase}
    And save STDOUT as {v}
    # Note this would give behat "undefined step" message as "save" step uses "\w+"
    #And save STDOUT as {VARIABLE_NAME_WITH_PERCENT_%}

    When I run `echo {VARIABLE_NAME}`
    Then STDOUT should match /^value$/
    And STDOUT should be:
      """
      value
      """

    When I run `echo {V}`
    Then STDOUT should match /^value$/

    When I run `echo {_VARIABLE_NAME_STARTING_WITH_UNDERSCORE}`
    Then STDOUT should match /^value$/

    When I run `echo {_}`
    Then STDOUT should match /^value$/

    When I run `echo {VARIABLE_NAME_WITH_DIGIT_2}`
    Then STDOUT should match /^value$/

    When I run `echo {V2}`
    Then STDOUT should match /^value$/

    When I run `echo {_2}`
    Then STDOUT should match /^value$/

    When I run `echo {2_VARIABLE_NAME_STARTING_WITH_DIGIT}`
    Then STDOUT should match /^\{2_VARIABLE_NAME_STARTING_WITH_DIGIT}$/
    And STDOUT should contain:
      """
      {
      """

    When I run `echo {2}`
    Then STDOUT should match /^\{2}$/

    When I run `echo {VARIABLE_NAME_WITH_lowercase}`
    Then STDOUT should match /^\{VARIABLE_NAME_WITH_lowercase}$/

    When I run `echo {v}`
    Then STDOUT should match /^\{v}$/

  Scenario: Special variables

    When I run `echo {INVOKE_WP_CLI_WITH_PHP_ARGS-} cli info`
    Then STDOUT should match /wp cli info/
    And STDERR should be empty

    When I run `echo {WP_VERSION-latest}`
    Then STDOUT should match /\d\.\d/
    And STDERR should be empty

  Scenario: Nested special variables
    Given an empty directory
    When I run `echo {INVOKE_WP_CLI_WITH_PHP_ARGS--dopen_basedir={RUN_DIR}} cli info`
    Then STDOUT should match /^WP_CLI_PHP_ARGS=-dopen_basedir=.* ?wp cli info/
    And STDERR should be empty

    When I run `echo {INVOKE_WP_CLI_WITH_PHP_ARGS--dopen_basedir={RUN_DIR}} eval 'echo "{RUN_DIR}";'`
    Then STDOUT should match /^WP_CLI_PHP_ARGS=-dopen_basedir=(.*)(.*) ?wp eval echo "\1";/

  @require-mysql-or-mariadb
  Scenario: SQL related variables
    When I run `echo {MYSQL_BINARY}`
    Then STDOUT should match /(mysql|mariadb)/

    When I run `echo {SQL_DUMP_COMMAND}`
    Then STDOUT should match /(mysqldump|mariadb-dump)/
