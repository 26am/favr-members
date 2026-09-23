<?php
/**
 * Membership application form.
 *
 * Override by copying to yourtheme/favr-members/register.php.
 *
 * @package FavrMembers
 */

use FavrMembers\Frontend\View;
use FavrMembers\Schema\Identifiers as ID;
use FavrMembers\Support\Settings;

defined( 'ABSPATH' ) || exit;

// Re-fill after a failed submit (display only; the handler verified the nonce).
// phpcs:disable WordPress.Security.NonceVerification.Missing
$favr_old  = static fn( string $key ): string => isset( $_POST[ $key ] ) ? sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) : '';
$favr_type = 'business' === $favr_old( 'type' ) ? 'business' : 'individual';
// phpcs:enable
?>
<div class="favr-m">
	<form class="favr-m-card favr-m-form favr-m-form--wide" method="post" action="" data-favr-join>
		<h2 class="favr-m-card__title"><?php esc_html_e( 'Apply for membership', 'favr-members' ); ?></h2>
		<?php echo View::render( 'parts/notices', array( 'form' => 'register' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- template escapes. ?>

		<fieldset class="favr-m-field">
			<legend><?php esc_html_e( 'I’m joining as', 'favr-members' ); ?></legend>
			<div class="favr-m-segmented">
				<label><input type="radio" name="type" value="individual" <?php checked( $favr_type, 'individual' ); ?>> <span><?php esc_html_e( 'An individual', 'favr-members' ); ?></span></label>
				<label><input type="radio" name="type" value="business" <?php checked( $favr_type, 'business' ); ?>> <span><?php esc_html_e( 'A business', 'favr-members' ); ?></span></label>
			</div>
		</fieldset>

		<p class="favr-m-field favr-m-business-only">
			<label for="favr-business"><?php esc_html_e( 'Business name', 'favr-members' ); ?></label>
			<input type="text" id="favr-business" name="business_name" value="<?php echo esc_attr( $favr_old( 'business_name' ) ); ?>" autocomplete="organization">
		</p>
		<div class="favr-m-grid">
			<p class="favr-m-field">
				<label for="favr-first"><?php esc_html_e( 'First name', 'favr-members' ); ?></label>
				<input type="text" id="favr-first" name="first_name" value="<?php echo esc_attr( $favr_old( 'first_name' ) ); ?>" autocomplete="given-name" required>
			</p>
			<p class="favr-m-field">
				<label for="favr-last"><?php esc_html_e( 'Last name', 'favr-members' ); ?></label>
				<input type="text" id="favr-last" name="last_name" value="<?php echo esc_attr( $favr_old( 'last_name' ) ); ?>" autocomplete="family-name" required>
			</p>
			<p class="favr-m-field">
				<label for="favr-email"><?php esc_html_e( 'Email', 'favr-members' ); ?></label>
				<input type="email" id="favr-email" name="email" value="<?php echo esc_attr( $favr_old( 'email' ) ); ?>" autocomplete="email" required>
			</p>
			<p class="favr-m-field">
				<label for="favr-phone"><?php esc_html_e( 'Phone', 'favr-members' ); ?> <span class="favr-m-optional"><?php esc_html_e( '(optional)', 'favr-members' ); ?></span></label>
				<input type="tel" id="favr-phone" name="phone" value="<?php echo esc_attr( $favr_old( 'phone' ) ); ?>" autocomplete="tel">
			</p>
		</div>

		<p class="favr-m-hp" aria-hidden="true"><label>Website <input type="text" name="favr_website" tabindex="-1" autocomplete="off"></label></p>
		<input type="hidden" name="favr_members_action" value="register">
		<?php wp_nonce_field( ID::NONCE_FRONT . '_register', '_favr_members_nonce' ); ?>
		<p class="favr-m-hint"><?php esc_html_e( 'We’ll email you a link to confirm your address and choose a password.', 'favr-members' ); ?></p>
		<button type="submit" class="favr-m-btn favr-m-btn--primary"><?php esc_html_e( 'Submit application', 'favr-members' ); ?></button>
		<p class="favr-m-foot"><?php esc_html_e( 'Already a member?', 'favr-members' ); ?> <a href="<?php echo esc_url( Settings::pageUrl( 'login_page' ) ); ?>"><?php esc_html_e( 'Log in', 'favr-members' ); ?></a></p>
	</form>
</div>
