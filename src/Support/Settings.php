<?php
/**
 * Typed access to the plugin settings option.
 *
 * @package FavrMembers
 */

declare(strict_types=1);

namespace FavrMembers\Support;

use FavrMembers\Schema\Identifiers as ID;

/**
 * Settings are one serialized option; defaults live here.
 */
final class Settings {

	/**
	 * Cache.
	 *
	 * @var array<string, mixed>|null
	 */
	private static ?array $cache = null;

	/**
	 * Defaults.
	 *
	 * @return array<string, mixed>
	 */
	public static function defaults(): array {
		return array(
			'login_page'      => 0,
			'account_page'    => 0,
			'register_page'   => 0,
			'registration'    => 'invite', // invite | open.
			'listing_status'  => 'publish', // publish | draft (new business members' listings).
			'lapsed_listing'  => 'keep', // keep | hide.
			'auto_lapse'      => '0',
			'lapse_grace'     => 30,
			'email_from_name' => '',
			'delete_data'     => '0',
		);
	}

	/**
	 * All settings.
	 *
	 * @return array<string, mixed>
	 */
	public static function all(): array {
		if ( null === self::$cache ) {
			$stored      = get_option( ID::OPTION_SETTINGS, array() );
			self::$cache = array_merge( self::defaults(), is_array( $stored ) ? $stored : array() );
		}
		return self::$cache;
	}

	/**
	 * One setting.
	 *
	 * @param string $key Key.
	 * @return mixed
	 */
	public static function get( string $key ) {
		return self::all()[ $key ] ?? ( self::defaults()[ $key ] ?? null );
	}

	/**
	 * Update some settings.
	 *
	 * @param array<string, mixed> $values Values.
	 */
	public static function update( array $values ): void {
		update_option( ID::OPTION_SETTINGS, array_merge( self::all(), $values ) );
		self::flush();
	}

	/** Forget the cache. */
	public static function flush(): void {
		self::$cache = null;
	}

	/**
	 * URL of a configured page ('' when unset).
	 *
	 * @param string $key login_page | account_page | register_page.
	 */
	public static function pageUrl( string $key ): string {
		$id = (int) self::get( $key );
		return $id && 'publish' === get_post_status( $id ) ? (string) get_permalink( $id ) : '';
	}
}
