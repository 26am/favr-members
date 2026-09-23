<?php
/**
 * "Membership applications" queue in the shared Approvals inbox.
 *
 * @package FavrMembers
 */

declare(strict_types=1);

namespace FavrMembers\Admin;

use FavrMembers\Model\Accounts;
use FavrMembers\Model\Member;
use FavrMembers\Schema\Identifiers as ID;

/**
 * Pending member records (from "Become a Member") appear in Approvals. Approving activates the
 * membership exactly like changing the status on the member screen: the applicant is emailed
 * and, for businesses, the directory listing is created.
 */
final class ApplicationsQueue {

	/** Hook. */
	public function hook(): void {
		add_filter( 'favr_approvals_providers', array( $this, 'provider' ) );
	}

	/**
	 * Register the queue.
	 *
	 * @param array<int, array<string, mixed>> $providers Providers.
	 * @return array<int, array<string, mixed>>
	 */
	public function provider( array $providers ): array {
		$providers[] = array(
			'id'         => 'favr_members_applications',
			'label'      => __( 'Membership applications', 'favr-members' ),
			'capability' => 'edit_others_' . ID::CAP_PLURAL,
			'items'      => array( $this, 'items' ),
			'decide'     => array( $this, 'decide' ),
			'count'      => static fn(): int => (int) ( \FavrMembers\Model\Repository::statusCounts()[ ID::STATUS_PENDING ] ?? 0 ),
		);
		return $providers;
	}

	/**
	 * Pending applications.
	 *
	 * @return list<array<string, mixed>>
	 */
	public function items(): array {
		$posts = get_posts(
			array(
				'post_type'      => ID::POST_TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => 100,
				'orderby'        => 'date',
				'order'          => 'ASC',
				'meta_key'       => ID::meta( 'status' ), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- small, admin-only.
				'meta_value'     => ID::STATUS_PENDING, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			)
		);
		$items = array();
		foreach ( $posts as $post ) {
			$member   = new Member( $post );
			$verified = array_filter( $member->userIds(), static fn( int $u ): bool => ! get_user_meta( $u, Accounts::META_UNVERIFIED, true ) );
			$rows     = array(
				__( 'Type', 'favr-members' )  => $member->isBusiness() ? __( 'Business', 'favr-members' ) : __( 'Individual', 'favr-members' ),
				__( 'Email', 'favr-members' ) => $member->text( 'email' ),
				__( 'Phone', 'favr-members' ) => $member->text( 'phone' ),
			);
			$details  = '<table class="widefat striped favr-diff"><tbody>';
			foreach ( array_filter( $rows ) as $label => $value ) {
				$details .= '<tr><th scope="row">' . esc_html( $label ) . '</th><td>' . esc_html( $value ) . '</td></tr>';
			}
			$details .= '</tbody></table>';
			if ( $member->isBusiness() && \FavrMembers\Integration\Directory::sameName( $member->name() ) ) {
				$details .= '<p class="description"><strong>' . esc_html__( 'A directory listing with this name already exists.', 'favr-members' ) . '</strong> ' . esc_html__( 'Approving creates a new listing; it won’t take over the existing one. If it really is theirs, link it on the member screen instead.', 'favr-members' ) . '</p>';
			}
			if ( $member->userIds() && ! $verified ) {
				$details .= '<p class="description">' . esc_html__( 'The applicant hasn’t confirmed their email address yet. You can still approve; they’ll set a password from the link they were sent.', 'favr-members' ) . '</p>';
			}
			$items[] = array(
				'id'       => $member->id(),
				'title'    => $member->name(),
				'subtitle' => __( 'applied to join', 'favr-members' ),
				'edit_url' => (string) get_edit_post_link( $member->id(), 'raw' ),
				'time'     => (int) get_post_time( 'U', true, $post ),
				'version'  => ID::STATUS_PENDING,
				'details'  => $details,
			);
		}
		return $items;
	}

	/**
	 * Approve (activate) or reject (mark inactive).
	 *
	 * @param int               $member_id Member id.
	 * @param string            $decision  approve | reject.
	 * @param list<string>|null $fields    Unused.
	 * @param string            $note      Note for the applicant.
	 */
	public function decide( int $member_id, string $decision, ?array $fields, string $note ): string {
		$member = Member::find( $member_id );
		if ( ! $member || ID::STATUS_PENDING !== $member->status() ) {
			return __( 'That application was already handled.', 'favr-members' );
		}
		if ( ! current_user_can( 'edit_post', $member_id ) ) {
			return __( 'You can’t edit that member.', 'favr-members' );
		}

		if ( 'approve' === $decision ) {
			update_post_meta( $member_id, ID::meta( 'status' ), ID::STATUS_ACTIVE );
			if ( '' === $member->text( 'member_since' ) ) {
				update_post_meta( $member_id, ID::meta( 'member_since' ), wp_date( 'Y-m-d' ) );
			}
			foreach ( $member->userIds() as $user_id ) {
				Accounts::sendApproval( $user_id, $member->name() );
			}
		} else {
			update_post_meta( $member_id, ID::meta( 'status' ), ID::STATUS_INACTIVE );
			foreach ( $member->userIds() as $user_id ) {
				$user = get_userdata( $user_id );
				if ( $user ) {
					Accounts::mail(
						$user->user_email,
						/* translators: %s: site name. */
						sprintf( __( 'About your membership application at %s', 'favr-members' ), wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES ) ),
						__( 'Thank you for applying. We weren’t able to approve your membership application at this time.', 'favr-members' ) . ( '' !== $note ? "\n\n" . $note : '' )
					);
				}
			}
		}
		wp_cache_delete( 'status_counts', 'favr_members' );
		$fresh = Member::find( $member_id );
		if ( $fresh ) {
			/** This action is documented in src/Admin/EditScreen.php */
			do_action( 'favr_members_member_saved', $fresh, 'approve' === $decision ? 'approval' : 'rejection' );
		}

		return sprintf(
			/* translators: %s: member name. */
			'approve' === $decision ? __( 'Approved %s. They’ve been emailed.', 'favr-members' ) : __( 'Declined %s’s application.', 'favr-members' ),
			$member->name()
		);
	}
}
