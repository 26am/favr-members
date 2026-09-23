# Favr Core

Shared building blocks for the Favr Sites WordPress plugins
([Favr Directory](https://github.com/26am/favr-directory), [Favr Members](https://github.com/26am/favr-members),
Favr Events).

- `FavrCore\Fields\FieldSet`: a declarative set of fields grouped into tabs, the single definition
  of an editable record.
- `FavrCore\Fields\Sanitizer`: type-driven sanitizing used on every write path (admin, REST, CSV).
- `FavrCore\Admin\FieldRenderer` plus `assets/fields.{css,js}`: native-looking admin inputs (tabs,
  toggles, chips, segmented radios, image/gallery pickers, weekly hours, repeaters, conditional
  fields).
- `FavrCore\Support\{Template, AssetVersion, Hours, CsvFormat}`.
- **Front-end forms (0.2):** pass `upload` settings to `new FieldRenderer( 'name', [ 'endpoint' => …,
  'nonce' => wp_create_nonce( 'wp_rest' ), 'parent' => $post_id ] )` and image/gallery fields render
  a no-wp-admin uploader (`assets/uploader.js`). Wrap the form in `.favr-front` and load
  `assets/front-fields.css` after `fields.css` for a theme-friendly skin. The endpoint must return
  `{ id, thumb }` and should use `Moderation\Uploads::handle()`; validate submitted ids with
  `Uploads::filterUsable()`.
- `FavrCore\Moderation\PendingChanges`: staged edits awaiting review (`_favr_pending_changes`) plus a
  capped change log (`_favr_change_log`). The owning plugin applies approved values.
- `FavrCore\Approvals\Inbox`: one shared **Approvals** admin screen. Call `Inbox::boot()` and add
  queues through the `favr_approvals_providers` filter (see the class docblock for the shape).
  Give items a `version` fingerprint so `decide()` can refuse stale decisions, and a cheap `count`
  callable for the menu badge.
- `FieldRenderer::panel()`: the shared tabbed panel (tabs + panes) used by every edit screen and
  front-end form.
- `FavrCore\Support\RateLimit::hit( $key, $max, $window )`: simple per-key limits for member forms.

## How plugins consume it

Each plugin requires `favr/core` with Composer and bundles a **namespace-prefixed copy** made with
[Strauss](https://github.com/BrianHenryIE/strauss), e.g. `FavrMembers\Vendor\FavrCore\…`. Two plugins
that ship different versions of this library can then run on the same site without conflicts. The
prefixed copy is committed in each plugin (`vendor-prefixed/`), so plugins install from a plain zip.
The CSS/JS assets are copied into each plugin at `assets/core/` by a Composer script.

```bash
composer install && composer test && composer lint
```
