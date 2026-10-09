<?php
/**
 * Members-only content.
 *
 * @package FavrMembers
 */

declare(strict_types=1);

namespace FavrMembers\Frontend;

use FavrMembers\Model\Member;
use FavrMembers\Model\Repository;
use FavrMembers\Schema\Identifiers as ID;
use FavrMembers\Support\Settings;

/**
 * Three ways to restrict content to current members:
 *  - the "Members Only" block (wraps any blocks),
 *  - the [favr_members_only]…[/favr_members_only] shortcode,
 *  - a "Members only" toggle on pages and posts (the whole content).
 * Staff who can edit the post always see the content.
 *
 * The toggle hides a post's text, hand-written excerpt and comments. Its title, date and
 * featured image stay public, so a members-only article can still be listed and found.
 *
 * How the toggle is enforced: WordPress already has the idea of a post whose text is withheld,
 * the password-protected post, and core, feeds, the REST API, comments, the embed card, core
 * blocks and Elementor all ask post_password_required() before handing text out. For anyone who
 * may not read a members-only post, that question is answered "yes". Filters on rendered output
 * alone (the_content and friends) are not enough: a lot of code reads the post directly.
 */
final class Restrict {

	/**
	 * Whether the last post_password_required() answer was "yes, because it is members-only".
	 * WordPress's next sentence about a "protected post" is then about members, not passwords.
	 *
	 * @var bool
	 */
	private static bool $members_only_now = false;

	/**
	 * Who is a current member, remembered for the request (user id => bool).
	 *
	 * @var array<int, bool>
	 */
	private static array $members = array();

	/** Hook. */
	public function hook(): void {
		add_action( 'init', array( $this, 'register' ) );
		add_shortcode( 'favr_members_only', array( $this, 'shortcode' ) );

		add_filter( 'post_password_required', array( $this, 'asProtected' ), 20, 2 );
		add_filter( 'the_password_form', array( $this, 'insteadOfPasswordForm' ), 20, 2 );
		add_filter( 'gettext', array( $this, 'wording' ), 10, 3 );

		add_filter( 'the_posts', array( $this, 'withhold' ), 20, 2 );
		add_filter( 'the_content', array( $this, 'gatePage' ), 5 );
		add_filter( 'the_content', array( $this, 'gatePageLast' ), 99999 );
		add_filter( 'get_the_excerpt', array( $this, 'gateExcerpt' ), 5, 2 );
		add_filter( 'the_content_feed', array( $this, 'gateFeed' ), 5 );
		add_filter( 'the_excerpt_rss', array( $this, 'gateFeed' ), 5 );
		add_filter( 'rest_prepare_post', array( $this, 'gateRest' ), 10, 2 );
		add_filter( 'rest_prepare_page', array( $this, 'gateRest' ), 10, 2 );

		add_filter( 'comments_array', array( $this, 'gateComments' ), 10, 2 );
		add_filter( 'get_comment_text', array( $this, 'gateCommentText' ), 5, 2 );
		add_filter( 'comments_open', array( $this, 'closeComments' ), 20, 2 );

		add_filter( 'posts_search', array( $this, 'searchTitlesOnly' ), 20, 2 );
		add_filter( 'hello_elementor_description_meta_tag', array( $this, 'themeDescription' ) );
		add_filter( 'wp_sitemaps_posts_query_args', array( $this, 'sitemap' ) );
		add_filter( 'wp_robots', array( $this, 'robots' ) );

		add_filter( 'display_post_states', array( $this, 'postState' ), 10, 2 );
		add_action( 'enqueue_block_editor_assets', array( $this, 'editorAssets' ) );
		add_action( 'favr_members_member_saved', array( self::class, 'forget' ) );
	}

	/** Block + page meta. */
	public function register(): void {
		register_block_type(
			FAVR_MEMBERS_PATH . 'blocks/members-only',
			array( 'render_callback' => array( $this, 'renderBlock' ) )
		);
		// Login, account and application blocks: the same renderers as the shortcodes.
		$pages = new Pages();
		foreach ( array( 'login', 'account', 'register' ) as $slug ) {
			register_block_type(
				FAVR_MEMBERS_PATH . 'blocks/' . $slug,
				array(
					'render_callback' => static fn(): string => sprintf( '<div %s>%s</div>', get_block_wrapper_attributes(), $pages->{$slug}() ),
				)
			);
		}
		foreach ( array( 'page', 'post' ) as $type ) {
			register_post_meta(
				$type,
				ID::PAGE_META_GATED,
				array(
					'type'          => 'boolean',
					'single'        => true,
					'default'       => false,
					'show_in_rest'  => true,
					'auth_callback' => static fn( $allowed, $key, $post_id ): bool => current_user_can( 'edit_post', (int) $post_id ),
				)
			);
		}
	}

	/** Editor UI: the block and the page toggle panel. */
	public function editorAssets(): void {
		wp_enqueue_script(
			'favr-members-editor',
			FAVR_MEMBERS_URL . 'assets/blocks/editor.js',
			array( 'wp-blocks', 'wp-block-editor', 'wp-element', 'wp-components', 'wp-plugins', 'wp-editor', 'wp-data', 'wp-core-data', 'wp-i18n' ),
			\FavrMembers\Vendor\FavrCore\Support\AssetVersion::of( FAVR_MEMBERS_PATH . 'assets/blocks/editor.js', FAVR_MEMBERS_VERSION ),
			true
		);
	}

	/**
	 * Whether a stored value of the page flag means "members only".
	 *
	 * @param mixed $value Stored meta value.
	 */
	public static function flagged( $value ): bool {
		return is_scalar( $value ) && ! in_array( $value, array( '', '0', 0, false ), true );
	}

	/**
	 * Whether a page or post is switched to members only (whoever is looking).
	 *
	 * @param int $post_id Post id.
	 */
	public static function isMembersOnly( int $post_id ): bool {
		return $post_id > 0 && self::flagged( get_post_meta( $post_id, ID::PAGE_META_GATED, true ) ) && ! self::alwaysOpen( $post_id, self::openPages() );
	}

	/**
	 * Whether a page is one of the pages that can never be members-only.
	 *
	 * @param int               $post_id  Post id.
	 * @param array<int, mixed> $page_ids Ids of those pages (as stored: numbers or text).
	 */
	public static function alwaysOpen( int $post_id, array $page_ids ): bool {
		if ( $post_id <= 0 ) {
			return false;
		}
		foreach ( $page_ids as $page_id ) {
			if ( is_numeric( $page_id ) && (int) $page_id === $post_id ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * The pages people need in order to become or stay a member: log in, account, join. Whatever
	 * their switch says they stay open, because a lapsed member has to reach their account to
	 * renew and nobody can log in through a page that asks them to log in first.
	 *
	 * @return array<int, mixed>
	 */
	private static function openPages(): array {
		$pages = array( Settings::get( 'login_page' ), Settings::get( 'account_page' ), Settings::get( 'register_page' ) );
		/**
		 * Filter the pages that can never be members-only (a payments plugin adds its pay page).
		 *
		 * @param array<int, mixed> $pages Page ids.
		 */
		$more = apply_filters( 'favr_members_always_open_pages', $pages );
		return is_array( $more ) ? $more : $pages;
	}

	/** Whether the current visitor is a current member (remembered for the request). */
	private static function isMember(): bool {
		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			return false;
		}
		if ( ! isset( self::$members[ $user_id ] ) ) {
			self::$members[ $user_id ] = favr_members_is_active( $user_id );
		}
		return self::$members[ $user_id ];
	}

	/** A membership changed: ask again who is a member. */
	public static function forget(): void {
		self::$members = array();
	}

	/**
	 * Whether the current visitor may see members-only content.
	 *
	 * @param int $post_id Post being viewed (staff who can edit it always pass).
	 */
	public static function canView( int $post_id = 0 ): bool {
		return ( $post_id && self::isStaff( $post_id ) ) || self::isMember();
	}

	/**
	 * Whether the current user is staff as far as a post is concerned: they can edit it, or they
	 * edit other people's posts of that kind. The second half matters where a page is locked
	 * against editing (Favr Sites locks the pages it manages for Editors): an editor who may
	 * not change a page is still staff, and must not be shown "your membership isn't active".
	 *
	 * @param int $post_id Post id.
	 */
	public static function isStaff( int $post_id ): bool {
		if ( current_user_can( 'edit_post', $post_id ) ) {
			return true;
		}
		$type = get_post_type_object( (string) get_post_type( $post_id ) );
		return null !== $type && current_user_can( $type->cap->edit_others_posts );
	}

	/**
	 * Block render.
	 *
	 * @param array<string, mixed> $attributes Attributes.
	 * @param string               $content    Inner blocks.
	 */
	public function renderBlock( array $attributes, string $content ): string {
		if ( self::canView( (int) get_the_ID() ) ) {
			return $content;
		}
		return self::gate( (string) ( $attributes['message'] ?? '' ) );
	}

	/**
	 * Shortcode.
	 *
	 * @param array<string, string>|string $atts    Attributes.
	 * @param string|null                  $content Enclosed content.
	 */
	public function shortcode( $atts, ?string $content = null ): string {
		$atts = shortcode_atts( array( 'message' => '' ), is_array( $atts ) ? $atts : array() );
		return self::canView( (int) get_the_ID() ) ? do_shortcode( (string) $content ) : self::gate( (string) $atts['message'] );
	}

	/**
	 * Whether a post is members-only and hidden from the current visitor.
	 *
	 * @param int $post_id Post id.
	 */
	public static function isGated( int $post_id ): bool {
		return self::isMembersOnly( $post_id ) && ! self::canView( $post_id );
	}

	/**
	 * On a page view, hand the theme and everything else a copy of a members-only post with its
	 * text and hand-written excerpt taken out, for a visitor who may not read it. The theme's
	 * description tag, Elementor's page settings and others read those straight from the post
	 * the page is about, where no filter on rendered output can reach.
	 *
	 * Only the page's own (main) query on the public site, and only copies: the stored post and
	 * the object cache are never touched, and nothing here runs in wp-admin, the REST API, cron
	 * or WP-CLI, where a post read might be saved again.
	 *
	 * @param mixed          $posts Posts found.
	 * @param \WP_Query|null $query Query.
	 * @return mixed
	 */
	public function withhold( $posts, $query = null ) {
		if ( ! is_array( $posts ) || ! $query instanceof \WP_Query || ! $query->is_main_query() ) {
			return $posts;
		}
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			return $posts;
		}
		foreach ( $posts as $index => $post ) {
			if ( $post instanceof \WP_Post && self::isGated( (int) $post->ID ) ) {
				$copy               = clone $post;
				$copy->post_excerpt = '';
				$copy->post_content = __( 'This content is for members.', 'favr-members' );
				$posts[ $index ]    = $copy;
			}
		}
		return $posts;
	}

	/**
	 * Tell WordPress a members-only post is "protected" for anyone who may not read it.
	 *
	 * @param bool          $required Whether a password is required (WordPress's own answer).
	 * @param \WP_Post|null $post     Post.
	 */
	public function asProtected( $required, $post = null ): bool {
		$hidden                 = $post instanceof \WP_Post && self::isGated( (int) $post->ID );
		self::$members_only_now = $hidden && ! $required;
		return (bool) $required || $hidden;
	}

	/**
	 * Where WordPress would print a password form for a "protected" post, print the members box.
	 *
	 * @param string        $form Password form.
	 * @param \WP_Post|null $post Post.
	 */
	public function insteadOfPasswordForm( $form, $post = null ): string {
		$post = $post instanceof \WP_Post ? $post : get_post();
		return $post instanceof \WP_Post && self::isGated( (int) $post->ID ) ? self::gate( '' ) : (string) $form;
	}

	/**
	 * Which of our sentences replaces one of WordPress's about a protected post ('' = none).
	 *
	 * @param string $text WordPress's original (untranslated) sentence.
	 * @return string members | comments | ''
	 */
	public static function wordingFor( string $text ): string {
		switch ( $text ) {
			case 'There is no excerpt because this is a protected post.':
			case 'This content is password protected.':
				return 'members';
			case 'Password protected':
			case 'This post is password protected. Enter the password to view comments.':
			case 'Protected Comments: Please enter your password to view comments.':
				return 'comments';
		}
		return '';
	}

	/**
	 * WordPress has just been told a members-only post is protected: what it says next about a
	 * password is said about membership instead.
	 *
	 * @param string $translation Translated text.
	 * @param string $text        Original text.
	 * @param string $domain      Text domain.
	 */
	public function wording( $translation, $text, $domain ) {
		if ( ! self::$members_only_now || 'default' !== $domain || ! is_string( $text ) ) {
			return $translation;
		}
		$ours = self::wordingFor( $text );
		if ( 'members' === $ours ) {
			return __( 'This content is for members.', 'favr-members' );
		}
		if ( 'comments' === $ours ) {
			return __( 'Comments are for members.', 'favr-members' );
		}
		return $translation;
	}

	/**
	 * Whole-page gate, first half: before anything else works on the content (blocks,
	 * shortcodes, embeds, page builders), take it away, so nothing hidden is ever processed.
	 *
	 * @param string $content Content.
	 */
	public function gatePage( string $content ): string {
		// Every context that runs the_content (single views, archives, blocks, search,
		// excerpts generated from content) — not just singular pages.
		return self::isGated( (int) get_the_ID() ) ? '' : $content;
	}

	/**
	 * Whole-page gate, second half: after everything else has run, put the members box where the
	 * content would be. Done last so WordPress's paragraph formatting and other plugins' additions
	 * (sharing buttons, related posts) neither mangle the box nor sit underneath it.
	 *
	 * @param string $content Content.
	 */
	public function gatePageLast( string $content ): string {
		$post_id = (int) get_the_ID();
		if ( self::isGated( $post_id ) ) {
			return self::gate( '' );
		}
		return self::staffNote( $post_id ) . $content;
	}

	/**
	 * Staff see a members-only page in full, which looks exactly like a page that is not
	 * protected. Say that it is, once, above the content of the page being viewed.
	 *
	 * @param int $post_id Post id.
	 */
	public static function staffNote( int $post_id ): string {
		if ( ! $post_id || ! is_singular() || ! in_the_loop() || ! is_main_query() || get_queried_object_id() !== $post_id ) {
			return '';
		}
		if ( ! self::isMembersOnly( $post_id ) || ! self::isStaff( $post_id ) ) {
			return '';
		}
		Assets::enqueue();
		return '<p class="favr-m favr-m-staffnote"><strong>' . esc_html__( 'Members only.', 'favr-members' ) . '</strong> '
			. esc_html__( 'You see this because you are staff. Current members see it too; everyone else gets a log-in box instead.', 'favr-members' ) . '</p>';
	}

	/**
	 * Hand-written excerpts are hidden too.
	 *
	 * @param string        $excerpt Excerpt.
	 * @param \WP_Post|null $post    Post.
	 */
	public function gateExcerpt( string $excerpt, $post = null ): string {
		$post_id = $post instanceof \WP_Post ? (int) $post->ID : (int) get_the_ID();
		return self::isGated( $post_id ) ? __( 'This content is for members.', 'favr-members' ) : $excerpt;
	}

	/**
	 * RSS/Atom never carries members-only content.
	 *
	 * @param string $content Content.
	 */
	public function gateFeed( string $content ): string {
		return self::isGated( (int) get_the_ID() ) ? __( 'This content is for members.', 'favr-members' ) : $content;
	}

	/**
	 * REST responses for members-only posts omit content and excerpt for non-members.
	 *
	 * @param \WP_REST_Response $response Response.
	 * @param \WP_Post          $post     Post.
	 */
	public function gateRest( \WP_REST_Response $response, \WP_Post $post ): \WP_REST_Response {
		if ( ! self::isGated( (int) $post->ID ) ) {
			return $response;
		}
		$data = $response->get_data();
		foreach ( array( 'content', 'excerpt' ) as $key ) {
			if ( isset( $data[ $key ] ) && is_array( $data[ $key ] ) ) {
				$data[ $key ]['rendered'] = '';
				if ( isset( $data[ $key ]['raw'] ) ) {
					$data[ $key ]['raw'] = '';
				}
				$data[ $key ]['protected'] = true;
			}
		}
		$response->set_data( $data );
		return $response;
	}

	/**
	 * Comments on a members-only post are part of what is hidden.
	 *
	 * @param array<int, \WP_Comment> $comments Comments for the post being shown.
	 * @param int                     $post_id  Post id.
	 * @return array<int, \WP_Comment>
	 */
	public function gateComments( $comments, $post_id ): array {
		return self::isGated( (int) $post_id ) ? array() : (array) $comments;
	}

	/**
	 * A comment's text, wherever it is listed (recent comments, feeds).
	 *
	 * @param string           $text    Comment text.
	 * @param \WP_Comment|null $comment Comment.
	 */
	public function gateCommentText( $text, $comment = null ): string {
		return $comment instanceof \WP_Comment && self::isGated( (int) $comment->comment_post_ID ) ? __( 'Comments are for members.', 'favr-members' ) : (string) $text;
	}

	/**
	 * Only people who can read a members-only post can comment on it.
	 *
	 * @param bool $open    Whether comments are open.
	 * @param int  $post_id Post id.
	 */
	public function closeComments( $open, $post_id ): bool {
		return (bool) $open && ! self::isGated( (int) $post_id );
	}

	/**
	 * The words of a search to look for in titles: the ones the searcher did not exclude.
	 *
	 * @param array<int, mixed> $terms  Search terms as WordPress parsed them.
	 * @param string            $prefix The "leave these out" prefix ('' when that is switched off).
	 * @return list<string>
	 */
	public static function titleTerms( array $terms, string $prefix ): array {
		$keep = array();
		foreach ( $terms as $term ) {
			if ( ! is_string( $term ) || '' === $term || ( '' !== $prefix && str_starts_with( $term, $prefix ) ) ) {
				continue;
			}
			$keep[] = $term;
		}
		return $keep;
	}

	/**
	 * A search must not say which members-only post contains a word: for anyone who may not
	 * read them, members-only posts are only found by their titles.
	 *
	 * @param string    $search The search part of the query's WHERE.
	 * @param \WP_Query $query  Query.
	 */
	public function searchTitlesOnly( $search, $query ): string {
		global $wpdb;
		$search = (string) $search;
		if ( '' === trim( $search ) || ! $query instanceof \WP_Query ) {
			return $search;
		}
		// Members read everything; so do staff who can edit other people's posts and pages.
		if ( self::isMember() || ( current_user_can( 'edit_others_posts' ) && current_user_can( 'edit_others_pages' ) ) ) {
			return $search;
		}
		/** This filter is documented in wp-includes/class-wp-query.php */
		$prefix = (string) apply_filters( 'wp_query_search_exclusion_prefix', '-' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress's own filter, asked the way core asks it.
		$titles = array();
		foreach ( self::titleTerms( (array) $query->get( 'search_terms' ), $prefix ) as $term ) {
			$titles[] = $wpdb->prepare( "{$wpdb->posts}.post_title LIKE %s", '%' . $wpdb->esc_like( $term ) . '%' );
		}
		$by_title = $titles ? '( ' . implode( ' AND ', $titles ) . ' )' : '1 = 0';
		$flagged  = $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value NOT IN ( '', '0' )", ID::PAGE_META_GATED );
		return $search . " AND ( {$wpdb->posts}.ID NOT IN ( {$flagged} ) OR {$by_title} ) ";
	}

	/**
	 * Hello Elementor prints a description tag from the hand-written excerpt, read straight
	 * from the post.
	 *
	 * @param bool $show Whether to print it.
	 */
	public function themeDescription( $show ): bool {
		return (bool) $show && ! ( is_singular() && self::isGated( (int) get_queried_object_id() ) );
	}

	/**
	 * Members-only pages carry noindex, so they do not belong in the sitemap.
	 *
	 * @param array<string, mixed> $args Sitemap query arguments.
	 * @return array<string, mixed>
	 */
	public function sitemap( $args ): array {
		$args                 = is_array( $args ) ? $args : array();
		$args['meta_query']   = isset( $args['meta_query'] ) && is_array( $args['meta_query'] ) ? $args['meta_query'] : array(); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- sitemap pages are cached by core.
		$args['meta_query'][] = array(
			'relation' => 'OR',
			array(
				'key'     => ID::PAGE_META_GATED,
				'compare' => 'NOT EXISTS',
			),
			array(
				'key'     => ID::PAGE_META_GATED,
				'value'   => array( '', '0' ),
				'compare' => 'IN',
			),
		);
		return $args;
	}

	/**
	 * Keep gated pages out of search results.
	 *
	 * @param array<string, bool|string> $robots Robots.
	 * @return array<string, bool|string>
	 */
	public function robots( array $robots ): array {
		if ( is_singular() && self::isMembersOnly( (int) get_queried_object_id() ) ) {
			$robots['noindex'] = true;
		}
		return $robots;
	}

	/**
	 * "Members only" next to the title in the Pages and Posts lists.
	 *
	 * @param array<string, string> $states States.
	 * @param \WP_Post|null         $post   Post.
	 * @return array<string, string>
	 */
	public function postState( $states, $post = null ): array {
		$states = is_array( $states ) ? $states : array();
		if ( $post instanceof \WP_Post && self::isMembersOnly( (int) $post->ID ) ) {
			$states['favr_members_only'] = __( 'Members only', 'favr-members' );
		}
		return $states;
	}

	/**
	 * Where a signed-in person who is not a current member stands, from the status of each
	 * member record their account is linked to. Someone on several records hears about the one
	 * closest to getting in.
	 *
	 * @param array<int, mixed> $statuses Status of each linked member record.
	 * @return string none (no record) | other (a status another plugin added, such as awaiting
	 *                payment) | pending | lapsed | inactive
	 */
	public static function standing( array $statuses ): string {
		if ( array() === $statuses ) {
			return 'none';
		}
		$known = array( ID::STATUS_ACTIVE, ID::STATUS_PENDING, ID::STATUS_LAPSED, ID::STATUS_INACTIVE );
		foreach ( $statuses as $status ) {
			if ( is_string( $status ) && '' !== $status && ! in_array( $status, $known, true ) ) {
				return 'other';
			}
		}
		foreach ( array( ID::STATUS_PENDING, ID::STATUS_LAPSED ) as $closer ) {
			if ( in_array( $closer, $statuses, true ) ) {
				return $closer;
			}
		}
		return ID::STATUS_INACTIVE;
	}

	/**
	 * The message shown instead of restricted content.
	 *
	 * @param string $message Custom message.
	 */
	public static function gate( string $message ): string {
		Assets::enqueue();
		$standing = '';
		if ( is_user_logged_in() ) {
			$standing = self::standing( array_map( static fn( Member $member ): string => $member->status(), Repository::forUser( get_current_user_id() ) ) );
		}
		return View::render(
			'gate',
			array(
				'message'  => $message,
				'standing' => $standing,
			)
		);
	}
}
