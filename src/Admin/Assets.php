<?php
/**
 * Admin assets.
 *
 * @package FavrMembers
 */

declare(strict_types=1);

namespace FavrMembers\Admin;

use FavrMembers\Schema\Identifiers as ID;
use FavrMembers\Vendor\FavrCore\Support\AssetVersion;

/**
 * Shared field UI (favr/core, copied to assets/core) + Members' own admin styles.
 */
final class Assets {

	/** Hook. */
	public function hook(): void {
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/** Enqueue on member screens. */
	public function enqueue(): void {
		$screen = get_current_screen();
		if ( ! $screen || ( ID::POST_TYPE !== $screen->post_type && ID::TAX_LEVEL !== $screen->taxonomy ) ) {
			return;
		}
		$v = static fn( string $rel ): string => AssetVersion::of( FAVR_MEMBERS_PATH . $rel, FAVR_MEMBERS_VERSION );

		wp_enqueue_style( 'favr-core-fields', FAVR_MEMBERS_URL . 'assets/core/fields.css', array(), $v( 'assets/core/fields.css' ) );
		wp_enqueue_style( 'favr-members-admin', FAVR_MEMBERS_URL . 'assets/admin/admin.css', array( 'favr-core-fields' ), $v( 'assets/admin/admin.css' ) );
		wp_enqueue_script( 'favr-core-fields', FAVR_MEMBERS_URL . 'assets/core/fields.js', array( 'jquery' ), $v( 'assets/core/fields.js' ), true );
		wp_localize_script(
			'favr-core-fields',
			'favrCoreFields',
			array(
				'i18n' => array(
					/* translators: %d: characters left. */
					'charsLeft' => __( '%d characters left', 'favr-members' ),
				),
			)
		);
		wp_enqueue_script( 'favr-members-admin', FAVR_MEMBERS_URL . 'assets/admin/admin.js', array( 'jquery', 'favr-core-fields' ), $v( 'assets/admin/admin.js' ), true );
		if ( ID::TAX_LEVEL === $screen->taxonomy || 'favr_member_page_' . SettingsPage::SLUG === $screen->id ) {
			wp_enqueue_style( 'wp-color-picker' );
			wp_enqueue_script( 'wp-color-picker' );
		}
	}
}
