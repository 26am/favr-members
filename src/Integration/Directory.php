<?php
/**
 * Favr Directory integration (optional).
 *
 * @package FavrMembers
 */

declare(strict_types=1);

namespace FavrMembers\Integration;

use FavrMembers\Model\Member;
use FavrMembers\Model\Repository;
use FavrMembers\Schema\Identifiers as ID;
use FavrMembers\Support\Settings;

/**
 * When Favr Directory is active, every business member gets a linked listing. The member
 * record is the source of truth for membership data: level, member since, renewal date and
 * member ID are copied onto the listing, and the Directory shows those fields read-only with a
 * link back here. Only runs when the Directory's post type exists.
 */
final class Directory {

	/** Hook. */
	public function hook(): void {
		add_action( 'favr_members_member_saved', array( $this, 'sync' ), 10, 2 );
		add_filter( 'favr_directory_membership_manager_url', array( $this, 'managerUrl' ), 10, 2 );
		add_action( 'transition_post_status', array( $this, 'onStatusChange' ), 10, 3 );
		add_action( 'before_delete_post', array( $this, 'onDelete' ) );
	}

	/**
	 * A business member leaving "publish" (trashed, drafted) hides its listing; coming back
	 * re-syncs (which restores what we hid).
	 *
	 * @param string   $new_status New status.
	 * @param string   $old_status Old status.
	 * @param \WP_Post $post       Post.
	 */
	public function onStatusChange( string $new_status, string $old_status, \WP_Post $post ): void {
		if ( ID::POST_TYPE !== $post->post_type || $new_status === $old_status || ! self::available() ) {
			return;
		}
		$member = new Member( $post );
		if ( 'publish' === $old_status && ! $member->listingId() ) {
			return;
		}
		if ( 'publish' === $old_status ) {
			$this->hide( $member, $member->listingId() );
		} elseif ( 'publish' === $new_status ) {
			$this->sync( $member, 'restore' );
		}
	}

	/**
	 * A member being deleted permanently: hide its listing and remove the link both ways.
	 *
	 * @param int $post_id Post id.
	 */
	public function onDelete( int $post_id ): void {
		if ( ID::POST_TYPE !== get_post_type( $post_id ) || ! self::available() ) {
			return;
		}
		$member  = Member::find( $post_id );
		$listing = $member ? $member->listingId() : 0;
		if ( $member && $listing ) {
			$this->hide( $member, $listing );
			delete_post_meta( $listing, ID::BUSINESS_LINK );
		}
	}

	/**
	 * Draft a listing and remember that we did.
	 *
	 * @param Member $member     Member.
	 * @param int    $listing_id Listing.
	 */
	private function hide( Member $member, int $listing_id ): void {
		if ( 'publish' !== get_post_status( $listing_id ) ) {
			return;
		}
		wp_update_post(
			array(
				'ID'          => $listing_id,
				'post_status' => 'draft',
			)
		);
		update_post_meta( $member->id(), ID::META_HIDDEN, 1 );
	}

	/** Whether Favr Directory is available. */
	public static function available(): bool {
		return post_type_exists( ID::DIRECTORY_POST_TYPE );
	}

	/**
	 * Create / sync the listing for a business member.
	 *
	 * @param Member $member  Member.
	 * @param string $context Why it was saved.
	 */
	public function sync( Member $member, string $context = 'save' ): void {
		if ( ! self::available() || ! $member->isBusiness() || 'publish' !== $member->post()->post_status ) {
			return;
		}

		$listing_id = $member->listingId();
		if ( ! $listing_id && ! self::mayCreateListing( $member->status() ) ) {
			return; // Applications get a listing once approved, never before.
		}
		if ( ! $listing_id && self::mayAdopt( $context, (bool) get_post_meta( $member->id(), ID::META_APPLIED, true ) ) ) {
			$listing_id = $this->adoptListing( $member );
		}
		if ( ! $listing_id ) {
			$listing_id = $this->createListing( $member );
			if ( ! $listing_id ) {
				return;
			}
		}

		// Membership data: member record → listing.
		$level = $member->level();
		wp_set_object_terms( $listing_id, $level ? array( (int) $level->term_id ) : array(), ID::TAX_LEVEL );
		$this->copy( $listing_id, 'favr_member_since', $member->text( 'member_since' ) );
		$this->copy( $listing_id, 'favr_renewal_date', $member->text( 'renewal_date' ) );
		$this->copy( $listing_id, 'favr_member_id', $member->text( 'member_number' ) );

		$this->applyVisibility( $member, $listing_id );
	}

	/**
	 * Pure rule: may a same-named listing be linked automatically? Only for records staff
	 * created or imported. An application's name is typed by the applicant, and linking would
	 * hand them edit rights to someone else's listing; staff link those explicitly.
	 *
	 * @param string $context Save context.
	 * @param bool   $applied Record came from an application.
	 */
	public static function mayAdopt( string $context, bool $applied ): bool {
		return ! $applied && in_array( $context, array( 'save', 'import' ), true );
	}

	/**
	 * Unlinked listings with this exact name (for staff to review).
	 *
	 * @param string $name Business name.
	 * @return list<int>
	 */
	public static function sameName( string $name ): array {
		if ( '' === $name || ! self::available() ) {
			return array();
		}
		$ids = get_posts(
			array(
				'post_type'      => ID::DIRECTORY_POST_TYPE,
				'post_status'    => array( 'publish', 'draft', 'pending', 'private' ),
				'title'          => $name,
				'posts_per_page' => 5,
				'fields'         => 'ids',
			)
		);
		return array_values( array_filter( array_map( 'intval', $ids ), static fn( int $id ): bool => ! get_post_meta( $id, ID::BUSINESS_LINK, true ) ) );
	}

	/**
	 * Staff explicitly link an existing listing to a member.
	 *
	 * @param Member $member     Member.
	 * @param int    $listing_id Listing.
	 */
	public static function link( Member $member, int $listing_id ): bool {
		if ( ! self::available() || $member->listingId() || ID::DIRECTORY_POST_TYPE !== get_post_type( $listing_id ) || get_post_meta( $listing_id, ID::BUSINESS_LINK, true ) || ! current_user_can( 'edit_post', $listing_id ) ) {
			return false;
		}
		update_post_meta( $listing_id, ID::BUSINESS_LINK, $member->id() );
		update_post_meta( $member->id(), ID::META_LISTING, $listing_id );
		return true;
	}

	/**
	 * Link an existing, unlinked listing with exactly the same name (sites that already had a
	 * directory before adding Favr Members) instead of creating a duplicate.
	 *
	 * @param Member $member Member.
	 */
	private function adoptListing( Member $member ): int {
		if ( '' === $member->name() ) {
			return 0;
		}
		$candidates = get_posts(
			array(
				'post_type'      => ID::DIRECTORY_POST_TYPE,
				'post_status'    => array( 'publish', 'draft', 'pending', 'private' ),
				'title'          => $member->name(),
				'posts_per_page' => 5,
				'fields'         => 'ids',
			)
		);
		foreach ( $candidates as $candidate ) {
			$linked = (int) get_post_meta( (int) $candidate, ID::BUSINESS_LINK, true );
			$live   = $linked && in_array( get_post_status( $linked ), array( 'publish', 'draft', 'pending', 'private' ), true );
			if ( ! $live ) {
				update_post_meta( (int) $candidate, ID::BUSINESS_LINK, $member->id() );
				update_post_meta( $member->id(), ID::META_LISTING, (int) $candidate );
				return (int) $candidate;
			}
		}
		return 0;
	}

	/**
	 * Create the listing.
	 *
	 * @param Member $member Member.
	 */
	private function createListing( Member $member ): int {
		$status = 'draft' === Settings::get( 'listing_status' ) ? 'draft' : 'publish';
		$id     = wp_insert_post(
			array(
				'post_type'   => ID::DIRECTORY_POST_TYPE,
				'post_title'  => '' !== $member->name() ? $member->name() : __( 'New business', 'favr-members' ),
				'post_status' => $status,
			),
			true
		);
		if ( is_wp_error( $id ) ) {
			return 0;
		}
		$id = (int) $id;
		update_post_meta( $id, ID::BUSINESS_LINK, $member->id() );
		update_post_meta( $member->id(), ID::META_LISTING, $id );

		// Seed contact details once; afterwards the listing's own contact info is independent.
		$this->copy( $id, 'favr_phone', $member->text( 'phone' ) );
		$this->copy( $id, 'favr_email', $member->text( 'email' ) );
		foreach ( array( 'address_1', 'address_2', 'city', 'state', 'postal_code' ) as $key ) {
			$this->copy( $id, 'favr_' . $key, $member->text( $key ) );
		}
		return $id;
	}

	/**
	 * Hide a lapsed/inactive member's listing (if configured) and restore it on renewal.
	 *
	 * @param Member $member     Member.
	 * @param int    $listing_id Listing id.
	 */
	private function applyVisibility( Member $member, int $listing_id ): void {
		$current_status = (string) get_post_status( $listing_id );
		$hidden_by_us   = (bool) get_post_meta( $member->id(), ID::META_HIDDEN, true );
		$should_hide    = self::shouldHide( $member->status(), (string) Settings::get( 'lapsed_listing' ) );

		if ( $should_hide && 'publish' === $current_status ) {
			$this->hide( $member, $listing_id );
		} elseif ( ! $should_hide && $hidden_by_us ) {
			// Only republish what we hid; never publish a listing staff drafted themselves.
			if ( 'draft' === $current_status ) {
				wp_update_post(
					array(
						'ID'          => $listing_id,
						'post_status' => 'publish',
					)
				);
			}
			delete_post_meta( $member->id(), ID::META_HIDDEN );
		}
	}

	/**
	 * Pure rule: may a listing be created (or adopted) for a member with this status?
	 * Only active members join the public directory.
	 *
	 * @param string $status Member status.
	 */
	public static function mayCreateListing( string $status ): bool {
		return ID::STATUS_ACTIVE === $status;
	}

	/**
	 * Pure rule: hide the listing for this status?
	 *
	 * @param string $status  Member status.
	 * @param string $setting keep | hide.
	 */
	public static function shouldHide( string $status, string $setting ): bool {
		return 'hide' === $setting && in_array( $status, array( ID::STATUS_LAPSED, ID::STATUS_INACTIVE ), true );
	}

	/**
	 * Write a directory field (empty deletes).
	 *
	 * @param int    $listing_id Listing id.
	 * @param string $key        Meta key.
	 * @param string $value      Value.
	 */
	private function copy( int $listing_id, string $key, string $value ): void {
		if ( '' === $value ) {
			delete_post_meta( $listing_id, $key );
		} else {
			update_post_meta( $listing_id, $key, $value );
		}
	}

	/**
	 * Tell the Directory who manages a listing's membership data.
	 *
	 * @param string   $url  Current URL.
	 * @param \WP_Post $post Listing.
	 */
	public function managerUrl( string $url, $post ): string {
		if ( ! $post instanceof \WP_Post ) {
			return $url;
		}
		$member = Repository::forListing( (int) $post->ID );
		return $member ? (string) get_edit_post_link( $member->id(), 'raw' ) : $url;
	}
}
