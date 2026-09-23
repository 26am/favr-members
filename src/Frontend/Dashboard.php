<?php
/**
 * The member dashboard shell and its tab API.
 *
 * @package FavrMembers
 */

declare(strict_types=1);

namespace FavrMembers\Frontend;

use FavrMembers\Model\Repository;
use FavrMembers\Support\Settings;

/**
 * Other plugins add tabs with the `favr_members_dashboard_tabs` filter. Each tab is an array
 * with `label`, `priority` (lower = earlier), `render` (a callable that receives the WP_User
 * and returns HTML) and an optional `visible` boolean. Tabs are plain links (?tab=id), so the
 * dashboard works without JavaScript. See README.md for an example.
 */
final class Dashboard {

	/**
	 * Tabs for a user, sorted, visible only.
	 *
	 * @param \WP_User $user User.
	 * @return array<string, array<string, mixed>>
	 */
	public static function tabs( \WP_User $user ): array {
		$tabs = array(
			'overview' => array(
				'label'    => __( 'Overview', 'favr-members' ),
				'priority' => 10,
				'render'   => static fn( \WP_User $u ): string => View::render(
					'tabs/overview',
					array(
						'user'        => $u,
						'memberships' => Repository::forUser( $u->ID ),
					)
				),
			),
			'profile'  => array(
				'label'    => __( 'My Profile', 'favr-members' ),
				'priority' => 90,
				'render'   => static fn( \WP_User $u ): string => View::render( 'tabs/profile', array( 'user' => $u ) ),
			),
		);

		/**
		 * Filter the member dashboard tabs.
		 *
		 * @param array    $tabs Tab id => { label, priority, render( WP_User ): string, visible? }.
		 * @param \WP_User $user The logged-in person.
		 */
		$tabs = (array) apply_filters( 'favr_members_dashboard_tabs', $tabs, $user );

		$tabs = array_filter(
			$tabs,
			static fn( $tab ): bool => is_array( $tab ) && isset( $tab['label'], $tab['render'] ) && is_callable( $tab['render'] ) && ( $tab['visible'] ?? true )
		);
		uasort( $tabs, static fn( array $a, array $b ): int => ( (int) ( $a['priority'] ?? 50 ) ) <=> ( (int) ( $b['priority'] ?? 50 ) ) );
		return $tabs;
	}

	/**
	 * URL of a tab.
	 *
	 * @param string $tab Tab id.
	 */
	public static function url( string $tab = '' ): string {
		$base = Settings::pageUrl( 'account_page' );
		return '' === $tab ? $base : add_query_arg( 'tab', $tab, $base );
	}

	/** Render the dashboard for the current user. */
	public static function render(): string {
		$user = wp_get_current_user();
		$tabs = self::tabs( $user );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- navigation only.
		$current = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : '';
		if ( ! isset( $tabs[ $current ] ) ) {
			$current = (string) array_key_first( $tabs );
		}
		return View::render(
			'account',
			array(
				'user'    => $user,
				'tabs'    => $tabs,
				'current' => $current,
				'content' => (string) call_user_func( $tabs[ $current ]['render'], $user ),
			)
		);
	}
}
