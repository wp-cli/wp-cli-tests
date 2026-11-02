<?php

namespace WP_CLI\Tests\Tests;

use WP_CLI\Tests\TestCase;
use WP_CLI\Utils;

/**
 * Splitting the scenarios across machines through WP_CLI_TEST_SHARD.
 *
 * The runner is exercised the way run-behat-tests uses it, over feature files in a temporary
 * project. With a single worker, it writes the scenarios of the shard to a list that it hands
 * to Behat, which is what these tests look at. Behat itself runs with --dry-run.
 */
class TestBehatParallel extends TestCase {

	/**
	 * @var string|null
	 */
	private $temp_dir;

	protected function set_up(): void {
		parent::set_up();

		if ( DIRECTORY_SEPARATOR === '\\' ) {
			$this->markTestSkipped( 'The runner does not split the scenarios on Windows.' );
		}

		$this->temp_dir = Utils\get_temp_dir() . uniqid( 'wp-cli-test-behat-parallel-', true );
		mkdir( $this->temp_dir . '/features', 0777, true );
		file_put_contents( $this->temp_dir . '/behat.yml', "default:\n  suites:\n    default:\n      contexts: []\n" );

		// The runner loads the Gherkin parser and Behat from the project it runs in.
		$vendor = dirname( dirname( __DIR__ ) ) . '/vendor';
		if ( ! @symlink( $vendor, $this->temp_dir . '/vendor' ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Skipped below.
			$this->markTestSkipped( 'The vendor directory cannot be linked into the temporary project.' );
		}
	}

	protected function tear_down(): void {
		if ( null === $this->temp_dir ) {
			parent::tear_down();
			return;
		}

		if ( is_link( $this->temp_dir . '/vendor' ) ) {
			unlink( $this->temp_dir . '/vendor' );
		}
		$this->remove_dir( $this->get_base_dir() );
		$this->remove_dir( $this->temp_dir );

		parent::tear_down();
	}

	public function test_shards_run_every_scenario_once(): void {
		$expected = $this->write_features();

		$picked = [];
		for ( $index = 1; $index <= 3; $index++ ) {
			$result = $this->run_shard( "{$index}/3" );
			$this->assertSame( 0, $result['exit_code'], $result['output'] );

			$shard = $this->get_shard_scenarios();
			$this->assertNotEmpty( $shard, "Shard {$index} of 3 got no scenarios." );
			$this->assertStringContainsString( sprintf( 'Running shard %d of 3, with %d of %d scenarios.', $index, count( $shard ), count( $expected ) ), $result['output'] );

			$picked = array_merge( $picked, $shard );
		}

		sort( $picked );
		$this->assertSame( $expected, $picked );
	}

	public function test_shards_are_balanced_by_cost(): void {
		$this->write_feature(
			'install.feature',
			[
				'Install one' => [ 'Given a WP install' ],
				'Install two' => [ 'Given a WP install' ],
			]
		);
		$this->write_feature(
			'cheap.feature',
			[
				'Cheap one'   => [ 'Given an empty directory' ],
				'Cheap two'   => [ 'Given an empty directory' ],
				'Cheap three' => [ 'Given an empty directory' ],
				'Cheap four'  => [ 'Given an empty directory' ],
			]
		);

		// The two installations are the most expensive by far, so each shard gets one of them.
		foreach ( [ '1/2', '2/2' ] as $shard ) {
			$this->run_shard( $shard );
			$installs = array_filter(
				$this->get_shard_scenarios(),
				static function ( $path ) {
					return false !== strpos( $path, 'install.feature' );
				}
			);
			$this->assertCount( 1, $installs, "Shard {$shard}" );
		}
	}

	public function test_shard_without_scenarios_succeeds(): void {
		$this->write_feature( 'only.feature', [ 'The only one' => [ 'Given an empty directory' ] ] );

		$result = $this->run_shard( '2/2' );

		$this->assertSame( 0, $result['exit_code'], $result['output'] );
		$this->assertStringContainsString( 'Running shard 2 of 2, with 0 of 1 scenarios.', $result['output'] );
		$this->assertSame( [], $this->get_shard_scenarios() );
	}

	public function test_invalid_shard_runs_all_scenarios(): void {
		$this->write_features();

		$result = $this->run_shard( '3/2' );

		$this->assertStringContainsString( 'WP_CLI_TEST_SHARD is not of the form <index>/<total>', $result['output'] );
		$this->assertStringNotContainsString( 'Running shard', $result['output'] );
	}

	public function test_selected_scenarios_ignore_the_shard(): void {
		$this->write_features();

		$result = $this->run_shard( '1/3', [ 'features/a.feature' ] );

		$this->assertStringNotContainsString( 'Running shard', $result['output'] );
	}

	/**
	 * Write feature files with a mix of scenarios, including an outline and one using shared resources.
	 *
	 * @return string[] Paths of all scenarios, sorted.
	 */
	private function write_features(): array {
		$this->write_feature(
			'a.feature',
			[
				'First'  => [ 'Given a WP install', 'When I run `wp option get home`' ],
				'Second' => [ 'Given an empty directory' ],
				'Server' => [ 'Given a WP install', 'And a PHP built-in web server' ],
			]
		);
		$this->write_feature(
			'b.feature',
			[
				'Third'  => [ 'Given an empty directory', 'When I run `wp cli version`' ],
				'Fourth' => [ 'Given a WP install with Composer' ],
			],
			"  Scenario Outline: Fifth\n    Given an empty directory\n    When I run `wp <command>`\n\n    Examples:\n      | command    |\n      | cli info   |\n      | cli version |\n"
		);
		$this->write_feature(
			'c.feature',
			[
				'Sixth'   => [ 'Given an empty directory' ],
				'Seventh' => [ 'Given a WP install', 'When I run `wp plugin list`' ],
			]
		);

		$paths = [];
		foreach ( glob( $this->temp_dir . '/features/*.feature' ) as $file ) {
			$lines = file( $file );
			foreach ( $lines as $number => $line ) {
				if ( preg_match( '/^\s*Scenario( Outline)?:/', $line ) ) {
					$paths[] = 'features/' . basename( $file ) . ':' . ( $number + 1 );
				}
			}
		}
		sort( $paths );

		return $paths;
	}

	/**
	 * @param string                  $name      File name.
	 * @param array<string, string[]> $scenarios Steps by scenario title.
	 * @param string                  $extra     Additional Gherkin to append.
	 */
	private function write_feature( $name, array $scenarios, $extra = '' ): void {
		$contents = "Feature: {$name}\n";
		foreach ( $scenarios as $title => $steps ) {
			$contents .= "\n  Scenario: {$title}\n    " . implode( "\n    ", $steps ) . "\n";
		}
		if ( '' !== $extra ) {
			$contents .= "\n" . $extra;
		}

		file_put_contents( $this->temp_dir . '/features/' . $name, $contents );
	}

	/**
	 * Run the runner with a single worker, as run-behat-tests does for a shard.
	 *
	 * @param string   $shard     Value of WP_CLI_TEST_SHARD.
	 * @param string[] $user_args Arguments passed to run-behat-tests.
	 * @return array{output: string, exit_code: int}
	 */
	private function run_shard( $shard, array $user_args = [] ): array {
		$script  = dirname( dirname( __DIR__ ) ) . '/utils/behat-parallel.php';
		$command = array_merge( [ PHP_BINARY, $script, '1', '--dry-run', '--format=progress', '--' ], $user_args );

		$env = array_merge(
			getenv(),
			[
				'WP_CLI_TEST_SHARD'     => $shard,
				'WP_CLI_TEST_DOCKER'    => '0',
				'BEHAT_FEATURES_FOLDER' => 'features',
			]
		);
		unset( $env['WP_CLI_TEST_COVERAGE'] );

		$process = proc_open(
			implode( ' ', array_map( 'escapeshellarg', $command ) ) . ' 2>&1',
			[ 1 => [ 'pipe', 'w' ] ],
			$pipes,
			$this->temp_dir,
			$env
		);
		$this->assertIsResource( $process );

		$output = (string) stream_get_contents( $pipes[1] );
		fclose( $pipes[1] );

		return [
			'output'    => $output,
			'exit_code' => proc_close( $process ),
		];
	}

	/**
	 * Get the scenarios the last run handed to Behat for its shard.
	 *
	 * @return string[]
	 */
	private function get_shard_scenarios(): array {
		$list = $this->get_base_dir() . '/shard.scenarios';
		$this->assertFileExists( $list );

		return array_values( array_filter( explode( "\n", (string) file_get_contents( $list ) ) ) );
	}

	/**
	 * Directory the runner keeps its state in, which depends on the project directory.
	 */
	private function get_base_dir(): string {
		return sys_get_temp_dir() . '/wp-cli-test-parallel-' . substr( md5( (string) realpath( $this->temp_dir ) ), 0, 8 );
	}

	private function remove_dir( string $dir ): void {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		$items = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach ( $items as $item ) {
			$item->isDir() && ! $item->isLink() ? rmdir( (string) $item ) : unlink( (string) $item );
		}
		rmdir( $dir );
	}
}
