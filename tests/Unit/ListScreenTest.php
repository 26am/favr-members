<?php
/**
 * The "All members" list: what its search finds, the order of its tabs and how its cells read.
 *
 * @package FavrMembers
 */

declare(strict_types=1);

namespace FavrMembers\Tests\Unit;

use Brain\Monkey\Functions;
use FavrMembers\Admin\ListScreen;

final class ListScreenTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Functions\stubs(
			array(
				'esc_html' => static fn( $text ) => htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' ),
				'esc_attr' => static fn( $text ) => htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' ),
			)
		);
	}

	/**
	 * Just enough of $wpdb: table names, LIKE escaping and prepare() with string placeholders.
	 */
	private function wpdb(): object {
		return new class() {
			public string $posts    = 'wp_posts';
			public string $postmeta = 'wp_postmeta';

			public function esc_like( string $text ): string {
				return addcslashes( $text, '_%\\' );
			}

			public function prepare( string $query, ...$args ): string {
				$args = is_array( $args[0] ?? null ) ? $args[0] : $args;
				return vsprintf( str_replace( '%s', "'%s'", $query ), array_map( 'addslashes', $args ) );
			}
		};
	}

	/** The search WordPress builds for one word: the title, the excerpt or the text. */
	private function wordpressSearch( string ...$words ): string {
		$sql = '';
		foreach ( $words as $word ) {
			$sql .= ( '' === $sql ? ' AND (' : ' AND ' ) . "((wp_posts.post_title LIKE '%{$word}%') OR (wp_posts.post_excerpt LIKE '%{$word}%') OR (wp_posts.post_content LIKE '%{$word}%'))";
		}
		return $sql . ') ';
	}

	/* ------------------------------------------------------------ Search */

	/**
	 * The list shows a member's company, e-mail, phone, member ID and (on the record) city, so
	 * typing any of them into the list's search finds the member.
	 */
	public function test_search_also_looks_in_company_email_phone_member_id_and_city(): void {
		$this->assertSame(
			array( 'favr_member_organization', 'favr_member_email', 'favr_member_phone', 'favr_member_member_number', 'favr_member_city' ),
			ListScreen::SEARCH_FIELDS
		);

		$sql = ListScreen::searchWithFields( $this->wordpressSearch( 'dubois' ), array( 'dubois' ), $this->wpdb() );

		$this->assertSame(
			" AND (((wp_posts.post_title LIKE '%dubois%') OR EXISTS ( SELECT 1 FROM wp_postmeta AS favr_s WHERE favr_s.post_id = wp_posts.ID AND favr_s.meta_key IN ( 'favr_member_organization', 'favr_member_email', 'favr_member_phone', 'favr_member_member_number', 'favr_member_city' ) AND favr_s.meta_value LIKE '%dubois%' ) OR (wp_posts.post_excerpt LIKE '%dubois%') OR (wp_posts.post_content LIKE '%dubois%'))) ",
			$sql
		);
	}

	/**
	 * Every word must still be found somewhere ("West Palm" is one city, "Camille 555" a name and
	 * a phone), and nothing is joined, so a member never appears twice and the counts stay right.
	 */
	public function test_each_word_is_looked_for_on_its_own_and_nothing_is_joined(): void {
		$sql = ListScreen::searchWithFields( $this->wordpressSearch( 'west', 'palm' ), array( 'west', 'palm' ), $this->wpdb() );

		$this->assertSame( 2, substr_count( $sql, 'OR EXISTS ( SELECT 1 FROM wp_postmeta AS favr_s' ) );
		$this->assertStringContainsString( "favr_s.meta_value LIKE '%west%' )", $sql );
		$this->assertStringContainsString( "favr_s.meta_value LIKE '%palm%' )", $sql );
		$this->assertStringNotContainsString( 'JOIN', $sql );
		// WordPress's own "all words" rule is untouched: the two word groups are still ANDed.
		$this->assertSame( 1, substr_count( $sql, ')) AND ((' ) );
	}

	public function test_a_word_typed_twice_is_added_once(): void {
		$sql = ListScreen::searchWithFields( $this->wordpressSearch( 'kim' ), array( 'kim', 'kim' ), $this->wpdb() );

		$this->assertSame( 1, substr_count( $sql, 'OR EXISTS' ) );
	}

	public function test_like_wildcards_in_a_search_are_literal(): void {
		$sql = ListScreen::searchWithFields( " AND (((wp_posts.post_title LIKE '%50\\\\%%'))) ", array( '50%' ), $this->wpdb() );

		$this->assertStringContainsString( "favr_s.meta_value LIKE '%50\\\\%%' )", $sql );
	}

	/**
	 * "-word" means "without this word" to WordPress. That stays a rule about the name only.
	 */
	public function test_an_excluded_word_and_an_empty_search_are_left_alone(): void {
		$excluded = " AND (((wp_posts.post_title NOT LIKE '%test%') AND (wp_posts.post_content NOT LIKE '%test%'))) ";

		$this->assertSame( $excluded, ListScreen::searchWithFields( $excluded, array( '-test' ), $this->wpdb() ) );
		$this->assertSame( '', ListScreen::searchWithFields( '', array( 'dubois' ), $this->wpdb() ) );
		$this->assertSame( $this->wordpressSearch( 'dubois' ), ListScreen::searchWithFields( $this->wordpressSearch( 'dubois' ), array(), $this->wpdb() ) );
	}

	/* ------------------------------------------------------------ Tabs */

	/**
	 * Trash is the last tab on every list; the member statuses used to be added after it.
	 */
	public function test_status_tabs_come_before_trash(): void {
		$views = ListScreen::beforeTrash(
			array(
				'all'     => 'All',
				'publish' => 'Published',
				'trash'   => 'Trash',
			),
			array(
				'favr_active' => 'Active',
				'favr_lapsed' => 'Lapsed',
			)
		);

		$this->assertSame( array( 'all', 'publish', 'favr_active', 'favr_lapsed', 'trash' ), array_keys( $views ) );
		$this->assertSame( 'Trash', $views['trash'] );
	}

	public function test_status_tabs_go_last_when_nothing_is_in_the_trash(): void {
		$views = ListScreen::beforeTrash( array( 'all' => 'All' ), array( 'favr_active' => 'Active' ) );

		$this->assertSame( array( 'all', 'favr_active' ), array_keys( $views ) );
	}

	/* ------------------------------------------------------------ Cells */

	/**
	 * Every other list writes "Oct 8, 2026"; the site's long date format wrapped in this column.
	 */
	public function test_renewal_is_a_short_date(): void {
		Functions\when( 'date_i18n' )->alias( static fn( string $format, int $time ): string => gmdate( $format, $time ) );

		$this->assertSame( 'Jul 8, 2027', ListScreen::shortDate( '2027-07-08' ) );
		$this->assertSame( 'Dec 31, 2026', ListScreen::shortDate( '2026-12-31' ) );
	}

	/**
	 * The same cell as the Directory list: the e-mail first, as the link, with the whole address
	 * as its title (the skin ends a long one in an ellipsis), then the phone, each after its icon.
	 */
	public function test_contact_is_email_then_phone_like_the_directory(): void {
		$this->assertSame(
			'<div><span class="dashicons dashicons-email" aria-hidden="true"></span> <a href="mailto:rachel@kim.example" title="rachel@kim.example">rachel@kim.example</a></div>'
			. '<div><span class="dashicons dashicons-phone" aria-hidden="true"></span> (904) 555-0142</div>',
			ListScreen::contactHtml( 'rachel@kim.example', '(904) 555-0142' )
		);
	}

	public function test_contact_with_one_part_or_none(): void {
		$this->assertSame(
			'<div><span class="dashicons dashicons-phone" aria-hidden="true"></span> 555-0100</div>',
			ListScreen::contactHtml( '', '555-0100' )
		);
		$this->assertSame(
			'<div><span class="dashicons dashicons-email" aria-hidden="true"></span> <a href="mailto:a@b.example" title="a@b.example">a@b.example</a></div>',
			ListScreen::contactHtml( 'a@b.example', '' )
		);
		$this->assertSame( '<span aria-hidden="true">—</span>', ListScreen::contactHtml( '', '' ) );
	}
}
