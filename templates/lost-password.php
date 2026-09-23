<?php
/**
 * Lost-password form.
 *
 * @package FavrMembers
 */

use FavrMembers\Frontend\View;
use FavrMembers\Schema\Identifiers as ID;
use FavrMembers\Support\Settings;

defined( 'ABSPATH' ) || exit;
?>
<div class="favr-m">
	<form class="favr-m-card favr-m-form" method="post" action="">
		<h2 class="favr-m-card__title"><?php esc_html_e( 'Reset your password', 'favr-members' ); ?></h2>
		<?php echo View::render( 'parts/notices', array( 'form' => 'lostpassword' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- template escapes. ?>
		<p><?php esc_html_e( 'Enter your email address and we’ll send you a link to choose a new password.', 'favr-members' ); ?></p>
		<p class="favr-m-field">
			<label for="favr-user-login"><?php esc_html_e( 'Email or username', 'favr-members' ); ?></label>
			<input type="text" id="favr-user-login" name="user_login" autocomplete="username" required>
		</p>
		<input type="hidden" name="favr_members_action" value="lostpassword">
		<?php wp_nonce_field( ID::NONCE_FRONT . '_lostpassword', '_favr_members_nonce' ); ?>
		<button type="submit" class="favr-m-btn favr-m-btn--primary"><?php esc_html_e( 'Email me a link', 'favr-members' ); ?></button>
		<p class="favr-m-foot"><a href="<?php echo esc_url( Settings::pageUrl( 'login_page' ) ); ?>"><?php esc_html_e( '← Back to login', 'favr-members' ); ?></a></p>
	</form>
</div>
