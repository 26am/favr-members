<?php
/**
 * Choose a new password (from an invite or reset email).
 *
 * @package FavrMembers
 *
 * @var bool $valid Whether the emailed link is still valid (the key travels in a cookie).
 */

use FavrMembers\Frontend\View;
use FavrMembers\Schema\Identifiers as ID;
use FavrMembers\Support\Settings;

defined( 'ABSPATH' ) || exit;
?>
<div class="favr-m">
	<?php if ( ! $valid ) : ?>
		<div class="favr-m-card">
			<h2 class="favr-m-card__title"><?php esc_html_e( 'This link has expired', 'favr-members' ); ?></h2>
			<p><?php esc_html_e( 'Password links can only be used once and expire after a day. Request a new one:', 'favr-members' ); ?></p>
			<a class="favr-m-btn favr-m-btn--primary" href="<?php echo esc_url( add_query_arg( 'action', 'lostpassword', Settings::pageUrl( 'login_page' ) ) ); ?>"><?php esc_html_e( 'Send a new link', 'favr-members' ); ?></a>
		</div>
	<?php else : ?>
		<form class="favr-m-card favr-m-form" method="post" action="">
			<h2 class="favr-m-card__title"><?php esc_html_e( 'Choose your password', 'favr-members' ); ?></h2>
			<?php echo View::render( 'parts/notices', array( 'form' => 'resetpass' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- template escapes. ?>
			<p class="favr-m-field">
				<label for="favr-pass1"><?php esc_html_e( 'New password', 'favr-members' ); ?></label>
				<input type="password" id="favr-pass1" name="pass1" autocomplete="new-password" minlength="8" required>
				<span class="favr-m-hint"><?php esc_html_e( 'At least 8 characters.', 'favr-members' ); ?></span>
			</p>
			<p class="favr-m-field">
				<label for="favr-pass2"><?php esc_html_e( 'Confirm password', 'favr-members' ); ?></label>
				<input type="password" id="favr-pass2" name="pass2" autocomplete="new-password" minlength="8" required>
			</p>
			<input type="hidden" name="favr_members_action" value="resetpass">
			<?php wp_nonce_field( ID::NONCE_FRONT . '_resetpass', '_favr_members_nonce' ); ?>
			<button type="submit" class="favr-m-btn favr-m-btn--primary"><?php esc_html_e( 'Save password & log in', 'favr-members' ); ?></button>
		</form>
	<?php endif; ?>
</div>
