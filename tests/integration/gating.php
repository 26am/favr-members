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
	'a contributor'        => $person( 'gating_contributor', 'contributor' ),
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
// Members-only posts written by people who are not members themselves: an Author and a Contributor.
$mine  = $post( array( 'post_title' => 'Gating members post by the author', 'post_author' => $people['an author of other posts'], 'post_content' => '<!-- wp:paragraph --><p>Hidden body ' . $mark( 'minebody' ) . '.</p><!-- /wp:paragraph -->' ), true );
$theirs = $post( array( 'post_title' => 'Gating members post by the contributor', 'post_author' => $people['a contributor'], 'post_content' => '<!-- wp:paragraph --><p>Hidden body ' . $mark( 'theirsbody' ) . '.</p><!-- /wp:paragraph -->' ), true );
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
// The same Loop Grid on a public page, inside a container shown to current members only.
$ruled = 0;
if ( $grid ) {
	$ruled = $post( array( 'post_type' => 'page', 'post_title' => 'Gating news list for members inside a public page', 'post_content' => '' ), false );
	update_post_meta( $ruled, '_elementor_edit_mode', 'builder' );
	$as(
		$owner,
		static function () use ( $ruled, $card, $cat ): void {
			\Elementor\Plugin::$instance->documents->get( $ruled, false )->save(
				array(
					'elements' => array(
						array(
							'id'       => 'gtg3001',
							'elType'   => 'container',
							'settings' => array( 'favr_members_visibility' => 'members' ),
							'isInner'  => false,
							'elements' => array(
								array(
									'id'         => 'gtg3002',
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
// Excluding a word ("-word") must be decided by the title as well, or it says what the hidden text holds.
$check( 'excluding a word that is only in the hidden text does not drop the post', in_array( $hidden, $as( $visitor, static fn(): array => $found( 'Gating members -' . $mark( 'body' ) ) ), true ) );
$check( 'nor does excluding a word from the hidden excerpt, or a phrase', in_array( $hidden, $as( $visitor, static fn(): array => $found( 'Gating members -' . $mark( 'excerpt' ) ) ), true ) && in_array( $hidden, $as( $lapsed, static fn(): array => $found( 'Gating members "-Hidden body"' ) ), true ) );
$check( 'excluding a word of the title does drop it', ! in_array( $hidden, $as( $visitor, static fn(): array => $found( 'Gating -members' ) ), true ) );
$public = static fn( string $words ): bool => in_array( $open, array_map( 'intval', (array) $as( $visitor, static fn(): array => ( new WP_Query( array( 's' => $words, 'post_type' => 'post', 'fields' => 'ids' ) ) )->posts ) ), true );
$check( 'excluding words still works on public posts, by their text', $public( 'Gating public' ) && ! $public( 'Gating public -' . $mark( 'publicbody' ) ) && $public( 'Gating public -zqnothere' ) );
$own = static fn( string $who, int $id, string $word ): bool => in_array( $id, array_map( 'intval', (array) $as( $people[ $who ], static fn(): array => ( new WP_Query( array( 's' => $word, 'post_type' => 'post', 'fields' => 'ids' ) ) )->posts ) ), true );
$check( 'the author of a members-only post finds it by its text', $own( 'an author of other posts', $mine, $mark( 'minebody' ) ) );
$check( 'but not other people\'s, and a visitor finds neither', ! $own( 'an author of other posts', $hidden, $mark( 'body' ) ) && ! $own( 'a visitor', $mine, $mark( 'minebody' ) ) );
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
// Yoast SEO and Rank Math build sitemaps of their own and ask about each entry.
foreach ( array( 'wpseo_sitemap_entry' => 'Yoast SEO', 'rank_math/sitemap/entry' => 'Rank Math' ) as $hook => $plugin ) {
	$entry = array( 'loc' => 'https://example.test/x/' );
	$check( $plugin . '\'s sitemap leaves members-only posts out too', false === apply_filters( $hook, $entry, 'post', get_post( $hidden ) ) && $entry === apply_filters( $hook, $entry, 'post', get_post( $open ) ) && $entry === apply_filters( $hook, $entry, 'term', get_term( $cat ) ) );
}
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
$reads = static fn( string $who, int $id, string $word ): bool => false !== strpos( (string) $as( $people[ $who ], static fn(): string => $loop( array( 'p' => $id ), static function (): void { the_content(); } ) ), $word );
$check( 'the person who wrote a members-only post can read it, member or not (Author)', $reads( 'an author of other posts', $mine, $mark( 'minebody' ) ) );
$check( 'the same for a Contributor, who can no longer edit it once it is published', $reads( 'a contributor', $theirs, $mark( 'theirsbody' ) ) );
$check( 'neither can read the other\'s, and a visitor reads neither', ! $reads( 'a contributor', $mine, $mark( 'minebody' ) ) && ! $reads( 'an author of other posts', $theirs, $mark( 'theirsbody' ) ) && ! $reads( 'a visitor', $mine, $mark( 'minebody' ) ) );
// An older saved version of a page is as members-only as the page.
if ( $built ) {
	$as(
		$owner,
		static function () use ( $built ): void {
			$document = \Elementor\Plugin::$instance->documents->get( $built, false );
			$document->save( array( 'elements' => $document->get_elements_raw_data(), 'settings' => array( 'post_status' => 'publish' ) ) );
		}
	);
	$old = array_keys( wp_get_post_revisions( $built ) );
	$out = '';
	foreach ( $old as $revision ) {
		$out .= (string) $as( $visitor, static fn(): string => do_shortcode( '[elementor-template id="' . $revision . '"]' ) . (string) \Elementor\Plugin::$instance->frontend->get_builder_content( $revision ) );
	}
	$check( 'older saved versions of a members-only page are members-only too (' . count( $old ) . ' checked)', array() !== $old && false === strpos( $out, $mark( 'built' ) ) && \FavrMembers\Frontend\Restrict::isMembersOnly( (int) $old[0] ) );
}
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
$copy->post_excerpt = 'This content is for members.';
$copy->{\FavrMembers\Frontend\Restrict::WITHHELD} = true;
wp_update_post( $copy );
clean_post_cache( $hidden );
$check( 'a withheld copy saved whole by mistake does not overwrite the real text', false !== strpos( (string) get_post_field( 'post_content', $hidden, 'raw' ), $mark( 'body' ) ) && false !== strpos( (string) get_post_field( 'post_excerpt', $hidden, 'raw' ), $mark( 'excerpt' ) ) );
wp_update_post( array( 'ID' => $hidden, 'post_content' => $copy->post_content, 'post_excerpt' => $copy->post_excerpt ) );
clean_post_cache( $hidden );
$check( 'nor does saving only the text taken from such a copy', false !== strpos( (string) get_post_field( 'post_content', $hidden, 'raw' ), $mark( 'body' ) ) && false !== strpos( (string) get_post_field( 'post_excerpt', $hidden, 'raw' ), $mark( 'excerpt' ) ) );
$copy               = clone get_post( $theirs );
$copy->post_content = 'Rewritten on purpose ' . $mark( 'rewritten' );
$copy->{\FavrMembers\Frontend\Restrict::WITHHELD} = true;
wp_update_post( $copy );
clean_post_cache( $theirs );
$check( 'text written on purpose is saved, even on a copy', false !== strpos( (string) get_post_field( 'post_content', $theirs, 'raw' ), $mark( 'rewritten' ) ) );

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
	if ( $grid ) {
		$got = $fetch( get_permalink( $grid ) );
		$check( 'on a Loop Grid card a members-only post says it is for members where its excerpt would be', false !== strpos( $got['body'], 'This content is for members.' ) );
		// Elementor Pro also re-renders a Loop Grid on request. On a public page that must still work;
		// on a members-only page it must not, however the address is spelled.
		$post_json = static function ( string $url, array $body ): array {
			$response = wp_remote_post( $url, array( 'timeout' => 45, 'headers' => array( 'Content-Type' => 'application/json' ), 'body' => wp_json_encode( $body ) ) );
			return array( 'code' => is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response ), 'body' => is_wp_error( $response ) ? '' : (string) wp_remote_retrieve_body( $response ) );
		};
		$got = $post_json( rest_url( 'elementor-pro/v1/refresh-loop' ), array( 'post_id' => $grid, 'widget_id' => 'gtg2002', 'widget_filters' => (object) array() ) );
		$check( 'a public Loop Grid can still be re-rendered on request, with nothing hidden in it', 200 === $got['code'] && false !== strpos( $got['body'], 'Gating' ) && array() === $leaks( $got['body'] ), 'http ' . $got['code'] . ' ' . substr( $got['body'], 0, 160 ) );
		update_post_meta( $grid, ID::PAGE_META_GATED, true );
		$got = $post_json( rest_url( 'elementor-pro/v1/refresh-loop' ), array( 'post_id' => $grid, 'widget_id' => 'gtg2002', 'widget_filters' => (object) array() ) );
		$check( 'the same Loop Grid on a members-only page is refused', in_array( $got['code'], array( 401, 403 ), true ) && false === strpos( $got['body'], 'Gating members post' ), 'http ' . $got['code'] . ' ' . substr( $got['body'], 0, 160 ) );
		$got = $post_json( rest_url( 'ELEMENTOR-PRO/V1/Refresh-Loop' ), array( 'post_id' => $grid, 'widget_id' => 'gtg2002', 'widget_filters' => (object) array() ) );
		$check( 'and refused when the address is typed in capitals', in_array( $got['code'], array( 401, 403 ), true ) && false === strpos( $got['body'], 'Gating members post' ), 'http ' . $got['code'] . ' ' . substr( $got['body'], 0, 160 ) );
		delete_post_meta( $grid, ID::PAGE_META_GATED );
		$loop_route = rest_url( 'elementor-pro/v1/refresh-loop' );
		$got = $post_json( $loop_route, array( 'post_id' => $ruled, 'widget_id' => 'gtg3002', 'widget_filters' => (object) array() ) );
		$check( 'a Loop Grid inside a members-only section of a public page is not re-rendered for a visitor', in_array( $got['code'], array( 401, 403 ), true ) && false === strpos( $got['body'], 'Gating' ), 'http ' . $got['code'] . ' ' . substr( $got['body'], 0, 120 ) );
		$got = $post_json( $loop_route, array( 'post_id' => $ruled, 'widget_id' => 'gtg3002', 'element_id' => 'zzzzzzz', 'widget_filters' => (object) array() ) );
		$check( 'nor when the request names a second, made-up element to be checked instead', in_array( $got['code'], array( 401, 403 ), true ) && false === strpos( $got['body'], 'Gating' ), 'http ' . $got['code'] . ' ' . substr( $got['body'], 0, 120 ) );
		$got = $post_json( $loop_route, array( 'post_id' => $grid, 'widget_id' => 'gtg2002', 'element_id' => 'zzzzzzz', 'widget_filters' => (object) array() ) );
		$check( 'while an ordinary public Loop Grid is still re-rendered whatever else the request names', 200 === $got['code'] && false !== strpos( $got['body'], 'Gating' ), 'http ' . $got['code'] );
	}
	if ( $built && defined( 'ELEMENTOR_PRO_VERSION' ) ) {
		foreach ( array( '-' . $built, '-' . $built . '.0', $built . 'abc' ) as $spelled ) {
			$got = $fetch( add_query_arg( array( 'post_id' => rawurlencode( $spelled ), 'element_id' => 'gtg0002' ), rest_url( 'elementor-pro/v1/posts-widget' ) ) );
			$check( 'Elementor Pro\'s render-one-element address refuses a members-only page however its number is written (' . $spelled . ')', array() === $leaks( $got['body'] ) && 200 !== $got['code'], 'http ' . $got['code'] . ' ' . substr( $got['body'], 0, 120 ) );
		}
		foreach ( array( 'elementor-pro/v1/Posts-Widget', 'ELEMENTOR-PRO/V1/posts-widget' ) as $spelling ) {
			$got = $fetch( add_query_arg( array( 'post_id' => $built, 'element_id' => 'gtg0002' ), rest_url( $spelling ) ) );
			$check( 'Elementor Pro\'s render-one-element address refuses a members-only page however it is spelled (' . $spelling . ')', array() === $leaks( $got['body'] ) && in_array( $got['code'], array( 401, 403 ), true ), 'http ' . $got['code'] . ' ' . substr( $got['body'], 0, 120 ) );
		}
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
	// The real editor sends the switch with every save, together with how it stood when the page
	// was opened and a token for the editing session.
	$opened = static fn(): string => (string) ( ( (array) apply_filters( 'elementor/document/config', array(), $built ) )['settings']['settings']['favr_members_only_was'] ?? 'not said' );
	$was    = $opened();
	$check( 'the editor is told how the switch stands when it opens', 0 === strpos( $was, '1|' ) && strlen( $was ) > 8, $was );
	$save( array( 'post_status' => 'publish', 'favr_members_only' => '', 'favr_members_only_was' => '' ) );
	$check( 'looking at a revision resets the switch in the editor: publishing after that leaves a members-only page members-only', $is() );
	delete_post_meta( $built, ID::PAGE_META_GATED );
	$save( array( 'post_status' => 'publish', 'favr_members_only' => '1', 'favr_members_only_was' => $was ) );
	$check( 'a tab left open while the page was made public elsewhere does not switch it back on', ! $is() );
	update_post_meta( $built, ID::PAGE_META_GATED, true );
	$was = $opened();
	$save( array( 'post_status' => 'publish', 'favr_members_only' => '', 'favr_members_only_was' => $was ) );
	$check( 'switching it off in the editor and publishing makes it public', ! $is() );
	$save( array( 'post_status' => 'publish', 'favr_members_only' => '', 'favr_members_only_was' => $was ) );
	$check( 'publishing again in the same session leaves it public', ! $is() );
	$save( array( 'post_status' => 'publish', 'favr_members_only' => '1', 'favr_members_only_was' => $was ) );
	$check( 'switching it back on in the same session and publishing makes it members-only again', $is() );
	$save( array( 'post_status' => 'publish', 'favr_members_only' => '', 'favr_members_only_was' => $was ) );
	$check( 'and off again', ! $is() );
	// An unpublished change waits in the person's autosave, like their other unpublished changes.
	$was = $opened();
	// Elementor goes back to a person's autosave when it is newer than the page.
	$GLOBALS['wpdb']->update( $GLOBALS['wpdb']->posts, array( 'post_modified' => gmdate( 'Y-m-d H:i:s', time() - 300 ), 'post_modified_gmt' => gmdate( 'Y-m-d H:i:s', time() - 300 ) ), array( 'ID' => $built ) ); // phpcs:ignore WordPress.DB
	clean_post_cache( $built );
	$save( array( 'post_status' => 'autosave', 'favr_members_only' => '1', 'favr_members_only_was' => $was ), true );
	$pending = (array) $as(
		$owner,
		static function () use ( $built ): array {
			$autosave = \Elementor\Plugin::$instance->documents->get( $built, false )->get_autosave();
			do_action( 'elementor/document/before_get_config', $autosave );
			return (array) ( ( (array) apply_filters( 'elementor/document/config', array(), $built ) )['settings']['settings'] ?? array() );
		}
	);
	$check( 'a switch turned on but not published yet is still on when the editor comes back to the page (Site Settings, reopening)', ! $is() && '1' === ( $pending['favr_members_only'] ?? null ) && 0 === strpos( (string) ( $pending['favr_members_only_was'] ?? 'x' ), '|' ), wp_json_encode( $pending ) );
	$save( array( 'post_status' => 'publish', 'favr_members_only' => '1', 'favr_members_only_was' => (string) ( $pending['favr_members_only_was'] ?? '' ) ) );
	$check( 'and publishing then makes the page members-only', $is() );
	$kept = get_post_meta( $built, '_elementor_page_settings', true );
	// What the editor is told when the person comes back to the page (their autosave, if newer).
	$comes_back = static function () use ( $as, $owner, $built ): array {
		return (array) $as(
			$owner,
			static function () use ( $built ): array {
				$document = \Elementor\Plugin::$instance->documents->get( $built, false );
				$autosave = $document->get_newer_autosave();
				do_action( 'elementor/document/before_get_config', $autosave ? $autosave : $document );
				return (array) ( ( (array) apply_filters( 'elementor/document/config', array(), $built ) )['settings']['settings'] ?? array() );
			}
		);
	};
	$older = static function () use ( $built ): void {
		$GLOBALS['wpdb']->update( $GLOBALS['wpdb']->posts, array( 'post_modified' => gmdate( 'Y-m-d H:i:s', time() - 300 ), 'post_modified_gmt' => gmdate( 'Y-m-d H:i:s', time() - 300 ) ), array( 'ID' => $built ) ); // phpcs:ignore WordPress.DB
		clean_post_cache( $built );
	};
	// Elementor autosaves by itself after a revision is applied, and a minute after any edit.
	$older();
	$save( array( 'post_status' => 'autosave', 'favr_members_only' => '', 'favr_members_only_was' => '' ), true );
	$back = $comes_back();
	$check( 'a switch that Elementor reset (a revision was shown) and then autosaved is not taken for the person\'s change: back in the editor it shows on', $is() && '1' === ( $back['favr_members_only'] ?? null ), wp_json_encode( $back ) );
	$save( array( 'post_status' => 'publish', 'favr_members_only' => (string) ( $back['favr_members_only'] ?? '' ), 'favr_members_only_was' => (string) ( $back['favr_members_only_was'] ?? '' ) ) );
	$check( 'and publishing from there leaves the page members-only', $is() );
	delete_post_meta( $built, ID::PAGE_META_GATED );
	$stale = $opened();
	update_post_meta( $built, ID::PAGE_META_GATED, true );
	$older();
	$save( array( 'post_status' => 'autosave', 'favr_members_only' => '', 'favr_members_only_was' => $stale ), true );
	$back = $comes_back();
	$check( 'nor is the switch of a tab that was open before the page was made members-only elsewhere, once that tab autosaves', $is() && '1' === ( $back['favr_members_only'] ?? null ), wp_json_encode( $back ) );
	$save( array( 'post_status' => 'publish', 'favr_members_only' => (string) ( $back['favr_members_only'] ?? '' ), 'favr_members_only_was' => (string) ( $back['favr_members_only_was'] ?? '' ) ) );
	$check( 'and publishing from there leaves the page members-only too', $is() );
	// What a session last did must outlive a cleared cache: it is kept with the page.
	$was = $opened();
	$save( array( 'post_status' => 'publish', 'favr_members_only' => '', 'favr_members_only_was' => $was ) );
	$GLOBALS['wpdb']->query( "DELETE FROM {$GLOBALS['wpdb']->options} WHERE option_name LIKE '\_transient%favr\_members\_switch%'" ); // phpcs:ignore WordPress.DB
	wp_cache_flush();
	$save( array( 'post_status' => 'publish', 'favr_members_only' => '1', 'favr_members_only_was' => $was ) );
	$check( 'off then on in one session works even after the site\'s caches and transients were cleared in between', $is() );
	// Publishing a new page with the switch on: anything that reacts to "published" must already find it members-only.
	$fresh = $post( array( 'post_type' => 'page', 'post_status' => 'draft', 'post_title' => 'Gating page published with the switch on', 'post_content' => '' ), false );
	update_post_meta( $fresh, '_elementor_edit_mode', 'builder' );
	$seen_at_publish = null;
	$watch           = static function ( $new, $old, $changed ) use ( $fresh, &$seen_at_publish ): void {
		if ( 'publish' === $new && $changed instanceof WP_Post && (int) $changed->ID === $fresh && null === $seen_at_publish ) {
			$seen_at_publish = \FavrMembers\Frontend\Restrict::isMembersOnly( $fresh );
		}
	};
	add_action( 'transition_post_status', $watch, 1, 3 );
	$as(
		$owner,
		static function () use ( $fresh ): void {
			$was = (string) ( ( (array) apply_filters( 'elementor/document/config', array(), $fresh ) )['settings']['settings']['favr_members_only_was'] ?? '' );
			\Elementor\Plugin::$instance->documents->get( $fresh, false )->save(
				array(
					'elements' => array( array( 'id' => 'gtg4001', 'elType' => 'container', 'settings' => array(), 'isInner' => false, 'elements' => array() ) ),
					'settings' => array( 'post_status' => 'publish', 'favr_members_only' => '1', 'favr_members_only_was' => $was ),
				)
			);
		}
	);
	remove_action( 'transition_post_status', $watch, 1 );
	$check( 'a page first published with the switch on is already members-only at the moment WordPress announces it as published', true === $seen_at_publish && \FavrMembers\Frontend\Restrict::isMembersOnly( $fresh ) && 'publish' === get_post_status( $fresh ), wp_json_encode( $seen_at_publish ) );
	$check( 'the page itself never stores the switch or how it stood', ! is_array( $kept ) || ( ! array_key_exists( 'favr_members_only', $kept ) && ! array_key_exists( 'favr_members_only_was', $kept ) ), wp_json_encode( $kept ) );
	// "Save as template" copies the page's text into Elementor's template library.
	$template = static function ( int $from ) use ( $as, $owner, &$made ): int {
		$saved = $as(
			$owner,
			static function () use ( $from ) {
				$elements = \Elementor\Plugin::$instance->documents->get( $from, false )->get_elements_raw_data();
				return \Elementor\Plugin::$instance->templates_manager->save_template( array( 'post_id' => $from, 'source' => 'local', 'content' => wp_json_encode( $elements ), 'type' => 'page', 'title' => 'Gating saved template ' . $from ) );
			}
		);
		$id = is_array( $saved ) ? (int) ( $saved['template_id'] ?? 0 ) : 0;
		if ( $id ) {
			$made['posts'][] = $id;
		}
		return $id;
	};
	$only           = static fn( int $id ): bool => \FavrMembers\Frontend\Restrict::isMembersOnly( $id );
	$copy_of_hidden = $template( $built );
	$check( 'a template saved from a members-only page is members-only too', $copy_of_hidden > 0 && $only( $copy_of_hidden ), 'template ' . $copy_of_hidden );
	if ( $grid ) {
		$copy_of_public = $template( $grid );
		$check( 'a template saved from a public page is not', $copy_of_public > 0 && ! $only( $copy_of_public ) );
		update_post_meta( $grid, ID::PAGE_META_GATED, true );
		$check( 'until that page is made members-only: its copies follow it', $only( $copy_of_public ) );
		delete_post_meta( $grid, ID::PAGE_META_GATED );
		$check( 'and stop being members-only when the page does', ! $only( $copy_of_public ) );
	}
	// A page that is deleted while members-only leaves its copies members-only.
	$doomed = $post( array( 'post_type' => 'page', 'post_title' => 'Gating members page to be deleted', 'post_content' => '' ), true );
	update_post_meta( $doomed, '_elementor_edit_mode', 'builder' );
	$as(
		$owner,
		static function () use ( $doomed, $mark ): void {
			\Elementor\Plugin::$instance->documents->get( $doomed, false )->save( array( 'elements' => array( array( 'id' => 'gtg5001', 'elType' => 'container', 'settings' => array(), 'isInner' => false, 'elements' => array( array( 'id' => 'gtg5002', 'elType' => 'widget', 'widgetType' => 'text-editor', 'settings' => array( 'editor' => '<p>Doomed ' . $mark( 'doomed' ) . '.</p>' ), 'elements' => array() ) ) ) ), 'settings' => array() ) );
		}
	);
	$orphan = $template( $doomed );
	wp_delete_post( $doomed, true );
	$check( 'a template whose members-only page has been deleted stays members-only', $orphan > 0 && $only( $orphan ) );
	// Elementor lets anyone who can write posts export any saved template as a file.
	$died = '';
	$dies = static fn() => static function ( $message ): void {
		throw new \RuntimeException( is_scalar( $message ) ? (string) $message : 'died' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
	};
	add_filter( 'wp_die_handler', $dies );
	add_filter( 'wp_die_ajax_handler', $dies );
	$kept_request = $_REQUEST; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$_REQUEST     = array( 'action' => 'elementor_library_direct_actions', 'library_action' => 'export_template', 'source' => 'local', 'template_id' => (string) $copy_of_hidden ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$as(
		$people['an author of other posts'],
		static function () use ( &$died ): void {
			try {
				( new \FavrMembers\Integration\Elementor() )->guardTemplateExport();
			} catch ( \RuntimeException $e ) {
				$died = $e->getMessage();
			}
		}
	);
	$check( 'an Author who is not a member cannot export a members-only template to read it', '' !== $died, $died );
	$died = '';
	$as(
		$owner,
		static function () use ( &$died ): void {
			try {
				( new \FavrMembers\Integration\Elementor() )->guardTemplateExport();
			} catch ( \RuntimeException $e ) {
				$died = $e->getMessage();
			}
		}
	);
	$check( 'staff who may read it can', '' === $died, $died );
	$_REQUEST = $kept_request; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	remove_filter( 'wp_die_handler', $dies );
	remove_filter( 'wp_die_ajax_handler', $dies );
	$library = wp_remote_get( add_query_arg( array( 'feed' => 'rss2', 'post_type' => 'elementor_library' ), home_url( '/' ) ), array( 'timeout' => 45 ) );
	$library = is_wp_error( $library ) ? '' : (string) wp_remote_retrieve_body( $library );
	$check( 'so the template library\'s feed does not hand out the hidden text', false !== strpos( $library, 'Gating saved template' ) && false === strpos( $library, $mark( 'built' ) ), false === strpos( $library, 'Gating saved template' ) ? 'the feed does not list the template at all' : 'leaked' );
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
