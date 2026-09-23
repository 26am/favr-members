<?php
/**
 * Type-driven sanitization for field values.
 *
 * @package FavrCore
 */

declare(strict_types=1);

namespace FavrMembers\Vendor\FavrCore\Fields;

/**
 * Turns untrusted input (a POST, a CSV cell, a REST payload) into the canonical stored shape
 * for a field. Every write path goes through here. An "empty" result ('' or array()) means
 * "no value": the caller deletes the meta instead of storing a blank.
 */
final class Sanitizer {

	private const TIME_PATTERN = '/^([01]\d|2[0-3]):[0-5]\d$/';

	/**
	 * Sanitize a value for a field definition.
	 *
	 * @param array<string, mixed> $field Field definition (see FieldSet).
	 * @param mixed                $value Raw input.
	 * @return mixed Canonical value ('' / array() for empty).
	 */
	public static function sanitize( array $field, $value ) {
		switch ( $field['type'] ) {
			case 'textarea':
				return self::limit( sanitize_textarea_field( self::str( $value ) ), (int) $field['maxlength'] );

			case 'email':
				return sanitize_email( self::str( $value ) );

			case 'url':
				return self::url( self::str( $value ) );

			case 'tel':
				return self::tel( self::str( $value ) );

			case 'number':
				return self::number( $value, $field['min'], $field['max'] );

			case 'select':
			case 'radio':
				$value = self::str( $value );
				return ( '' !== $value && array_key_exists( $value, (array) $field['options'] ) ) ? $value : '';

			case 'toggle':
				return self::toggle( $value );

			case 'date':
				return self::date( self::str( $value ) );

			case 'time':
				return self::time( self::str( $value ) );

			case 'image':
				$id = is_numeric( $value ) ? (int) $value : 0;
				return $id > 0 ? $id : '';

			case 'gallery':
				return self::gallery( $value );

			case 'hours':
				return self::hours( $value );

			case 'checkboxes':
				return self::checkboxes( $value, (array) $field['options'] );

			case 'repeater':
				return self::repeater( $value, (array) $field['sub_fields'] );

			case 'text':
			default:
				return self::limit( sanitize_text_field( self::str( $value ) ), (int) $field['maxlength'] );
		}
	}

	/**
	 * Whether a sanitized value means "nothing stored".
	 *
	 * @param array<string, mixed> $field Field definition.
	 * @param mixed                $value Sanitized value.
	 */
	public static function isEmpty( array $field, $value ): bool {
		if ( 'toggle' === $field['type'] ) {
			// An "off" toggle only needs storing when its default is "on".
			return '0' === $value && '1' !== (string) $field['default'];
		}
		return '' === $value || array() === $value || null === $value;
	}

	/**
	 * Normalize a URL. Adds https:// when the scheme is missing so "example.com" just works.
	 *
	 * @param string $value Raw URL.
	 */
	public static function url( string $value ): string {
		$value = trim( $value );
		if ( '' === $value ) {
			return '';
		}
		if ( ! preg_match( '#^[a-z][a-z0-9+.-]*:#i', $value ) ) {
			$value = 'https://' . ltrim( $value, '/' );
		}
		return (string) esc_url_raw( $value, array( 'http', 'https' ) );
	}

	/**
	 * Keep characters that belong in a phone number.
	 *
	 * @param string $value Raw phone.
	 */
	public static function tel( string $value ): string {
		$value = (string) preg_replace( '/<[^>]*>/', '', $value );

		// Keep an extension ("ext 12", "x12", "#12") as a normalized " ext 12" suffix.
		$extension = '';
		if ( preg_match( '/\s*(?:ext\.?|extension|x|#)\s*(\d{1,6})\s*$/i', $value, $match ) ) {
			$extension = ' ext ' . $match[1];
			$value     = substr( $value, 0, -strlen( $match[0] ) );
		}

		$value = (string) preg_replace( '/[^0-9+().\-\s]/', '', $value );
		$value = trim( (string) preg_replace( '/\s+/', ' ', $value ) );
		return '' === $value ? '' : $value . $extension;
	}

	/**
	 * Integer within bounds, or ''.
	 *
	 * @param mixed    $value Raw.
	 * @param int|null $min   Minimum.
	 * @param int|null $max   Maximum.
	 * @return int|string
	 */
	public static function number( $value, $min, $max ) {
		if ( ! is_scalar( $value ) || '' === trim( (string) $value ) || ! is_numeric( $value ) ) {
			return '';
		}
		$number = (int) $value;
		if ( null !== $min && $number < (int) $min ) {
			return '';
		}
		if ( null !== $max && $number > (int) $max ) {
			return '';
		}
		return $number;
	}

	/**
	 * Toggle to '1' / '0'.
	 *
	 * @param mixed $value Raw.
	 */
	public static function toggle( $value ): string {
		if ( is_string( $value ) ) {
			$value = strtolower( trim( $value ) );
			return in_array( $value, array( '1', 'yes', 'true', 'on', 'y' ), true ) ? '1' : '0';
		}
		return $value ? '1' : '0';
	}

	/**
	 * Y-m-d date, or ''. Accepts any strtotime-parsable input (CSV friendliness).
	 *
	 * @param string $value Raw.
	 */
	public static function date( string $value ): string {
		$value = trim( $value );
		if ( '' === $value ) {
			return '';
		}
		$date = \DateTimeImmutable::createFromFormat( '!Y-m-d', $value );
		if ( false === $date || $date->format( 'Y-m-d' ) !== $value ) {
			$timestamp = strtotime( $value );
			if ( false === $timestamp ) {
				return '';
			}
			return gmdate( 'Y-m-d', $timestamp );
		}
		return $value;
	}

	/**
	 * 24-hour HH:MM, or ''. Accepts "9:30", "09:30:00", "9:30 pm".
	 *
	 * @param string $value Raw.
	 */
	public static function time( string $value ): string {
		$value = strtolower( trim( $value ) );
		if ( ! preg_match( '/^(\d{1,2})(?::(\d{2})(?::\d{2})?)?\s*(am|pm)?$/', $value, $m ) || ( empty( $m[2] ) && empty( $m[3] ) ) ) {
			return ''; // A bare number ("9") is ambiguous; require minutes or am/pm.
		}
		$hour   = (int) $m[1];
		$minute = (int) ( '' !== ( $m[2] ?? '' ) ? $m[2] : 0 );
		if ( ! empty( $m[3] ) ) {
			if ( $hour < 1 || $hour > 12 ) {
				return '';
			}
			$hour = ( 12 === $hour ? 0 : $hour ) + ( 'pm' === $m[3] ? 12 : 0 );
		}
		return ( $hour > 23 || $minute > 59 ) ? '' : sprintf( '%02d:%02d', $hour, $minute );
	}

	/**
	 * Unique positive attachment ids, order preserved. Accepts an array or "1,2,3".
	 *
	 * @param mixed $value Raw.
	 * @return list<int>
	 */
	public static function gallery( $value ): array {
		if ( is_string( $value ) ) {
			$value = explode( ',', $value );
		}
		if ( ! is_array( $value ) ) {
			return array();
		}
		// intval (not absint): "-1" must be dropped, not turned into attachment 1.
		$ids = array_filter(
			array_map( static fn( $v ): int => is_numeric( $v ) ? (int) $v : 0, $value ),
			static fn( int $id ): bool => $id > 0
		);
		return array_values( array_unique( $ids ) );
	}

	/**
	 * Normalize a weekly schedule. Output always has all 7 days when any day is set:
	 * day => { status: open|closed|24h, open: HH:MM, close: HH:MM }.
	 *
	 * @param mixed $value Raw.
	 * @return array<string, array{status: string, open: string, close: string}>
	 */
	public static function hours( $value ): array {
		if ( ! is_array( $value ) ) {
			return array();
		}
		$days    = array( 'mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun' );
		$out     = array();
		$has_any = false;

		foreach ( $days as $day ) {
			$row    = isset( $value[ $day ] ) && is_array( $value[ $day ] ) ? $value[ $day ] : array();
			$status = isset( $row['status'] ) ? (string) $row['status'] : '';
			$open   = isset( $row['open'] ) ? trim( (string) $row['open'] ) : '';
			$close  = isset( $row['close'] ) ? trim( (string) $row['close'] ) : '';

			$open  = preg_match( self::TIME_PATTERN, $open ) ? $open : '';
			$close = preg_match( self::TIME_PATTERN, $close ) ? $close : '';

			if ( ! in_array( $status, array( 'open', 'closed', '24h' ), true ) ) {
				$status = ( '' !== $open && '' !== $close ) ? 'open' : '';
			}
			if ( 'open' === $status && ( '' === $open || '' === $close ) ) {
				$status = '';
			}
			if ( '' !== $status ) {
				$has_any = true;
			}
			if ( 'open' !== $status ) {
				$open  = '';
				$close = '';
			}

			$out[ $day ] = array(
				'status' => '' === $status ? 'closed' : $status,
				'open'   => $open,
				'close'  => $close,
			);
		}

		return $has_any ? $out : array();
	}

	/**
	 * Keep only known option keys.
	 *
	 * @param mixed                 $value   Raw (array or "a|b" string).
	 * @param array<string, string> $options Allowed options.
	 * @return list<string>
	 */
	public static function checkboxes( $value, array $options ): array {
		if ( is_string( $value ) ) {
			$value = preg_split( '/\s*[|,]\s*/', $value );
		}
		if ( ! is_array( $value ) ) {
			return array();
		}
		$keys = array_map( 'strval', array_keys( $options ) );
		return array_values( array_intersect( $keys, array_map( 'strval', $value ) ) );
	}

	/**
	 * Sanitize repeater rows by their sub-field definitions; drop rows with no content.
	 *
	 * @param mixed                      $value      Raw rows.
	 * @param list<array<string, mixed>> $sub_fields Sub-field definitions.
	 * @return list<array<string, mixed>>
	 */
	public static function repeater( $value, array $sub_fields ): array {
		if ( ! is_array( $value ) ) {
			return array();
		}
		$rows = array();
		foreach ( $value as $raw_row ) {
			if ( ! is_array( $raw_row ) ) {
				continue;
			}
			$row   = array();
			$empty = true;
			foreach ( $sub_fields as $sub ) {
				$sub   = array_merge(
					array(
						'type'      => 'text',
						'maxlength' => 0,
						'options'   => array(),
						'min'       => null,
						'max'       => null,
						'default'   => '',
					),
					$sub
				);
				$clean = self::sanitize( $sub, $raw_row[ $sub['id'] ] ?? '' );
				if ( ! self::isEmpty( $sub, $clean ) ) {
					$empty = false;
				}
				$row[ $sub['id'] ] = $clean;
			}
			if ( ! $empty ) {
				$rows[] = $row;
			}
		}
		return $rows;
	}

	/**
	 * Scalar input as a string.
	 *
	 * @param mixed $value Raw.
	 */
	private static function str( $value ): string {
		// Callers unslash request data up front; CSV/REST input is never slashed.
		return is_scalar( $value ) ? (string) $value : '';
	}

	/**
	 * Trim to a maximum length (multibyte safe).
	 *
	 * @param string $value Value.
	 * @param int    $max   Max length (0 = unlimited).
	 */
	private static function limit( string $value, int $max ): string {
		if ( $max > 0 && mb_strlen( $value ) > $max ) {
			return mb_substr( $value, 0, $max );
		}
		return $value;
	}
}
