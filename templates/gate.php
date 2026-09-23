<?php
/**
 * Shown in place of members-only content.
 *
 * @package FavrMembers
 *
 * @var string $message Custom message ('' for the default).
 */

use FavrMembers\Support\Settings;

defined( 'ABSPATH' ) || exit;

$favr_login = Settings::pageUrl( 'login_page' );
$favr_join  = 'open' === Settings::get( 'registration' ) ? Settings::pageUrl( 'register_page' ) : '';
?>
<div class="favr-m favr-m-gate">
	<div class="favr-m-card favr-m-gate__card">
		<span class="favr-m-gate__icon" aria-hidden="true">
			<svg width="28" height="28" viewBox="0 0 24 24" fill="currentColor"><path d="M18 8h-1V6A5 5 0 0 0 7 6v2H6a2 2 0 0 0-2 2v10a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V10a2 2 0 0 0-2-2zM9 6a3 3 0 0 1 6 0v2H9zm3 11a2 2 0 1 1 0-4 2 2 0 0 1 0 4z"/></svg>
		</span>
		<p class="favr-m-gate__title"><?php echo esc_html( '' !== $message ? $message : __( 'This content is for members.', 'favr-members' ) ); ?></p>
		<?php if ( is_user_logged_in() ) : ?>
			<p class="favr-m-hint"><?php esc_html_e( 'Your membership isn’t active right now. Please contact us to renew.', 'favr-members' ); ?></p>
		<?php else : ?>
			<p>
				<?php if ( '' !== $favr_login ) : ?>
					<a class="favr-m-btn favr-m-btn--primary" href="<?php echo esc_url( add_query_arg( 'redirect_to', rawurlencode( (string) get_permalink() ), $favr_login ) ); ?>"><?php esc_html_e( 'Log in', 'favr-members' ); ?></a>
				<?php endif; ?>
				<?php if ( '' !== $favr_join ) : ?>
					<a class="favr-m-btn" href="<?php echo esc_url( $favr_join ); ?>"><?php esc_html_e( 'Become a member', 'favr-members' ); ?></a>
				<?php endif; ?>
			</p>
		<?php endif; ?>
	</div>
</div>
