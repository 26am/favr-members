<?php
/**
 * Member dashboard shell.
 *
 * Override by copying to yourtheme/favr-members/account.php.
 *
 * @package FavrMembers
 *
 * @var WP_User $user    Logged-in person.
 * @var array   $tabs    Tab id => { label, … }.
 * @var string  $current Current tab id.
 * @var string  $content Rendered tab HTML.
 */

use FavrMembers\Frontend\Dashboard;

defined( 'ABSPATH' ) || exit;
?>
<div class="favr-m favr-m-account">
	<header class="favr-m-account__head">
		<div class="favr-m-avatar" aria-hidden="true"><?php echo esc_html( mb_strtoupper( mb_substr( $user->first_name ?: $user->display_name, 0, 1 ) ) ); ?></div>
		<div>
			<p class="favr-m-account__hello">
				<?php
				/* translators: %s: first name. */
				echo esc_html( sprintf( __( 'Hi, %s', 'favr-members' ), $user->first_name ?: $user->display_name ) );
				?>
			</p>
			<a class="favr-m-account__logout" href="<?php echo esc_url( wp_logout_url() ); ?>"><?php esc_html_e( 'Log out', 'favr-members' ); ?></a>
		</div>
	</header>

	<?php if ( count( $tabs ) > 1 ) : ?>
		<nav class="favr-m-tabs" aria-label="<?php esc_attr_e( 'Account sections', 'favr-members' ); ?>">
			<?php foreach ( $tabs as $favr_id => $favr_tab ) : ?>
				<a class="favr-m-tabs__item<?php echo $favr_id === $current ? ' is-active' : ''; ?>" href="<?php echo esc_url( Dashboard::url( (string) $favr_id ) ); ?>"<?php echo $favr_id === $current ? ' aria-current="page"' : ''; ?>><?php echo esc_html( (string) $favr_tab['label'] ); ?></a>
			<?php endforeach; ?>
		</nav>
	<?php endif; ?>

	<div class="favr-m-account__panel">
		<?php echo $content; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- tab renderers escape their own output. ?>
	</div>
</div>
