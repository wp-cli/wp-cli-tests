Feature: Test that WP-CLI Behat steps work as expected

  This feature file contains functional tests for all the Behat steps
  provided by the WP-CLI testing framework. It ensures that each Given,
  When, and Then step definition works correctly.

  The tests are organized by step type and functionality:
  - Basic file and directory operations
  - Command execution and output validation
  - Variable management and replacement
  - WordPress installation and configuration
  - HTTP mocking and network operations
  - Output format validation (JSON, CSV, YAML, tables)

  Each scenario tests a specific step or combination of steps to verify
  they produce the expected behavior.

  Scenario: Test "Given an empty directory" step
    Given an empty directory
    Then the {RUN_DIR} directory should exist

  Scenario: Test "Given a specific directory" steps
    Given an empty directory
    And an empty test-dir directory
    Then the test-dir directory should exist

    Given a non-existent test-dir directory
    Then the test-dir directory should not exist

  Scenario: Test "Given an empty cache" step
    Given a WP installation
    And an empty cache
    Then the {SUITE_CACHE_DIR} directory should exist

  Scenario: Test "Given a file" step
    Given an empty directory
    And a test.txt file:
      """
      Hello World
      """
    Then the test.txt file should exist
    And the test.txt file should contain:
      """
      Hello World
      """

  Scenario: Test "Given a cache file" step
    Given a WP installation
    And an empty cache
    And a test-cache.txt cache file:
      """
      Cached content
      """
    Then the {SUITE_CACHE_DIR}/test-cache.txt file should exist
    And the {SUITE_CACHE_DIR}/test-cache.txt file should contain:
      """
      Cached content
      """

  Scenario: Test string replacement in file
    Given an empty directory
    And a config.txt file:
      """
      foo bar baz
      """
    And "foo" replaced with "hello" in the config.txt file
    Then the config.txt file should contain:
      """
      hello bar baz
      """

  Scenario: Test "When I run" step with basic command
    When I run `echo "test output"`
    Then STDOUT should be:
      """
      test output
      """
    And STDERR should be empty
    And the return code should be 0

  Scenario: Test "When I try" step with failing command
    When I try `false`
    Then the return code should be 1

  Scenario: Test "save STDOUT as" variable
    When I run `echo "saved value"`
    Then save STDOUT as {MY_VAR}

    When I run `echo {MY_VAR}`
    Then STDOUT should be:
      """
      saved value
      """

  Scenario: Test "save STDOUT with pattern" as variable
    When I run `echo "Version 1.2.3 downloaded"`
    Then save STDOUT 'Version ([\d\.]+)' as {VERSION}

    When I run `echo {VERSION}`
    Then STDOUT should be:
      """
      1.2.3
      """

  Scenario: Test "STDOUT should contain" step
    When I run `echo "hello world"`
    Then STDOUT should contain:
      """
      world
      """

  Scenario: Test "STDOUT should not contain" step
    When I run `echo "hello world"`
    Then STDOUT should not contain:
      """
      goodbye
      """

  Scenario: Test "STDOUT should be a number" step
    When I run `echo 42`
    Then STDOUT should be a number

  Scenario: Test "STDOUT should not be a number" step
    When I run `echo "not a number"`
    Then STDOUT should not be a number

  Scenario: Test "STDOUT should not be empty" step
    When I run `echo "something"`
    Then STDOUT should not be empty

  Scenario: Test "STDERR should be empty" step
    When I run `echo "test"`
    Then STDERR should be empty

  Scenario: Test "STDOUT should match" regex
    When I run `echo "test-123"`
    Then STDOUT should match /^test-\d+$/

  Scenario: Test "STDOUT should not match" regex
    When I run `echo "hello"`
    Then STDOUT should not match /^\d+$/

  Scenario: Test "the return code should be" step
    When I run `true`
    Then the return code should be 0

    When I try `false`
    Then the return code should be 1

  Scenario: Test "the return code should not be" step
    When I run `true`
    Then the return code should not be 1

  Scenario: Test "file should exist" step
    Given an empty directory
    And a myfile.txt file:
      """
      content
      """
    Then the myfile.txt file should exist

  Scenario: Test "file should not exist" step
    Given an empty directory
    Then the missing.txt file should not exist

  Scenario: Test "directory should exist" step
    Given an empty directory
    And an empty subdir directory
    Then the subdir directory should exist

  Scenario: Test "directory should not exist" step
    Given an empty directory
    Then the nonexistent directory should not exist

  Scenario: Test "file should contain" step
    Given an empty directory
    And a content.txt file:
      """
      Line 1
      Line 2
      """
    Then the content.txt file should contain:
      """
      Line 1
      """

  Scenario: Test "file should not contain" step
    Given an empty directory
    And a content.txt file:
      """
      Some content
      """
    Then the content.txt file should not contain:
      """
      Missing text
      """

  Scenario: Test "contents of file should match" regex
    Given an empty directory
    And a pattern.txt file:
      """
      Version: 1.2.3
      """
    Then the contents of the pattern.txt file should match /Version:\s+\d+\.\d+\.\d+/

  Scenario: Test "contents of file should not match" regex
    Given an empty directory
    And a text.txt file:
      """
      No version here
      """
    Then the contents of the text.txt file should not match /Version:\s+\d+/

  Scenario: Test "directory should contain" files
    Given an empty directory
    And a file1.txt file:
      """
      content1
      """
    And a file2.txt file:
      """
      content2
      """
    Then the {RUN_DIR} directory should contain:
      """
      file1.txt
      """
    And the {RUN_DIR} directory should contain:
      """
      file2.txt
      """

  Scenario: Test "I run the previous command again" step
    When I run `echo "test"`
    Then STDOUT should contain:
      """
      test
      """

    When I run the previous command again
    Then STDOUT should contain:
      """
      test
      """

  Scenario: Test variable replacement in commands
    When I run `echo "myvalue"`
    Then save STDOUT as {TEST_VAR}

    When I run `echo "Value is: {TEST_VAR}"`
    Then STDOUT should contain:
      """
      myvalue
      """

  Scenario: Test STDOUT strictly be
    When I run `echo "exact"`
    Then STDOUT should strictly be:
      """
      exact
      """

  Scenario: Test file strictly contain
    Given an empty directory
    And a strict.txt file:
      """
      exact content
      """
    Then the strict.txt file should strictly contain:
      """
      exact content
      """

  @require-wp
  Scenario: Test WP installation steps
    Given a WP installation
    When I run `wp core version`
    Then STDOUT should not be empty
    And the return code should be 0

  @require-wp
  Scenario: Test WP files and wp-config.php steps
    Given an empty directory
    And WP files
    Then the wp-settings.php file should exist

    Given wp-config.php
    Then the wp-config.php file should exist
    And the wp-config.php file should contain:
      """
      DB_NAME
      """

  @require-wp
  Scenario: Test WP installation in subdirectory
    Given a WP installation in 'subdir'
    When I run `wp core version` from 'subdir'
    Then STDOUT should not be empty
    And the return code should be 0



  @require-wp
  Scenario: Test version string comparison
    Given a WP installation
    When I run `wp core version`
    Then STDOUT should be a version string >= 4.0

  Scenario: Test STDOUT as table containing rows
    When I run `printf "name\tversion\nfoo\t1.0\nbar\t2.0"`
    Then STDOUT should be a table containing rows:
      | name | version |
      | foo  | 1.0     |

  Scenario: Test JSON output
    When I run `echo '{"name":"test","value":"example.com"}'`
    Then STDOUT should be JSON containing:
      """
      {"name":"test"}
      """

  Scenario: Test CSV output
    When I run `printf "user_login,user_email\nadmin,admin@example.com"`
    Then STDOUT should contain:
      """
      user_login
      """

  Scenario: Test YAML output
    When I run `printf "name: test\nversion: 1.0"`
    Then STDOUT should be YAML containing:
      """
      name: test
      """

  @require-wp
  Scenario: Test save file as variable
    Given a WP installation
    And a composer.json file:
      """
      {"name": "test"}
      """
    And save the {RUN_DIR}/composer.json file as {COMPOSER}
    When I run `echo '{COMPOSER}'`
    Then STDOUT should contain:
      """
      test
      """

  Scenario: Test HTTP request mocking
    Given that HTTP requests to https://example.com/test will respond with:
      """
      HTTP/1.1 200
      Content-Type: text/plain

      Mock response
      """
    Then the wp-cli.yml file should exist
    And the mock-requests.php file should exist

  @require-wp
  Scenario: Test background process launch
    Given a WP installation
    When I launch in the background `wp eval 'sleep(2); echo "done";'`
    # Background process should not block, continuing immediately

  @require-wp
  Scenario: Test custom wp-content directory
    Given a WP installation
    And a custom wp-content directory
    Then the my-content directory should exist
    And the my-plugins directory should exist
    And the my-mu-plugins directory should exist
    And the wp-config.php file should contain:
      """
      WP_CONTENT_DIR
      """

  @require-wp
  Scenario: Test WP multisite subdirectory installation
    Given a WP multisite subdirectory installation
    When I run `wp core is-installed --network`
    Then the return code should be 0

  @require-wp
  Scenario: Test WP multisite subdomain installation
    Given a WP multisite subdomain installation
    When I run `wp core is-installed --network`
    Then the return code should be 0

  @require-wp
  Scenario: Test misconfigured WP_CONTENT_DIR
    Given a WP installation
    And a misconfigured WP_CONTENT_DIR constant directory
    Then the wp-config.php file should contain:
      """
      define( 'WP_CONTENT_DIR', '' );
      """

  @require-download
  Scenario: Test download step
    Given an empty cache
    And download:
      | path                       | url                                    |
      | {SUITE_CACHE_DIR}/test.txt | https://www.iana.org/robots.txt        |
    Then the {SUITE_CACHE_DIR}/test.txt file should exist

  # Skipped on Windows because of curl getaddrinfo() errors.
  @require-wp @require-composer @skip-windows
  Scenario: Test WP installation with Composer
    Given a WP installation with Composer
    Then the composer.json file should exist
    And the vendor directory should exist
    When I run `wp core version`
    Then STDOUT should not be empty

  # Skipped on Windows because of curl getaddrinfo() errors.
  @require-wp @require-composer @skip-windows
  Scenario: Test WP installation with Composer and custom vendor directory
    Given a WP installation with Composer and a custom vendor directory 'custom-vendor'
    Then the composer.json file should exist
    And the custom-vendor directory should exist

  # Skipped on Windows because of curl getaddrinfo() errors.
  @require-wp @require-composer @skip-windows
  Scenario: Test dependency on current wp-cli
    Given a WP installation with Composer
    And a dependency on current wp-cli
    Then the composer.json file should contain:
      """
      wp-cli/wp-cli
      """

  @require-linux
  Scenario: Test STDOUT should be empty
    When I run `echo -n ""`
    Then STDOUT should be empty

  Scenario: Test running command from subdirectory
    Given an empty directory
    And an empty testdir directory
    When I run `pwd` from 'testdir'
    Then STDOUT should contain:
      """
      testdir
      """

  Scenario: Test STDOUT strictly contain
    When I run `echo "exact"`
    Then STDOUT should strictly contain:
      """
      exact
      """

  Scenario: Test STDERR output
    When I try `bash -c 'echo "error message" >&2'`
    Then STDERR should contain:
      """
      error message
      """
    And STDERR should not be empty

  Scenario: Test file path with nested directory
    Given an empty directory
    And a nested/path/file.txt file:
      """
      content
      """
    Then the nested/path/file.txt file should contain:
      """
      content
      """

  Scenario: Test multiple files in directory
    Given an empty directory
    And a file-a.txt file:
      """
      A
      """
    And a file-b.txt file:
      """
      B
      """
    And a file-c.txt file:
      """
      C
      """
    Then the {RUN_DIR} directory should contain:
      """
      file-a.txt
      """
    And the {RUN_DIR} directory should contain:
      """
      file-b.txt
      """
    And the {RUN_DIR} directory should contain:
      """
      file-c.txt
      """

  Scenario: Test special characters in file content
    Given an empty directory
    And a special.txt file:
      """
      Line with "quotes"
      Line with 'apostrophes'
      Line with $variable
      """
    Then the special.txt file should contain:
      """
      quotes
      """

  Scenario: Test version comparison operators
    When I run `echo "5.6.2"`
    Then STDOUT should be a version string > 5.6.1
    And STDOUT should be a version string >= 5.6.2
    And STDOUT should be a version string < 5.6.3
    And STDOUT should be a version string <= 5.6.2
    And STDOUT should be a version string == 5.6.2
    And STDOUT should be a version string != 5.6.3

  @require-wp
  Scenario: Test JSON array containing
    Given a WP installation
    When I run `wp eval 'echo json_encode(["apple", "banana", "cherry"]);'`
    Then STDOUT should be a JSON array containing:
      """
      ["apple", "banana"]
      """

  @require-wp
  Scenario: Test email sending detection
    Given a WP installation
    And a send-email.php file:
      """
      <?php
      wp_mail('test@example.com', 'Test', 'Body');
      """
    When I run `wp eval-file send-email.php`
    Then an email should be sent

  Scenario: Test STDOUT end with table
    When I run `printf "Some output\nuser_login\nadmin\nuser2"`
    Then STDOUT should end with a table containing rows:
      | user_login |
      | admin      |

  Scenario: Test combining multiple string replacements
    Given an empty directory
    And a template.txt file:
      """
      Hello FIRST_NAME LAST_NAME
      """
    And "FIRST_NAME" replaced with "John" in the template.txt file
    And "LAST_NAME" replaced with "Doe" in the template.txt file
    Then the template.txt file should contain:
      """
      Hello John Doe
      """

  Scenario: Test nested directory creation
    Given an empty directory
    And a deep/nested/path/file.txt file:
      """
      Deep content
      """
    Then the deep/nested/path/file.txt file should exist

  Scenario: Test absolute vs relative paths
    Given an empty directory
    And a relative.txt file:
      """
      Relative
      """
    Then the relative.txt file should exist
    And the {RUN_DIR}/relative.txt file should exist





  Scenario: Test variable naming conventions
    When I run `echo "value1"`
    Then save STDOUT as {VAR_NAME}

    When I run `echo {VAR_NAME}`
    Then STDOUT should be:
      """
      value1
      """

  Scenario: Test variable with underscore prefix
    When I run `echo "value2"`
    Then save STDOUT as {_UNDERSCORE_VAR}

    When I run `echo {_UNDERSCORE_VAR}`
    Then STDOUT should contain:
      """
      value2
      """

  Scenario: Test variable with numbers
    When I run `echo "value3"`
    Then save STDOUT as {VAR123}

    When I run `echo {VAR123}`
    Then STDOUT should contain:
      """
      value3
      """

  Scenario: Test built-in variables
    Given an empty directory
    When I run `pwd`
    Then STDOUT should contain:
      """
      {RUN_DIR}
      """

  Scenario: Test CACHE_DIR variable
    Given an empty cache
    When I run `echo {SUITE_CACHE_DIR}`
    Then STDOUT should not be empty

  Scenario: Test multiline STDOUT capture
    When I run `printf "line1\nline2\nline3"`
    Then STDOUT should contain:
      """
      line1
      """
    And STDOUT should contain:
      """
      line2
      """
    And STDOUT should contain:
      """
      line3
      """

  Scenario: Test STDERR capture
    When I try `bash -c 'echo "stdout"; echo "stderr" >&2'`
    Then STDOUT should contain:
      """
      stdout
      """
    And STDERR should contain:
      """
      stderr
      """

  Scenario: Test file with multiline content
    Given an empty directory
    And a multiline.txt file:
      """
      First line
      Second line
      Third line
      """
    Then the multiline.txt file should contain:
      """
      First line
      """
    And the multiline.txt file should contain:
      """
      Second line
      """
    And the multiline.txt file should contain:
      """
      Third line
      """

  Scenario: Test return code not be assertion
    When I run `true`
    Then the return code should not be 1
    And the return code should not be 2

  Scenario: Test CSV containing with headers
    When I run `printf "user_login,user_email\nadmin,admin@example.com\nuser2,user2@example.com"`
    Then STDOUT should be CSV containing:
      | user_login | user_email        |
      | admin      | admin@example.com |
