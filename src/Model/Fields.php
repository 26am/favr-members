<?php
/**
 * The member record's field definitions.
 *
 * @package FavrMembers
 */

declare(strict_types=1);

namespace FavrMembers\Model;

use FavrMembers\Schema\Identifiers as ID;
use FavrMembers\Vendor\FavrCore\Fields\FieldSet;

/**
 * One FieldSet drives the admin form, sanitizing, meta registration, CSV and the dashboard.
 * Individual-only and business-only fields use `conditions` on `type`, so staff only ever
 * see the fields that apply. Extend with the `favr_members_fields` filter.
 */
final class Fields {

	/**
	 * Cached set.
	 *
	 * @var FieldSet|null
	 */
	private static ?FieldSet $set = null;

	/** The field set. */
	public static function set(): FieldSet {
		if ( null === self::$set ) {
			$individual = array(
				'field' => 'type',
				'value' => ID::TYPE_INDIVIDUAL,
			);
			$business   = array(
				'field' => 'type',
				'value' => ID::TYPE_BUSINESS,
			);

			$fields = array(
				array(
					'id'      => 'type',
					'label'   => __( 'Member type', 'favr-members' ),
					'type'    => 'radio',
					'tab'     => 'details',
					'default' => ID::TYPE_INDIVIDUAL,
					'options' => array(
						ID::TYPE_INDIVIDUAL => __( 'Individual', 'favr-members' ),
						ID::TYPE_BUSINESS   => __( 'Business', 'favr-members' ),
					),
				),
				array(
					'id'         => 'first_name',
					'label'      => __( 'First name', 'favr-members' ),
					'tab'        => 'details',
					'width'      => 'half',
					'conditions' => $individual,
				),
				array(
					'id'         => 'last_name',
					'label'      => __( 'Last name', 'favr-members' ),
					'tab'        => 'details',
					'width'      => 'half',
					'conditions' => $individual,
				),
				array(
					'id'          => 'business_name',
					'label'       => __( 'Business name', 'favr-members' ),
					'tab'         => 'details',
					'conditions'  => $business,
					'description' => __( 'Also used as the name of the business’s directory listing.', 'favr-members' ),
				),
				array(
					'id'         => 'organization',
					'label'      => __( 'Company / organization', 'favr-members' ),
					'tab'        => 'details',
					'width'      => 'half',
					'conditions' => $individual,
				),
				array(
					'id'         => 'job_title',
					'label'      => __( 'Job title', 'favr-members' ),
					'tab'        => 'details',
					'width'      => 'half',
					'conditions' => $individual,
				),
				array(
					'id'    => 'email',
					'label' => __( 'Email', 'favr-members' ),
					'type'  => 'email',
					'tab'   => 'details',
					'width' => 'half',
				),
				array(
					'id'    => 'phone',
					'label' => __( 'Phone', 'favr-members' ),
					'type'  => 'tel',
					'tab'   => 'details',
					'width' => 'half',
				),
				array(
					'id'    => 'address_1',
					'label' => __( 'Mailing address', 'favr-members' ),
					'tab'   => 'address',
				),
				array(
					'id'    => 'address_2',
					'label' => __( 'Address line 2', 'favr-members' ),
					'tab'   => 'address',
				),
				array(
					'id'    => 'city',
					'label' => __( 'City', 'favr-members' ),
					'tab'   => 'address',
					'width' => 'third',
				),
				array(
					'id'    => 'state',
					'label' => __( 'State / province', 'favr-members' ),
					'tab'   => 'address',
					'width' => 'third',
				),
				array(
					'id'    => 'postal_code',
					'label' => __( 'ZIP / postal code', 'favr-members' ),
					'tab'   => 'address',
					'width' => 'third',
				),
				array(
					'id'      => 'status',
					'label'   => __( 'Status', 'favr-members' ),
					'type'    => 'select',
					'tab'     => 'membership',
					'width'   => 'half',
					'default' => ID::STATUS_ACTIVE,
					'options' => ID::statuses(),
				),
				array(
					'id'      => 'member_number',
					'label'   => __( 'Member ID', 'favr-members' ),
					'tab'     => 'membership',
					'width'   => 'half',
					'private' => true,
				),
				array(
					'id'    => 'member_since',
					'label' => __( 'Member since', 'favr-members' ),
					'type'  => 'date',
					'tab'   => 'membership',
					'width' => 'half',
				),
				array(
					'id'          => 'renewal_date',
					'label'       => __( 'Renewal date', 'favr-members' ),
					'type'        => 'date',
					'tab'         => 'membership',
					'width'       => 'half',
					'description' => __( 'When the current membership term ends.', 'favr-members' ),
				),
				array(
					'id'          => 'notes',
					'label'       => __( 'Staff notes', 'favr-members' ),
					'type'        => 'textarea',
					'tab'         => 'membership',
					'private'     => true,
					'description' => __( 'Private. Only visible to staff.', 'favr-members' ),
				),
			);

			$tabs = array(
				'details'    => array(
					'label' => __( 'Details', 'favr-members' ),
					'icon'  => 'dashicons-id',
				),
				'address'    => array(
					'label' => __( 'Address', 'favr-members' ),
					'icon'  => 'dashicons-location',
				),
				'membership' => array(
					'label' => __( 'Membership', 'favr-members' ),
					'icon'  => 'dashicons-awards',
				),
			);

			/**
			 * Filter the member record's fields.
			 *
			 * @param array $fields Field definitions (see FavrCore FieldSet).
			 */
			$fields = (array) apply_filters( 'favr_members_fields', $fields );

			/**
			 * Filter the member record's admin tabs.
			 *
			 * @param array $tabs Tab id => { label, icon }.
			 */
			$tabs = (array) apply_filters( 'favr_members_tabs', $tabs );

			self::$set = new FieldSet( $fields, $tabs );
		}
		return self::$set;
	}

	/** Reset (tests / late filters). */
	public static function reset(): void {
		self::$set = null;
	}
}
