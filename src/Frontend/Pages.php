<?php
/**
 * Shortcodes for the member pages.
 *
 * @package FavrMembers
 */

declare(strict_types=1);

namespace FavrMembers\Frontend;

use FavrMembers\Support\Settings;

/**
 * [favr_login]    Login, lost password and set-new-password (?action=lostpassword|rp).
 * [favr_account]  The member dashboard (shows the login form when logged out).
 * [favr_register] The membership application form (when registration is open).
 */
final class Pages {

	/** Hook. */
	public function hook(): void {
		add_shortcode( 'favr_login', array( $this, 'login' ) );
		add_shortcode( 'favr_account', array( $this, 'account' ) );
		add_shortcode( 'favr_register', array( $this, 'register' ) );
	}

	/** Login / lost / reset. */
	public function login(): string {
		Assets::enqueue();
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- display only.
		$action = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : '';
		if ( 'rp' === $action || 'resetpass' === $action ) {
			list( $login, $key ) = Auth::resetCookie();
			$valid               = '' !== $key && ! is_wp_error( check_password_reset_key( $key, $login ) );
			return View::render( 'reset-password', array( 'valid' => $valid ) );
		}
		// phpcs:enable
		if ( 'lostpassword' === $action ) {
			return View::render( 'lost-password' );
		}
		if ( is_user_logged_in() ) {
			return View::render( 'logged-in' );
		}
		return View::render( 'login' );
	}

	/** Dashboard. */
	public function account(): string {
		Assets::enqueue();
		if ( ! is_user_logged_in() ) {
			return View::render( 'login', array( 'redirect' => Settings::pageUrl( 'account_page' ) ) );
		}
		return Dashboard::render();
	}

	/** Application form. */
	public function register(): string {
		Assets::enqueue();
		if ( 'open' !== Settings::get( 'registration' ) ) {
			return View::render( 'register-closed' );
		}
		if ( is_user_logged_in() ) {
			return View::render( 'logged-in' );
		}
		return View::render( 'register' );
	}

	/**
	 * Human text for a ?favr_msg code.
	 *
	 * @param string $code Code.
	 */
	public static function message( string $code ): string {
		$messages = array(
			'reset_sent'          => __( 'If that account exists, we’ve emailed a link to choose a new password. Check your inbox (and spam folder).', 'favr-members' ),
			'password_set'        => __( 'Your password is set and you’re logged in. Welcome!', 'favr-members' ),
			'applied'             => __( 'Thanks for applying! Your membership is pending review. We’ll email you once it’s approved.', 'favr-members' ),
			'profile_saved'       => __( 'Your profile has been updated.', 'favr-members' ),
			'check_email'         => __( 'Thanks for applying! Check your inbox for a link to confirm your email and choose a password. We’ll review your application and let you know once it’s approved.', 'favr-members' ),
			'email_change_sent'   => __( 'Almost done: we’ve sent a confirmation link to your new email address. Your email changes once you click it.', 'favr-members' ),
			'email_changed'       => __( 'Your email address has been updated.', 'favr-members' ),
			'email_change_failed' => __( 'That confirmation link is invalid or has expired. Please try changing your email again.', 'favr-members' ),
		);
		return $messages[ $code ] ?? '';
	}

	/** Message for the current request ('' when none). */
	public static function currentMessage(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
		return isset( $_GET['favr_msg'] ) ? self::message( sanitize_key( wp_unslash( $_GET['favr_msg'] ) ) ) : '';
	}
}
