<?php
/**
 * Dashboard: membership overview.
 *
 * @package FavrMembers
 *
 * @var WP_User                         $user        Person.
 * @var list<FavrMembers\Model\Member>  $memberships Memberships held or represented.
 */

use FavrMembers\Frontend\View;
use FavrMembers\Schema\Identifiers as ID;

defined( 'ABSPATH' ) || exit;

$favr_format = (string) get_option( 'date_format' );
?>
<?php echo View::render( 'parts/notices', array( 'form' => 'overview' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- template escapes. ?>

<?php if ( ! $memberships ) : ?>
	<div class="favr-m-card">
		<p><?php esc_html_e( 'Your account isn’t linked to a membership yet. If you think this is a mistake, please contact us.', 'favr-members' ); ?></p>
	</div>
<?php endif; ?>

<div class="favr-m-memberships">
	<?php foreach ( $memberships as $favr_member ) : ?>
		<?php
		$favr_level   = $favr_member->level();
		$favr_renewal = $favr_member->text( 'renewal_date' );
		$favr_since   = $favr_member->text( 'member_since' );
		$favr_listing = $favr_member->listingId();
		?>
		<article class="favr-m-card favr-m-membership">
			<div class="favr-m-membership__top">
				<span class="favr-m-type"><?php echo esc_html( $favr_member->isBusiness() ? __( 'Business membership', 'favr-members' ) : __( 'Individual membership', 'favr-members' ) ); ?></span>
				<span class="favr-m-status favr-m-status--<?php echo esc_attr( $favr_member->status() ); ?>"><?php echo esc_html( ID::statuses()[ $favr_member->status() ] ); ?></span>
			</div>
			<h3 class="favr-m-membership__name"><?php echo esc_html( $favr_member->name() ); ?></h3>
			<dl class="favr-m-facts">
				<?php if ( $favr_level ) : ?>
					<div><dt><?php esc_html_e( 'Level', 'favr-members' ); ?></dt><dd><?php echo esc_html( $favr_level->name ); ?></dd></div>
				<?php endif; ?>
				<?php if ( '' !== $favr_since ) : ?>
					<div><dt><?php esc_html_e( 'Member since', 'favr-members' ); ?></dt><dd><?php echo esc_html( date_i18n( $favr_format, (int) strtotime( $favr_since ) ) ); ?></dd></div>
				<?php endif; ?>
				<?php if ( '' !== $favr_renewal ) : ?>
					<div><dt><?php esc_html_e( 'Renews', 'favr-members' ); ?></dt><dd><?php echo esc_html( date_i18n( $favr_format, (int) strtotime( $favr_renewal ) ) ); ?></dd></div>
				<?php endif; ?>
				<?php if ( '' !== $favr_member->text( 'member_number' ) ) : ?>
					<div><dt><?php esc_html_e( 'Member ID', 'favr-members' ); ?></dt><dd><?php echo esc_html( $favr_member->text( 'member_number' ) ); ?></dd></div>
				<?php endif; ?>
			</dl>
			<?php if ( ID::STATUS_PENDING === $favr_member->status() ) : ?>
				<p class="favr-m-hint"><?php esc_html_e( 'Your application is being reviewed. We’ll email you when it’s approved.', 'favr-members' ); ?></p>
			<?php elseif ( ID::STATUS_LAPSED === $favr_member->status() ) : ?>
				<p class="favr-m-hint"><?php esc_html_e( 'This membership has lapsed. Please contact us to renew.', 'favr-members' ); ?></p>
			<?php endif; ?>
			<?php if ( $favr_listing && 'publish' === get_post_status( $favr_listing ) ) : ?>
				<p><a href="<?php echo esc_url( (string) get_permalink( $favr_listing ) ); ?>"><?php esc_html_e( 'View your directory listing →', 'favr-members' ); ?></a></p>
			<?php endif; ?>
			<?php
			/**
			 * Extra content inside a membership card on the dashboard.
			 *
			 * @param FavrMembers\Model\Member $member Member.
			 * @param WP_User                  $user   Person.
			 */
			do_action( 'favr_members_membership_card', $favr_member, $user );
			?>
		</article>
	<?php endforeach; ?>
</div>
