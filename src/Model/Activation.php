<?php
/**
 * Activation, capabilities, pages and upgrades.
 *
 * @package FavrMembers
 */

declare(strict_types=1);

namespace FavrMembers\Model;

use FavrMembers\Schema\Identifiers as ID;
use FavrMembers\Support\Settings;

/**
 * Never touches existing members. Installs caps/roles, creates the member pages once, seeds
 * default levels when none exist and schedules the daily membership check.
 */
final class Activation {

	/** Activated. */
	public static function activate(): void {
		( new Registrar() )->registerPostType();
		( new Registrar() )->registerLevels();
		self::installCaps();
		self::createPages();
		self::seedLevels();
		if ( ! wp_next_scheduled( ID::CRON_LAPSE ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', ID::CRON_LAPSE );
		}
		update_option( ID::OPTION_VERSION, FAVR_MEMBERS_VERSION );
	}

	/** Deactivated. */
	public static function deactivate(): void {
		wp_clear_scheduled_hook( ID::CRON_LAPSE );
	}

	/** Upgrade routine when the code is newer than the stored version. */
	public static function maybeUpgrade(): void {
		if ( version_compare( (string) get_option( ID::OPTION_VERSION, '' ), FAVR_MEMBERS_VERSION, '>=' ) ) {
			return;
		}
		self::installCaps();
		update_option( ID::OPTION_VERSION, FAVR_MEMBERS_VERSION );
	}

	/**
	 * Staff capabilities.
	 *
	 * @return list<string>
	 */
	public static function caps(): array {
		$p = ID::CAP_PLURAL;
		return array( "edit_{$p}", "edit_others_{$p}", "edit_private_{$p}", "edit_published_{$p}", "publish_{$p}", "read_private_{$p}", "delete_{$p}", "delete_others_{$p}", "delete_private_{$p}", "delete_published_{$p}", ID::CAP_SETTINGS );
	}

	/** Grant caps and (re)create roles. Idempotent. */
	public static function installCaps(): void {
		foreach ( array( 'administrator', 'editor' ) as $role_name ) {
			$role = get_role( $role_name );
			if ( $role ) {
				foreach ( self::caps() as $cap ) {
					if ( ID::CAP_SETTINGS !== $cap || 'administrator' === $role_name ) {
						$role->add_cap( $cap );
					}
				}
			}
		}

		remove_role( ID::ROLE_PERSON );
		add_role( ID::ROLE_PERSON, __( 'Member', 'favr-members' ), array( 'read' => true ) );

		$caps = array_fill_keys( array_diff( self::caps(), array( ID::CAP_SETTINGS ) ), true );
		remove_role( ID::ROLE_MANAGER );
		add_role(
			ID::ROLE_MANAGER,
			__( 'Membership Manager', 'favr-members' ),
			$caps + array(
				'read'         => true,
				'list_users'   => true,
				'upload_files' => true,
			)
		);
		// Directory Managers (Favr Directory) can manage members too.
		$directory_manager = get_role( 'favr_directory_manager' );
		if ( $directory_manager ) {
			foreach ( array_keys( $caps ) as $cap ) {
				$directory_manager->add_cap( $cap );
			}
		}
	}

	/** Create the Member Login / My Account / Join pages once. */
	private static function createPages(): void {
		$pages = array(
			'login_page'    => array( __( 'Member Login', 'favr-members' ), '[favr_login]', 'publish' ),
			'account_page'  => array( __( 'My Account', 'favr-members' ), '[favr_account]', 'publish' ),
			// Registration is invite-only by default, so the join page starts as a draft.
			'register_page' => array( __( 'Become a Member', 'favr-members' ), '[favr_register]', 'draft' ),
		);
		$updates = array();
		foreach ( $pages as $key => $page ) {
			$existing = (int) Settings::get( $key );
			if ( $existing && get_post( $existing ) ) {
				continue;
			}
			$id = wp_insert_post(
				array(
					'post_type'    => 'page',
					'post_title'   => $page[0],
					'post_content' => '<!-- wp:shortcode -->' . $page[1] . '<!-- /wp:shortcode -->',
					'post_status'  => $page[2],
				)
			);
			if ( ! is_wp_error( $id ) ) {
				$updates[ $key ] = (int) $id;
			}
		}
		if ( $updates ) {
			Settings::update( $updates );
		}
	}

	/** Default levels, only when none exist (Favr Directory may already have seeded them). */
	private static function seedLevels(): void {
		$existing = get_terms(
			array(
				'taxonomy'   => ID::TAX_LEVEL,
				'hide_empty' => false,
				'number'     => 1,
				'fields'     => 'ids',
			)
		);
		if ( is_wp_error( $existing ) || array() !== $existing ) {
			return;
		}
		$levels = array(
			array( __( 'Platinum', 'favr-members' ), 1, '#6d28d9' ),
			array( __( 'Gold', 'favr-members' ), 2, '#b45309' ),
			array( __( 'Silver', 'favr-members' ), 3, '#64748b' ),
			array( __( 'Member', 'favr-members' ), 4, '#0f766e' ),
		);
		foreach ( $levels as $level ) {
			$term = wp_insert_term( $level[0], ID::TAX_LEVEL );
			if ( ! is_wp_error( $term ) ) {
				update_term_meta( (int) $term['term_id'], 'favr_level_order', $level[1] );
				update_term_meta( (int) $term['term_id'], 'favr_level_color', $level[2] );
			}
		}
	}
}
