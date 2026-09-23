<?php
/**
 * Proposed changes awaiting staff review, plus a change log.
 *
 * @package FavrCore
 */

declare(strict_types=1);

namespace FavrMembers\Vendor\FavrCore\Moderation;

/**
 * Stores proposals on the post itself (the live content is untouched until approved):
 *   _favr_pending_changes = field => { old, new, user, time }   (latest proposal per field wins)
 *   _favr_change_log      = list of { action, fields, user, time, note } (last 100)
 * Applying a change is the owning plugin's job (it knows whether a field is meta, the title,
 * terms…); this class only stores, lists, takes and logs.
 */
final class PendingChanges {

	public const META = '_favr_pending_changes';
	public const LOG  = '_favr_change_log';

	/**
	 * Pending proposals for a post.
	 *
	 * @param int $post_id Post id.
	 * @return array<string, array{old: mixed, new: mixed, user: int, time: int}>
	 */
	public static function get( int $post_id ): array {
		$value = get_post_meta( $post_id, self::META, true );
		return is_array( $value ) ? $value : array();
	}

	/**
	 * Propose changes. Proposals equal to the live value are dropped; a new proposal for a
	 * field replaces the previous one.
	 *
	 * @param int                                          $post_id Post id.
	 * @param array<string, array{old: mixed, new: mixed}> $changes Field => { old, new }.
	 * @param int                                          $user_id Proposer.
	 * @return list<string> Fields now pending from this proposal.
	 */
	public static function propose( int $post_id, array $changes, int $user_id ): array {
		$pending = self::get( $post_id );
		$added   = array();
		foreach ( $changes as $field => $change ) {
			if ( ! self::differs( $change['old'], $change['new'] ) ) {
				unset( $pending[ $field ] ); // Changed back to the live value: nothing to review.
				continue;
			}
			$pending[ $field ] = array(
				'old'  => $change['old'],
				'new'  => $change['new'],
				'user' => $user_id,
				'time' => time(),
			);
			$added[]           = (string) $field;
		}
		self::store( $post_id, $pending );
		return $added;
	}

	/**
	 * Remove and return proposals (all, or the given fields).
	 *
	 * @param int               $post_id Post id.
	 * @param list<string>|null $fields  Fields, or null for all.
	 * @return array<string, array{old: mixed, new: mixed, user: int, time: int}>
	 */
	public static function take( int $post_id, ?array $fields = null ): array {
		$pending = self::get( $post_id );
		$taken   = null === $fields ? $pending : array_intersect_key( $pending, array_flip( $fields ) );
		self::store( $post_id, array_diff_key( $pending, $taken ) );
		return $taken;
	}

	/**
	 * Append to the change log.
	 *
	 * @param int          $post_id Post id.
	 * @param string       $action  One of saved, proposed, approved, rejected.
	 * @param list<string> $fields  Field ids.
	 * @param int          $user_id Actor.
	 * @param string       $note    Optional note.
	 */
	public static function log( int $post_id, string $action, array $fields, int $user_id, string $note = '' ): void {
		if ( array() === $fields && '' === $note ) {
			return;
		}
		$log   = self::logEntries( $post_id );
		$log[] = array(
			'action' => $action,
			'fields' => array_values( $fields ),
			'user'   => $user_id,
			'time'   => time(),
			'note'   => $note,
		);
		update_post_meta( $post_id, self::LOG, array_slice( $log, -100 ) );
	}

	/**
	 * Change log, oldest first.
	 *
	 * @param int $post_id Post id.
	 * @return list<array<string, mixed>>
	 */
	public static function logEntries( int $post_id ): array {
		$value = get_post_meta( $post_id, self::LOG, true );
		return is_array( $value ) ? array_values( $value ) : array();
	}

	/**
	 * Pure: whether two stored values differ (arrays compared structurally, scalars as strings).
	 *
	 * @param mixed $a Value.
	 * @param mixed $b Value.
	 */
	public static function differs( $a, $b ): bool {
		if ( is_array( $a ) || is_array( $b ) ) {
			return wp_json_encode( self::normalize( $a ) ) !== wp_json_encode( self::normalize( $b ) );
		}
		return (string) $a !== (string) $b;
	}

	/**
	 * Treat '' / null / [] alike and stringify scalars inside arrays.
	 *
	 * @param mixed $value Value.
	 * @return mixed
	 */
	private static function normalize( $value ) {
		if ( null === $value || '' === $value ) {
			return array();
		}
		if ( is_array( $value ) ) {
			return array_map( array( self::class, 'normalize' ), $value );
		}
		return (string) $value;
	}

	/**
	 * Save (or clear) the pending set.
	 *
	 * @param int                  $post_id Post id.
	 * @param array<string, mixed> $pending Pending set.
	 */
	private static function store( int $post_id, array $pending ): void {
		if ( array() === $pending ) {
			delete_post_meta( $post_id, self::META );
		} else {
			update_post_meta( $post_id, self::META, $pending );
		}
	}
}
