<?php
/**
 * Member logins: creation, invites, password links and emails.
 *
 * @package FavrMembers
 */

declare(strict_types=1);

namespace FavrMembers\Model;

use FavrMembers\Schema\Identifiers as ID;
use FavrMembers\Support\Settings;

/**
 * Members are ordinary WordPress users with the Member role. They never need to see wp-admin:
 * invites and password resets link to the front-end login page.
 */
final class Accounts {

	/** Hook. */
	public function hook(): void {
		add_filter( 'retrieve_password_message', array( $this, 'resetMessage' ), 10, 4 );
		add_filter( 'lostpassword_url', array( $this, 'lostPasswordUrl' ), 10, 2 );
	}

	/** User meta flag: the account's email address has not been proven yet. */
	public const META_UNVERIFIED = 'favr_members_unverified';

	/** User meta: pending email change { email, hash, expires }. */
	public const META_EMAIL_CHANGE = 'favr_members_email_change';

	/**
	 * Resolve an email to a login that staff may link to a membership: an existing, safe
	 * account, or a new Member-role account.
	 *
	 * Refuses (WP_Error) when the existing account has never proven it owns the email (e.g. a
	 * stranger applied with that address) or is a staff account the current user may not
	 * manage, so a membership can never be handed to the wrong person.
	 *
	 * @param string $email      Email.
	 * @param string $first_name First name.
	 * @param string $last_name  Last name.
	 * @return array{user_id: int, created: bool}|\WP_Error
	 */
	public static function resolve( string $email, string $first_name = '', string $last_name = '' ) {
		$email = sanitize_email( $email );
		if ( ! is_email( $email ) ) {
			return new \WP_Error( 'favr_members_email', __( 'Please enter a valid email address.', 'favr-members' ) );
		}
		$existing = get_user_by( 'email', $email );
		if ( $existing ) {
			if ( get_user_meta( $existing->ID, self::META_UNVERIFIED, true ) ) {
				/* translators: %s: email. */
				return new \WP_Error( 'favr_members_unverified', sprintf( __( 'An account for %s exists but hasn’t confirmed its email address yet, so it can’t be linked. Ask the person to click the confirmation link in their email, then try again.', 'favr-members' ), $email ) );
			}
			if ( user_can( $existing, 'edit_posts' ) && ! current_user_can( 'edit_user', $existing->ID ) ) {
				/* translators: %s: email. */
				return new \WP_Error( 'favr_members_staff', sprintf( __( '%s belongs to a staff account, which you don’t have permission to link to a membership.', 'favr-members' ), $email ) );
			}
			return array(
				'user_id' => (int) $existing->ID,
				'created' => false,
			);
		}
		$created = self::create( $email, $first_name, $last_name );
		return is_wp_error( $created ) ? $created : array(
			'user_id' => $created,
			'created' => true,
		);
	}

	/**
	 * Create a Member-role user.
	 *
	 * @param string $email      Email (validated).
	 * @param string $first_name First name.
	 * @param string $last_name  Last name.
	 * @return int|\WP_Error
	 */
	public static function create( string $email, string $first_name = '', string $last_name = '' ) {
		$user_id = wp_insert_user(
			array(
				'user_login'   => self::uniqueLogin( $email ),
				'user_email'   => $email,
				'user_pass'    => wp_generate_password( 32, true, true ),
				'first_name'   => sanitize_text_field( $first_name ),
				'last_name'    => sanitize_text_field( $last_name ),
				'display_name' => trim( sanitize_text_field( $first_name . ' ' . $last_name ) ) ?: $email,
				'role'         => ID::ROLE_PERSON,
			)
		);
		return is_wp_error( $user_id ) ? $user_id : (int) $user_id;
	}

	/**
	 * A login name derived from the email's local part.
	 *
	 * @param string $email Email.
	 */
	private static function uniqueLogin( string $email ): string {
		$base  = sanitize_user( (string) strstr( $email, '@', true ), true );
		$base  = '' === $base ? 'member' : $base;
		$login = $base;
		$i     = 1;
		while ( username_exists( $login ) ) {
			++$i;
			$login = $base . $i;
		}
		return $login;
	}

	/**
	 * Front-end "set your password" URL for a user ('' when no login page is configured).
	 *
	 * @param \WP_User $user User.
	 */
	public static function setPasswordUrl( \WP_User $user ): string {
		$key = get_password_reset_key( $user );
		if ( is_wp_error( $key ) ) {
			return '';
		}
		$base = Settings::pageUrl( 'login_page' );
		if ( '' === $base ) {
			return network_site_url( 'wp-login.php?action=rp&key=' . rawurlencode( $key ) . '&login=' . rawurlencode( $user->user_login ), 'login' );
		}
		return add_query_arg(
			array(
				'action' => 'rp',
				'key'    => rawurlencode( $key ),
				'login'  => rawurlencode( $user->user_login ),
			),
			$base
		);
	}

	/**
	 * Email an invite with a set-password link.
	 *
	 * @param int    $user_id User id.
	 * @param string $context Name of the membership (for the message).
	 */
	public static function sendInvite( int $user_id, string $context = '' ): bool {
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return false;
		}
		$url  = self::setPasswordUrl( $user );
		$site = wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );
		/* translators: %s: site name. */
		$subject = sprintf( __( 'Your member account at %s', 'favr-members' ), $site );
		$lines   = array(
			/* translators: %s: person's first name or email. */
			sprintf( __( 'Hi %s,', 'favr-members' ), $user->first_name ?: $user->user_email ),
			'',
			'' !== $context
				/* translators: 1: membership name, 2: site name. */
				? sprintf( __( 'A member account has been created for you for %1$s at %2$s.', 'favr-members' ), $context, $site )
				/* translators: %s: site name. */
				: sprintf( __( 'A member account has been created for you at %s.', 'favr-members' ), $site ),
			__( 'Choose a password to get started:', 'favr-members' ),
			'',
			$url,
			'',
			/* translators: %s: login name. */
			sprintf( __( 'Your username is: %s', 'favr-members' ), $user->user_login ),
		);
		/**
		 * Filter the invite email.
		 *
		 * @param array    $email { subject, message }.
		 * @param \WP_User $user  User.
		 */
		$email = (array) apply_filters(
			'favr_members_invite_email',
			array(
				'subject' => $subject,
				'message' => implode( "\n", $lines ),
			),
			$user
		);
		update_user_meta( $user_id, 'favr_members_invited', time() );
		return self::mail( $user->user_email, (string) $email['subject'], (string) $email['message'] );
	}

	/**
	 * Tell an existing account holder they now have access to a membership (no password link:
	 * they already have a password, and staff actions must never reset someone's password).
	 *
	 * @param int    $user_id User id.
	 * @param string $name    Membership name.
	 */
	public static function sendAccessNotice( int $user_id, string $name ): bool {
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return false;
		}
		$site = wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );
		return self::mail(
			$user->user_email,
			/* translators: 1: membership name, 2: site name. */
			sprintf( __( 'You now have access to %1$s at %2$s', 'favr-members' ), $name, $site ),
			implode(
				"\n",
				array(
					/* translators: %s: first name or email. */
					sprintf( __( 'Hi %s,', 'favr-members' ), $user->first_name ?: $user->user_email ),
					'',
					/* translators: 1: membership name, 2: site name. */
					sprintf( __( 'Your account has been linked to the membership for %1$s at %2$s. Log in with your existing password:', 'favr-members' ), $name, $site ),
					'',
					Settings::pageUrl( 'login_page' ) ?: wp_login_url(),
				)
			)
		);
	}

	/**
	 * Applicant: confirm the email address and choose a password (one link does both).
	 *
	 * @param int $user_id User id.
	 */
	public static function sendApplicationConfirmation( int $user_id ): bool {
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return false;
		}
		$site = wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );
		return self::mail(
			$user->user_email,
			/* translators: %s: site name. */
			sprintf( __( 'Confirm your email to finish applying to %s', 'favr-members' ), $site ),
			implode(
				"\n",
				array(
					/* translators: %s: first name. */
					sprintf( __( 'Hi %s,', 'favr-members' ), $user->first_name ?: $user->user_email ),
					'',
					__( 'Thanks for applying! Confirm your email address and choose a password here:', 'favr-members' ),
					'',
					self::setPasswordUrl( $user ),
					'',
					__( 'We’ll review your application and email you once it’s approved.', 'favr-members' ),
				)
			)
		);
	}

	/**
	 * Someone applied with an email that already has an account: tell its owner (instead of
	 * revealing on screen that the account exists).
	 *
	 * @param \WP_User $user Existing account.
	 */
	public static function sendAlreadyRegistered( \WP_User $user ): bool {
		$site = wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );
		return self::mail(
			$user->user_email,
			/* translators: %s: site name. */
			sprintf( __( 'You already have an account at %s', 'favr-members' ), $site ),
			implode(
				"\n",
				array(
					__( 'Someone (hopefully you) tried to apply for membership with this email address, which already has an account.', 'favr-members' ),
					__( 'Log in here, or reset your password if you’ve forgotten it:', 'favr-members' ),
					'',
					Settings::pageUrl( 'login_page' ) ?: wp_login_url(),
					'',
					__( 'If this wasn’t you, you can ignore this email.', 'favr-members' ),
				)
			)
		);
	}

	/**
	 * Start an email change: the new address must be confirmed before it takes effect.
	 *
	 * @param \WP_User $user      User.
	 * @param string   $new_email New email (validated, not in use).
	 */
	public static function requestEmailChange( \WP_User $user, string $new_email ): bool {
		$token = wp_generate_password( 32, false );
		update_user_meta(
			$user->ID,
			self::META_EMAIL_CHANGE,
			array(
				'email'   => $new_email,
				'hash'    => wp_hash_password( $token ),
				'expires' => time() + DAY_IN_SECONDS,
			)
		);
		$url  = add_query_arg(
			array(
				'favr_confirm_email' => $token,
				'uid'                => $user->ID,
			),
			Settings::pageUrl( 'account_page' ) ?: home_url( '/' )
		);
		$site = wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );
		return self::mail(
			$new_email,
			/* translators: %s: site name. */
			sprintf( __( 'Confirm your new email address for %s', 'favr-members' ), $site ),
			implode( "\n", array( __( 'Click to confirm this as the email address for your member account:', 'favr-members' ), '', $url, '', __( 'The link expires in 24 hours. If you didn’t request this, ignore this email.', 'favr-members' ) ) )
		);
	}

	/**
	 * Complete an email change from its link.
	 *
	 * @param int    $user_id User id.
	 * @param string $token   Token from the link.
	 */
	public static function confirmEmailChange( int $user_id, string $token ): bool {
		$pending = get_user_meta( $user_id, self::META_EMAIL_CHANGE, true );
		if ( ! is_array( $pending ) || empty( $pending['hash'] ) || (int) ( $pending['expires'] ?? 0 ) < time() || ! wp_check_password( $token, (string) $pending['hash'] ) ) {
			return false;
		}
		delete_user_meta( $user_id, self::META_EMAIL_CHANGE );
		$email = (string) $pending['email'];
		if ( ! is_email( $email ) || ( email_exists( $email ) && email_exists( $email ) !== $user_id ) ) {
			return false;
		}
		$result = wp_update_user(
			array(
				'ID'         => $user_id,
				'user_email' => $email,
			)
		);
		if ( is_wp_error( $result ) ) {
			return false;
		}
		foreach ( Repository::forUser( $user_id ) as $member ) {
			if ( ! $member->isBusiness() ) {
				update_post_meta( $member->id(), ID::meta( 'email' ), $email );
			}
		}
		return true;
	}

	/**
	 * Tell an applicant their membership was approved.
	 *
	 * @param int    $user_id User id.
	 * @param string $name    Membership name.
	 */
	public static function sendApproval( int $user_id, string $name ): bool {
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return false;
		}
		$site = wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );
		/**
		 * Filter the approval email.
		 *
		 * @param array    $email { subject, message }.
		 * @param \WP_User $user  User.
		 */
		$email = (array) apply_filters(
			'favr_members_approval_email',
			array(
				/* translators: %s: site name. */
				'subject' => sprintf( __( 'Welcome! Your membership at %s is approved', 'favr-members' ), $site ),
				'message' => implode(
					"\n",
					array(
						/* translators: %s: first name or email. */
						sprintf( __( 'Hi %s,', 'favr-members' ), $user->first_name ?: $user->user_email ),
						'',
						/* translators: 1: membership name, 2: site name. */
						sprintf( __( 'Good news — the membership for %1$s at %2$s has been approved.', 'favr-members' ), $name, $site ),
						__( 'You can log in to your member dashboard here:', 'favr-members' ),
						'',
						Settings::pageUrl( 'account_page' ) ?: home_url( '/' ),
					)
				),
			),
			$user
		);
		return self::mail( $user->user_email, (string) $email['subject'], (string) $email['message'] );
	}

	/**
	 * Send mail with the configured From name.
	 *
	 * @param string $to      Recipient.
	 * @param string $subject Subject.
	 * @param string $message Plain-text body.
	 */
	public static function mail( string $to, string $subject, string $message ): bool {
		$name   = (string) Settings::get( 'email_from_name' );
		$filter = static fn( string $from_name ): string => '' !== $name ? $name : $from_name;
		add_filter( 'wp_mail_from_name', $filter );
		$sent   = wp_mail( $to, $subject, $message );
		remove_filter( 'wp_mail_from_name', $filter );
		return (bool) $sent;
	}

	/**
	 * Point core's reset email at the front-end login page for Member-role users.
	 *
	 * @param string   $message    Message.
	 * @param string   $key        Reset key.
	 * @param string   $user_login Login.
	 * @param \WP_User $user       User.
	 */
	public function resetMessage( string $message, string $key, string $user_login, \WP_User $user ): string {
		$base = Settings::pageUrl( 'login_page' );
		if ( '' === $base || ! in_array( ID::ROLE_PERSON, (array) $user->roles, true ) ) {
			return $message;
		}
		$ours = add_query_arg(
			array(
				'action' => 'rp',
				'key'    => rawurlencode( $key ),
				'login'  => rawurlencode( $user_login ),
			),
			$base
		);
		// Core's link format varies by version (param order, wp_lang); replace any reset link.
		$login_url = preg_quote( network_site_url( 'wp-login.php', 'login' ), '#' );
		return (string) preg_replace( '#' . $login_url . '\?[^\s<>"]*action=rp[^\s<>"]*#', $ours, $message );
	}

	/**
	 * "Lost your password?" links go to the front-end form.
	 *
	 * @param string $url      URL.
	 * @param string $redirect Redirect.
	 */
	public function lostPasswordUrl( string $url, string $redirect ): string {
		if ( is_admin() ) {
			return $url;
		}
		$base = Settings::pageUrl( 'login_page' );
		return '' === $base ? $url : add_query_arg( 'action', 'lostpassword', $base );
	}
}
