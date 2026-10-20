<?php
declare(strict_types=1);

namespace FavrMembers\Tests\Unit;

use FavrMembers\Frontend\Auth;
use FavrMembers\Schema\Identifiers as ID;

/**
 * The join form's hidden robot trap. Named "favr_website" and labelled "Website", it was filled by
 * password managers (1Password fills a field by that name from an identity), and the applicant was
 * told to check their email while nothing was saved.
 */
final class TrapFieldTest extends TestCase {

	public function test_the_hidden_field_has_a_name_no_autofill_or_password_manager_recognises(): void {
		foreach ( array( 'web', 'site', 'url', 'link', 'home', 'mail', 'name', 'nick', 'phone', 'tel', 'fax', 'org', 'company', 'business', 'address', 'street', 'city', 'zip', 'postal', 'country', 'user', 'login', 'pass', 'title' ) as $word ) {
			$this->assertStringNotContainsString( $word, substr( ID::FIELD_TRAP, strlen( 'favr_' ) ) );
		}
	}

	public function test_the_join_form_tells_password_managers_to_skip_the_hidden_field(): void {
		$template = (string) file_get_contents( dirname( __DIR__, 2 ) . '/templates/register.php' );
		preg_match( '/<p class="favr-m-hp"[^\n]*/', $template, $match );
		$line = $match[0] ?? '';
		$this->assertStringContainsString( 'ID::FIELD_TRAP', $line );
		foreach ( array( 'autocomplete="off"', 'tabindex="-1"', 'data-1p-ignore', 'data-lpignore="true"', 'data-bwignore', 'data-protonpass-ignore', 'data-form-type="other"' ) as $attribute ) {
			$this->assertStringContainsString( $attribute, $line );
		}
		$this->assertStringNotContainsString( 'Website', $line, 'its label is matched like its name' );
	}

	public function test_only_the_hidden_field_marks_a_robot(): void {
		$this->assertTrue( Auth::trapped( array( ID::FIELD_TRAP => 'http://spam.example' ) ) );
		$this->assertFalse( Auth::trapped( array( ID::FIELD_TRAP => '' ) ) );
		$this->assertFalse( Auth::trapped( array( ID::FIELD_TRAP => '   ' ) ) );
		$this->assertFalse( Auth::trapped( array( ID::FIELD_TRAP => array( 'x' ) ) ) );
		$this->assertFalse( Auth::trapped( array( 'favr_website' => 'https://26am.com' ) ), 'the old name is an ordinary field now' );
		$this->assertFalse( Auth::trapped( array() ) );
	}
}
