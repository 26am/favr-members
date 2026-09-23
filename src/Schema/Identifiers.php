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
	public const CRON_LAPSE      = 'favr_members_daily';
	public const PAGE_META_GATED = '_favr_members_only';
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
	 * Status labels.
	 *
	 * @return array<string, string>
	 */
	public static function statuses(): array {
		return array(
			self::STATUS_ACTIVE   => __( 'Active', 'favr-members' ),
			self::STATUS_PENDING  => __( 'Pending', 'favr-members' ),
			self::STATUS_LAPSED   => __( 'Lapsed', 'favr-members' ),
			self::STATUS_INACTIVE => __( 'Inactive', 'favr-members' ),
		);
	}
}
