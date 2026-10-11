<?php
/**
 * Why the member edit screen has no Slug box (a Directory listing keeps WordPress's).
 *
 * @package FavrMembers
 */

declare(strict_types=1);

namespace FavrMembers\Tests\Unit;

use Brain\Monkey\Functions;
use FavrMembers\Admin\EditScreen;
use FavrMembers\Schema\Identifiers as ID;

final class EditScreenSlugTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Functions\stubs(
			array(
				'wp_verify_nonce' => 1,
				'sanitize_key'    => static fn( $key ) => $key,
				'wp_unslash'      => static fn( $value ) => $value,
				'sanitize_title'  => static fn( $title ) => strtolower( trim( (string) preg_replace( '/[^A-Za-z0-9]+/', '-', (string) $title ), '-' ) ),
			)
		);
		$_POST = array(
			'_favr_member_nonce' => 'nonce',
			'favr_member'        => array(
				'type'       => ID::TYPE_INDIVIDUAL,
				'first_name' => 'Ada',
				'last_name'  => 'Lovelace',
			),
		);
	}

	protected function tearDown(): void {
		$_POST = array();
		parent::tearDown();
	}

	/**
	 * A member has no title field and no address, and its slug is written from its name on every
	 * save: whatever is typed into a Slug box is replaced. (Tried on a test site: "Member
	 * updated." and the old slug.)
	 */
	public function test_a_members_slug_follows_the_name_whatever_slug_was_submitted(): void {
		$data = ( new EditScreen() )->deriveTitle(
			array(
				'post_type'  => ID::POST_TYPE,
				'post_title' => '',
				'post_name'  => 'typed-into-the-slug-box',
			),
			array( 'post_name' => 'typed-into-the-slug-box' )
		);

		$this->assertSame( 'Ada Lovelace', $data['post_title'] );
		$this->assertSame( 'ada-lovelace', $data['post_name'] );
	}

	public function test_so_the_slug_box_is_not_offered_on_a_member(): void {
		$this->assertContains( 'slugdiv', EditScreen::REMOVED_BOXES, 'It would be a field that never saves what is typed into it.' );
		$this->assertSame( array( 'postcustom', 'slugdiv' ), EditScreen::REMOVED_BOXES );
	}
}
