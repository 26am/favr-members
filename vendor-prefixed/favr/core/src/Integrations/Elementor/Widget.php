<?php
/**
 * Base for Favr Elementor widgets.
 *
 * @package FavrCore
 */

declare(strict_types=1);

namespace FavrMembers\Vendor\FavrCore\Integrations\Elementor;

use Elementor\Controls_Manager;
use Elementor\Widget_Base;

/**
 * A Favr widget is a thin, declarative wrapper around the same renderer the plugin's block and
 * shortcode use, so the three stay identical. Subclasses describe their settings with
 * `settings()` and render with `output()`. Only loaded when Elementor fires its widget hook.
 *
 * Setting shape: id => { label, type: text|textarea|number|select|switcher, default?, options?,
 * placeholder?, description?, min?, max?, condition? }. Switchers return '1' or ''.
 */
abstract class Widget extends Widget_Base {

	/** Elementor category shared by all Favr plugins. */
	public const CATEGORY = 'favr';

	/**
	 * Content settings.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	abstract protected function settings(): array;

	/**
	 * Render from the chosen settings.
	 *
	 * @param array<string, mixed> $settings Values keyed by setting id.
	 */
	abstract protected function output( array $settings ): string;

	/**
	 * CSS custom properties the "Accent color" style control sets on the widget wrapper (the
	 * plugins' stylesheets read `--favr-brand`). Return '' to hide the control.
	 */
	protected function accentProperty(): string {
		return '--favr-brand';
	}

	/**
	 * Favr widgets depend on the visitor (login state, membership) and on the URL (directory
	 * filters, calendar month), so Elementor's element cache must render them fresh each time.
	 */
	protected function is_dynamic_content(): bool {
		return true;
	}

	/** Category. */
	public function get_categories(): array {
		return array( self::CATEGORY );
	}

	/** Shown in the panel footer. */
	public function get_custom_help_url(): string {
		return 'https://github.com/26am';
	}

	/** Controls. */
	protected function register_controls(): void {
		$this->start_controls_section(
			'favr_content',
			array(
				'label' => $this->get_title(),
				'tab'   => Controls_Manager::TAB_CONTENT,
			)
		);
		$types = array(
			'text'     => Controls_Manager::TEXT,
			'textarea' => Controls_Manager::TEXTAREA,
			'number'   => Controls_Manager::NUMBER,
			'select'   => Controls_Manager::SELECT,
			'switcher' => Controls_Manager::SWITCHER,
		);
		foreach ( $this->settings() as $id => $setting ) {
			$type    = (string) ( $setting['type'] ?? 'text' );
			$control = array(
				'label'       => (string) ( $setting['label'] ?? $id ),
				'type'        => $types[ $type ] ?? Controls_Manager::TEXT,
				'default'     => $setting['default'] ?? '',
				'label_block' => in_array( $type, array( 'text', 'textarea' ), true ),
			);
			foreach ( array( 'options', 'placeholder', 'min', 'max', 'condition' ) as $key ) {
				if ( isset( $setting[ $key ] ) ) {
					$control[ $key ] = $setting[ $key ];
				}
			}
			if ( isset( $setting['description'] ) ) {
				$control['description'] = $setting['description'];
			}
			if ( 'switcher' === $type ) {
				$control['return_value'] = '1';
				$control['default']      = ! empty( $setting['default'] ) ? '1' : '';
			}
			$this->add_control( $id, $control );
		}
		$this->end_controls_section();

		$property = $this->accentProperty();
		if ( '' !== $property ) {
			$this->start_controls_section(
				'favr_style',
				array(
					'label' => __( 'Colors', 'favr-core' ),
					'tab'   => Controls_Manager::TAB_STYLE,
				)
			);
			$this->add_control(
				'favr_accent',
				array(
					'label'       => __( 'Accent color', 'favr-core' ),
					'type'        => Controls_Manager::COLOR,
					'description' => __( 'Buttons, links and highlights. Leave empty to use the plugin setting or your theme color.', 'favr-core' ),
					'selectors'   => array( '{{WRAPPER}}' => $property . ': {{VALUE}};' ),
				)
			);
			$this->end_controls_section();
		}
	}

	/** Render. */
	protected function render(): void {
		$settings = array();
		$values   = (array) $this->get_settings_for_display();
		foreach ( array_keys( $this->settings() ) as $id ) {
			$settings[ $id ] = $values[ $id ] ?? '';
		}
		echo $this->output( $settings ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- plugin renderers escape their output.
	}

	/**
	 * Register the shared "Favr" category once, whichever plugin gets there first.
	 *
	 * @param object $elements_manager Elementor elements manager.
	 */
	public static function registerCategory( $elements_manager ): void {
		if ( ! is_object( $elements_manager ) || ! method_exists( $elements_manager, 'add_category' ) ) {
			return;
		}
		$existing = method_exists( $elements_manager, 'get_categories' ) ? (array) $elements_manager->get_categories() : array();
		if ( ! isset( $existing[ self::CATEGORY ] ) ) {
			$elements_manager->add_category(
				self::CATEGORY,
				array(
					'title' => __( 'Favr', 'favr-core' ),
					'icon'  => 'eicon-apps',
				)
			);
		}
	}
}
