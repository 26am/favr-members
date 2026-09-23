# CLAUDE.md

## What this is

**Favr Members** (namespace `FavrMembers\`, data prefix `favr_member_`) is part of the Favr Sites
suite. The suite spec lives in Favr Directory: `docs/specs/2026-09-23-favr-suite.md`. PHP 8.1+,
WP 6.7+, no payments.

## Commands

```bash
composer install   # also runs Strauss → vendor-prefixed/ and copies favr/core assets → assets/core/
composer test      # PHPUnit + Brain Monkey (pure logic)
composer lint      # WPCS, keep at 0 errors
```

Local test site: `http://sermonator-test.local/`, with this repo symlinked to
`wp-content/plugins/favr-members`. `wp favr-members seed` loads sample members. The site's UTMwp
plugin fatals on `user_register` when there is no UTM session, so run WP-CLI with
`--skip-plugins=utmwp` when creating users.

## Architecture

- **One Member record per membership** (`favr_member` CPT) with type `individual` | `business`.
  Fields are defined once in `Model\Fields` (a favr/core `FieldSet`); type-specific fields use
  `conditions` on `type`.
- **Logins** are WordPress users with the `favr_member_person` role, linked by multi-value meta
  `favr_member_user` (one row per user). `Model\Repository::forUser()` answers "which memberships
  does this person hold or represent".
- **Every change fires `favr_members_member_saved`** (admin save, import, auto-lapse, application).
  `Integration\Directory` listens and creates, adopts and syncs the business listing. It never
  creates listings for non-active members.
- **The front end** is PRG form handlers (`Frontend\Auth`), shortcodes (`Frontend\Pages`), the
  dashboard tab API (`Frontend\Dashboard`), access control (`Frontend\Access`) and content gating
  (`Frontend\Restrict`). Templates are in `templates/`.
- **Shared code:** import from `FavrMembers\Vendor\FavrCore\…`. Never edit `vendor-prefixed/`; change
  favr/core instead and re-run `composer update favr/core`.
