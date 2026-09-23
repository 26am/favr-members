<?php
/**
 * Renders admin inputs for field definitions.
 *
 * @package FavrCore
 */

declare(strict_types=1);

namespace FavrMembers\Vendor\FavrCore\Admin;

use FavrMembers\Vendor\FavrCore\Support\Hours;

/**
 * One method per field type. Inputs are named `{name}[{id}]` so a whole panel posts as a
 * single array that the owning plugin runs through the Sanitizer.
 */
final class FieldRenderer {

	/**
	 * Top-level input name (e.g. "favr", "favr_member").
	 *
	 * @var string
	 */
	private string $input_name;

	/**
	 * Front-end upload config (null in wp-admin, where the media library is used):
	 * { endpoint: REST URL, nonce: wp_rest nonce, parent: post id }.
	 *
	 * @var array<string, mixed>|null
	 */
	private ?array $upload;

	/**
	 * Constructor.
	 *
	 * @param string                    $input_name Top-level input name for posted values.
	 * @param array<string, mixed>|null $upload     Front-end mode: image/gallery fields upload
	 *                                              through this endpoint instead of wp.media.
	 */
	public function __construct( string $input_name = 'favr', ?array $upload = null ) {
		$this->input_name = $input_name;
		$this->upload     = $upload;
	}

	/** The top-level input name. */
	public function inputName(): string {
		return $this->input_name;
	}

	/**
	 * Render a field wrapper + input.
	 *
	 * @param array<string, mixed> $field Field definition.
	 * @param mixed                $value Stored value.
	 */
	public function render( array $field, $value ): void {
		$id         = $this->input_name . '-field-' . $field['id'];
		$conditions = $field['conditions'];
		$classes    = array( 'favr-field', 'favr-field--' . $field['type'], 'favr-w-' . $field['width'] );

		printf(
			'<div class="%1$s" data-field="%2$s"%3$s>',
			esc_attr( implode( ' ', $classes ) ),
			esc_attr( (string) $field['id'] ),
			$conditions ? sprintf(
				' data-cond-field="%s" data-cond-value="%s"',
				esc_attr( (string) $conditions['field'] ),
				esc_attr( is_array( $conditions['value'] ) ? implode( '|', array_map( 'strval', $conditions['value'] ) ) : (string) $conditions['value'] )
			) : '' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- attributes escaped above.
		);

		if ( 'toggle' !== $field['type'] ) {
			printf(
				'<label class="favr-field__label" for="%s">%s%s</label>',
				esc_attr( $id ),
				esc_html( (string) $field['label'] ),
				$field['private'] ? ' <span class="favr-private" title="' . esc_attr__( 'Only visible to staff', 'favr-core' ) . '"><span class="dashicons dashicons-lock"></span></span>' : '' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static markup.
			);
		}

		echo '<div class="favr-field__input">';
		$method = 'input' . str_replace( ' ', '', ucwords( str_replace( '_', ' ', (string) $field['type'] ) ) );
		if ( method_exists( $this, $method ) ) {
			$this->{$method}( $field, $value, $id );
		} else {
			$this->inputText( $field, $value, $id );
		}
		echo '</div>';

		if ( '' !== $field['description'] && 'toggle' !== $field['type'] ) {
			printf( '<p class="favr-field__help">%s</p>', esc_html( (string) $field['description'] ) );
		}
		echo '</div>';
	}

	/**
	 * Input name for a field (optionally nested).
	 *
	 * @param string $id   Field id.
	 * @param string $tail Extra brackets.
	 */
	private function name( string $id, string $tail = '' ): string {
		return $this->input_name . '[' . $id . ']' . $tail;
	}

	/**
	 * Text-like input.
	 *
	 * @param array<string, mixed> $field Field.
	 * @param mixed                $value Value.
	 * @param string               $id    DOM id.
	 * @param string               $type  HTML input type.
	 */
	private function inputText( array $field, $value, string $id, string $type = 'text' ): void {
		printf(
			'<input type="%1$s" id="%2$s" name="%3$s" value="%4$s" class="widefat" placeholder="%5$s"%6$s%7$s%8$s>',
			esc_attr( $type ),
			esc_attr( $id ),
			esc_attr( $this->name( (string) $field['id'] ) ),
			esc_attr( is_scalar( $value ) ? (string) $value : '' ),
			esc_attr( (string) $field['placeholder'] ),
			$field['maxlength'] ? ' maxlength="' . (int) $field['maxlength'] . '"' : '',
			null !== $field['min'] ? ' min="' . (int) $field['min'] . '"' : '',
			null !== $field['max'] ? ' max="' . (int) $field['max'] . '"' : ''
		);
		if ( $field['maxlength'] ) {
			echo '<span class="favr-counter" aria-hidden="true"></span>';
		}
	}

	/**
	 * Email input.
	 *
	 * @param array<string, mixed> $field Field.
	 * @param mixed                $value Value.
	 * @param string               $id    DOM id.
	 */
	private function inputEmail( array $field, $value, string $id ): void {
		$this->inputText( $field, $value, $id, 'email' );
	}

	/**
	 * URL input (text type so "example.com" is accepted; normalized on save and blur).
	 *
	 * @param array<string, mixed> $field Field.
	 * @param mixed                $value Value.
	 * @param string               $id    DOM id.
	 */
	private function inputUrl( array $field, $value, string $id ): void {
		echo '<div class="favr-input-affix"><span class="dashicons dashicons-admin-links" aria-hidden="true"></span>';
		$this->inputText( $field, $value, $id, 'text' );
		echo '</div>';
	}

	/**
	 * Phone input.
	 *
	 * @param array<string, mixed> $field Field.
	 * @param mixed                $value Value.
	 * @param string               $id    DOM id.
	 */
	private function inputTel( array $field, $value, string $id ): void {
		$this->inputText( $field, $value, $id, 'tel' );
	}

	/**
	 * Number input.
	 *
	 * @param array<string, mixed> $field Field.
	 * @param mixed                $value Value.
	 * @param string               $id    DOM id.
	 */
	private function inputNumber( array $field, $value, string $id ): void {
		$this->inputText( $field, ( 0 === $value || '0' === $value ) ? '' : $value, $id, 'number' );
	}

	/**
	 * Date input.
	 *
	 * @param array<string, mixed> $field Field.
	 * @param mixed                $value Value.
	 * @param string               $id    DOM id.
	 */
	private function inputDate( array $field, $value, string $id ): void {
		$this->inputText( $field, $value, $id, 'date' );
	}

	/**
	 * Time input.
	 *
	 * @param array<string, mixed> $field Field.
	 * @param mixed                $value Value.
	 * @param string               $id    DOM id.
	 */
	private function inputTime( array $field, $value, string $id ): void {
		$this->inputText( $field, $value, $id, 'time' );
	}

	/**
	 * Front-end uploader (members have no media library access).
	 *
	 * @param array<string, mixed> $field    Field.
	 * @param list<int>            $ids      Current attachment ids.
	 * @param string               $id       DOM id.
	 * @param bool                 $multiple Gallery (true) or single image.
	 */
	private function uploader( array $field, array $ids, string $id, bool $multiple ): void {
		printf(
			'<div class="favr-upload%1$s" id="%2$s" data-endpoint="%3$s" data-nonce="%4$s" data-parent="%5$d" data-multiple="%6$s" data-error="%7$s"><input type="hidden" name="%8$s" value="%9$s"><ul class="favr-upload__list">',
			$multiple ? ' favr-upload--multiple' : '',
			esc_attr( $id ),
			esc_url( (string) $this->upload['endpoint'] ),
			esc_attr( (string) $this->upload['nonce'] ),
			(int) ( $this->upload['parent'] ?? 0 ),
			$multiple ? '1' : '0',
			esc_attr__( 'That file could not be uploaded. Use a JPG, PNG, WebP or GIF image under the size limit.', 'favr-core' ),
			esc_attr( $this->name( (string) $field['id'] ) ),
			esc_attr( implode( ',', $ids ) )
		);
		foreach ( $ids as $attachment ) {
			$src = wp_get_attachment_image_url( $attachment, 'thumbnail' );
			if ( $src ) {
				printf(
					'<li class="favr-upload__item" data-id="%1$d" draggable="%4$s"><img src="%2$s" alt=""><button type="button" class="favr-upload__remove" aria-label="%3$s">&times;</button></li>',
					(int) $attachment,
					esc_url( $src ),
					esc_attr__( 'Remove image', 'favr-core' ),
					$multiple ? 'true' : 'false'
				);
			}
		}
		printf(
			'</ul><label class="favr-upload__drop"><input type="file" accept="image/jpeg,image/png,image/webp,image/gif"%1$s><span>%2$s</span></label><p class="favr-upload__status" role="status" aria-live="polite"></p></div>',
			$multiple ? ' multiple' : '',
			esc_html( $multiple ? __( 'Add photos (drag to reorder)', 'favr-core' ) : __( 'Choose an image', 'favr-core' ) )
		);
	}

	/**
	 * Textarea.
	 *
	 * @param array<string, mixed> $field Field.
	 * @param mixed                $value Value.
	 * @param string               $id    DOM id.
	 */
	private function inputTextarea( array $field, $value, string $id ): void {
		printf(
			'<textarea id="%1$s" name="%2$s" rows="4" class="widefat" placeholder="%3$s"%4$s>%5$s</textarea>',
			esc_attr( $id ),
			esc_attr( $this->name( (string) $field['id'] ) ),
			esc_attr( (string) $field['placeholder'] ),
			$field['maxlength'] ? ' maxlength="' . (int) $field['maxlength'] . '"' : '',
			esc_textarea( is_scalar( $value ) ? (string) $value : '' )
		);
		if ( $field['maxlength'] ) {
			echo '<span class="favr-counter" aria-hidden="true"></span>';
		}
	}

	/**
	 * Select.
	 *
	 * @param array<string, mixed> $field Field.
	 * @param mixed                $value Value.
	 * @param string               $id    DOM id.
	 */
	private function inputSelect( array $field, $value, string $id ): void {
		printf( '<select id="%s" name="%s">', esc_attr( $id ), esc_attr( $this->name( (string) $field['id'] ) ) );
		foreach ( (array) $field['options'] as $key => $label ) {
			printf(
				'<option value="%s"%s>%s</option>',
				esc_attr( (string) $key ),
				selected( (string) $value, (string) $key, false ),
				esc_html( (string) $label )
			);
		}
		echo '</select>';
	}

	/**
	 * Radio group rendered as a segmented control (few, mutually exclusive choices).
	 *
	 * @param array<string, mixed> $field Field.
	 * @param mixed                $value Value.
	 * @param string               $id    DOM id.
	 */
	private function inputRadio( array $field, $value, string $id ): void {
		$value = '' === (string) $value ? (string) $field['default'] : (string) $value;
		printf( '<div class="favr-segmented" id="%s" role="radiogroup">', esc_attr( $id ) );
		foreach ( (array) $field['options'] as $key => $label ) {
			printf(
				'<label class="favr-segmented__option"><input type="radio" name="%1$s" value="%2$s"%3$s><span>%4$s</span></label>',
				esc_attr( $this->name( (string) $field['id'] ) ),
				esc_attr( (string) $key ),
				checked( $value, (string) $key, false ),
				esc_html( (string) $label )
			);
		}
		echo '</div>';
	}

	/**
	 * Toggle switch. A hidden "0" precedes the checkbox so "off" always posts.
	 *
	 * @param array<string, mixed> $field Field.
	 * @param mixed                $value Value.
	 * @param string               $id    DOM id.
	 */
	private function inputToggle( array $field, $value, string $id ): void {
		$name = $this->name( (string) $field['id'] );
		printf( '<input type="hidden" name="%s" value="0">', esc_attr( $name ) );
		printf(
			'<label class="favr-toggle" for="%1$s"><input type="checkbox" id="%1$s" name="%2$s" value="1"%3$s><span class="favr-toggle__track" aria-hidden="true"></span><span class="favr-toggle__label">%4$s</span></label>',
			esc_attr( $id ),
			esc_attr( $name ),
			checked( '1', (string) $value, false ),
			esc_html( (string) $field['label'] )
		);
		if ( '' !== $field['description'] ) {
			printf( '<p class="favr-field__help">%s</p>', esc_html( (string) $field['description'] ) );
		}
	}

	/**
	 * Checkbox group.
	 *
	 * @param array<string, mixed> $field Field.
	 * @param mixed                $value Value.
	 * @param string               $id    DOM id.
	 */
	private function inputCheckboxes( array $field, $value, string $id ): void {
		$value = is_array( $value ) ? array_map( 'strval', $value ) : array();
		// No hidden fallback: an unchecked group posts nothing, which saves as "empty".
		printf( '<div class="favr-chips" id="%s">', esc_attr( $id ) );
		foreach ( (array) $field['options'] as $key => $label ) {
			printf(
				'<label class="favr-chip"><input type="checkbox" name="%1$s" value="%2$s"%3$s><span>%4$s</span></label>',
				esc_attr( $this->name( (string) $field['id'], '[]' ) ),
				esc_attr( (string) $key ),
				checked( in_array( (string) $key, $value, true ), true, false ),
				esc_html( (string) $label )
			);
		}
		echo '</div>';
	}

	/**
	 * Single image picker (media library).
	 *
	 * @param array<string, mixed> $field Field.
	 * @param mixed                $value Value.
	 * @param string               $id    DOM id.
	 */
	private function inputImage( array $field, $value, string $id ): void {
		if ( null !== $this->upload ) {
			$this->uploader( $field, (int) $value > 0 ? array( (int) $value ) : array(), $id, false );
			return;
		}
		$attachment = (int) $value;
		$src        = $attachment ? wp_get_attachment_image_url( $attachment, 'medium' ) : '';
		printf(
			'<div class="favr-image%1$s" id="%2$s" data-title="%3$s">'
				. '<input type="hidden" name="%4$s" value="%5$s">'
				. '<button type="button" class="favr-image__preview favr-media-pick" aria-label="%6$s">%7$s<span class="favr-image__empty"><span class="dashicons dashicons-format-image"></span>%8$s</span></button>'
				. '<div class="favr-image__actions"><button type="button" class="button-link favr-media-pick">%9$s</button> <button type="button" class="button-link button-link-delete favr-image__remove">%10$s</button></div>'
				. '</div>',
			$src ? ' has-image' : '',
			esc_attr( $id ),
			esc_attr( (string) $field['label'] ),
			esc_attr( $this->name( (string) $field['id'] ) ),
			esc_attr( $attachment ? (string) $attachment : '' ),
			esc_attr__( 'Choose image', 'favr-core' ),
			$src ? '<img src="' . esc_url( $src ) . '" alt="">' : '',
			esc_html__( 'Add image', 'favr-core' ),
			esc_html__( 'Replace', 'favr-core' ),
			esc_html__( 'Remove', 'favr-core' )
		);
	}

	/**
	 * Sortable gallery (media library, multiple).
	 *
	 * @param array<string, mixed> $field Field.
	 * @param mixed                $value Value.
	 * @param string               $id    DOM id.
	 */
	private function inputGallery( array $field, $value, string $id ): void {
		if ( null !== $this->upload ) {
			$this->uploader( $field, is_array( $value ) ? array_map( 'intval', $value ) : array(), $id, true );
			return;
		}
		$ids = is_array( $value ) ? array_map( 'intval', $value ) : array();
		printf(
			'<div class="favr-gallery" id="%1$s"><input type="hidden" name="%2$s" value="%3$s"><ul class="favr-gallery__list">',
			esc_attr( $id ),
			esc_attr( $this->name( (string) $field['id'] ) ),
			esc_attr( implode( ',', $ids ) )
		);
		foreach ( $ids as $attachment ) {
			$src = wp_get_attachment_image_url( $attachment, 'thumbnail' );
			if ( ! $src ) {
				continue;
			}
			printf(
				'<li class="favr-gallery__item" data-id="%1$d"><img src="%2$s" alt=""><button type="button" class="favr-gallery__remove" aria-label="%3$s">&times;</button></li>',
				(int) $attachment,
				esc_url( $src ),
				esc_attr__( 'Remove image', 'favr-core' )
			);
		}
		printf(
			'</ul><button type="button" class="button favr-gallery__add"><span class="dashicons dashicons-plus-alt2"></span> %s</button></div>',
			esc_html__( 'Add photos', 'favr-core' )
		);
	}

	/**
	 * Weekly hours grid.
	 *
	 * @param array<string, mixed> $field Field.
	 * @param mixed                $value Value.
	 * @param string               $id    DOM id.
	 */
	private function inputHours( array $field, $value, string $id ): void {
		$value = is_array( $value ) ? $value : array();
		$empty = array() === $value;
		// With no schedule the grid starts disabled (disabled inputs never post), so saving a
		// listing never invents hours the business did not give us.
		printf(
			'<div class="favr-hours%1$s" id="%2$s"><button type="button" class="button favr-hours__start"><span class="dashicons dashicons-clock"></span> %3$s</button><div class="favr-hours__grid">',
			$empty ? ' is-empty' : '',
			esc_attr( $id ),
			esc_html__( 'Add opening hours', 'favr-core' )
		);
		foreach ( Hours::dayLabels() as $day => $label ) {
			$weekend = in_array( $day, array( 'sat', 'sun' ), true );
			$row     = $value[ $day ] ?? array();
			$status  = $empty ? ( $weekend ? 'closed' : 'open' ) : (string) ( $row['status'] ?? 'closed' );
			$open    = $empty ? '09:00' : (string) ( $row['open'] ?? '' );
			$close   = $empty ? '17:00' : (string) ( $row['close'] ?? '' );
			$base    = $this->name( (string) $field['id'], '[' . $day . ']' );
			$off     = $empty ? ' disabled' : '';
			printf(
				'<div class="favr-hours__row" data-day="%1$s" data-status="%2$s">'
					. '<span class="favr-hours__day">%3$s</span>'
					. '<select name="%4$s[status]" class="favr-hours__status" aria-label="%5$s"%16$s>'
					. '<option value="open"%6$s>%7$s</option><option value="closed"%8$s>%9$s</option><option value="24h"%10$s>%11$s</option>'
					. '</select>'
					. '<span class="favr-hours__times"><input type="time" name="%4$s[open]" value="%12$s" aria-label="%13$s"%16$s> <span class="favr-hours__sep">–</span> <input type="time" name="%4$s[close]" value="%14$s" aria-label="%15$s"%16$s></span>'
					. '</div>',
				esc_attr( $day ),
				esc_attr( $status ),
				esc_html( $label ),
				esc_attr( $base ),
				/* translators: %s: day of the week. */
				esc_attr( sprintf( __( '%s status', 'favr-core' ), $label ) ),
				selected( $status, 'open', false ),
				esc_html__( 'Open', 'favr-core' ),
				selected( $status, 'closed', false ),
				esc_html__( 'Closed', 'favr-core' ),
				selected( $status, '24h', false ),
				esc_html__( 'Open 24 hours', 'favr-core' ),
				esc_attr( $open ),
				/* translators: %s: day of the week. */
				esc_attr( sprintf( __( '%s opening time', 'favr-core' ), $label ) ),
				esc_attr( $close ),
				/* translators: %s: day of the week. */
				esc_attr( sprintf( __( '%s closing time', 'favr-core' ), $label ) ),
				$off // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static attribute.
			);
		}
		printf(
			'<p class="favr-hours__tools"><button type="button" class="button-link favr-hours__copy">%s</button> · <button type="button" class="button-link button-link-delete favr-hours__clear">%s</button></p></div></div>',
			esc_html__( 'Copy Monday to all weekdays', 'favr-core' ),
			esc_html__( 'Remove hours', 'favr-core' )
		);
	}

	/**
	 * Repeater rows with add/remove/sort.
	 *
	 * @param array<string, mixed> $field Field.
	 * @param mixed                $value Value.
	 * @param string               $id    DOM id.
	 */
	private function inputRepeater( array $field, $value, string $id ): void {
		$rows = is_array( $value ) ? array_values( $value ) : array();
		printf( '<div class="favr-repeater" id="%s"><ol class="favr-repeater__rows">', esc_attr( $id ) );
		foreach ( $rows as $index => $row ) {
			$this->repeaterRow( $field, (string) $index, is_array( $row ) ? $row : array() );
		}
		echo '</ol><script type="text/html" class="favr-repeater__template">';
		$this->repeaterRow( $field, '__INDEX__', array() );
		printf(
			'</script><button type="button" class="button favr-repeater__add"><span class="dashicons dashicons-plus-alt2"></span> %s</button></div>',
			esc_html__( 'Add link', 'favr-core' )
		);
	}

	/**
	 * One repeater row.
	 *
	 * @param array<string, mixed> $field Field.
	 * @param string               $index Row index.
	 * @param array<string, mixed> $row   Row values.
	 */
	private function repeaterRow( array $field, string $index, array $row ): void {
		echo '<li class="favr-repeater__row"><span class="favr-repeater__handle dashicons dashicons-menu" aria-hidden="true"></span><div class="favr-repeater__cells">';
		foreach ( (array) $field['sub_fields'] as $sub ) {
			printf(
				'<input type="text" name="%1$s" value="%2$s" placeholder="%3$s" aria-label="%4$s" class="widefat%5$s">',
				esc_attr( $this->name( (string) $field['id'], '[' . $index . '][' . $sub['id'] . ']' ) ),
				esc_attr( (string) ( $row[ $sub['id'] ] ?? '' ) ),
				esc_attr( (string) ( $sub['placeholder'] ?? '' ) ),
				esc_attr( (string) ( $sub['label'] ?? '' ) ),
				'url' === ( $sub['type'] ?? '' ) ? ' favr-url' : ''
			);
		}
		printf(
			'</div><button type="button" class="favr-repeater__remove" aria-label="%s"><span class="dashicons dashicons-trash"></span></button></li>',
			esc_attr__( 'Remove row', 'favr-core' )
		);
	}
}
