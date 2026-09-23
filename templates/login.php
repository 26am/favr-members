<?php
/**
 * Login form.
 *
 * Override by copying to yourtheme/favr-members/login.php.
 *
 * @package FavrMembers
 *
 * @var string $redirect Optional redirect after login.
 */

use FavrMembers\Frontend\View;
use FavrMembers\Schema\Identifiers as ID;
use FavrMembers\Support\Settings;

defined( 'ABSPATH' ) || exit;

// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- validated with wp_validate_redirect() on submit.
$favr_redirect = isset( $redirect ) ? (string) $redirect : ( isset( $_GET['redirect_to'] ) ? esc_url_raw( wp_unslash( $_GET['redirect_to'] ) ) : '' );
$favr_join     = 'open' === Settings::get( 'registration' ) ? Settings::pageUrl( 'register_page' ) : '';
// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
$favr_logged_out = isset( $_GET['loggedout'] );
?>
<div class="favr-m">
	<form class="favr-m-card favr-m-form" method="post" action="">
		<h2 class="favr-m-card__title"><?php esc_html_e( 'Member login', 'favr-members' ); ?></h2>
		<?php if ( $favr_logged_out ) : ?>
			<div class="favr-m-notice favr-m-notice--success" role="status"><?php esc_html_e( 'You’ve been logged out.', 'favr-members' ); ?></div>
		<?php endif; ?>
		<?php echo View::render( 'parts/notices', array( 'form' => 'login' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- template escapes. ?>

		<p class="favr-m-field">
			<label for="favr-log"><?php esc_html_e( 'Email or username', 'favr-members' ); ?></label>
			<input type="text" id="favr-log" name="log" autocomplete="username" required>
		</p>
		<p class="favr-m-field">
			<label for="favr-pwd"><?php esc_html_e( 'Password', 'favr-members' ); ?></label>
			<input type="password" id="favr-pwd" name="pwd" autocomplete="current-password" required>
		</p>
		<p class="favr-m-row">
			<label class="favr-m-check"><input type="checkbox" name="rememberme" value="1"> <?php esc_html_e( 'Keep me logged in', 'favr-members' ); ?></label>
			<a href="<?php echo esc_url( add_query_arg( 'action', 'lostpassword', Settings::pageUrl( 'login_page' ) ) ); ?>"><?php esc_html_e( 'Forgot password?', 'favr-members' ); ?></a>
		</p>

		<input type="hidden" name="favr_members_action" value="login">
		<input type="hidden" name="redirect_to" value="<?php echo esc_attr( $favr_redirect ); ?>">
		<?php wp_nonce_field( ID::NONCE_FRONT . '_login', '_favr_members_nonce' ); ?>
		<button type="submit" class="favr-m-btn favr-m-btn--primary"><?php esc_html_e( 'Log in', 'favr-members' ); ?></button>

		<?php if ( '' !== $favr_join ) : ?>
			<p class="favr-m-foot"><?php esc_html_e( 'Not a member yet?', 'favr-members' ); ?> <a href="<?php echo esc_url( $favr_join ); ?>"><?php esc_html_e( 'Apply to join', 'favr-members' ); ?></a></p>
		<?php endif; ?>
	</form>
</div>
