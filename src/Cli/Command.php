<?php
/**
 * WP-CLI commands.
 *
 * @package FavrMembers
 */

declare(strict_types=1);

namespace FavrMembers\Cli;

use FavrMembers\ImportExport\Csv;
use FavrMembers\Model\Lapse;

/**
 * Manage Favr Members.
 */
final class Command {

	/**
	 * Import members from CSV.
	 *
	 * ## OPTIONS
	 *
	 * <file>
	 * : CSV file.
	 *
	 * [--dry-run]
	 * : Validate only.
	 *
	 * [--send-invites]
	 * : Email new logins an invite.
	 *
	 * @param list<string>          $args       Args.
	 * @param array<string, string> $assoc_args Flags.
	 */
	public function import( array $args, array $assoc_args ): void {
		if ( ! is_readable( $args[0] ?? '' ) ) {
			\WP_CLI::error( 'Cannot read the file.' );
		}
		$report = ( new Csv( isset( $assoc_args['dry-run'] ), isset( $assoc_args['send-invites'] ) ) )->import( $args[0] );
		foreach ( $report['errors'] as $error ) {
			\WP_CLI::warning( $error );
		}
		\WP_CLI::success( sprintf( 'Created %d, updated %d, skipped %d.', $report['created'], $report['updated'], $report['skipped'] ) );
	}

	/**
	 * Export members to CSV.
	 *
	 * [--file=<file>]
	 * : Output path (default STDOUT).
	 *
	 * @param list<string>          $args       Args.
	 * @param array<string, string> $assoc_args Flags.
	 */
	public function export( array $args, array $assoc_args ): void {
		$handle = fopen( $assoc_args['file'] ?? 'php://stdout', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( ! $handle ) {
			\WP_CLI::error( 'Cannot write the file.' );
		}
		$count = ( new Csv() )->export( $handle );
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		if ( isset( $assoc_args['file'] ) ) {
			\WP_CLI::success( "Exported {$count} members." );
		}
	}

	/**
	 * Run the daily lapse check now.
	 */
	public function lapse(): void {
		\WP_CLI::success( sprintf( '%d members marked lapsed.', ( new Lapse() )->run() ) );
	}

	/**
	 * Create sample members (individuals and businesses) for demos.
	 */
	public function seed(): void {
		$report = ( new Csv() )->import( FAVR_MEMBERS_PATH . 'data/sample-members.csv' );
		\WP_CLI::success( sprintf( 'Created %d, updated %d.', $report['created'], $report['updated'] ) );
	}
}
