<?php
/**
 * Favr dashboard contribution.
 *
 * @package FavrMembers
 */

declare(strict_types=1);

namespace FavrMembers\Tests\Unit;

use Brain\Monkey\Functions;
use FavrMembers\Integration\FavrSites;

final class FavrSitesTest extends TestCase {

	public function test_renewal_date_is_the_stored_day_in_the_site_timezone(): void {
		$zone = new \DateTimeZone( 'America/New_York' );
		Functions\when( 'wp_timezone' )->justReturn( $zone );
		Functions\when( 'wp_date' )->alias( static fn( string $format, int $ts ): string => ( new \DateTimeImmutable( '@' . $ts ) )->setTimezone( $zone )->format( $format ) );
		$this->assertSame( 'Oct 5', FavrSites::renewalDay( '2026-10-05' ) );
	}
}
