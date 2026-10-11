<?php
/**
 * What the shared field script is loaded with on Members screens.
 *
 * @package FavrMembers
 */

declare(strict_types=1);

namespace FavrMembers\Tests\Unit;

use FavrMembers\Admin\Assets;

final class AdminAssetsTest extends TestCase {

	/**
	 * The shared field script (assets/core/fields.js) calls sortable() for galleries and repeaters
	 * as it starts, on every screen it is loaded on, whether or not the screen has one.
	 */
	public function test_the_field_script_always_gets_sortable(): void {
		$deps = Assets::fieldScriptDependencies();

		$this->assertContains( 'jquery', $deps );
		$this->assertContains( 'jquery-ui-sortable', $deps );
	}
}
