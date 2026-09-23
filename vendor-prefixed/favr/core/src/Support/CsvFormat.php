<?php
/**
 * Field value <-> CSV cell encoding.
 *
 * @package FavrCore
 */

declare(strict_types=1);

namespace FavrMembers\Vendor\FavrCore\Support;

/**
 * Human-editable CSV encodings so a spreadsheet round-trips cleanly:
 *  - lists (checkboxes, categories)  "a|b|c"
 *  - gallery                          "12,34,56"
 *  - hours                            "mon=09:00-17:00;tue=closed;sat=24h"
 *  - repeater                         "Label=https://a;Label 2=https://b"
 *  - toggle                           "yes" / "" (anything truthy imports as on)
 * Decoded values are still passed through Sanitizer by the importer.
 */
final class CsvFormat {

	/**
	 * Encode a stored value for a CSV cell.
	 *
	 * @param array<string, mixed> $field Field definition.
	 * @param mixed                $value Stored value.
	 */
	public static function encode( array $field, $value ): string {
		switch ( $field['type'] ) {
			case 'toggle':
				return '1' === (string) $value ? 'yes' : ( '1' === (string) $field['default'] ? 'no' : '' );

			case 'checkboxes':
				return is_array( $value ) ? implode( '|', array_map( 'strval', $value ) ) : '';

			case 'gallery':
				return is_array( $value ) ? implode( ',', array_map( 'intval', $value ) ) : '';

			case 'image':
				return (int) $value > 0 ? (string) (int) $value : '';

			case 'hours':
				return self::encodeHours( is_array( $value ) ? $value : array() );

			case 'repeater':
				return self::encodeRepeater( is_array( $value ) ? $value : array(), (array) $field['sub_fields'] );

			default:
				return is_scalar( $value ) ? (string) $value : '';
		}
	}

	/**
	 * Decode a CSV cell into the shape the Sanitizer expects.
	 *
	 * @param array<string, mixed> $field Field definition.
	 * @param string               $cell  Cell text.
	 * @return mixed
	 */
	public static function decode( array $field, string $cell ) {
		$cell = trim( $cell );
		switch ( $field['type'] ) {
			case 'hours':
				return self::decodeHours( $cell );
			case 'repeater':
				return self::decodeRepeater( $cell, (array) $field['sub_fields'] );
			case 'toggle':
				// Blank means "use the default", so hand-made sheets never flip on-by-default toggles off.
				return '' === $cell ? (string) $field['default'] : $cell;
			default:
				return $cell;
		}
	}

	/**
	 * Encode hours.
	 *
	 * @param array<string, array<string, string>> $hours Schedule.
	 */
	public static function encodeHours( array $hours ): string {
		$parts = array();
		foreach ( Hours::DAYS as $day ) {
			if ( ! isset( $hours[ $day ] ) ) {
				continue;
			}
			$row = $hours[ $day ];
			switch ( $row['status'] ?? 'closed' ) {
				case 'open':
					$parts[] = $day . '=' . $row['open'] . '-' . $row['close'];
					break;
				case '24h':
					$parts[] = $day . '=24h';
					break;
				default:
					$parts[] = $day . '=closed';
			}
		}
		return implode( ';', $parts );
	}

	/**
	 * Decode hours. Accepts 9:00 or 09:00 and en/em dashes from spreadsheets.
	 *
	 * @param string $cell Cell.
	 * @return array<string, array<string, string>>
	 */
	public static function decodeHours( string $cell ): array {
		$out = array();
		if ( '' === $cell ) {
			return $out;
		}
		foreach ( preg_split( '/\s*;\s*/', $cell ) ?: array() as $part ) {
			if ( ! str_contains( $part, '=' ) ) {
				continue;
			}
			list( $day, $spec ) = array_map( 'trim', explode( '=', $part, 2 ) );
			$day                = strtolower( substr( $day, 0, 3 ) );
			$spec               = strtolower( str_replace( array( '–', '—' ), '-', $spec ) );
			if ( ! in_array( $day, Hours::DAYS, true ) ) {
				continue;
			}
			if ( '24h' === $spec ) {
				$out[ $day ] = array(
					'status' => '24h',
					'open'   => '',
					'close'  => '',
				);
			} elseif ( preg_match( '/^(.+?)\s*-\s*(.+)$/', $spec, $m ) && null !== self::time( $m[1] ) && null !== self::time( $m[2] ) ) {
				$out[ $day ] = array(
					'status' => 'open',
					'open'   => (string) self::time( $m[1] ),
					'close'  => (string) self::time( $m[2] ),
				);
			} elseif ( in_array( $spec, array( 'closed', 'off', 'x', '' ), true ) ) {
				$out[ $day ] = array(
					'status' => 'closed',
					'open'   => '',
					'close'  => '',
				);
			}
		}
		return $out;
	}

	/**
	 * Parse "9", "09:00", "9am", "5:30 pm", "24:00" into HH:MM (24:00 becomes 00:00, which the
	 * hours logic reads as "until midnight"). Null when unparseable.
	 *
	 * @param string $raw Time text.
	 */
	public static function time( string $raw ): ?string {
		if ( ! preg_match( '/^(\d{1,2})(?::(\d{2}))?\s*(am|pm|a|p)?$/', trim( strtolower( $raw ) ), $m ) ) {
			return null;
		}
		$hour   = (int) $m[1];
		$minute = isset( $m[2] ) && '' !== $m[2] ? (int) $m[2] : 0;
		$suffix = $m[3] ?? '';
		if ( '' !== $suffix ) {
			if ( $hour < 1 || $hour > 12 ) {
				return null;
			}
			$hour = ( 12 === $hour ? 0 : $hour ) + ( 'p' === $suffix[0] ? 12 : 0 );
		}
		if ( 24 === $hour && 0 === $minute ) {
			$hour = 0;
		}
		if ( $hour > 23 || $minute > 59 ) {
			return null;
		}
		return sprintf( '%02d:%02d', $hour, $minute );
	}

	/**
	 * Day specs in an hours cell that could not be understood (for import warnings).
	 *
	 * @param string $cell Cell.
	 * @return list<string>
	 */
	public static function invalidHourSpecs( string $cell ): array {
		$bad = array();
		foreach ( preg_split( '/\s*;\s*/', trim( $cell ) ) ?: array() as $part ) {
			if ( '' === $part ) {
				continue;
			}
			$decoded = self::decodeHours( $part );
			if ( array() === $decoded ) {
				$bad[] = $part;
			}
		}
		return $bad;
	}

	/**
	 * Encode repeater rows as "first=second;…" using the first two sub-fields.
	 *
	 * @param list<array<string, mixed>> $rows       Rows.
	 * @param list<array<string, mixed>> $sub_fields Sub-fields.
	 */
	public static function encodeRepeater( array $rows, array $sub_fields ): string {
		$keys = array_column( $sub_fields, 'id' );
		if ( count( $keys ) < 2 ) {
			return '';
		}
		$parts = array();
		foreach ( $rows as $row ) {
			$first   = str_replace( array( ';', '=' ), ' ', (string) ( $row[ $keys[0] ] ?? '' ) );
			$second  = str_replace( ';', '%3B', (string) ( $row[ $keys[1] ] ?? '' ) );
			$parts[] = trim( $first ) . '=' . $second;
		}
		return implode( ';', $parts );
	}

	/**
	 * Decode repeater rows.
	 *
	 * @param string                     $cell       Cell.
	 * @param list<array<string, mixed>> $sub_fields Sub-fields.
	 * @return list<array<string, string>>
	 */
	public static function decodeRepeater( string $cell, array $sub_fields ): array {
		$keys = array_column( $sub_fields, 'id' );
		if ( '' === $cell || count( $keys ) < 2 ) {
			return array();
		}
		$rows = array();
		foreach ( preg_split( '/\s*;\s*/', $cell ) ?: array() as $part ) {
			if ( '' === $part ) {
				continue;
			}
			$pair   = explode( '=', $part, 2 );
			$rows[] = array(
				$keys[0] => trim( count( $pair ) > 1 ? $pair[0] : '' ),
				$keys[1] => str_replace( '%3B', ';', trim( count( $pair ) > 1 ? $pair[1] : $pair[0] ) ),
			);
		}
		return $rows;
	}

	/**
	 * Split a "a|b" list cell.
	 *
	 * @param string $cell Cell.
	 * @return list<string>
	 */
	public static function splitList( string $cell ): array {
		$cell = trim( $cell );
		if ( '' === $cell ) {
			return array();
		}
		return array_values( array_filter( array_map( 'trim', explode( '|', $cell ) ), static fn( string $v ): bool => '' !== $v ) );
	}

	/**
	 * Neutralize spreadsheet formula injection for exported cells.
	 *
	 * @param string $cell Cell.
	 */
	public static function safeCell( string $cell ): string {
		if ( '' !== $cell && in_array( $cell[0], array( '=', '+', '-', '@', "\t", "\r" ), true ) ) {
			return "'" . $cell;
		}
		return $cell;
	}

	/**
	 * Reverse safeCell() on import ("'+1 555…" -> "+1 555…").
	 *
	 * @param string $cell Cell.
	 */
	public static function unsafeCell( string $cell ): string {
		if ( strlen( $cell ) > 1 && "'" === $cell[0] && in_array( $cell[1], array( '=', '+', '-', '@', "\t", "\r" ), true ) ) {
			return substr( $cell, 1 );
		}
		return $cell;
	}
}
