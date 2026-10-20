<?php
/**
 * Every identifier Favr Members uses.
 *
 * @package FavrMembers
 */

declare(strict_types=1);

namespace FavrMembers\Schema;

/**
 * Single source of truth for post types, meta, options, roles, capabilities and hooks.
 */
final class Identifiers {
	public const POST_TYPE       = 'favr_member';
	public const TAX_LEVEL       = 'favr_member_level'; // Shared with Favr Directory.
	public const META_PREFIX     = 'favr_member_';
	public const META_USER       = 'favr_member_user'; // One row per linked login (multi-value).
	public const META_LISTING    = 'favr_member_listing';
	public const META_HIDDEN     = '_favr_member_hid_listing';
	public const META_APPLIED    = '_favr_member_applied'; // Created by a "Become a Member" application.
	public const BUSINESS_LINK   = '_favr_member_record'; // On favr_business: its member record.
	public const OPTION_SETTINGS = 'favr_members_settings';
	public const OPTION_VERSION  = 'favr_members_version';
	public const ROLE_PERSON     = 'favr_member_person';
	public const ROLE_MANAGER    = 'favr_membership_manager';
	public const CAP_TYPE        = 'favr_member';
	public const CAP_PLURAL      = 'favr_members';
	public const CAP_SETTINGS    = 'manage_favr_members';
	public const NONCE_META      = 'favr_members_meta';
	public const NONCE_FRONT     = 'favr_members_front';
	public const FIELD_TRAP      = 'favr_leave_blank'; // The join form's hidden robot trap. No word autofill knows (it was "favr_website": 1Password filled it).
	public const CRON_LAPSE      = 'favr_members_daily';
	public const PAGE_META_GATED = '_favr_members_only';
	public const PAGE_META_COPY  = '_favr_members_copy_of'; // On a copy (an Elementor template saved from a page): the page it was saved from.
	public const PAGE_META_KEPT  = '_favr_members_copy_kept'; // On a copy made while its page was members-only: it holds members-only text and stays protected.
	public const PAGE_META_SESS  = '_favr_members_switch'; // What the last few Elementor editing sessions did to the switch (token => on|off).
	public const BLOCK_GATE      = 'favr-members/members-only';

	/** Business post type from Favr Directory (optional integration). */
	public const DIRECTORY_POST_TYPE = 'favr_business';

	/** Member types. */
	public const TYPE_INDIVIDUAL = 'individual';
	public const TYPE_BUSINESS   = 'business';

	/** Statuses. */
	public const STATUS_ACTIVE   = 'active';
	public const STATUS_PENDING  = 'pending';
	public const STATUS_LAPSED   = 'lapsed';
	public const STATUS_INACTIVE = 'inactive';

	/**
	 * Full meta key for a field id.
	 *
	 * @param string $field_id Field id.
	 */
	public static function meta( string $field_id ): string {
		return self::META_PREFIX . $field_id;
	}

	/**
	 * Status labels. Other plugins may add statuses (Favr Payments adds "Awaiting payment") but
	 * can never remove or rename the built-in ones. A status this list does not know is treated
	 * as not a current member.
	 *
	 * @return array<string, string>
	 */
	public static function statuses(): array {
		$statuses = array(
			self::STATUS_ACTIVE   => __( 'Active', 'favr-members' ),
			self::STATUS_PENDING  => __( 'Pending', 'favr-members' ),
			self::STATUS_LAPSED   => __( 'Lapsed', 'favr-members' ),
			self::STATUS_INACTIVE => __( 'Inactive', 'favr-members' ),
		);
		/**
		 * Filter the member statuses.
		 *
		 * @param array<string, string> $statuses Status key => label.
		 */
		$extra = apply_filters( 'favr_members_statuses', $statuses );
		return $statuses + array_filter( is_array( $extra ) ? $extra : array(), 'is_string' );
	}
}
