# Defaults for the code style check of the PHP blocks embedded in Behat feature
# files, shared by `run-phpcs-tests` and `run-phpcbf-cleanup`.
#
# Keeping the list in one place is what makes the check and the fixer agree: a
# sniff excluded for one but not the other would have the fixer rewrite feature
# files over something the check never reports, or have the check report
# something the fixer refuses to touch.
#
# The exclusions are passed on the command line rather than declared in a
# ruleset because a ruleset aborts the whole run over a sniff that the installed
# PHP_CodeSniffer does not know, while `--exclude` passes over it. The list
# spans several major versions of PHP_CodeSniffer and of the standards it
# builds on, and not every entry exists in all of them.
#
# Only what cannot be said on the command line -- a sniff property, a single
# message code -- is declared in the `WP_CLI_CS_Feature_Files` ruleset, which is
# what tells the sniffs that indent that a feature file indents with spaces.
#
# A package replaces these defaults wholesale by adding a
# `phpcs-feature-files.xml` (or `phpcs-feature-files.xml.dist`) ruleset to its
# root, which is then used as the standard instead.

WP_CLI_TESTS_FEATURE_STANDARD="WP_CLI_CS_Feature_Files"

# Warnings are advisory, and the fixer must not rewrite a feature file over
# something the check does not report.
WP_CLI_TESTS_FEATURE_ARGS="--warning-severity=0"

# A block is not a file. It is padded with one empty line per preceding line of
# the feature file so that reported line numbers match it, and one that does not
# bring its own opening tag is given one. Neither is part of the snippet, and
# the shared docstring indentation is taken off before the check and put back
# afterwards, so none of the sniffs looking at a file as a whole apply.
WP_CLI_TESTS_FEATURE_EXCLUDES="Generic.Files.InlineHTML"
WP_CLI_TESTS_FEATURE_EXCLUDES="$WP_CLI_TESTS_FEATURE_EXCLUDES,Generic.PHP.CharacterBeforePHPOpenTag"
WP_CLI_TESTS_FEATURE_EXCLUDES="$WP_CLI_TESTS_FEATURE_EXCLUDES,Generic.Files.LineEndings"
WP_CLI_TESTS_FEATURE_EXCLUDES="$WP_CLI_TESTS_FEATURE_EXCLUDES,PSR2.Files.EndFileNewline"
WP_CLI_TESTS_FEATURE_EXCLUDES="$WP_CLI_TESTS_FEATURE_EXCLUDES,PSR12.Files.FileHeader"
WP_CLI_TESTS_FEATURE_EXCLUDES="$WP_CLI_TESTS_FEATURE_EXCLUDES,Squiz.Commenting.FileComment"
WP_CLI_TESTS_FEATURE_EXCLUDES="$WP_CLI_TESTS_FEATURE_EXCLUDES,Generic.PHP.RequireStrictTypes"
WP_CLI_TESTS_FEATURE_EXCLUDES="$WP_CLI_TESTS_FEATURE_EXCLUDES,WordPress.Files.FileName"
WP_CLI_TESTS_FEATURE_EXCLUDES="$WP_CLI_TESTS_FEATURE_EXCLUDES,Universal.WhiteSpace.PrecisionAlignment"

# A block is a fixture, not production code. Snippets exist to set up a
# scenario, run inside a throwaway WordPress installation, are written to be
# read at a glance, and are routinely a single class or function on their own.
WP_CLI_TESTS_FEATURE_EXCLUDES="$WP_CLI_TESTS_FEATURE_EXCLUDES,WordPress.NamingConventions.PrefixAllGlobals"
WP_CLI_TESTS_FEATURE_EXCLUDES="$WP_CLI_TESTS_FEATURE_EXCLUDES,WordPress.WP.GlobalVariablesOverride"
WP_CLI_TESTS_FEATURE_EXCLUDES="$WP_CLI_TESTS_FEATURE_EXCLUDES,WordPress.PHP.YodaConditions"
WP_CLI_TESTS_FEATURE_EXCLUDES="$WP_CLI_TESTS_FEATURE_EXCLUDES,Universal.Files.SeparateFunctionsFromOO"
WP_CLI_TESTS_FEATURE_EXCLUDES="$WP_CLI_TESTS_FEATURE_EXCLUDES,Generic.Files.OneObjectStructurePerFile"
WP_CLI_TESTS_FEATURE_EXCLUDES="$WP_CLI_TESTS_FEATURE_EXCLUDES,Universal.Namespaces.OneDeclarationPerFile"
WP_CLI_TESTS_FEATURE_EXCLUDES="$WP_CLI_TESTS_FEATURE_EXCLUDES,Universal.Namespaces.DisallowCurlyBraceSyntax"
WP_CLI_TESTS_FEATURE_EXCLUDES="$WP_CLI_TESTS_FEATURE_EXCLUDES,Universal.Namespaces.DisallowDeclarationWithoutName"
WP_CLI_TESTS_FEATURE_EXCLUDES="$WP_CLI_TESTS_FEATURE_EXCLUDES,PSR2.Methods.FunctionClosingBrace"

# A snippet testing error handling is deliberately incomplete.
WP_CLI_TESTS_FEATURE_EXCLUDES="$WP_CLI_TESTS_FEATURE_EXCLUDES,Generic.CodeAnalysis.EmptyStatement"

WP_CLI_TESTS_FEATURE_ARGS="$WP_CLI_TESTS_FEATURE_ARGS --exclude=$WP_CLI_TESTS_FEATURE_EXCLUDES"
