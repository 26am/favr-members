<?php
/**
 * Installing capabilities and roles on activation and on every version upgrade.
 *
 * @package FavrMembers
 */

declare(strict_types=1);

namespace FavrMembers\Tests\Unit;

use Brain\Monkey\Functions;
use FavrMembers\Model\Activation;
use FavrMembers\Schema\Identifiers as ID;

final class ActivationTest extends TestCase {

	/** What staff may do with member records; settings stay with administrators. */
	private const MEMBER_CAPS = array(
		'edit_favr_members',
		'edit_others_favr_members',
		'edit_private_favr_members',
		'edit_published_favr_members',
		'publish_favr_members',
		'read_private_favr_members',
		'delete_favr_members',
		'delete_others_favr_members',
		'delete_private_favr_members',
		'delete_published_favr_members',
	);

	/** @var array<string, object> The site's roles. */
	private array $roles = array();

	/** @var list<array{role: string, name: string, caps: array<string, bool>}> Roles created. */
	private array $created = array();

	protected function setUp(): void {
		parent::setUp();
		$this->roles   = array(
			'administrator' => $this->role(),
			'editor'        => $this->role(),
		);
		$this->created = array();
		Functions\when( 'get_role' )->alias( fn( $name ) => $this->roles[ $name ] ?? null );
		Functions\when( 'add_role' )->alias(
			function ( $role, $name, $caps ): void {
				$this->created[] = compact( 'role', 'name', 'caps' );
			}
		);
	}

	/**
	 * A stand-in for WP_Role.
	 *
	 * @param list<string> $caps Capabilities it starts with.
	 */
	private function role( array $caps = array() ): object {
		return new class( $caps ) {
			/** @var array<string, bool> */
			public array $capabilities;

			/** @param list<string> $caps Caps. */
			public function __construct( array $caps ) {
				$this->capabilities = array_fill_keys( $caps, true );
			}

			public function add_cap( string $cap ): void {
				$this->capabilities[ $cap ] = true;
			}

			public function has_cap( string $cap ): bool {
				return ! empty( $this->capabilities[ $cap ] );
			}
		};
	}

	public function test_an_update_keeps_what_others_added_to_the_membership_manager_and_member_roles(): void {
		// An older install: the roles exist, and another plugin or the site owner has added to them.
		$manager = $this->role( array( 'read', 'edit_favr_members', 'export_member_reports' ) );
		$person  = $this->role( array( 'read', 'view_member_documents' ) );
		$this->roles[ ID::ROLE_MANAGER ] = $manager;
		$this->roles[ ID::ROLE_PERSON ]  = $person;
		Functions\expect( 'remove_role' )->never();

		Activation::installCaps();

		$this->assertSame( array(), $this->created, 'An existing role is never created again.' );
		$this->assertTrue( $manager->has_cap( 'export_member_reports' ), 'What somebody else gave the Membership Manager survives.' );
		$this->assertTrue( $person->has_cap( 'view_member_documents' ), 'What somebody else gave the Member role survives.' );
		foreach ( self::MEMBER_CAPS as $cap ) {
			$this->assertTrue( $manager->has_cap( $cap ), "The Membership Manager is given the missing “{$cap}”." );
		}
		$this->assertTrue( $manager->has_cap( 'list_users' ) );
		$this->assertTrue( $manager->has_cap( 'upload_files' ) );
		$this->assertFalse( $manager->has_cap( ID::CAP_SETTINGS ), 'Settings stay with administrators.' );
		$this->assertSame( array( 'read', 'view_member_documents' ), array_keys( $person->capabilities ), 'The Member role gains nothing it should not have.' );
	}

	public function test_an_update_restores_a_capability_the_roles_need(): void {
		$person                          = $this->role();
		$this->roles[ ID::ROLE_PERSON ]  = $person;
		$this->roles[ ID::ROLE_MANAGER ] = $this->role( self::MEMBER_CAPS );
		Functions\expect( 'remove_role' )->never();

		Activation::installCaps();

		$this->assertTrue( $person->has_cap( 'read' ) );
		$this->assertTrue( $this->roles[ ID::ROLE_MANAGER ]->has_cap( 'read' ) );
	}

	public function test_a_first_install_creates_both_roles_exactly_as_before(): void {
		Functions\expect( 'remove_role' )->never();

		Activation::installCaps();

		$this->assertSame(
			array(
				array(
					'role' => ID::ROLE_PERSON,
					'name' => 'Member',
					'caps' => array( 'read' => true ),
				),
				array(
					'role' => ID::ROLE_MANAGER,
					'name' => 'Membership Manager',
					'caps' => array_fill_keys( self::MEMBER_CAPS, true ) + array(
						'read'         => true,
						'list_users'   => true,
						'upload_files' => true,
					),
				),
			),
			$this->created
		);
	}

	public function test_administrators_and_editors_manage_members_and_only_administrators_the_settings(): void {
		Activation::installCaps();

		foreach ( self::MEMBER_CAPS as $cap ) {
			$this->assertTrue( $this->roles['administrator']->has_cap( $cap ), $cap );
			$this->assertTrue( $this->roles['editor']->has_cap( $cap ), $cap );
		}
		$this->assertTrue( $this->roles['administrator']->has_cap( ID::CAP_SETTINGS ) );
		$this->assertFalse( $this->roles['editor']->has_cap( ID::CAP_SETTINGS ) );
	}

	public function test_directory_managers_can_manage_members_too(): void {
		$directory                             = $this->role( array( 'read', 'edit_favr_businesses' ) );
		$this->roles['favr_directory_manager'] = $directory;

		Activation::installCaps();

		foreach ( self::MEMBER_CAPS as $cap ) {
			$this->assertTrue( $directory->has_cap( $cap ), $cap );
		}
		$this->assertTrue( $directory->has_cap( 'edit_favr_businesses' ) );
		$this->assertFalse( $directory->has_cap( ID::CAP_SETTINGS ) );
	}
}
