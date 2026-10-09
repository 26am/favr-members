<?php
/**
 * Members-only pages and posts: who can read them, through every way WordPress, the theme and
 * Elementor hand out a post's text.
 *
 *   wp eval-file tests/integration/gating.php
 *
 * Makes its own posts, people and comments and removes them at the end, so it can be run on any
 * local site. Each hidden text carries a marker word; a marker reaching someone who is not a
 * current member (and cannot edit the post) is a leak.
 *
 * @package FavrMembers
 */

use FavrMembers\Schema\Identifiers as ID;

$favr_gate = array( 0, 0 );
$check     = static function ( string $label, bool $ok, string $detail = '' ) use ( &$favr_gate ): void {
	++$favr_gate[ $ok ? 0 : 1 ];
	echo ( $ok ? '  ok   ' : '  FAIL ' ) . $label . ( $ok || '' === $detail ? '' : ' — ' . $detail ) . "\n";
};
$made = array( 'posts' => array(), 'users' => array(), 'terms' => array() );
// The REST API is asked in-process further down. Its routes are set up now, the way a real
// request sets them up before anything is rendered.
rest_get_server();
// An earlier run that was interrupted may have left its people and posts behind.
require_once ABSPATH . 'wp-admin/includes/user.php';
foreach ( get_users( array( 'search' => 'gating_*', 'search_columns' => array( 'user_login' ), 'fields' => 'ID' ) ) as $favr_gate_left ) {
	wp_delete_user( (int) $favr_gate_left );
}
foreach ( get_posts( array( 'post_type' => array( 'post', 'page', ID::POST_TYPE ), 'post_status' => 'any', 'posts_per_page' => 200, 'fields' => 'ids', 'suppress_filters' => true ) ) as $favr_gate_left ) {
	if ( 0 === strpos( (string) get_post_field( 'post_title', $favr_gate_left, 'raw' ), 'Gating ' ) ) {
		wp_delete_post( (int) $favr_gate_left, true );
	}
}
$mark = static fn( string $name ): string => 'zq' . $name . 'gate';

// --- People -------------------------------------------------------------------------------
$person = static function ( string $login, string $role ) use ( &$made ): int {
	$id = username_exists( $login );
	$id = $id ? (int) $id : (int) wp_insert_user( array( 'user_login' => $login, 'user_email' => $login . '@example.test', 'user_pass' => wp_generate_password( 24 ), 'role' => $role ) );
	$made['users'][] = $id;
	return $id;
};
$membership = static function ( string $name, string $status, int $user_id ) use ( &$made ): void {
	$id = (int) wp_insert_post( array( 'post_type' => ID::POST_TYPE, 'post_status' => 'publish', 'post_title' => $name ) );
	foreach ( array( 'type' => 'individual', 'first_name' => $name, 'last_name' => 'Gating', 'email' => get_userdata( $user_id )->user_email, 'status' => $status ) as $k => $v ) {
		update_post_meta( $id, ID::meta( $k ), $v );
	}
	add_post_meta( $id, ID::meta( 'user' ), $user_id );
	if ( get_post_meta( $id, ID::meta( 'status' ), true ) !== $status ) {
		// The status field only takes the statuses known when it was set up (a plugin adds its own
		// at load, this script adds one late): store it the way that plugin's would be stored.
		$GLOBALS['wpdb']->update( $GLOBALS['wpdb']->postmeta, array( 'meta_value' => $status ), array( 'post_id' => $id, 'meta_key' => ID::meta( 'status' ) ) ); // phpcs:ignore WordPress.DB
		wp_cache_delete( $id, 'post_meta' );
	}
	$made['posts'][] = $id;
};
$people = array(
	'a visitor'            => 0,
	'a signed-in non-member' => $person( 'gating_subscriber', 'subscriber' ),
	'a lapsed member'      => $person( 'gating_lapsed', ID::ROLE_PERSON ),
	'an applicant'         => $person( 'gating_pending', ID::ROLE_PERSON ),
	'an approved member who has not paid' => $person( 'gating_waiting', ID::ROLE_PERSON ),
	'an author of other posts' => $person( 'gating_author', 'author' ),
	'a current member'     => $person( 'gating_member', ID::ROLE_PERSON ),
	'an editor'            => $person( 'gating_editor', 'editor' ),
);
$membership( 'Gating Lapsed', ID::STATUS_LAPSED, $people['a lapsed member'] );
$membership( 'Gating Pending', ID::STATUS_PENDING, $people['an applicant'] );
$membership( 'Gating Member', ID::STATUS_ACTIVE, $people['a current member'] );
// A status another plugin adds (Favr Payments adds "Awaiting payment").
add_filter( 'favr_members_statuses', static fn( $statuses ): array => (array) $statuses + array( 'gating_waiting' => 'Waiting' ) );
$membership( 'Gating Waiting', 'gating_waiting', $people['an approved member who has not paid'] );
$allowed = array( 'a current member', 'an editor' );
$as      = static function ( int $user_id, callable $work ) {
	$before = get_current_user_id();
	wp_set_current_user( $user_id );
	try {
		return $work();
	} finally {
		wp_set_current_user( $before );
	}
};

// --- Posts ----------------------------------------------------------------------------------
$owner = $people['an editor'];
$term  = wp_insert_term( 'Gating Test ' . wp_rand(), 'category' );
$cat   = (int) $term['term_id'];
$made['terms'][] = $cat;
$post  = static function ( array $args, bool $gated ) use ( &$made, $owner, $cat ): int {
	$id = (int) wp_insert_post( array_merge( array( 'post_status' => 'publish', 'post_author' => $owner, 'comment_status' => 'open' ), $args ) );
	if ( 'page' !== ( $args['post_type'] ?? 'post' ) ) {
		wp_set_post_terms( $id, array( $cat ), 'category' );
	}
	if ( $gated ) {
		update_post_meta( $id, ID::PAGE_META_GATED, true );
	}
	$made['posts'][] = $id;
	return $id;
};
$open   = $post( array( 'post_title' => 'Gating public post', 'post_content' => '<!-- wp:paragraph --><p>Public body ' . $mark( 'publicbody' ) . '.</p><!-- /wp:paragraph -->', 'post_excerpt' => 'Public excerpt ' . $mark( 'publicexcerpt' ) ), false );
$hidden = $post( array( 'post_title' => 'Gating members post', 'post_content' => '<!-- wp:paragraph --><p>Hidden body ' . $mark( 'body' ) . '.</p><!-- /wp:paragraph -->', 'post_excerpt' => 'Hidden excerpt ' . $mark( 'excerpt' ) ), true );
$auto   = $post( array( 'post_title' => 'Gating members post without an excerpt', 'post_content' => '<!-- wp:paragraph --><p>Hidden body ' . $mark( 'autobody' ) . ' with enough words after it for WordPress to make an excerpt of its own.</p><!-- /wp:paragraph -->' ), true );
$page   = $post( array( 'post_type' => 'page', 'post_title' => 'Gating members page', 'post_content' => '<!-- wp:paragraph --><p>Hidden page ' . $mark( 'page' ) . '.</p><!-- /wp:paragraph -->' ), true );
$plain  = $post( array( 'post_title' => 'Gating public post without an excerpt', 'post_content' => '<!-- wp:paragraph --><p>Public body ' . $mark( 'plainbody' ) . ' with enough words after it for WordPress to make an excerpt of its own.</p><!-- /wp:paragraph -->' ), false );
wp_insert_comment( array( 'comment_post_ID' => $hidden, 'comment_author' => 'A member', 'comment_author_email' => 'm@example.test', 'comment_content' => 'Hidden comment ' . $mark( 'comment' ), 'comment_approved' => 1 ) );
$built  = 0;
if ( class_exists( '\Elementor\Plugin' ) ) {
	$built = $post( array( 'post_type' => 'page', 'post_title' => 'Gating members page built in Elementor', 'post_content' => '' ), true );
	update_post_meta( $built, '_elementor_edit_mode', 'builder' );
	$as(
		$owner,
		static function () use ( $built, $mark ): void {
			\Elementor\Plugin::$instance->documents->get( $built, false )->save(
				array(
					'elements' => array(
						array(
							'id'       => 'gtg0001',
							'elType'   => 'container',
							'settings' => array(),
							'isInner'  => false,
							'elements' => array(
								array( 'id' => 'gtg0002', 'elType' => 'widget', 'widgetType' => 'text-editor', 'settings' => array( 'editor' => '<p>Hidden built page ' . $mark( 'built' ) . '.</p>' ), 'elements' => array() ),
							),
						),
					),
					'settings' => array( 'favr_members_only' => '1' ),
				)
			);
		}
	);
}
// A news list the way Elementor Pro builds one: a Loop Grid whose card shows each post's title and
// its hand-written excerpt (Elementor's Post Excerpt reads that straight from the post).
$grid = 0;
if ( $built && defined( 'ELEMENTOR_PRO_VERSION' ) ) {
	$card = (int) wp_insert_post( array( 'post_type' => 'elementor_library', 'post_status' => 'publish', 'post_title' => 'Gating loop card', 'post_author' => $owner ) );
	$made['posts'][] = $card;
	update_post_meta( $card, '_elementor_edit_mode', 'builder' );
	update_post_meta( $card, '_elementor_template_type', 'loop-item' );
	wp_set_object_terms( $card, 'loop-item', 'elementor_library_type' );
	$grid = $post( array( 'post_type' => 'page', 'post_title' => 'Gating news list', 'post_content' => '' ), false );
	update_post_meta( $grid, '_elementor_edit_mode', 'builder' );
	$tag  = static fn( string $name ): string => '[elementor-tag id="gtg' . substr( md5( $name ), 0, 4 ) . '" name="' . $name . '" settings="%7B%7D"]';
	$as(
		$owner,
		static function () use ( $card, $grid, $cat, $tag ): void {
			\Elementor\Plugin::$instance->documents->get( $card, false )->save(
				array(
					'elements' => array(
						array(
							'id'       => 'gtg1001',
							'elType'   => 'container',
							'settings' => array(),
							'isInner'  => false,
							'elements' => array(
								array( 'id' => 'gtg1002', 'elType' => 'widget', 'widgetType' => 'heading', 'settings' => array( 'title' => 'Title', '__dynamic__' => array( 'title' => $tag( 'post-title' ) ) ), 'elements' => array() ),
								array( 'id' => 'gtg1003', 'elType' => 'widget', 'widgetType' => 'text-editor', 'settings' => array( 'editor' => 'Excerpt', '__dynamic__' => array( 'editor' => $tag( 'post-excerpt' ) ) ), 'elements' => array() ),
							),
						),
					),
					'settings' => array( 'source' => 'post' ),
				)
			);
			\Elementor\Plugin::$instance->documents->get( $grid, false )->save(
				array(
					'elements' => array(
						array(
							'id'       => 'gtg2001',
							'elType'   => 'container',
							'settings' => array(),
							'isInner'  => false,
							'elements' => array(
								array(
									'id'         => 'gtg2002',
									'elType'     => 'widget',
									'widgetType' => 'loop-grid',
									'settings'   => array(
										'template_id'                 => $card,
										'posts_per_page'              => 10,
										'post_query_post_type'        => 'post',
										'post_query_include'          => array( 'terms' ),
										'post_query_include_term_ids' => array( (string) get_term( $cat )->term_taxonomy_id ),
									),
									'elements'   => array(),
								),
							),
						),
					),
					'settings' => array(),
				)
			);
		}
	);
}
$secrets = array( $mark( 'body' ), $mark( 'excerpt' ), $mark( 'autobody' ), $mark( 'page' ), $mark( 'comment' ), $mark( 'built' ) );

// --- Ways a post's text is handed out ------------------------------------------------------------
// Each returns everything that way would send to the browser (or feed reader, or API client).
$loop = static function ( array $query, callable $each ): string {
	global $wp_query, $wp_the_query, $post;
	$keep = array( $wp_query, $wp_the_query, $post );
	// phpcs:disable WordPress.WP.GlobalVariablesOverride.Prohibited -- stands in for the page's main query, and is put back.
	$wp_query     = new WP_Query( $query );
	$wp_the_query = $wp_query;
	ob_start();
	while ( have_posts() ) {
		the_post();
		$each();
	}
	wp_reset_postdata();
	list( $wp_query, $wp_the_query, $post ) = $keep;
	// phpcs:enable WordPress.WP.GlobalVariablesOverride.Prohibited
	return (string) ob_get_clean();
};
$rest = static function ( string $route, array $params = array() ): string {
	$request = new WP_REST_Request( 'GET', $route );
	$request->set_query_params( $params );
	$response = rest_do_request( $request );
	return (string) wp_json_encode( rest_get_server()->response_to_data( $response, ! empty( $params['_embed'] ) ) );
};
$ids  = array_filter( array( $hidden, $auto, $page, $built, $open ) );
$ways = array(
	'the page itself (content, excerpt, comments)' => static fn(): string => $loop(
		array( 'post__in' => $ids, 'post_type' => array( 'post', 'page' ), 'posts_per_page' => 20, 'ignore_sticky_posts' => true ),
		static function (): void {
			the_title();
			the_content();
			the_excerpt();
			echo get_the_content(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- inspected, not displayed.
			if ( ! post_password_required() ) {
				foreach ( get_comments( array( 'post_id' => get_the_ID(), 'status' => 'approve' ) ) as $comment ) {
					echo esc_html( $comment->comment_content );
				}
			}
		}
	),
	'the theme\'s description tag' => static function () use ( $hidden ): string {
		if ( ! function_exists( 'hello_elementor_add_description_meta_tag' ) ) {
			return '';
		}
		global $wp_query, $post;
		$keep     = array( $wp_query, $post );
		$wp_query = new WP_Query( array( 'p' => $hidden ) ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- stands in for the main query.
		$wp_query->the_post();
		ob_start();
		hello_elementor_add_description_meta_tag();
		$out = (string) ob_get_clean();
		wp_reset_postdata();
		list( $wp_query, $post ) = $keep; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- put back.
		return $out;
	},
	'a Latest Posts block showing full posts' => static fn(): string => do_blocks( '<!-- wp:latest-posts {"categories":[{"id":' . $cat . '}],"postsToShow":10,"displayPostContent":true,"displayPostContentRadio":"full_post"} /-->' ),
	'a Latest Posts block showing excerpts'   => static fn(): string => do_blocks( '<!-- wp:latest-posts {"categories":[{"id":' . $cat . '}],"postsToShow":10,"displayPostContent":true,"displayPostContentRadio":"excerpt"} /-->' ),
	'a Query Loop with excerpt and content'   => static fn(): string => do_blocks( '<!-- wp:query {"queryId":9,"query":{"perPage":10,"postType":"post","taxQuery":{"category":[' . $cat . ']},"inherit":false}} --><div class="wp-block-query"><!-- wp:post-template --><!-- wp:post-title /--><!-- wp:post-excerpt /--><!-- wp:post-content /--><!-- /wp:post-template --></div><!-- /wp:query -->' ),
	'the feed'                                => static fn(): string => $loop(
		array( 'post__in' => $ids, 'post_type' => 'post', 'posts_per_page' => 20, 'ignore_sticky_posts' => true, 'feed' => 'rss2' ),
		static function (): void {
			the_title_rss();
			the_excerpt_rss();
			the_content_feed( 'rss2' );
		}
	),
	'the REST API: one post'                  => static fn(): string => $rest( '/wp/v2/posts/' . $hidden ) . $rest( '/wp/v2/pages/' . $page ),
	'the REST API: lists, embedded'           => static fn(): string => $rest( '/wp/v2/posts', array( 'categories' => $cat, '_embed' => 1 ) ) . $rest( '/wp/v2/posts', array( 'include' => array( $hidden, $auto ), 'context' => 'embed' ) ),
	'the REST API: comments'                  => static fn(): string => $rest( '/wp/v2/comments', array( 'post' => $hidden ) ),
	'the embed card other sites show'         => static function () use ( $hidden ): string {
		$data = get_oembed_response_data( $hidden, 600 );
		return (string) wp_json_encode( $data ) . $GLOBALS['favr_gate_loop']( array( 'p' => $hidden ), static function (): void { the_excerpt_embed(); } );
	},
);
$GLOBALS['favr_gate_loop'] = $loop;
if ( $built ) {
	$ways['a page built in Elementor'] = static fn(): string => (string) \Elementor\Plugin::$instance->frontend->get_builder_content_for_display( $built ) . $loop(
		array( 'page_id' => $built ),
		static function (): void {
			the_content();
		}
	);
	$ways['an Elementor template shortcode pointing at it'] = static fn(): string => do_shortcode( '[elementor-template id="' . $built . '"]' );
}

echo "Who can read a members-only page or post\n";
foreach ( $ways as $way => $render ) {
	foreach ( $people as $who => $user_id ) {
		$html  = (string) $as( $user_id, $render );
		$seen  = array_values( array_filter( $secrets, static fn( string $word ): bool => false !== strpos( $html, $word ) ) );
		$may   = in_array( $who, $allowed, true );
		$label = $way . ', read by ' . $who;
		if ( $may ) {
			continue; // What members and staff see is checked once, below.
		}
		$check( $label . ': nothing hidden comes out', array() === $seen, 'leaked ' . implode( ', ', $seen ) );
	}
}

echo "Members and staff still get everything\n";
foreach ( $allowed as $who ) {
	$html = (string) $as( $people[ $who ], $ways['the page itself (content, excerpt, comments)'] );
	$want = array( $mark( 'body' ), $mark( 'excerpt' ), $mark( 'autobody' ), $mark( 'page' ), $mark( 'comment' ) );
	$miss = array_values( array_filter( $want, static fn( string $word ): bool => false === strpos( $html, $word ) ) );
	$check( $who . ' reads the hidden text', array() === $miss, 'missing ' . implode( ', ', $miss ) );
	if ( $built ) {
		$check( $who . ' reads the page built in Elementor', false !== strpos( (string) $as( $people[ $who ], $ways['a page built in Elementor'] ), $mark( 'built' ) ) );
	}
}

echo "Public posts are untouched (and each way of looking really does show posts)\n";
foreach ( array( 'the page itself (content, excerpt, comments)', 'a Latest Posts block showing full posts', 'a Latest Posts block showing excerpts', 'a Query Loop with excerpt and content', 'the feed', 'the REST API: lists, embedded' ) as $way ) {
	foreach ( array( 'a visitor', 'a lapsed member' ) as $who ) {
		$html = (string) $as( $people[ $who ], $ways[ $way ] );
		$check( $way . ', read by ' . $who . ': the public post is there', false !== strpos( $html, $mark( 'publicbody' ) ) || false !== strpos( $html, $mark( 'publicexcerpt' ) ) );
	}
}
$check( 'the REST API answers a visitor for one public post', false !== strpos( (string) $as( $people['a visitor'], static fn(): string => $rest( '/wp/v2/posts/' . $open ) ), $mark( 'publicbody' ) ) );
$check( 'and names a members-only post without its text', false !== strpos( (string) $as( $people['a visitor'], $ways['the REST API: one post'] ), 'Gating members post' ) );

echo "What a non-member is shown instead\n";
$visitor = $people['a visitor'];
$lapsed  = $people['a lapsed member'];
$shown   = (string) $as( $visitor, static fn(): string => $loop( array( 'p' => $hidden ), static function (): void { the_content(); } ) );
$check( 'a visitor gets the members box with a way to log in', false !== strpos( $shown, 'favr-m-gate' ) && false !== strpos( $shown, 'This content is for members.' ) );
$check( 'never WordPress\'s password form', false === strpos( $shown, 'post-password-form' ) && false === strpos( $shown, 'post_password' ) );
$shown = (string) $as( $lapsed, static fn(): string => $loop( array( 'p' => $hidden ), static function (): void { the_content(); } ) );
$check( 'a lapsed member is told their membership has lapsed', false !== strpos( $shown, 'Your membership has lapsed' ) );
$excerpt = (string) $as( $visitor, static fn(): string => get_the_excerpt( $hidden ) );
$check( 'in a list, the excerpt says it is for members (not "protected post")', 'This content is for members.' === $excerpt, $excerpt );
$block = (string) $as( $visitor, $ways['a Latest Posts block showing full posts'] );
$check( 'a Latest Posts block says the same, and still lists the title', false !== strpos( $block, 'This content is for members.' ) && false !== strpos( $block, 'Gating members post' ) && false === strpos( $block, 'password' ) );
$check( 'a post that really has a password keeps WordPress\'s wording', (static function () use ( $post, $as, $visitor ): bool {
	$locked = $post( array( 'post_title' => 'Gating password post', 'post_content' => 'Password body', 'post_password' => 'opensesame' ), false );
	return 'There is no excerpt because this is a protected post.' === $as( $visitor, static fn(): string => get_the_excerpt( $locked ) );
} )() );

echo "Comments\n";
// Favr Sites closes comments everywhere; for these checks, let WordPress decide again.
remove_all_filters( 'comments_open', PHP_INT_MAX );
remove_all_filters( 'comments_array', PHP_INT_MAX );
$check( 'a visitor cannot comment on a members-only post', false === $as( $visitor, static fn(): bool => comments_open( $hidden ) ) );
$check( 'a member can', true === $as( $people['a current member'], static fn(): bool => comments_open( $hidden ) ) );
$check( 'anyone can still comment on a public post', true === $as( $visitor, static fn(): bool => comments_open( $open ) ) );
$one = get_comments( array( 'post_id' => $hidden, 'number' => 1 ) )[0];
$check( 'a list of recent comments does not show a hidden one\'s text', false === strpos( (string) $as( $visitor, static fn(): string => get_comment_text( $one ) . get_comment_excerpt( $one ) ), $mark( 'comment' ) ) );
$check( 'the theme\'s comment list gets nothing for a visitor', array() === $as( $visitor, static fn(): array => (array) apply_filters( 'comments_array', array( $one ), $hidden ) ) );
$check( 'and everything for a member', 1 === count( (array) $as( $people['a current member'], static fn(): array => (array) apply_filters( 'comments_array', array( $one ), $hidden ) ) ) );

echo "Search\n";
$found = static function ( string $words ) use ( $hidden, $auto, $page ): array {
	$query = new WP_Query( array( 's' => $words, 'post_type' => array( 'post', 'page' ), 'posts_per_page' => 50, 'fields' => 'ids' ) );
	return array_values( array_intersect( array( $hidden, $auto, $page ), array_map( 'intval', $query->posts ) ) );
};
$check( 'a visitor searching for a word that is only in the hidden text finds nothing', array() === $as( $visitor, static fn(): array => $found( $mark( 'body' ) ) ) );
$check( 'nor for a word that is only in the hidden excerpt', array() === $as( $visitor, static fn(): array => $found( $mark( 'excerpt' ) ) ) );
$check( 'a lapsed member is in the same position', array() === $as( $lapsed, static fn(): array => $found( $mark( 'page' ) ) ) );
$check( 'the title still finds it', in_array( $hidden, $as( $visitor, static fn(): array => $found( 'Gating members post' ) ), true ) );
$check( 'a title word plus a hidden word does not', array() === $as( $visitor, static fn(): array => $found( 'Gating ' . $mark( 'body' ) ) ) );
$check( 'a member finds it by the hidden text', array( $hidden ) === $as( $people['a current member'], static fn(): array => $found( $mark( 'body' ) ) ) );
$check( 'so does an editor', array( $hidden ) === $as( $people['an editor'], static fn(): array => $found( $mark( 'body' ) ) ) );
$check( 'searching public posts is unchanged', 1 === count( (array) $as( $visitor, static fn(): array => ( new WP_Query( array( 's' => $mark( 'publicbody' ), 'fields' => 'ids' ) ) )->posts ) ) );
$rest_search = (string) $as( $visitor, static fn(): string => $rest( '/wp/v2/posts', array( 'search' => $mark( 'body' ) ) ) . $rest( '/wp/v2/search', array( 'search' => $mark( 'autobody' ) ) ) );
$check( 'the REST API search gives nothing away either', false === strpos( $rest_search, 'Gating members post' ), $rest_search );

echo "For search engines and for staff\n";
$raw = (string) $as( $visitor, static fn(): string => $rest( '/wp/v2/posts/' . $hidden ) );
$api = json_decode( $raw, true );
$api = is_array( $api ) ? $api : array( 'undecodable' => substr( $raw, 0, 300 ) );
$check( 'the REST API marks the content as protected and leaves the title', true === ( $api['content']['protected'] ?? null ) && '' === ( $api['content']['rendered'] ?? 'x' ) && false !== strpos( (string) ( $api['title']['rendered'] ?? '' ), 'Gating members post' ), substr( (string) wp_json_encode( $api ), 0, 400 ) );
// The query WordPress builds its sitemap from (asked directly: a site hidden from search engines has no sitemap).
$listed = array();
foreach ( array( 'post', 'page' ) as $favr_gate_type ) {
	$args   = (array) apply_filters( 'wp_sitemaps_posts_query_args', array( 'post_type' => $favr_gate_type, 'post_status' => array( 'publish' ), 'posts_per_page' => 500, 'orderby' => 'ID', 'order' => 'ASC', 'no_found_rows' => true, 'fields' => 'ids' ), $favr_gate_type ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress's own filter.
	$listed = array_merge( $listed, array_map( 'intval', ( new WP_Query( $args ) )->posts ) );
}
$check( 'the sitemap lists the public post and not the members-only ones', in_array( $open, $listed, true ) && ! in_array( $hidden, $listed, true ) && ! in_array( $page, $listed, true ) );
$states = (array) apply_filters( 'display_post_states', array(), get_post( $hidden ) );
$check( 'the Posts list says "Members only" next to the title', in_array( 'Members only', $states, true ) && ! in_array( 'Members only', (array) apply_filters( 'display_post_states', array(), get_post( $open ) ), true ) );
$single = static function ( int $id ) use ( $loop ): string {
	return $loop(
		array( 'p' => $id, 'post_type' => 'any' ),
		static function (): void {
			the_content();
		}
	);
};
$note = (string) $as( $people['an editor'], static fn(): string => $single( $hidden ) );
$check( 'staff reading a members-only post are told visitors do not see it', false !== strpos( $note, 'favr-m-staffnote' ) && false !== strpos( $note, $mark( 'body' ) ) );
$check( 'a member reading it sees no such note', false === strpos( (string) $as( $people['a current member'], static fn(): string => $single( $hidden ) ), 'favr-m-staffnote' ) );
$check( 'and there is none on a public post', false === strpos( (string) $as( $people['an editor'], static fn(): string => $single( $open ) ), 'favr-m-staffnote' ) );

// --- Around a members-only post ------------------------------------------------------------------
echo "Around a members-only post\n";
// A list of other posts on a members-only page (a Latest Posts block in the footer, "related posts")
// asks for their excerpts while the page's own post is the current one.
$near = (string) $as( $visitor, static fn(): string => $loop( array( 'p' => $hidden ), static function () use ( $plain, $auto ): void { echo esc_html( get_the_excerpt( $plain ) . ' | ' . get_the_excerpt( $auto ) ); } ) );
$check( 'on a members-only page, a public post listed beside it keeps its own excerpt', false !== strpos( $near, $mark( 'plainbody' ) ), $near );
$check( 'and another members-only post listed there stays hidden', false === strpos( $near, $mark( 'autobody' ) ) );
$near = (string) $as( $visitor, static fn(): string => $loop( array( 'p' => $plain ), static function () use ( $auto ): void { echo esc_html( get_the_excerpt( $auto ) ); the_content(); } ) );
$check( 'on a public page, a members-only post listed beside it is hidden and the page itself is not', false === strpos( $near, $mark( 'autobody' ) ) && false !== strpos( $near, $mark( 'plainbody' ) ) );
$note = (string) $as( $people['an editor'], static fn(): string => $loop( array( 'p' => $auto ), static function (): void { echo esc_html( get_the_excerpt() ); } ) );
$check( 'the note for staff never ends up inside an excerpt', false === strpos( $note, 'because you are staff' ) && false !== strpos( $note, $mark( 'autobody' ) ), $note );
// Another plugin handing an empty value to WordPress's filters must not crash the page.
$crash = '';
try {
	apply_filters( 'the_content', null );
	apply_filters( 'get_the_excerpt', null, null );
	apply_filters( 'the_content_feed', null, 'rss2' );
	apply_filters( 'the_excerpt_rss', null );
	apply_filters( 'comments_array', null, 0 );
	apply_filters( 'display_post_states', null, null );
} catch ( \Throwable $e ) {
	$crash = $e->getMessage();
}
$check( 'an empty value from another plugin passing through WordPress\'s filters does not crash the page', '' === $crash, $crash );
// A copy handed out with its text withheld must never be saved over the real post.
$copy               = clone get_post( $hidden );
$copy->post_content = 'This content is for members.';
$copy->post_excerpt = '';
$copy->{\FavrMembers\Frontend\Restrict::WITHHELD} = true;
wp_update_post( $copy );
clean_post_cache( $hidden );
$check( 'a withheld copy saved by mistake does not overwrite the real text', false !== strpos( (string) get_post_field( 'post_content', $hidden, 'raw' ), $mark( 'body' ) ) && false !== strpos( (string) get_post_field( 'post_excerpt', $hidden, 'raw' ), $mark( 'excerpt' ) ) );

// --- What the members box says -----------------------------------------------------------------
echo "What the members box tells each person\n";
$says = array(
	'a signed-in non-member'              => 'this account has no membership',
	'a lapsed member'                     => 'Your membership has lapsed',
	'an applicant'                        => 'Your application is being reviewed',
	'an approved member who has not paid' => 'Your membership is not active yet',
	'an author of other posts'            => 'this account has no membership',
);
$box  = (string) $as( 0, static fn(): string => \FavrMembers\Frontend\Restrict::gate( '' ) );
$told = array_filter( $says, static fn( string $words ): bool => false !== strpos( $box, $words ) );
$check( 'a visitor is told it is for members, and nothing about a membership of their own', false !== strpos( $box, 'This content is for members.' ) && array() === $told && false === strpos( $box, 'isn’t active' ), implode( ' | ', $told ) );
foreach ( $says as $who => $words ) {
	$box  = (string) $as( $people[ $who ], static fn(): string => \FavrMembers\Frontend\Restrict::gate( '' ) );
	$told = array_unique( array_values( array_filter( $says, static fn( string $other ): bool => false !== strpos( $box, $other ) ) ) );
	$check( $who . ' is told where they stand, and only that', array( $words ) === $told && false === strpos( $box, 'isn’t active' ), implode( ' | ', $told ) );
}

// --- Whole pages, fetched from the site the way a browser would -----------------------------------
echo "What a browser is sent\n";
$fetch = static function ( string $url, int $user_id = 0 ): array {
	$cookies = array();
	if ( $user_id ) {
		$expires   = time() + HOUR_IN_SECONDS;
		$token     = WP_Session_Tokens::get_instance( $user_id )->create( $expires );
		$cookies[] = new WP_Http_Cookie( array( 'name' => LOGGED_IN_COOKIE, 'value' => wp_generate_auth_cookie( $user_id, $expires, 'logged_in', $token ) ) );
	}
	$response = wp_remote_get( $url, array( 'timeout' => 45, 'redirection' => 3, 'cookies' => $cookies, 'sslverify' => false ) );
	return array(
		'code'  => is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response ),
		'cache' => is_wp_error( $response ) ? '' : strtolower( (string) wp_remote_retrieve_header( $response, 'cache-control' ) ),
		'body'  => is_wp_error( $response ) ? '' : (string) wp_remote_retrieve_body( $response ),
	);
};
$leaks = static fn( string $body ): array => array_values( array_filter( $secrets, static fn( string $word ): bool => false !== strpos( $body, $word ) ) );
$boxes = static fn( string $body ): int => substr_count( $body, 'class="favr-m-gate__title"' );
// A members box above the page's main part would be the site header replaced by it.
$above = static function ( string $body ) use ( $boxes ): int {
	$main = strpos( $body, '<main' );
	return $boxes( false === $main ? '' : substr( $body, 0, $main ) );
};
$hidden_site = '0' === (string) get_option( 'blog_public' ); // A site hidden from search engines says noindex everywhere.
$home  = $fetch( get_permalink( $open ) );
if ( 200 !== $home['code'] || false === strpos( $home['body'], $mark( 'publicbody' ) ) ) {
	echo "  (skipped: this site could not be fetched over HTTP from here)\n";
} else {
	$singles = array_filter(
		array(
			'a members-only post'                       => $hidden,
			'a members-only post without an excerpt'    => $auto,
			'a members-only page'                       => $page,
			'a members-only page built in Elementor'    => $built,
		)
	);
	foreach ( $singles as $what => $id ) {
		foreach ( array( 'a visitor', 'a lapsed member' ) as $who ) {
			$got  = $fetch( get_permalink( $id ), $people[ $who ] );
			$seen = $leaks( $got['body'] );
			$check( $what . ', opened by ' . $who . ': nothing hidden anywhere in the page', 200 === $got['code'] && array() === $seen, 'http ' . $got['code'] . ' leaked ' . implode( ', ', $seen ) );
			$check( $what . ', opened by ' . $who . ': one members box, and the site around it is intact', 1 === $boxes( $got['body'] ) && 0 === $above( $got['body'] ) && false !== strpos( $got['body'], '</html>' ), $boxes( $got['body'] ) . ' boxes, ' . $above( $got['body'] ) . ' above the content' );
			$check( $what . ', opened by ' . $who . ': kept out of search engines and out of page caches', false !== strpos( $got['body'], 'noindex' ) && ( false !== strpos( $got['cache'], 'no-cache' ) || false !== strpos( $got['cache'], 'no-store' ) ), 'cache-control: ' . $got['cache'] );
		}
	}
	$got = $fetch( get_permalink( $hidden ), $people['a current member'] );
	$check( 'a member opening the post reads it with no members box', false !== strpos( $got['body'], $mark( 'body' ) ) && 0 === $boxes( $got['body'] ) );
	$check( 'and a member\'s copy is never stored by a page cache', false !== strpos( $got['cache'], 'no-cache' ) || false !== strpos( $got['cache'], 'no-store' ), 'cache-control: ' . $got['cache'] );
	$got = $fetch( get_permalink( $hidden ), $people['an editor'] );
	$check( 'staff opening it read it and are told it is members-only', false !== strpos( $got['body'], $mark( 'body' ) ) && 1 === substr_count( $got['body'], 'class="favr-m favr-m-staffnote"' ), substr_count( $got['body'], 'class="favr-m favr-m-staffnote"' ) . ' notes' );
	$got = $fetch( get_permalink( $open ) );
	$check( 'a public post is served whole, indexable, with no box and no note', false !== strpos( $got['body'], $mark( 'publicbody' ) ) && 0 === $boxes( $got['body'] ) && 0 === substr_count( $got['body'], 'class="favr-m favr-m-staffnote"' ) && ( $hidden_site || ! preg_match( '/<meta name=.robots. content=.[^>]*noindex/', $got['body'] ) ) );

	foreach ( array( 'the category list' => get_category_link( $cat ), 'a search for the title' => home_url( '/?s=Gating+members' ), 'a search for a hidden word' => home_url( '/?s=' . $mark( 'body' ) ) ) as $what => $url ) {
		$got  = $fetch( $url );
		$seen = array_diff( $leaks( $got['body'] ), 'a search for a hidden word' === $what ? array( $mark( 'body' ) ) : array() ); // The search form echoes the word that was typed.
		$check( $what . ', opened by a visitor: nothing hidden', 200 === $got['code'] && array() === $seen, 'http ' . $got['code'] . ' leaked ' . implode( ', ', $seen ) );
		$check( $what . ': the site header is the site\'s own, not a members box', 0 === $above( $got['body'] ), $above( $got['body'] ) . ' boxes above the content' );
	}
	$got = $fetch( home_url( '/?s=' . $mark( 'body' ) ) );
	$check( 'a visitor searching for a hidden word is not shown which post has it', false === strpos( $got['body'], 'Gating members post' ) );

	if ( $grid ) {
		$got = $fetch( get_permalink( $grid ) );
		$check( 'a news list built as an Elementor Loop Grid really lists the posts (the public excerpt is there)', 200 === $got['code'] && false !== strpos( $got['body'], $mark( 'publicexcerpt' ) ), 'http ' . $got['code'] );
		foreach ( array( 'a visitor', 'a lapsed member' ) as $who ) {
			$got  = $fetch( get_permalink( $grid ), $people[ $who ] );
			$seen = $leaks( $got['body'] );
			$check( 'the Loop Grid, opened by ' . $who . ': nothing hidden, not even the hand-written excerpt', array() === $seen, 'leaked ' . implode( ', ', $seen ) );
			$check( 'the Loop Grid, opened by ' . $who . ': the members-only post is still listed by its title, as a card and not a log-in box', false !== strpos( $got['body'], 'Gating members post' ) && 0 === $boxes( $got['body'] ), $boxes( $got['body'] ) . ' boxes' );
		}
		$got = $fetch( get_permalink( $grid ), $people['a current member'] );
		$check( 'a member opening the Loop Grid reads the hand-written excerpt', false !== strpos( $got['body'], $mark( 'excerpt' ) ) );
	}

	foreach ( array( 'the site\'s comments feed' => get_bloginfo( 'comments_rss2_url' ), 'the post\'s own comments feed' => get_post_comments_feed_link( $hidden ), 'the posts feed' => get_bloginfo( 'rss2_url' ), 'the category feed' => get_category_feed_link( $cat ) ) as $what => $url ) {
		$got  = $fetch( $url );
		$seen = $leaks( $got['body'] );
		$check( $what . ', read by anyone: nothing hidden', array() === $seen, 'http ' . $got['code'] . ' leaked ' . implode( ', ', $seen ) );
	}
	if ( $built && defined( 'ELEMENTOR_PRO_VERSION' ) ) {
		$route = rest_url( 'elementor-pro/v1/posts-widget' );
		$got   = $fetch( add_query_arg( array( 'post_id' => $built, 'element_id' => 'gtg0002' ), $route ) );
		$check( 'Elementor Pro\'s render-one-element address refuses a members-only page', array() === $leaks( $got['body'] ) && in_array( $got['code'], array( 401, 403 ), true ), 'http ' . $got['code'] . ' ' . substr( $got['body'], 0, 120 ) );
	}
}

// --- Saving a members-only page in Elementor ---------------------------------------------------
if ( $built ) {
	echo "Editing a members-only page in Elementor\n";
	$is   = static fn(): bool => \FavrMembers\Frontend\Restrict::isMembersOnly( $built );
	$save = static function ( array $settings, bool $autosave = false ) use ( $as, $owner, $built ): void {
		$as(
			$owner,
			static function () use ( $built, $settings, $autosave ): void {
				$document = \Elementor\Plugin::$instance->documents->get( $built, false );
				$elements = $document->get_elements_raw_data();
				$document = $autosave ? $document->get_autosave( 0, true ) : $document;
				$document->save( array( 'elements' => $elements, 'settings' => $settings ) );
			}
		);
	};
	// Elementor's editor leaves out every setting that still has its starting value, and other
	// tools save a page with no settings at all. Saying nothing about the switch is not "off".
	$save( array( 'post_status' => 'publish' ) );
	$check( 'saving a members-only page without touching the switch leaves it members-only', $is() );
	$save( array( 'post_status' => 'publish', 'favr_members_only' => '' ), true );
	$check( 'switching it off without publishing (an autosave) leaves the live page members-only', $is() );
	$save( array( 'post_status' => 'publish', 'favr_members_only' => '' ) );
	$check( 'switching it off and publishing makes the page public', ! $is() );
	$save( array( 'post_status' => 'publish' ) );
	$check( 'and saving again leaves it public', ! $is() );
	$save( array( 'post_status' => 'publish', 'favr_members_only' => '1' ), true );
	$check( 'switching it on without publishing (an autosave) leaves the live page public', ! $is() );
	$save( array( 'post_status' => 'publish', 'favr_members_only' => '1' ) );
	$check( 'switching it on and publishing makes it members-only', $is() );
	$kept = get_post_meta( $built, '_elementor_page_settings', true );
	$check( 'the switch has one home: Elementor keeps no copy of its own that could go stale', ! is_array( $kept ) || ! array_key_exists( 'favr_members_only', $kept ) );
	$control = (array) $as( $owner, static fn() => \Elementor\Plugin::$instance->documents->get( $built, false )->get_controls( 'favr_members_only' ) );
	$check( 'the editor is told to send the switch with every save', '' === ( $control['default'] ?? null ) && ! empty( $control['save_default'] ), wp_json_encode( array_intersect_key( $control, array( 'default' => 1, 'save_default' => 1 ) ) ) );
	$shown = static fn(): string => (string) ( ( (array) apply_filters( 'elementor/document/config', array(), $built ) )['settings']['settings']['favr_members_only'] ?? 'not said' );
	$check( 'when the editor opens, the switch is shown on for a members-only page', '1' === $shown(), $shown() );
	delete_post_meta( $built, ID::PAGE_META_GATED );
	$check( 'switched off somewhere else (the block editor), the editor shows it off next time', '' === $shown(), $shown() );
	// The log-in, account and join pages can never be members-only.
	$settings = get_option( ID::OPTION_SETTINGS );
	update_post_meta( $built, ID::PAGE_META_GATED, true );
	update_option( ID::OPTION_SETTINGS, array_merge( is_array( $settings ) ? $settings : array(), array( 'account_page' => $built ) ) );
	\FavrMembers\Support\Settings::flush();
	$check( 'the account page stays open even when someone switches it to members only', ! $is() && '' === $shown() );
	$seen = (string) $as( $people['a lapsed member'], static fn(): string => (string) \Elementor\Plugin::$instance->frontend->get_builder_content( $built ) );
	$check( 'so a lapsed member can reach their account to renew', false !== strpos( $seen, $mark( 'built' ) ) );
	false === $settings ? delete_option( ID::OPTION_SETTINGS ) : update_option( ID::OPTION_SETTINGS, $settings );
	\FavrMembers\Support\Settings::flush();
	$check( 'and any other page with the switch on is members-only', $is() );
	if ( class_exists( '\FavrPayments\Support\Settings' ) ) {
		// Applicants and lapsed members pay on Favr Payments' pay page before they are members.
		$pay  = \FavrPayments\Support\Settings::get( 'pay_page' );
		$kept = get_option( 'favr_pay_settings' );
		\FavrPayments\Support\Settings::update( array( 'pay_page' => $built ) );
		$check( 'Favr Payments\' pay page stays open even when someone switches it to members only', ! $is() );
		\FavrPayments\Support\Settings::update( array( 'pay_page' => $pay ) );
		$check( '(and the page is members-only again once it is no longer the pay page)', $is() );
	}
}

// --- Clean up ----------------------------------------------------------------------------------
foreach ( array_reverse( $made['posts'] ) as $id ) {
	wp_delete_post( $id, true );
}
foreach ( $made['terms'] as $id ) {
	wp_delete_term( $id, 'category' );
}
require_once ABSPATH . 'wp-admin/includes/user.php';
foreach ( $made['users'] as $id ) {
	wp_delete_user( $id );
}
list( $pass, $fail ) = $favr_gate;
echo "\n" . ( $fail ? "FAILED: {$fail} of " . ( $pass + $fail ) : "PASSED: {$pass}" ) . "\n";
