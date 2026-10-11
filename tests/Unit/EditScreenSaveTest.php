<?php
/**
 * Saving a member: a record needs a name, and the Publish box says what it does to a record.
 *
 * @package FavrMembers
 */

declare(strict_types=1);

namespace FavrMembers\Tests\Unit;

use Brain\Monkey\Functions;
use FavrMembers\Admin\EditScreen;
use FavrMembers\Schema\Identifiers as ID;

final class EditScreenSaveTest extends TestCase {

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
	}

	protected function tearDown(): void {
		$_POST = array();
		parent::tearDown();
	}

	/**
	 * What the form posts.
	 *
	 * @param array<string, string> $fields Member fields.
	 */
	private function submit( array $fields ): void {
		$_POST = array(
			'_favr_member_nonce' => 'nonce',
			'favr_member'        => $fields,
		);
	}

	/* ------------------------------------------------------------ A member needs a name */

	/**
	 * An empty form could be published: the list then held a member called "(no name)" and the
	 * screen said "Member added."
	 *
	 * @dataProvider liveStatuses
	 *
	 * @param string $status The status the save asks for.
	 */
	public function test_a_member_without_a_name_stays_a_draft( string $status ): void {
		$this->submit(
			array(
				'type'       => ID::TYPE_INDIVIDUAL,
				'first_name' => '  ',
				'last_name'  => '',
				'email'      => 'someone@example.org',
			)
		);
		$screen = new EditScreen();

		$data = $screen->deriveTitle(
			array(
				'post_type'   => ID::POST_TYPE,
				'post_status' => $status,
				'post_title'  => '',
			),
			array()
		);

		$this->assertSame( 'draft', $data['post_status'] );
		$this->assertSame( '(no name)', $data['post_title'] );
		$this->assertSame( EditScreen::NAME_HELD, $screen->nameProblem() );
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public function liveStatuses(): array {
		return array(
			'published' => array( 'publish' ),
			'scheduled' => array( 'future' ),
			'private'   => array( 'private' ),
			'pending'   => array( 'pending' ),
		);
	}

	public function test_a_business_needs_its_business_name_not_a_persons_name(): void {
		$this->submit(
			array(
				'type'       => ID::TYPE_BUSINESS,
				'first_name' => 'Ada',
				'last_name'  => 'Lovelace',
			)
		);

		$data = ( new EditScreen() )->deriveTitle(
			array(
				'post_type'   => ID::POST_TYPE,
				'post_status' => 'publish',
			),
			array()
		);

		$this->assertSame( 'draft', $data['post_status'] );
	}

	public function test_a_named_member_is_saved_as_asked(): void {
		$this->submit(
			array(
				'type'       => ID::TYPE_INDIVIDUAL,
				'first_name' => 'Ada',
				'last_name'  => '',
			)
		);
		$screen = new EditScreen();

		$data = $screen->deriveTitle(
			array(
				'post_type'   => ID::POST_TYPE,
				'post_status' => 'publish',
			),
			array()
		);

		$this->assertSame( 'publish', $data['post_status'] );
		$this->assertSame( 'Ada', $data['post_title'] );
		$this->assertSame( '', $screen->nameProblem() );
	}

	/**
	 * Saving a draft or moving a nameless record to the Trash is not stopped or commented on.
	 */
	public function test_a_nameless_draft_or_trashed_record_is_left_alone(): void {
		$this->submit( array( 'type' => ID::TYPE_INDIVIDUAL ) );
		$screen = new EditScreen();

		foreach ( array( 'draft', 'auto-draft', 'trash' ) as $status ) {
			$data = $screen->deriveTitle(
				array(
					'post_type'   => ID::POST_TYPE,
					'post_status' => $status,
				),
				array()
			);
			$this->assertSame( $status, $data['post_status'] );
		}
		$this->assertSame( '', $screen->nameProblem() );
	}

	/**
	 * Taking a saved member back to a draft would end their access and hide a business's
	 * directory listing, all for an emptied field. The record stays as it is and keeps its name.
	 */
	public function test_a_saved_member_whose_name_is_emptied_keeps_its_name_and_stays_saved(): void {
		Functions\when( 'get_post_status' )->alias( static fn( int $id ) => 63 === $id ? 'publish' : 'draft' );
		$this->submit(
			array(
				'type'       => ID::TYPE_INDIVIDUAL,
				'first_name' => '',
				'last_name'  => '',
			)
		);
		$screen = new EditScreen();

		$data = $screen->deriveTitle(
			array(
				'post_type'   => ID::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => 'Camille Dubois',
				'post_name'   => 'camille-dubois',
			),
			array( 'ID' => 63 )
		);

		$this->assertSame( 'publish', $data['post_status'] );
		$this->assertSame( 'Camille Dubois', $data['post_title'] );
		$this->assertSame( 'camille-dubois', $data['post_name'] );
		$this->assertSame( EditScreen::NAME_KEPT, $screen->nameProblem() );
	}

	public function test_a_draft_whose_name_is_still_empty_stays_a_draft(): void {
		Functions\when( 'get_post_status' )->justReturn( 'draft' );
		$this->submit( array( 'type' => ID::TYPE_BUSINESS ) );
		$screen = new EditScreen();

		$data = $screen->deriveTitle(
			array(
				'post_type'   => ID::POST_TYPE,
				'post_status' => 'publish',
			),
			array( 'ID' => 70 )
		);

		$this->assertSame( 'draft', $data['post_status'] );
		$this->assertSame( EditScreen::NAME_HELD, $screen->nameProblem() );
	}

	public function test_only_a_live_status_without_a_name_is_held_back(): void {
		$this->assertTrue( EditScreen::mustStayDraft( '', 'publish' ) );
		$this->assertTrue( EditScreen::mustStayDraft( '  ', 'pending' ) );
		$this->assertFalse( EditScreen::mustStayDraft( '', 'draft' ) );
		$this->assertFalse( EditScreen::mustStayDraft( 'Ada', 'publish' ) );
	}

	public function test_other_post_types_are_never_held_back(): void {
		$this->submit( array( 'type' => ID::TYPE_INDIVIDUAL ) );

		$data = ( new EditScreen() )->deriveTitle(
			array(
				'post_type'   => 'page',
				'post_status' => 'publish',
				'post_title'  => '',
			),
			array()
		);

		$this->assertSame( 'publish', $data['post_status'] );
	}

	/* ------------------------------------------------------------ The Publish box */

	/**
	 * A member record is never public (no address, no page), so "Publish" and "Published" were
	 * not true of it. The box saves a record: its button says so and its state is Draft or Saved.
	 */
	public function test_the_publish_box_speaks_of_saving_a_record(): void {
		$this->assertSame( 'Save member', EditScreen::recordWords( 'Publish', 'Publish', 'default' ) );
		$this->assertSame( 'Saved', EditScreen::recordWords( 'Published', 'Published', 'default' ) );
		$this->assertSame( 'Record:', EditScreen::recordWords( 'Status:', 'Status:', 'default' ) );
	}

	public function test_only_wordpress_own_words_change_and_all_others_stay(): void {
		$this->assertSame( 'Publish', EditScreen::recordWords( 'Publish', 'Publish', 'favr-members' ) );
		$this->assertSame( 'Update', EditScreen::recordWords( 'Update', 'Update', 'default' ) );
		$this->assertSame( 'Draft', EditScreen::recordWords( 'Draft', 'Draft', 'default' ) );
		// A translated site keeps its translation of anything we have no word for.
		$this->assertSame( 'Mettre à jour', EditScreen::recordWords( 'Mettre à jour', 'Update', 'default' ) );
	}
}
