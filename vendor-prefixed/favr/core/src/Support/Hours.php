<?php
/**
 * Pure logic over a weekly opening-hours schedule.
 *
 * @package FavrCore
 */

declare(strict_types=1);

namespace FavrMembers\Vendor\FavrCore\Support;

/**
 * Works on the canonical shape produced by Sanitizer::hours():
 * day(mon..sun) => { status: open|closed|24h, open: HH:MM, close: HH:MM }.
 * A range whose close is at or before its open runs past midnight (e.g. 18:00–02:00).
 */
final class Hours {

	public const DAYS = array( 'mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun' );

	/**
	 * Translated day names in display order.
	 *
	 * @return array<string, string>
	 */
	public static function dayLabels(): array {
		return array(
			'mon' => __( 'Monday', 'favr-core' ),
			'tue' => __( 'Tuesday', 'favr-core' ),
			'wed' => __( 'Wednesday', 'favr-core' ),
			'thu' => __( 'Thursday', 'favr-core' ),
			'fri' => __( 'Friday', 'favr-core' ),
			'sat' => __( 'Saturday', 'favr-core' ),
			'sun' => __( 'Sunday', 'favr-core' ),
		);
	}

	/**
	 * Whether the business is open at a moment. Null when no schedule is set.
	 *
	 * @param array<string, array<string, string>> $hours Schedule.
	 * @param \DateTimeImmutable                   $now   Moment, in the site timezone.
	 */
	public static function isOpenAt( array $hours, \DateTimeImmutable $now ): ?bool {
		if ( array() === $hours ) {
			return null;
		}

		$today    = self::dayKey( $now );
		$previous = self::dayKey( $now->modify( '-1 day' ) );
		$minutes  = (int) $now->format( 'G' ) * 60 + (int) $now->format( 'i' );

		$row = $hours[ $today ] ?? null;
		if ( is_array( $row ) ) {
			if ( '24h' === ( $row['status'] ?? '' ) ) {
				return true;
			}
			if ( 'open' === ( $row['status'] ?? '' ) ) {
				$open  = self::toMinutes( $row['open'] );
				$close = self::toMinutes( $row['close'] );
				if ( $close > $open ) {
					if ( $minutes >= $open && $minutes < $close ) {
						return true;
					}
				} elseif ( $minutes >= $open ) {
					// Overnight range that started today.
					return true;
				}
			}
		}

		// Overnight range that started yesterday and is still running.
		$prev = $hours[ $previous ] ?? null;
		if ( is_array( $prev ) && 'open' === ( $prev['status'] ?? '' ) ) {
			$open  = self::toMinutes( $prev['open'] );
			$close = self::toMinutes( $prev['close'] );
			if ( $close <= $open && $minutes < $close ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Group consecutive days that share identical hours, for compact display
	 * (e.g. "Mon – Fri 9:00 AM – 5:00 PM").
	 *
	 * @param array<string, array<string, string>> $hours Schedule.
	 * @return list<array{days: list<string>, status: string, open: string, close: string}>
	 */
	public static function grouped( array $hours ): array {
		$groups = array();
		foreach ( self::DAYS as $day ) {
			$row  = $hours[ $day ] ?? array(
				'status' => 'closed',
				'open'   => '',
				'close'  => '',
			);
			$key  = $row['status'] . '|' . $row['open'] . '|' . $row['close'];
			$last = count( $groups ) - 1;
			if ( $last >= 0 && $groups[ $last ]['key'] === $key ) {
				$groups[ $last ]['days'][] = $day;
				continue;
			}
			$groups[] = array(
				'key'    => $key,
				'days'   => array( $day ),
				'status' => (string) $row['status'],
				'open'   => (string) $row['open'],
				'close'  => (string) $row['close'],
			);
		}
		return array_map(
			static function ( array $group ): array {
				unset( $group['key'] );
				return $group;
			},
			$groups
		);
	}

	/**
	 * Format "HH:MM" with a PHP date format (e.g. the site's time_format).
	 *
	 * @param string $time   24h time.
	 * @param string $format PHP date format.
	 */
	public static function formatTime( string $time, string $format ): string {
		$date = \DateTimeImmutable::createFromFormat( 'H:i', $time );
		return false === $date ? $time : $date->format( $format );
	}

	/**
	 * Day key for a date.
	 *
	 * @param \DateTimeImmutable $date Date.
	 */
	public static function dayKey( \DateTimeImmutable $date ): string {
		return strtolower( $date->format( 'D' ) );
	}

	/**
	 * Minutes past midnight for HH:MM.
	 *
	 * @param string $time Time.
	 */
	private static function toMinutes( string $time ): int {
		$parts = explode( ':', $time );
		return (int) ( $parts[0] ?? 0 ) * 60 + (int) ( $parts[1] ?? 0 );
	}
}
