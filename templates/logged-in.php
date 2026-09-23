<?php
/**
 * Shown on the login/join page when already logged in.
 *
 * @package FavrMembers
 */

use FavrMembers\Support\Settings;

defined( 'ABSPATH' ) || exit;
?>
<div class="favr-m">
	<div class="favr-m-card">
		<h2 class="favr-m-card__title">
			<?php
			/* translators: %s: person's name. */
			echo esc_html( sprintf( __( 'You’re logged in as %s', 'favr-members' ), wp_get_current_user()->display_name ) );
			?>
		</h2>
		<p>
			<a class="favr-m-btn favr-m-btn--primary" href="<?php echo esc_url( Settings::pageUrl( 'account_page' ) ); ?>"><?php esc_html_e( 'Go to my account', 'favr-members' ); ?></a>
			<a class="favr-m-btn" href="<?php echo esc_url( wp_logout_url() ); ?>"><?php esc_html_e( 'Log out', 'favr-members' ); ?></a>
		</p>
	</div>
</div>
