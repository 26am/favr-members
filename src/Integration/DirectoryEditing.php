<?php
/**
 * Favr Directory front-end editing: who represents a listing.
 *
 * @package FavrMembers
 */

declare(strict_types=1);

namespace FavrMembers\Integration;

use FavrMembers\Frontend\Dashboard;
use FavrMembers\Model\Accounts;
use FavrMembers\Model\Member;
use FavrMembers\Model\Repository;
use FavrMembers\Schema\Identifiers as ID;
use FavrMembers\Support\Settings;

/**
 * Representatives of an active business member may edit its linked listing. Approved claims
 * and staff invites on a linked listing add the person to the member record, so there's one
 * list of representatives to manage. All through Favr Directory's filters; nothing here runs
 * unless the Directory fires them.
 */
final class DirectoryEditing {

	/** Hook. */
	public function hook(): void {
		add_filter( 'favr_directory_can_member_edit', array( $this, 'canEdit' ), 10, 3 );
		add_filter( 'favr_directory_member_listings', array( $this, 'listings' ), 10, 2 );
		add_filter( 'favr_directory_claim_approve', array( $this, 'claimApproved' ), 10, 3 );
		add_filter( 'favr_directory_invite_user', array( $this, 'invite' ), 10, 3 );
		add_filter( 'favr_directory_edit_url', array( $this, 'editUrl' ) );
		add_filter( 'favr_directory_login_url', array( $this, 'loginUrl' ), 10, 2 );
		add_filter( 'favr_directory_managed_elsewhere', array( $this, 'managedElsewhere' ), 10, 2 );
	}

	/**
	 * Pure rule: a representative of an active business member may edit its listing.
	 *
	 * @param bool      $is_business Member type is business.
	 * @param bool      $is_active   Membership is active.
	 * @param list<int> $user_ids    Representatives.
	 * @param int       $user_id     Person asking.
	 */
	public static function represents( bool $is_business, bool $is_active, array $user_ids, int $user_id ): bool {
		return $is_business && $is_active && $user_id > 0 && in_array( $user_id, $user_ids, true );
	}

	/**
	 * Filter: may this person edit this listing?
	 *
	 * @param bool $allowed Already allowed (listing manager).
	 * @param int  $user_id User.
	 * @param int  $post_id Business.
	 */
	public function canEdit( $allowed, $user_id, $post_id ): bool {
		if ( $allowed ) {
			return true;
		}
		$member = Repository::forListing( (int) $post_id );
		return $member && self::represents( $member->isBusiness(), $member->isActive(), $member->userIds(), (int) $user_id );
	}

	/**
	 * Filter: listings of the person's active business memberships.
	 *
	 * @param list<int> $ids     Listing ids.
	 * @param int       $user_id User.
	 * @return list<int>
	 */
	public function listings( $ids, $user_id ): array {
		$ids = array_map( 'intval', (array) $ids );
		foreach ( Repository::forUser( (int) $user_id ) as $member ) {
			if ( $member->isBusiness() && $member->isActive() && $member->listingId() ) {
				$ids[] = $member->listingId();
			}
		}
		return $ids;
	}

	/**
	 * Filter: an approved claim on a linked listing makes the person a representative.
	 *
	 * @param bool $handled Already handled.
	 * @param int  $post_id Business.
	 * @param int  $user_id Claimant.
	 */
	public function claimApproved( $handled, $post_id, $user_id ): bool {
		if ( $handled ) {
			return true;
		}
		$member = Repository::forListing( (int) $post_id );
		if ( ! $member || ! $member->isBusiness() ) {
			return false; // The Directory makes them a listing manager instead.
		}
		$this->link( $member, (int) $user_id );
		return true;
	}

	/**
	 * Filter: create or find the account for a staff invite from the listing screen.
	 *
	 * @param mixed    $result Earlier result.
	 * @param string   $email  Email.
	 * @param \WP_Post $post   Business.
	 * @return mixed User id, WP_Error, or the earlier result.
	 */
	public function invite( $result, $email, $post ) {
		if ( null !== $result ) {
			return $result;
		}
		$resolved = Accounts::resolve( (string) $email );
		if ( is_wp_error( $resolved ) ) {
			return $resolved;
		}
		$member = $post instanceof \WP_Post ? Repository::forListing( $post->ID ) : null;
		$name   = $member ? $member->name() : ( $post instanceof \WP_Post ? get_the_title( $post ) : '' );
		if ( $resolved['created'] ) {
			Accounts::sendInvite( $resolved['user_id'], $name );
		}
		if ( $member && $member->isBusiness() ) {
			$this->link( $member, $resolved['user_id'] );
		}
		return $resolved['user_id'];
	}

	/**
	 * Filter: edit listings from the member dashboard.
	 *
	 * @param string $url Current URL.
	 */
	public function editUrl( $url ): string {
		return '' !== Settings::pageUrl( 'account_page' ) ? Dashboard::url( 'listing' ) : (string) $url;
	}

	/**
	 * Filter: the member login page, returning to where they were.
	 *
	 * @param string $url       Current URL.
	 * @param string $return_to Return URL.
	 */
	public function loginUrl( $url, $return_to ): string {
		$login = Settings::pageUrl( 'login_page' );
		return '' !== $login ? add_query_arg( 'redirect_to', rawurlencode( (string) $return_to ), $login ) : (string) $url;
	}

	/**
	 * Filter: tell the listing screen that representatives live on the member record.
	 *
	 * @param mixed    $elsewhere Earlier value.
	 * @param \WP_Post $post      Business.
	 * @return mixed
	 */
	public function managedElsewhere( $elsewhere, $post ) {
		$member = $post instanceof \WP_Post ? Repository::forListing( $post->ID ) : null;
		if ( ! $member || ! current_user_can( 'edit_post', $member->id() ) ) {
			return $elsewhere;
		}
		return array(
			/* translators: %s: member name. */
			'label' => sprintf( __( 'Manage representatives on %s →', 'favr-members' ), $member->name() ),
			'url'   => (string) get_edit_post_link( $member->id(), 'raw' ),
		);
	}

	/**
	 * Add a person to a member record as a representative.
	 *
	 * @param Member $member  Member.
	 * @param int    $user_id User.
	 */
	private function link( Member $member, int $user_id ): void {
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return;
		}
		$member->addUser( $user_id );
		if ( ! user_can( $user, 'edit_posts' ) && ! in_array( ID::ROLE_PERSON, (array) $user->roles, true ) ) {
			$user->add_role( ID::ROLE_PERSON );
		}
		/** This action is documented in src/Admin/EditScreen.php */
		do_action( 'favr_members_member_saved', $member, 'representative' );
	}
}
