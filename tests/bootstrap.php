<?php
/**
 * Unit test bootstrap: pure logic only; WordPress is stubbed with Brain Monkey.
 *
 * @package FavrMembers
 */

declare(strict_types=1);

require_once dirname( __DIR__ ) . '/vendor/autoload.php';
require_once dirname( __DIR__ ) . '/vendor-prefixed/autoload.php';

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', sys_get_temp_dir() . '/' );
}
