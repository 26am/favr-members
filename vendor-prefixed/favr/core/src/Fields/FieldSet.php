<?php
/**
 * A declarative set of fields grouped into tabs.
 *
 * @package FavrCore
 */

declare(strict_types=1);

namespace FavrMembers\Vendor\FavrCore\Fields;

/**
 * The single definition of an editable record (a business listing, a member, an event…).
 * Admin UI, sanitizing, meta/REST registration, CSV and front-end rendering all read from
 * one FieldSet, so adding a field is a one-place change.
 *
 * Field keys:
 *  - id          string  Unique id; stored as meta key `{prefix}{id}`.
 *  - label       string
 *  - type        string  text|textarea|email|url|tel|number|select|toggle|date|image|gallery|hours|repeater|checkboxes
 *  - tab         string  Tab id.
 *  - width       string  full|half|third (admin layout only).
 *  - description string  Help text under the input.
 *  - placeholder string
 *  - options     array   value => label (select, checkboxes).
 *  - default     mixed
 *  - maxlength   int
 *  - min/max     int     (number)
 *  - sub_fields  array   (repeater) list of simple fields.
 *  - conditions  array   { field, value } show only when another field matches.
 *  - private     bool    Never exposed publicly (REST, front end, public export).
 *  - weight      int     Contribution to a completeness score (0 = ignored).
 *  - required    bool    Must be filled in (validated by the owning plugin).
 */
final class FieldSet {

	/**
	 * Fields keyed by id.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $fields = array();

	/**
	 * Tabs keyed by id: { label, icon }.
	 *
	 * @var array<string, array<string, string>>
	 */
	private array $tabs;

	/**
	 * Constructor.
	 *
	 * @param list<array<string, mixed>>           $fields Field definitions.
	 * @param array<string, array<string, string>> $tabs   Tab id => { label, icon }.
	 */
	public function __construct( array $fields, array $tabs = array() ) {
		foreach ( $fields as $key => $field ) {
			$field['id']                  = (string) ( $field['id'] ?? $key );
			$this->fields[ $field['id'] ] = self::normalize( $field );
		}
		$this->tabs = $tabs;
	}

	/**
	 * All fields keyed by id, in display order.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function all(): array {
		return $this->fields;
	}

	/**
	 * One field, or null.
	 *
	 * @param string $id Field id.
	 * @return array<string, mixed>|null
	 */
	public function get( string $id ): ?array {
		return $this->fields[ $id ] ?? null;
	}

	/**
	 * Fields in a tab.
	 *
	 * @param string $tab Tab id.
	 * @return array<string, array<string, mixed>>
	 */
	public function forTab( string $tab ): array {
		return array_filter( $this->fields, static fn( array $f ): bool => $f['tab'] === $tab );
	}

	/**
	 * Tabs.
	 *
	 * @return array<string, array<string, string>>
	 */
	public function tabs(): array {
		return $this->tabs;
	}

	/**
	 * Fill defaults so consumers never need isset checks.
	 *
	 * @param array<string, mixed> $field Raw definition.
	 * @return array<string, mixed>
	 */
	public static function normalize( array $field ): array {
		return array_merge(
			array(
				'id'          => '',
				'label'       => '',
				'type'        => 'text',
				'tab'         => 'general',
				'width'       => 'full',
				'description' => '',
				'placeholder' => '',
				'options'     => array(),
				'default'     => '',
				'maxlength'   => 0,
				'min'         => null,
				'max'         => null,
				'sub_fields'  => array(),
				'conditions'  => array(),
				'private'     => false,
				'weight'      => 0,
				'required'    => false,
			),
			$field
		);
	}
}
