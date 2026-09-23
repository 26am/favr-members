<?php
/**
 * Dashboard: profile and password.
 *
 * @package FavrMembers
 *
 * @var WP_User $user Person.
 */

use FavrMembers\Frontend\View;
use FavrMembers\Schema\Identifiers as ID;

defined( 'ABSPATH' ) || exit;
?>
<form class="favr-m-card favr-m-form favr-m-form--wide" method="post" action="<?php echo esc_url( \FavrMembers\Frontend\Dashboard::url( 'profile' ) ); ?>">
	<h2 class="favr-m-card__title"><?php esc_html_e( 'My profile', 'favr-members' ); ?></h2>
	<?php echo View::render( 'parts/notices', array( 'form' => 'profile' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- template escapes. ?>
	<div class="favr-m-grid">
		<p class="favr-m-field">
			<label for="favr-first"><?php esc_html_e( 'First name', 'favr-members' ); ?></label>
			<input type="text" id="favr-first" name="first_name" value="<?php echo esc_attr( $user->first_name ); ?>" autocomplete="given-name">
		</p>
		<p class="favr-m-field">
			<label for="favr-last"><?php esc_html_e( 'Last name', 'favr-members' ); ?></label>
			<input type="text" id="favr-last" name="last_name" value="<?php echo esc_attr( $user->last_name ); ?>" autocomplete="family-name">
		</p>
		<p class="favr-m-field">
			<label for="favr-email"><?php esc_html_e( 'Email', 'favr-members' ); ?></label>
			<input type="email" id="favr-email" name="email" value="<?php echo esc_attr( $user->user_email ); ?>" autocomplete="email">
		</p>
		<p class="favr-m-field">
			<label for="favr-phone"><?php esc_html_e( 'Phone', 'favr-members' ); ?></label>
			<input type="tel" id="favr-phone" name="phone" value="<?php echo esc_attr( (string) get_user_meta( $user->ID, 'favr_members_phone', true ) ); ?>" autocomplete="tel">
		</p>
		<p class="favr-m-field">
			<label for="favr-title"><?php esc_html_e( 'Job title', 'favr-members' ); ?></label>
			<input type="text" id="favr-title" name="job_title" value="<?php echo esc_attr( (string) get_user_meta( $user->ID, 'favr_members_job_title', true ) ); ?>" autocomplete="organization-title">
		</p>
	</div>

	<h3 class="favr-m-subtitle"><?php esc_html_e( 'Change password', 'favr-members' ); ?></h3>
	<p class="favr-m-hint"><?php esc_html_e( 'Leave blank to keep your current password. Your current password is needed to change your email or password.', 'favr-members' ); ?></p>
	<div class="favr-m-grid">
		<p class="favr-m-field">
			<label for="favr-pass1"><?php esc_html_e( 'New password', 'favr-members' ); ?></label>
			<input type="password" id="favr-pass1" name="pass1" autocomplete="new-password" minlength="8">
		</p>
		<p class="favr-m-field">
			<label for="favr-pass2"><?php esc_html_e( 'Confirm new password', 'favr-members' ); ?></label>
			<input type="password" id="favr-pass2" name="pass2" autocomplete="new-password" minlength="8">
		</p>
		<p class="favr-m-field">
			<label for="favr-current"><?php esc_html_e( 'Current password', 'favr-members' ); ?></label>
			<input type="password" id="favr-current" name="current_password" autocomplete="current-password">
		</p>
	</div>

	<input type="hidden" name="favr_members_action" value="profile">
	<?php wp_nonce_field( ID::NONCE_FRONT . '_profile', '_favr_members_nonce' ); ?>
	<button type="submit" class="favr-m-btn favr-m-btn--primary"><?php esc_html_e( 'Save changes', 'favr-members' ); ?></button>
</form>
