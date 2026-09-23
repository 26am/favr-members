<?php
/**
 * Who may edit a linked listing.
 *
 * @package FavrMembers
 */

declare(strict_types=1);

namespace FavrMembers\Tests\Unit;

use FavrMembers\Integration\Directory;
use FavrMembers\Integration\DirectoryEditing;

final class DirectoryEditingTest extends TestCase {

	public function test_active_business_representatives_may_edit(): void {
		$this->assertTrue( DirectoryEditing::represents( true, true, array( 4, 9 ), 9 ) );
	}

	public function test_everyone_else_may_not(): void {
		$this->assertFalse( DirectoryEditing::represents( true, true, array( 4 ), 9 ), 'not a representative' );
		$this->assertFalse( DirectoryEditing::represents( true, false, array( 9 ), 9 ), 'lapsed or pending membership' );
		$this->assertFalse( DirectoryEditing::represents( false, true, array( 9 ), 9 ), 'individual members have no listing' );
		$this->assertFalse( DirectoryEditing::represents( true, true, array( 0 ), 0 ), 'logged out' );
	}

	public function test_only_staff_created_records_adopt_same_named_listings(): void {
		$this->assertTrue( Directory::mayAdopt( 'save', false ) );
		$this->assertTrue( Directory::mayAdopt( 'import', false ) );
		$this->assertFalse( Directory::mayAdopt( 'save', true ), 'application approved on the member screen' );
		$this->assertFalse( Directory::mayAdopt( 'approval', false ) );
		$this->assertFalse( Directory::mayAdopt( 'representative', false ) );
	}
}
