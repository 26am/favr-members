<?php
/**
 * Pure membership rules.
 *
 * @package FavrMembers
 */

declare(strict_types=1);

namespace FavrMembers\Tests\Unit;

use FavrMembers\Frontend\Auth;
use FavrMembers\Integration\Directory;
use FavrMembers\Model\Lapse;
use FavrMembers\Model\Member;
use FavrMembers\Model\Repository;

final class RulesTest extends TestCase {

	public function test_display_name_follows_type(): void {
		$this->assertSame( 'Ann Lee', Member::displayName( 'individual', ' Ann ', 'Lee', 'Ignored Co' ) );
		$this->assertSame( 'Lee Florist', Member::displayName( 'business', 'Ann', 'Lee', 'Lee Florist' ) );
		$this->assertSame( '', Member::displayName( 'business', 'Ann', 'Lee', '' ) );
	}

	public function test_lapse_after_renewal_plus_grace(): void {
		$this->assertTrue( Lapse::shouldLapse( 'active', '2026-08-01', '2026-09-23', 0 ) );
		$this->assertFalse( Lapse::shouldLapse( 'active', '2026-09-01', '2026-09-23', 30 ), 'Inside the grace period.' );
		$this->assertTrue( Lapse::shouldLapse( 'active', '2026-08-01', '2026-09-23', 30 ) );
		$this->assertFalse( Lapse::shouldLapse( 'active', '2026-09-23', '2026-09-23', 0 ), 'Lapses the day after, not on the renewal date.' );
		$this->assertFalse( Lapse::shouldLapse( 'pending', '2020-01-01', '2026-09-23', 0 ), 'Only active members lapse.' );
		$this->assertFalse( Lapse::shouldLapse( 'active', '', '2026-09-23', 0 ), 'No renewal date, no lapse.' );
	}

	public function test_listing_rules(): void {
		$this->assertTrue( Directory::mayCreateListing( 'active' ) );
		$this->assertFalse( Directory::mayCreateListing( 'pending' ), 'Applications never reach the public directory.' );
		$this->assertTrue( Directory::shouldHide( 'lapsed', 'hide' ) );
		$this->assertTrue( Directory::shouldHide( 'inactive', 'hide' ) );
		$this->assertFalse( Directory::shouldHide( 'lapsed', 'keep' ) );
		$this->assertFalse( Directory::shouldHide( 'active', 'hide' ) );
	}

	public function test_any_active(): void {
		$this->assertFalse( Repository::anyActive( array() ) );
		$this->assertFalse( Repository::anyActive( array( false, false ) ) );
		$this->assertTrue( Repository::anyActive( array( false, true ) ), 'An individual who also represents an active business is active.' );
	}

	public function test_password_rules(): void {
		$this->assertNotSame( '', Auth::passwordProblem( 'short', 'short' ) );
		$this->assertNotSame( '', Auth::passwordProblem( 'long-enough-1', 'long-enough-2' ) );
		$this->assertSame( '', Auth::passwordProblem( 'long-enough-1', 'long-enough-1' ) );
	}
}
