<?php
/**
 * What approving an application results in.
 *
 * @package FavrMembers
 */

declare(strict_types=1);

namespace FavrMembers\Tests\Unit;

use Brain\Monkey\Functions;
use FavrMembers\Model\Membership;
use FavrMembers\Schema\Identifiers as ID;

final class MembershipTest extends TestCase {

	public function test_approval_makes_a_member_active_by_default(): void {
		$this->assertSame( ID::STATUS_ACTIVE, Membership::approvedStatus( 42 ) );
	}

	public function test_another_plugin_can_hold_approval_in_its_own_status(): void {
		Functions\when( 'apply_filters' )->alias(
			static function ( string $hook, $value ) {
				if ( 'favr_members_statuses' === $hook ) {
					return $value + array( 'awaiting_payment' => 'Awaiting payment' );
				}
				return 'favr_members_approved_status' === $hook ? 'awaiting_payment' : $value;
			}
		);
		$this->assertSame( 'awaiting_payment', Membership::approvedStatus( 42 ) );
	}

	public function test_an_unknown_status_from_a_filter_falls_back_to_active(): void {
		Functions\when( 'apply_filters' )->alias(
			static fn( string $hook, $value ) => 'favr_members_approved_status' === $hook ? 'nonsense' : $value
		);
		$this->assertSame( ID::STATUS_ACTIVE, Membership::approvedStatus( 42 ) );
	}

	public function test_approval_can_never_leave_an_application_pending(): void {
		Functions\when( 'apply_filters' )->alias(
			static fn( string $hook, $value ) => 'favr_members_approved_status' === $hook ? ID::STATUS_PENDING : $value
		);
		$this->assertSame( ID::STATUS_ACTIVE, Membership::approvedStatus( 42 ) );
	}

	public function test_extra_statuses_are_listed_after_the_built_in_ones(): void {
		Functions\when( 'apply_filters' )->alias(
			static fn( string $hook, $value ) => 'favr_members_statuses' === $hook ? $value + array( 'awaiting_payment' => 'Awaiting payment' ) : $value
		);
		$this->assertSame( array( 'active', 'pending', 'lapsed', 'inactive', 'awaiting_payment' ), array_keys( ID::statuses() ) );
	}

	public function test_a_filter_cannot_remove_a_built_in_status(): void {
		Functions\when( 'apply_filters' )->alias(
			static fn( string $hook, $value ) => 'favr_members_statuses' === $hook ? array( 'only' => 'Only' ) : $value
		);
		$this->assertSame( array( 'active', 'pending', 'lapsed', 'inactive', 'only' ), array_keys( ID::statuses() ) );
	}
}
