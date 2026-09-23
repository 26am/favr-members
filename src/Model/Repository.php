<?php
/**
 * Member lookups.
 *
 * @package FavrMembers
 */

declare(strict_types=1);

namespace FavrMembers\Model;

use FavrMembers\Schema\Identifiers as ID;

/**
 * Answers "which memberships does this person hold or represent?".
 */
final class Repository {

	/**
	 * Memberships a user holds (individual) or represents (business).
	 *
	 * @param int $user_id User id.
	 * @return list<Member>
	 */
	public static function forUser( int $user_id ): array {
		if ( $user_id <= 0 ) {
			return array();
		}
		$posts = get_posts(
			array(
				'post_type'        => ID::POST_TYPE,
				'post_status'      => array( 'publish', 'private' ),
				'posts_per_page'   => 50,
				'meta_key'         => ID::META_USER, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- indexed, small.
				'meta_value'       => (string) $user_id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'orderby'          => 'title',
				'order'            => 'ASC',
				'suppress_filters' => true,
			)
		);
		return array_map( static fn( \WP_Post $p ): Member => new Member( $p ), $posts );
	}

	/**
	 * Whether a user holds or represents at least one active membership.
	 *
	 * @param int $user_id User id.
	 */
	public static function isActive( int $user_id ): bool {
		return self::anyActive( array_map( static fn( Member $m ): bool => $m->isActive(), self::forUser( $user_id ) ) );
	}

	/**
	 * Pure: true when any flag is true.
	 *
	 * @param list<bool> $flags Active flags.
	 */
	public static function anyActive( array $flags ): bool {
		return in_array( true, $flags, true );
	}

	/**
	 * Member linked to a directory listing.
	 *
	 * @param int $listing_id Business post id.
	 */
	public static function forListing( int $listing_id ): ?Member {
		$id = (int) get_post_meta( $listing_id, ID::BUSINESS_LINK, true );
		return $id ? Member::find( $id ) : null;
	}

	/**
	 * Counts by status (for list views and the menu badge).
	 *
	 * @return array<string, int>
	 */
	public static function statusCounts(): array {
		global $wpdb;
		$cached = wp_cache_get( 'status_counts', 'favr_members' );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- cached aggregate.
		$rows   = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT pm.meta_value AS status, COUNT(*) AS total FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = %s
				WHERE p.post_type = %s AND p.post_status = 'publish' GROUP BY pm.meta_value",
				ID::meta( 'status' ),
				ID::POST_TYPE
			)
		);
		$counts = array_fill_keys( array_keys( ID::statuses() ), 0 );
		foreach ( (array) $rows as $row ) {
			if ( isset( $counts[ $row->status ] ) ) {
				$counts[ $row->status ] = (int) $row->total;
			}
		}
		wp_cache_set( 'status_counts', $counts, 'favr_members', 300 );
		return $counts;
	}
}
