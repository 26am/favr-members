<?php
/**
 * The words staff read in wp-admin: sentence case, "All x / Add x / Edit x", US spelling.
 *
 * @package FavrMembers
 */

declare(strict_types=1);

namespace FavrMembers\Tests\Unit;

use Brain\Monkey\Functions;
use FavrMembers\Admin\EditScreen;
use FavrMembers\Admin\ImportExportPage;
use FavrMembers\Admin\ListScreen;
use FavrMembers\Admin\SettingsPage;
use FavrMembers\Model\Registrar;
use FavrMembers\Schema\Identifiers as ID;

final class AdminWordsTest extends TestCase {

	/** Words that keep their capital inside a sentence-case label. */
	private const PROPER_NOUNS = array( 'Trash', 'CSV' );

	/**
	 * No word after the first starts with a capital, apart from the proper nouns.
	 *
	 * @param string $label Label.
	 * @param string $where Which label, for the failure message.
	 */
	private function assertSentenceCase( string $label, string $where ): void {
		$words = preg_split( '/\s+/', ltrim( $label, '←— ' ) );
		foreach ( array_slice( is_array( $words ) ? $words : array(), 1 ) as $word ) {
			if ( in_array( rtrim( $word, '.:,' ), self::PROPER_NOUNS, true ) ) {
				continue;
			}
			$this->assertDoesNotMatchRegularExpression( '/^\p{Lu}/u', $word, "$where is not sentence case: “{$label}”" );
		}
	}

	public function test_members_are_all_add_edit_in_sentence_case(): void {
		$labels = Registrar::memberLabels();

		$this->assertSame( 'Members', $labels['name'] );
		$this->assertSame( 'Member', $labels['singular_name'] );
		$this->assertSame( 'Members', $labels['menu_name'] );
		$this->assertSame( 'All members', $labels['all_items'] );
		$this->assertSame( 'Add member', $labels['add_new'] );
		$this->assertSame( 'Add member', $labels['add_new_item'] );
		$this->assertSame( 'Edit member', $labels['edit_item'] );
		$this->assertSame( 'New member', $labels['new_item'] );
		$this->assertSame( 'View member', $labels['view_item'] );
		$this->assertSame( 'View members', $labels['view_items'] );
		$this->assertSame( 'Search members', $labels['search_items'] );
		$this->assertSame( 'No members found.', $labels['not_found'] );
		$this->assertSame( 'No members found in Trash.', $labels['not_found_in_trash'] );
		$this->assertSame( 'Member updated.', $labels['item_updated'] );
	}

	public function test_levels_are_all_add_edit_in_sentence_case(): void {
		$labels = Registrar::levelLabels();

		$this->assertSame( 'Membership levels', $labels['name'] );
		$this->assertSame( 'Level', $labels['singular_name'] );
		$this->assertSame( 'Membership levels', $labels['menu_name'] );
		$this->assertSame( 'All levels', $labels['all_items'] );
		$this->assertSame( 'Add level', $labels['add_new_item'] );
		$this->assertSame( 'Edit level', $labels['edit_item'] );
		$this->assertSame( 'Update level', $labels['update_item'] );
		$this->assertSame( 'Search levels', $labels['search_items'] );
		$this->assertSame( 'New level name', $labels['new_item_name'] );
		$this->assertSame( 'No levels found.', $labels['not_found'] );
	}

	/**
	 * Levels are a hierarchical taxonomy, so every label left out is filled in by WordPress with
	 * its category wording ("Search Categories", "Parent Category", "Most Used", "← Go to Categories").
	 */
	public function test_no_level_label_is_left_to_say_category(): void {
		$labels = Registrar::levelLabels();
		$needed = array(
			'search_items',
			'all_items',
			'parent_item',
			'parent_item_colon',
			'edit_item',
			'view_item',
			'update_item',
			'add_new_item',
			'new_item_name',
			'not_found',
			'no_terms',
			'filter_by_item',
			'items_list_navigation',
			'items_list',
			'most_used',
			'back_to_items',
		);
		foreach ( $needed as $key ) {
			$this->assertArrayHasKey( $key, $labels );
			$this->assertDoesNotMatchRegularExpression( '/categor|tag/i', $labels[ $key ], $key );
		}
		$this->assertSame( 'Parent level', $labels['parent_item'] );
		$this->assertSame( 'Most used', $labels['most_used'] );
		$this->assertSame( '← All levels', $labels['back_to_items'] );
	}

	public function test_no_label_is_title_case_or_says_add_new(): void {
		$sets = array(
			'member' => Registrar::memberLabels(),
			'level'  => Registrar::levelLabels(),
		);
		foreach ( $sets as $set => $labels ) {
			foreach ( $labels as $key => $label ) {
				$this->assertStringNotContainsString( 'Add New', $label, "$set.$key" );
				$this->assertSentenceCase( $label, "$set.$key" );
			}
		}
	}

	public function test_the_post_type_and_the_taxonomy_are_registered_with_those_words(): void {
		$types      = array();
		$taxonomies = array();
		Functions\when( 'register_post_type' )->alias(
			static function ( $type, $args ) use ( &$types ): void {
				$types[ $type ] = $args;
			}
		);
		Functions\when( 'taxonomy_exists' )->justReturn( false );
		Functions\when( 'register_taxonomy' )->alias(
			static function ( $taxonomy, $object_type, $args ) use ( &$taxonomies ): void {
				$taxonomies[ $taxonomy ] = $args;
			}
		);

		$registrar = new Registrar();
		$registrar->registerPostType();
		$registrar->registerLevels();

		$this->assertSame( Registrar::memberLabels(), $types[ ID::POST_TYPE ]['labels'] );
		$this->assertSame( Registrar::levelLabels(), $taxonomies[ ID::TAX_LEVEL ]['labels'] );
		// What must not move with the words.
		$this->assertFalse( $types[ ID::POST_TYPE ]['public'] );
		$this->assertFalse( $types[ ID::POST_TYPE ]['show_in_rest'] );
		$this->assertSame( 'membership-levels', $taxonomies[ ID::TAX_LEVEL ]['rest_base'] );
	}

	public function test_the_boxes_on_a_member_are_sentence_case(): void {
		$boxes = EditScreen::boxes();

		$this->assertSame( 'Membership level', $boxes['favr-member-level'] );
		$this->assertSame( 'Login access', $boxes['favr-member-logins'] );
		$this->assertSame( 'Directory listing', $boxes['favr-member-listing'] );
		foreach ( $boxes as $id => $title ) {
			$this->assertSentenceCase( $title, $id );
		}
	}

	public function test_save_messages_say_member_in_sentence_case(): void {
		$messages = ( new EditScreen() )->messages( array() )[ ID::POST_TYPE ];

		$this->assertSame( 'Member updated.', $messages[1] );
		$this->assertSame( 'Member added.', $messages[6] );
		foreach ( array_filter( $messages ) as $code => $message ) {
			$this->assertStringContainsStringIgnoringCase( 'member', $message, (string) $code );
			$this->assertSentenceCase( $message, "message $code" );
		}
	}

	public function test_trashing_from_the_list_says_member_not_post(): void {
		Functions\when( '_n' )->alias( static fn( $single, $plural, $number ) => 1 === (int) $number ? $single : $plural );

		$one  = ( new ListScreen() )->bulkMessages( array( 'post' => array( 'trashed' => 'core' ) ), array( 'trashed' => 1 ) );
		$many = ( new ListScreen() )->bulkMessages(
			array(),
			array(
				'updated'   => 2,
				'locked'    => 2,
				'deleted'   => 2,
				'trashed'   => 2,
				'untrashed' => 2,
			)
		);

		$this->assertSame( 'core', $one['post']['trashed'], 'Other post types keep their messages.' );
		$this->assertSame( '%s member moved to the Trash.', $one[ ID::POST_TYPE ]['trashed'] );
		$this->assertSame( '%s members moved to the Trash.', $many[ ID::POST_TYPE ]['trashed'] );
		$this->assertSame( '%s members restored from the Trash.', $many[ ID::POST_TYPE ]['untrashed'] );
		$this->assertSame( '%s members permanently deleted.', $many[ ID::POST_TYPE ]['deleted'] );
		$this->assertSame( '%s members updated.', $many[ ID::POST_TYPE ]['updated'] );
		$this->assertSame( array( 'updated', 'locked', 'deleted', 'trashed', 'untrashed' ), array_keys( $many[ ID::POST_TYPE ] ) );
		foreach ( $many[ ID::POST_TYPE ] as $action => $message ) {
			$this->assertStringNotContainsString( 'post', $message, $action );
		}
	}

	public function test_the_submenu_pages_are_sentence_case(): void {
		$pages = array();
		Functions\when( 'add_submenu_page' )->alias(
			static function ( $parent, $page_title, $menu_title ) use ( &$pages ): void {
				$pages[] = array( $page_title, $menu_title );
			}
		);

		( new SettingsPage() )->menu();
		( new ImportExportPage() )->menu();

		$this->assertSame( array( 'Member settings', 'Settings' ), $pages[0] );
		$this->assertSame( array( 'Import & export members', 'Import & export' ), $pages[1] );
		foreach ( $pages as $page ) {
			$this->assertSentenceCase( $page[0], 'page title' );
			$this->assertSentenceCase( $page[1], 'menu title' );
		}
	}
}
