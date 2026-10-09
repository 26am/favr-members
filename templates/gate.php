<?php
/**
 * Shown in place of members-only content.
 *
 * @package FavrMembers
 *
 * @var string $message  Custom message ('' for the default).
 * @var string $standing Where a signed-in visitor stands: none | other | pending | lapsed |
 *                       inactive ('' for a visitor who is not signed in).
 */

use FavrMembers\Support\Settings;

defined( 'ABSPATH' ) || exit;

$favr_login   = Settings::pageUrl( 'login_page' );
$favr_join    = 'open' === Settings::get( 'registration' ) ? Settings::pageUrl( 'register_page' ) : '';
$favr_account = Settings::pageUrl( 'account_page' );
$favr_hints   = array(
	'none'     => __( 'You are logged in, but this account has no membership. Please contact us if that is not right.', 'favr-members' ),
	'pending'  => __( 'Your application is being reviewed. You will be able to see this once it is approved.', 'favr-members' ),
	'lapsed'   => __( 'Your membership has lapsed. Renew it to see this.', 'favr-members' ),
	'other'    => __( 'Your membership is not active yet.', 'favr-members' ),
	'inactive' => __( 'Your membership isn’t active right now. Please contact us.', 'favr-members' ),
);
$favr_stands  = isset( $standing ) && isset( $favr_hints[ $standing ] ) ? $standing : 'inactive';
?>
<div class="favr-m favr-m-gate">
	<div class="favr-m-card favr-m-gate__card">
		<span class="favr-m-gate__icon" aria-hidden="true">
			<svg width="28" height="28" viewBox="0 0 24 24" fill="currentColor"><path d="M18 8h-1V6A5 5 0 0 0 7 6v2H6a2 2 0 0 0-2 2v10a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V10a2 2 0 0 0-2-2zM9 6a3 3 0 0 1 6 0v2H9zm3 11a2 2 0 1 1 0-4 2 2 0 0 1 0 4z"/></svg>
		</span>
		<p class="favr-m-gate__title"><?php echo esc_html( '' !== $message ? $message : __( 'This content is for members.', 'favr-members' ) ); ?></p>
		<?php if ( is_user_logged_in() ) : ?>
			<p class="favr-m-hint"><?php echo esc_html( $favr_hints[ $favr_stands ] ); ?></p>
			<?php if ( '' !== $favr_account && in_array( $favr_stands, array( 'lapsed', 'other' ), true ) ) : ?>
				<p><a class="favr-m-btn favr-m-btn--primary" href="<?php echo esc_url( $favr_account ); ?>"><?php esc_html_e( 'My account', 'favr-members' ); ?></a></p>
			<?php endif; ?>
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
