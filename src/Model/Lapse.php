<?php
/**
 * Automatic lapsing after the renewal date.
 *
 * @package FavrMembers
 */

declare(strict_types=1);

namespace FavrMembers\Model;

use FavrMembers\Schema\Identifiers as ID;
use FavrMembers\Support\Settings;

/**
 * Optional (Settings → "Mark members lapsed automatically"): once a day, active members whose
 * renewal date plus the grace period has passed become "lapsed". Staff renew by setting a new
 * renewal date and status back to Active.
 */
final class Lapse {

	/** Hook. */
	public function hook(): void {
		add_action( ID::CRON_LAPSE, array( $this, 'run' ) );
	}

	/**
	 * Pure rule.
	 *
	 * @param string $status  Current status.
	 * @param string $renewal Renewal date (Y-m-d or '').
	 * @param string $today   Today (Y-m-d).
	 * @param int    $grace   Grace days.
	 */
	public static function shouldLapse( string $status, string $renewal, string $today, int $grace ): bool {
		if ( ID::STATUS_ACTIVE !== $status || '' === $renewal ) {
			return false;
		}
		$deadline = \DateTimeImmutable::createFromFormat( '!Y-m-d', $renewal );
		if ( false === $deadline ) {
			return false;
		}
		return $deadline->modify( '+' . max( 0, $grace ) . ' days' )->format( 'Y-m-d' ) < $today;
	}

	/** Daily run. Returns how many members lapsed. */
	public function run(): int {
		if ( '1' !== Settings::get( 'auto_lapse' ) ) {
			return 0;
		}
		$today = ( new \DateTimeImmutable( 'now', wp_timezone() ) )->format( 'Y-m-d' );
		$grace = (int) Settings::get( 'lapse_grace' );
		$ids   = get_posts(
			array(
				'post_type'      => ID::POST_TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- daily cron.
					array(
						'key'   => ID::meta( 'status' ),
						'value' => ID::STATUS_ACTIVE,
					),
					array(
						'key'     => ID::meta( 'renewal_date' ),
						'value'   => $today,
						'compare' => '<',
						'type'    => 'DATE',
					),
				),
			)
		);
		$count = 0;
		foreach ( $ids as $id ) {
			$member = Member::find( (int) $id );
			if ( $member && self::shouldLapse( $member->status(), $member->text( 'renewal_date' ), $today, $grace ) ) {
				update_post_meta( (int) $id, ID::meta( 'status' ), ID::STATUS_LAPSED );
				/** This action is documented in src/Admin/EditScreen.php */
				do_action( 'favr_members_member_saved', $member, 'lapsed' );
				++$count;
			}
		}
		wp_cache_delete( 'status_counts', 'favr_members' );
		return $count;
	}
}
