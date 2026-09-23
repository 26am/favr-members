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

## How plugins consume it

Each plugin requires `favr/core` with Composer and bundles a **namespace-prefixed copy** made with
[Strauss](https://github.com/BrianHenryIE/strauss), e.g. `FavrMembers\Vendor\FavrCore\…`. Two plugins
that ship different versions of this library can then run on the same site without conflicts. The
prefixed copy is committed in each plugin (`vendor-prefixed/`), so plugins install from a plain zip.
The CSS/JS assets are copied into each plugin at `assets/core/` by a Composer script.

```bash
composer install && composer test && composer lint
```
