<?php
/**
 * Composition root.
 *
 * @package FavrMembers
 */

declare(strict_types=1);

namespace FavrMembers;

use FavrMembers\Admin;
use FavrMembers\Frontend;
use FavrMembers\Integration;
use FavrMembers\Model;

/**
 * Wires every service. Model, integration and front end always load; admin screens only in
 * wp-admin; CLI only under WP-CLI.
 */
final class Plugin {

	/**
	 * Whether boot() ran.
	 *
	 * @var bool
	 */
	private static bool $booted = false;

	/** Boot once. */
	public static function boot(): void {
		if ( self::$booted ) {
			return;
		}
		self::$booted = true;

		add_action( 'init', static fn() => load_plugin_textdomain( 'favr-members', false, dirname( plugin_basename( FAVR_MEMBERS_FILE ) ) . '/languages' ), 1 );
		add_action( 'init', array( Model\Activation::class, 'maybeUpgrade' ), 20 );

		( new Model\Registrar() )->hook();
		( new Model\Accounts() )->hook();
		( new Model\Lapse() )->hook();
		( new Integration\Directory() )->hook();
		( new Integration\DirectoryEditing() )->hook();

		( new Frontend\Auth() )->hook();
		( new Frontend\Pages() )->hook();
		( new Frontend\Access() )->hook();
		( new Frontend\Restrict() )->hook();
		add_action( 'wp_enqueue_scripts', array( self::class, 'enqueueEarly' ) );

		if ( is_admin() ) {
			( new Admin\Assets() )->hook();
			( new Admin\EditScreen() )->hook();
			( new Admin\ListScreen() )->hook();
			( new Admin\UsersScreen() )->hook();
			( new Admin\LevelScreen() )->hook();
			( new Admin\SettingsPage() )->hook();
			( new Admin\ImportExportPage() )->hook();
			( new Admin\ApplicationsQueue() )->hook();
			\FavrMembers\Vendor\FavrCore\Approvals\Inbox::boot();
		}

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'favr-members', Cli\Command::class );
		}

		/**
		 * Fires after Favr Members has wired its services.
		 */
		do_action( 'favr_members_loaded' );
	}

	/** Load the stylesheet in <head> on pages that contain member shortcodes/blocks. */
	public static function enqueueEarly(): void {
		$post = get_post();
		if ( ! is_singular() || ! $post ) {
			return;
		}
		foreach ( array( 'favr_login', 'favr_account', 'favr_register', 'favr_members_only' ) as $code ) {
			if ( has_shortcode( $post->post_content, $code ) ) {
				Frontend\Assets::enqueue();
				return;
			}
		}
		if ( has_block( 'favr-members/members-only', $post ) || get_post_meta( $post->ID, Schema\Identifiers::PAGE_META_GATED, true ) ) {
			Frontend\Assets::enqueue();
		}
	}
}
