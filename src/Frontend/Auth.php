<?php
/**
 * Front-end account form handlers.
 *
 * @package FavrMembers
 */

declare(strict_types=1);

namespace FavrMembers\Frontend;

use FavrMembers\Model\Accounts;
use FavrMembers\Model\Member;
use FavrMembers\Model\Repository;
use FavrMembers\Schema\Identifiers as ID;
use FavrMembers\Support\Settings;

/**
 * Handles POSTs from the login, lost-password, reset, join and profile forms before the page
 * renders. Errors are kept for the current request (the form re-renders with them); success
 * redirects (PRG) with a message code.
 */
final class Auth {

	/**
	 * Errors for the current request, keyed by form.
	 *
	 * @var array<string, list<string>>
	 */
	private static array $errors = array();

	/** Hook. */
	public function hook(): void {
		add_action( 'template_redirect', array( $this, 'links' ), 5 );
		add_action( 'template_redirect', array( $this, 'handle' ) );
	}

	/** Name of the cookie that carries a reset key (so the key never stays in the URL). */
	public const RP_COOKIE = 'favr-members-rp';

	/**
	 * Emailed links: move reset/invite keys into an HttpOnly cookie and redirect to a clean URL
	 * (like core's wp-login.php), and apply confirmed email changes.
	 */
	public function links(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- the key/token is the credential.
		$action = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : '';
		if ( 'rp' === $action && isset( $_GET['key'], $_GET['login'] ) ) {
			$key   = sanitize_text_field( wp_unslash( $_GET['key'] ) );
			$login = sanitize_user( wp_unslash( $_GET['login'] ) );
			$path  = (string) wp_parse_url( Settings::pageUrl( 'login_page' ) ?: home_url( '/' ), PHP_URL_PATH );
			setcookie( self::RP_COOKIE, $login . ':' . $key, 0, '' !== $path ? $path : '/', COOKIE_DOMAIN, is_ssl(), true );
			wp_safe_redirect( add_query_arg( 'action', 'rp', remove_query_arg( array( 'key', 'login' ) ) ) );
			exit;
		}
		if ( isset( $_GET['favr_confirm_email'], $_GET['uid'] ) ) {
			$ok = Accounts::confirmEmailChange( absint( $_GET['uid'] ), sanitize_text_field( wp_unslash( $_GET['favr_confirm_email'] ) ) );
			$this->done( add_query_arg( 'tab', 'profile', Settings::pageUrl( 'account_page' ) ?: home_url( '/' ) ), $ok ? 'email_changed' : 'email_change_failed' );
		}
		// phpcs:enable
	}

	/**
	 * Reset key and login from the cookie set by links().
	 *
	 * @return array{0: string, 1: string} [ login, key ] (empty strings when absent).
	 */
	public static function resetCookie(): array {
		$raw = isset( $_COOKIE[ self::RP_COOKIE ] ) ? sanitize_text_field( wp_unslash( $_COOKIE[ self::RP_COOKIE ] ) ) : '';
		if ( ! str_contains( $raw, ':' ) ) {
			return array( '', '' );
		}
		list( $login, $key ) = explode( ':', $raw, 2 );
		return array( sanitize_user( $login ), $key );
	}

	/** Forget the reset cookie. */
	private static function clearResetCookie(): void {
		$path = (string) wp_parse_url( Settings::pageUrl( 'login_page' ) ?: home_url( '/' ), PHP_URL_PATH );
		setcookie( self::RP_COOKIE, ' ', time() - YEAR_IN_SECONDS, '' !== $path ? $path : '/', COOKIE_DOMAIN, is_ssl(), true );
	}

	/**
	 * Errors for a form.
	 *
	 * @param string $form Form id.
	 * @return list<string>
	 */
	public static function errors( string $form ): array {
		return self::$errors[ $form ] ?? array();
	}

	/**
	 * Record an error.
	 *
	 * @param string $form    Form id.
	 * @param string $message Message.
	 */
	private static function fail( string $form, string $message ): void {
		self::$errors[ $form ][] = $message;
	}

	/**
	 * Redirect with a message code (PRG).
	 *
	 * @param string $url  Target.
	 * @param string $code Message code (see Pages::message()).
	 * @return never
	 */
	private function done( string $url, string $code ): void {
		wp_safe_redirect( add_query_arg( 'favr_msg', $code, $url ) );
		exit;
	}

	/** Dispatch. */
	public function handle(): void {
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_key( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- the nonce is verified just below, per action.
		if ( 'post' !== $method || empty( $_POST['favr_members_action'] ) ) {
			return;
		}
		$action = sanitize_key( wp_unslash( $_POST['favr_members_action'] ) );
		if ( ! isset( $_POST['_favr_members_nonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['_favr_members_nonce'] ) ), ID::NONCE_FRONT . '_' . $action ) ) {
			self::fail( $action, __( 'Your session expired. Please try again.', 'favr-members' ) );
			return;
		}
		switch ( $action ) {
			case 'login':
				$this->login();
				break;
			case 'lostpassword':
				$this->lostPassword();
				break;
			case 'resetpass':
				$this->resetPassword();
				break;
			case 'register':
				$this->register();
				break;
			case 'profile':
				$this->profile();
				break;
		}
	}

	/** Log in. */
	private function login(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified in handle().
		$creds    = array(
			'user_login'    => sanitize_text_field( wp_unslash( $_POST['log'] ?? '' ) ),
			'user_password' => (string) wp_unslash( $_POST['pwd'] ?? '' ), // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- passwords are not sanitized.
			'remember'      => ! empty( $_POST['rememberme'] ),
		);
		$redirect = isset( $_POST['redirect_to'] ) ? wp_validate_redirect( esc_url_raw( wp_unslash( $_POST['redirect_to'] ) ), '' ) : '';
		// phpcs:enable
		$user = wp_signon( $creds, is_ssl() );
		if ( is_wp_error( $user ) ) {
			self::fail( 'login', __( 'That email/username and password didn’t match. Please try again.', 'favr-members' ) );
			return;
		}
		$target = '' !== $redirect ? $redirect : Settings::pageUrl( 'account_page' );
		wp_safe_redirect( '' !== $target ? $target : home_url( '/' ) );
		exit;
	}

	/** Email a reset link. Always reports success so emails can't be probed. */
	private function lostPassword(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in handle().
		$login = sanitize_text_field( wp_unslash( $_POST['user_login'] ?? '' ) );
		if ( '' === $login ) {
			self::fail( 'lostpassword', __( 'Please enter your email address or username.', 'favr-members' ) );
			return;
		}
		if ( $this->rateLimited( 'lostpassword' ) ) {
			self::fail( 'lostpassword', __( 'Too many requests. Please wait a few minutes and try again.', 'favr-members' ) );
			return;
		}
		retrieve_password( $login );
		$this->done( add_query_arg( 'action', 'lostpassword', Settings::pageUrl( 'login_page' ) ), 'reset_sent' );
	}

	/** Set a new password from an emailed link, then log in. */
	private function resetPassword(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified in handle().
		list( $login, $key ) = self::resetCookie();
		$pass1               = (string) wp_unslash( $_POST['pass1'] ?? '' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- passwords are not sanitized.
		$pass2               = (string) wp_unslash( $_POST['pass2'] ?? '' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		// phpcs:enable
		$user = check_password_reset_key( $key, $login );
		if ( is_wp_error( $user ) ) {
			self::fail( 'resetpass', __( 'This link has expired or was already used. Request a new one below.', 'favr-members' ) );
			return;
		}
		$problem = self::passwordProblem( $pass1, $pass2 );
		if ( '' !== $problem ) {
			self::fail( 'resetpass', $problem );
			return;
		}
		reset_password( $user, $pass1 );
		self::clearResetCookie();
		// Using a link sent to the address proves the person owns it.
		delete_user_meta( $user->ID, Accounts::META_UNVERIFIED );
		wp_set_current_user( $user->ID );
		wp_set_auth_cookie( $user->ID, true, is_ssl() );
		do_action( 'wp_login', $user->user_login, $user ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core hook.
		$this->done( Settings::pageUrl( 'account_page' ) ?: home_url( '/' ), 'password_set' );
	}

	/**
	 * Pure password rule.
	 *
	 * @param string $pass1 Password.
	 * @param string $pass2 Confirmation.
	 */
	public static function passwordProblem( string $pass1, string $pass2 ): string {
		if ( strlen( $pass1 ) < 8 ) {
			return __( 'Please use at least 8 characters.', 'favr-members' );
		}
		if ( $pass1 !== $pass2 ) {
			return __( 'The two passwords don’t match.', 'favr-members' );
		}
		return '';
	}

	/**
	 * Membership application (only when registration is open). No password is chosen here:
	 * the applicant confirms their email and sets a password from an emailed link, so an
	 * account can never be created for an address its creator doesn't control. The response
	 * is identical whether or not the email already has an account (no enumeration).
	 */
	private function register(): void {
		if ( 'open' !== Settings::get( 'registration' ) ) {
			self::fail( 'register', __( 'Applications are not open. Please contact us to join.', 'favr-members' ) );
			return;
		}
		if ( $this->rateLimited( 'register' ) ) {
			self::fail( 'register', __( 'Too many requests. Please wait a few minutes and try again.', 'favr-members' ) );
			return;
		}
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified in handle().
		if ( ! empty( $_POST['favr_website'] ) ) { // Honeypot: bots fill every field.
			$this->done( Settings::pageUrl( 'register_page' ), 'check_email' );
		}
		$type     = ID::TYPE_BUSINESS === sanitize_key( wp_unslash( $_POST['type'] ?? '' ) ) ? ID::TYPE_BUSINESS : ID::TYPE_INDIVIDUAL;
		$first    = sanitize_text_field( wp_unslash( $_POST['first_name'] ?? '' ) );
		$last     = sanitize_text_field( wp_unslash( $_POST['last_name'] ?? '' ) );
		$business = sanitize_text_field( wp_unslash( $_POST['business_name'] ?? '' ) );
		$email    = sanitize_email( wp_unslash( $_POST['email'] ?? '' ) );
		$phone    = sanitize_text_field( wp_unslash( $_POST['phone'] ?? '' ) );
		// phpcs:enable

		if ( '' === $first || '' === $last ) {
			self::fail( 'register', __( 'Please enter your first and last name.', 'favr-members' ) );
		}
		if ( ID::TYPE_BUSINESS === $type && '' === $business ) {
			self::fail( 'register', __( 'Please enter your business name.', 'favr-members' ) );
		}
		if ( ! is_email( $email ) ) {
			self::fail( 'register', __( 'Please enter a valid email address.', 'favr-members' ) );
		}
		if ( self::errors( 'register' ) ) {
			return;
		}

		$existing = get_user_by( 'email', $email );
		if ( $existing ) {
			Accounts::sendAlreadyRegistered( $existing );
			$this->done( Settings::pageUrl( 'register_page' ), 'check_email' );
		}

		$user_id = Accounts::create( $email, $first, $last );
		if ( is_wp_error( $user_id ) ) {
			self::fail( 'register', __( 'Something went wrong. Please try again.', 'favr-members' ) );
			return;
		}
		update_user_meta( $user_id, Accounts::META_UNVERIFIED, 1 );

		$member_id = wp_insert_post(
			array(
				'post_type'   => ID::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => Member::displayName( $type, $first, $last, $business ),
			),
			true
		);
		if ( is_wp_error( $member_id ) ) {
			self::fail( 'register', __( 'Something went wrong. Please try again.', 'favr-members' ) );
			return;
		}
		$values = array(
			'type'          => $type,
			'first_name'    => ID::TYPE_INDIVIDUAL === $type ? $first : '',
			'last_name'     => ID::TYPE_INDIVIDUAL === $type ? $last : '',
			'business_name' => $business,
			'email'         => $email,
			'phone'         => $phone,
			'status'        => ID::STATUS_PENDING,
		);
		foreach ( array_filter( $values ) as $key => $value ) {
			update_post_meta( (int) $member_id, ID::meta( $key ), $value );
		}
		update_post_meta( (int) $member_id, ID::META_APPLIED, 1 );
		$member = Member::find( (int) $member_id );
		if ( $member ) {
			$member->addUser( $user_id );
			/** This action is documented in src/Admin/EditScreen.php */
			do_action( 'favr_members_member_saved', $member, 'registration' );
		}
		wp_cache_delete( 'status_counts', 'favr_members' );

		Accounts::sendApplicationConfirmation( $user_id );
		Accounts::mail(
			(string) get_option( 'admin_email' ),
			/* translators: %s: applicant name. */
			sprintf( __( 'New membership application: %s', 'favr-members' ), Member::displayName( $type, $first, $last, $business ) ),
			implode(
				"\n",
				array_filter(
					array(
						__( 'A new membership application is waiting for review.', 'favr-members' ),
						'',
						/* translators: %s: name. */
						sprintf( __( 'Name: %s', 'favr-members' ), trim( "$first $last" ) ),
						'' !== $business ? sprintf( /* translators: %s: business. */ __( 'Business: %s', 'favr-members' ), $business ) : null,
						/* translators: %s: email. */
						sprintf( __( 'Email: %s', 'favr-members' ), $email ),
						'',
						__( 'Review it here:', 'favr-members' ),
						admin_url( 'post.php?post=' . (int) $member_id . '&action=edit' ),
					),
					static fn( $line ): bool => null !== $line
				)
			)
		);

		$this->done( Settings::pageUrl( 'register_page' ), 'check_email' );
	}

	/** Update the logged-in person's profile (and their individual membership contact details). */
	private function profile(): void {
		$user = wp_get_current_user();
		if ( ! $user->exists() ) {
			return;
		}
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified in handle().
		$first   = sanitize_text_field( wp_unslash( $_POST['first_name'] ?? '' ) );
		$last    = sanitize_text_field( wp_unslash( $_POST['last_name'] ?? '' ) );
		$email   = sanitize_email( wp_unslash( $_POST['email'] ?? '' ) );
		$phone   = sanitize_text_field( wp_unslash( $_POST['phone'] ?? '' ) );
		$title   = sanitize_text_field( wp_unslash( $_POST['job_title'] ?? '' ) );
		$current = (string) wp_unslash( $_POST['current_password'] ?? '' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$pass1   = (string) wp_unslash( $_POST['pass1'] ?? '' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$pass2   = (string) wp_unslash( $_POST['pass2'] ?? '' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		// phpcs:enable

		$update = array(
			'ID'           => $user->ID,
			'first_name'   => $first,
			'last_name'    => $last,
			'display_name' => trim( "$first $last" ) ?: $user->display_name,
		);

		$sensitive = ( '' !== $email && strtolower( $email ) !== strtolower( $user->user_email ) ) || '' !== $pass1;
		if ( $sensitive && ! wp_check_password( $current, $user->user_pass, $user->ID ) ) {
			self::fail( 'profile', __( 'Please enter your current password to change your email or password.', 'favr-members' ) );
			return;
		}
		$email_change = '';
		if ( '' !== $email && strtolower( $email ) !== strtolower( $user->user_email ) ) {
			if ( ! is_email( $email ) ) {
				self::fail( 'profile', __( 'Please enter a valid email address.', 'favr-members' ) );
				return;
			}
			if ( email_exists( $email ) ) {
				self::fail( 'profile', __( 'That email is already used by another account.', 'favr-members' ) );
				return;
			}
			// Takes effect only after the new address is confirmed (see Accounts::confirmEmailChange()).
			$email_change = $email;
		}
		if ( '' !== $pass1 ) {
			$problem = self::passwordProblem( $pass1, $pass2 );
			if ( '' !== $problem ) {
				self::fail( 'profile', $problem );
				return;
			}
			$update['user_pass'] = $pass1;
		}

		$result = wp_update_user( $update );
		if ( is_wp_error( $result ) ) {
			self::fail( 'profile', $result->get_error_message() );
			return;
		}
		update_user_meta( $user->ID, 'favr_members_phone', $phone );
		update_user_meta( $user->ID, 'favr_members_job_title', $title );

		// Keep the person's own (individual) membership contact details in step.
		foreach ( Repository::forUser( $user->ID ) as $member ) {
			if ( $member->isBusiness() ) {
				continue;
			}
			$values = array(
				'first_name' => $first,
				'last_name'  => $last,
				'email'      => $user->user_email,
				'phone'      => $phone,
				'job_title'  => $title,
			);
			foreach ( $values as $key => $value ) {
				'' === $value ? delete_post_meta( $member->id(), ID::meta( $key ) ) : update_post_meta( $member->id(), ID::meta( $key ), $value );
			}
			wp_update_post(
				array(
					'ID'         => $member->id(),
					'post_title' => Member::displayName( ID::TYPE_INDIVIDUAL, $first, $last, '' ),
				)
			);
		}

		if ( isset( $update['user_pass'] ) ) {
			// Changing the password logs the session out; log straight back in.
			wp_set_auth_cookie( $user->ID, true, is_ssl() );
		}
		if ( '' !== $email_change ) {
			Accounts::requestEmailChange( $user, $email_change );
			$this->done( add_query_arg( 'tab', 'profile', Settings::pageUrl( 'account_page' ) ), 'email_change_sent' );
		}
		$this->done( add_query_arg( 'tab', 'profile', Settings::pageUrl( 'account_page' ) ), 'profile_saved' );
	}

	/**
	 * Simple per-IP rate limit (5 per 10 minutes per action).
	 *
	 * @param string $action Action.
	 */
	private function rateLimited( string $action ): bool {
		$ip  = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ?? '' ) );
		$key = 'favr_members_rl_' . md5( $action . '|' . $ip );
		$hit = (int) get_transient( $key );
		if ( $hit >= 5 ) {
			return true;
		}
		set_transient( $key, $hit + 1, 10 * MINUTE_IN_SECONDS );
		return false;
	}
}
