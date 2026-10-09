<?php
/**
 * Approving and activating a membership.
 *
 * @package FavrMembers
 */

declare(strict_types=1);

namespace FavrMembers\Model;

use FavrMembers\Schema\Identifiers as ID;

/**
 * The one place a membership becomes active, so the Approvals inbox and other plugins (Favr
 * Payments, once dues are paid) do exactly the same thing.
 */
final class Membership {

	/**
	 * What approving an application results in. Active unless another plugin holds the member in
	 * a status of its own (for example until dues are paid). Never "pending": approval always
	 * moves an application on.
	 *
	 * @param int $member_id Member id.
	 */
	public static function approvedStatus( int $member_id ): string {
		/**
		 * Filter the status an approved application gets.
		 *
		 * @param string $status    Status key (default active).
		 * @param int    $member_id Member id.
		 */
		$status = (string) apply_filters( 'favr_members_approved_status', ID::STATUS_ACTIVE, $member_id );
		return ID::STATUS_PENDING !== $status && array_key_exists( $status, ID::statuses() ) ? $status : ID::STATUS_ACTIVE;
	}

	/**
	 * Approve an application. Returns the status it now has.
	 *
	 * @param Member $member Member.
	 */
	public static function approve( Member $member ): string {
		$status = self::approvedStatus( $member->id() );
		if ( ID::STATUS_ACTIVE === $status ) {
			self::activate( $member, 'approval' );
			return $status;
		}
		// Held by another plugin: it tells the applicant what happens next and activates later.
		update_post_meta( $member->id(), ID::meta( 'status' ), $status );
		wp_cache_delete( 'status_counts', 'favr_members' );
		$fresh = Member::find( $member->id() );
		if ( $fresh ) {
			/** This action is documented in src/Admin/EditScreen.php */
			do_action( 'favr_members_member_saved', $fresh, 'approval' );
		}
		return $status;
	}

	/**
	 * Make a membership active: status, "member since", the welcome email to every linked person
	 * and the save hook (which creates a business's directory listing).
	 *
	 * @param Member $member  Member.
	 * @param string $context Passed to `favr_members_member_saved` (approval | renewal | …).
	 * @param bool   $welcome Whether to send the welcome email.
	 */
	public static function activate( Member $member, string $context = 'approval', bool $welcome = true ): void {
		update_post_meta( $member->id(), ID::meta( 'status' ), ID::STATUS_ACTIVE );
		if ( '' === $member->text( 'member_since' ) ) {
			update_post_meta( $member->id(), ID::meta( 'member_since' ), wp_date( 'Y-m-d' ) );
		}
		if ( $welcome ) {
			foreach ( $member->userIds() as $user_id ) {
				Accounts::sendApproval( $user_id, $member->name() );
			}
		}
		wp_cache_delete( 'status_counts', 'favr_members' );
		$fresh = Member::find( $member->id() );
		if ( $fresh ) {
			/** This action is documented in src/Admin/EditScreen.php */
			do_action( 'favr_members_member_saved', $fresh, $context );
		}
	}
}
