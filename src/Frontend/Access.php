<?php
/**
 * Keeps members on the front end.
 *
 * @package FavrMembers
 */

declare(strict_types=1);

namespace FavrMembers\Frontend;

use FavrMembers\Schema\Identifiers as ID;
use FavrMembers\Support\Settings;

/**
 * People whose only role is "Member" never see wp-admin or the admin bar; logging in anywhere
 * takes them to their dashboard. Also records each login time for staff.
 */
final class Access {

	/** Hook. */
	public function hook(): void {
		add_action( 'admin_init', array( $this, 'redirectFromAdmin' ) );
		add_filter( 'show_admin_bar', array( $this, 'adminBar' ) );
		add_filter( 'login_redirect', array( $this, 'loginRedirect' ), 10, 3 );
		add_action( 'wp_login', array( $this, 'recordLogin' ), 10, 2 );
		add_filter( 'logout_redirect', array( $this, 'logoutRedirect' ), 10, 3 );
		add_action( 'template_redirect', array( $this, 'noCache' ), 1 );
	}

	/**
	 * Member pages are personal (and carry form nonces), so page caches must never store
	 * them. The login page also withholds the referrer so emailed-link URLs never leak.
	 */
	public function noCache(): void {
		if ( ! is_singular() ) {
			return;
		}
		$id       = (int) get_queried_object_id();
		$pages    = array_map( 'intval', array( Settings::get( 'login_page' ), Settings::get( 'account_page' ), Settings::get( 'register_page' ) ) );
		$post     = get_post( $id );
		$personal = in_array( $id, $pages, true ) || ( $post && ( has_shortcode( $post->post_content, 'favr_login' ) || has_shortcode( $post->post_content, 'favr_account' ) || has_shortcode( $post->post_content, 'favr_register' ) ) );
		$gated    = $post && ( get_post_meta( $id, ID::PAGE_META_GATED, true ) || has_block( ID::BLOCK_GATE, $post ) || has_shortcode( $post->post_content, 'favr_members_only' ) );
		if ( ! $personal && ! $gated ) {
			return;
		}
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- cache-plugin convention.
		}
		nocache_headers();
		if ( (int) Settings::get( 'login_page' ) === $id ) {
			header( 'Referrer-Policy: no-referrer' );
		}
	}

	/**
	 * Whether a user is only a member (no staff role).
	 *
	 * @param \WP_User|null $user User.
	 */
	public static function isMemberOnly( ?\WP_User $user = null ): bool {
		$user = $user ?? wp_get_current_user();
		return $user->exists() && array() === array_diff( (array) $user->roles, array( ID::ROLE_PERSON ) ) && in_array( ID::ROLE_PERSON, (array) $user->roles, true );
	}

	/** Send members to their dashboard instead of wp-admin. */
	public function redirectFromAdmin(): void {
		if ( wp_doing_ajax() || ( defined( 'DOING_CRON' ) && DOING_CRON ) || ! self::isMemberOnly() ) {
			return;
		}
		global $pagenow;
		if ( 'admin-post.php' === $pagenow ) {
			return;
		}
		wp_safe_redirect( Settings::pageUrl( 'account_page' ) ?: home_url( '/' ) );
		exit;
	}

	/**
	 * Hide the admin bar for members.
	 *
	 * @param bool $show Show.
	 */
	public function adminBar( bool $show ): bool {
		return self::isMemberOnly() ? false : $show;
	}

	/**
	 * After logging in (anywhere), members land on their dashboard.
	 *
	 * @param string             $redirect  Redirect.
	 * @param string             $requested Requested redirect.
	 * @param \WP_User|\WP_Error $user      User.
	 */
	public function loginRedirect( string $redirect, string $requested, $user ): string {
		if ( $user instanceof \WP_User && self::isMemberOnly( $user ) && ( '' === $requested || str_contains( $requested, 'wp-admin' ) ) ) {
			return Settings::pageUrl( 'account_page' ) ?: $redirect;
		}
		return $redirect;
	}

	/**
	 * Members return to the login page after logging out.
	 *
	 * @param string             $redirect  Redirect.
	 * @param string             $requested Requested.
	 * @param \WP_User|\WP_Error $user      User.
	 */
	public function logoutRedirect( string $redirect, string $requested, $user ): string {
		if ( $user instanceof \WP_User && self::isMemberOnly( $user ) && '' === $requested ) {
			$login = Settings::pageUrl( 'login_page' );
			return '' !== $login ? add_query_arg( 'loggedout', '1', $login ) : $redirect;
		}
		return $redirect;
	}

	/**
	 * Remember the last login (shown to staff).
	 *
	 * @param string   $login Login.
	 * @param \WP_User $user  User.
	 */
	public function recordLogin( string $login, $user ): void {
		if ( $user instanceof \WP_User ) {
			update_user_meta( $user->ID, 'favr_members_last_login', time() );
		}
	}
}
