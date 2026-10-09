<?php
/**
 * Members-only content: the pure rules.
 *
 * @package FavrMembers
 */

declare(strict_types=1);

namespace FavrMembers\Tests\Unit;

use FavrMembers\Frontend\Restrict;

final class RestrictTest extends TestCase {

	public function test_wordpress_wording_for_a_protected_post_becomes_wording_about_members(): void {
		// WordPress says these about password-protected posts. A members-only post is handed to
		// WordPress as "protected", so its wording has to be put right.
		$this->assertSame( 'members', Restrict::wordingFor( 'There is no excerpt because this is a protected post.' ) );
		$this->assertSame( 'members', Restrict::wordingFor( 'This content is password protected.' ) );
		$this->assertSame( 'comments', Restrict::wordingFor( 'Password protected' ) );
		$this->assertSame( 'comments', Restrict::wordingFor( 'This post is password protected. Enter the password to view comments.' ) );
		$this->assertSame( 'comments', Restrict::wordingFor( 'Protected Comments: Please enter your password to view comments.' ) );
		$this->assertSame( '', Restrict::wordingFor( 'Read more' ) );
		$this->assertSame( '', Restrict::wordingFor( '' ) );
	}

	public function test_a_search_only_matches_members_only_posts_by_title_words_the_visitor_typed(): void {
		$this->assertSame( array( 'board', 'minutes' ), Restrict::titleTerms( array( 'board', 'minutes' ), '-' ) );
		// Words the searcher excluded are not something to look for.
		$this->assertSame( array( 'board' ), Restrict::titleTerms( array( 'board', '-minutes' ), '-' ) );
		$this->assertSame( array( 'board', '-minutes' ), Restrict::titleTerms( array( 'board', '-minutes' ), '' ), 'With exclusions switched off, a leading dash is part of the word.' );
		$this->assertSame( array(), Restrict::titleTerms( array( '-minutes' ), '-' ) );
		$this->assertSame( array( 'ok' ), Restrict::titleTerms( array( '', 'ok', array( 'junk' ), null ), '-' ) );
	}

	public function test_which_stored_values_mean_members_only(): void {
		$this->assertTrue( Restrict::flagged( '1' ) );
		$this->assertTrue( Restrict::flagged( true ) );
		$this->assertTrue( Restrict::flagged( 'yes' ) );
		$this->assertFalse( Restrict::flagged( '' ) );
		$this->assertFalse( Restrict::flagged( '0' ) );
		$this->assertFalse( Restrict::flagged( false ) );
		$this->assertFalse( Restrict::flagged( null ) );
		$this->assertFalse( Restrict::flagged( array() ) );
	}

	public function test_what_to_tell_a_signed_in_person_who_is_not_a_current_member(): void {
		// Nobody linked to this account: they have no membership to renew.
		$this->assertSame( 'none', Restrict::standing( array() ) );
		$this->assertSame( 'pending', Restrict::standing( array( 'pending' ) ) );
		$this->assertSame( 'lapsed', Restrict::standing( array( 'lapsed' ) ) );
		$this->assertSame( 'inactive', Restrict::standing( array( 'inactive' ) ) );
		// A status another plugin added (Favr Payments: awaiting payment): approved, not yet in.
		$this->assertSame( 'other', Restrict::standing( array( 'awaiting_payment' ) ) );
		// Someone on several records hears about the one closest to getting in.
		$this->assertSame( 'other', Restrict::standing( array( 'inactive', 'awaiting_payment', 'lapsed' ) ) );
		$this->assertSame( 'pending', Restrict::standing( array( 'inactive', 'pending', 'lapsed' ) ) );
		$this->assertSame( 'lapsed', Restrict::standing( array( 'inactive', 'lapsed' ) ) );
		// "active" on a record that does not count (not published) is not a reason to say more.
		$this->assertSame( 'inactive', Restrict::standing( array( 'active' ) ) );
		$this->assertSame( 'inactive', Restrict::standing( array( '', null, 7 ) ) );
	}

	public function test_the_pages_people_need_in_order_to_get_in_are_never_members_only(): void {
		// Log in, account and join: a lapsed member has to reach their account to renew.
		$this->assertTrue( Restrict::alwaysOpen( 12, array( 12, 15, 0 ) ) );
		$this->assertTrue( Restrict::alwaysOpen( 15, array( '12', '15' ) ), 'Page settings are stored as text.' );
		$this->assertFalse( Restrict::alwaysOpen( 7, array( 12, 15 ) ) );
		$this->assertFalse( Restrict::alwaysOpen( 0, array( 0, '' ) ), 'A page that is not set exempts nothing.' );
		$this->assertFalse( Restrict::alwaysOpen( 7, array( 'junk', null, array( 7 ) ) ) );
	}
}
