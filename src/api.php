<?php
/**
 * Public API for themes and other Favr plugins.
 *
 * @package FavrMembers
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'favr_members_is_active' ) ) {
	/**
	 * Whether a person holds an active individual membership or represents an active
	 * business member. Defaults to the current user.
	 *
	 * @param int $user_id User id (0 = current user).
	 */
	function favr_members_is_active( int $user_id = 0 ): bool {
		$user_id = $user_id ? $user_id : get_current_user_id();
		/**
		 * Filter whether a user counts as a current member.
		 *
		 * @param bool $active  Computed status.
		 * @param int  $user_id User id.
		 */
		return (bool) apply_filters( 'favr_members_is_active', \FavrMembers\Model\Repository::isActive( $user_id ), $user_id );
	}
}

if ( ! function_exists( 'favr_members_get_memberships' ) ) {
	/**
	 * Memberships a person holds or represents.
	 *
	 * @param int $user_id User id (0 = current user).
	 * @return list<\FavrMembers\Model\Member>
	 */
	function favr_members_get_memberships( int $user_id = 0 ): array {
		return \FavrMembers\Model\Repository::forUser( $user_id ? $user_id : get_current_user_id() );
	}
}
