# CLAUDE.md

## What this is

**Favr Members** (namespace `FavrMembers\`, data prefix `favr_member_`) is part of the Favr Sites
suite. The suite spec lives in Favr Directory: `docs/specs/2026-09-23-favr-suite.md`. PHP 8.1+,
WP 6.7+. No payments here: dues, invoices and renewals live in Favr Payments, which hooks in
through `Model\Membership` (the one place a member becomes active), `favr_members_statuses`,
`favr_members_approved_status` and the `favr_members_register_*` / `favr_members_application*` hooks.

## Commands

```bash
composer install   # also runs Strauss → vendor-prefixed/ and copies favr/core assets → assets/core/
composer test      # PHPUnit + Brain Monkey (pure logic)
composer lint      # WPCS, keep at 0 errors
```

`wp eval-file tests/integration/gating.php` checks members-only pages and posts through every way
a post's text is handed out (about 190 checks, including whole pages fetched over HTTP, an
Elementor page and an Elementor Pro Loop Grid). It makes its own posts and people and removes
them, so it can run on any local site. Run it after any change to `Restrict` or `Elementor`.
Not covered by it: SEO plugins (none installed locally), page caches that serve logged-in
users, and Elementor's alpha Markdown experiment for per-element visibility rules.

Local test site: `http://sermonator-test.local/`, with this repo symlinked to
`wp-content/plugins/favr-members`. `wp favr-members seed` loads sample members. (The site's UTMwp plugin, which fataled on
`user_register`, has been disabled.)

## Architecture

- **One Member record per membership** (`favr_member` CPT) with type `individual` | `business`.
  Fields are defined once in `Model\Fields` (a favr/core `FieldSet`); type-specific fields use
  `conditions` on `type`.
- **Logins** are WordPress users with the `favr_member_person` role, linked by multi-value meta
  `favr_member_user` (one row per user). `Model\Repository::forUser()` answers "which memberships
  does this person hold or represent".
- **Every change fires `favr_members_member_saved`** (admin save, import, auto-lapse, application).
  `Integration\Directory` listens and creates, adopts and syncs the business listing. It never
  creates listings for non-active members, and never adopts by name for applications
  (`Directory::mayAdopt()`).
- **Directory editing** (`Integration\DirectoryEditing`) grants listing edit rights to representatives
  of active business members and routes claims/invites onto the member record, all through Favr
  Directory filters. `Admin\ApplicationsQueue` adds applications to the shared Approvals inbox.
- **The front end** is PRG form handlers (`Frontend\Auth`), shortcodes (`Frontend\Pages`), the
  dashboard tab API (`Frontend\Dashboard`), access control (`Frontend\Access`) and content gating
  (`Frontend\Restrict`). Templates are in `templates/`.
- **Page builders** (ADR 0002 in Favr Directory): one renderer per feature; the shortcode, block
  and Elementor widget (`Integration\Elementor\*`, base class in favr/core) are thin adapters.
  Never reference Elementor classes outside callbacks of Elementor's own hooks.
  Elementor visibility (`Integration\Elementor`) marks gated elements dynamic so the element cache never serves them.
- **Members-only pages and posts** (`Frontend\Restrict`, post meta `_favr_members_only`). A lot of
  code reads a post directly, so filtering rendered output is not enough. Three layers:
  1. `post_password_required` answers "yes" for a members-only post the viewer may not read
     (`asProtected()`); core, feeds, REST, comments, the embed card, core blocks and Elementor
     all ask it. The password form becomes the members box, core's "protected post" wording is
     swapped through `gettext`.
  2. Every query on a request that hands posts to a visitor (`handsOut()`: not wp-admin screens,
     cron or WP-CLI) gets such a post as a **copy** with text and excerpt taken out
     (`withhold()`, `withholdCurrent()`), for code that reads `$post->post_excerpt` itself (the
     theme's description tag, Elementor's page config, Elementor Pro's Post Excerpt on a Loop
     Grid card). Copies carry `Restrict::WITHHELD`; `neverSaveWithheld()` stops one being saved
     over the real post. The object cache keeps the real post (core re-reads a filtered query).
  3. `the_content` in two halves (priority 5 empties, 99999 prints the box, so `wpautop` cannot
     mangle it), plus comments, title-only search (`searchTitlesOnly()`), sitemap, noindex.
  Who gets in: current members, and staff (`isStaff()`: can edit the post **or** edits other
  people's posts of that type, because Favr Sites' page lock denies `edit_post` to Editors).
  `alwaysOpen()`: the log-in, account and join pages are never members-only
  (`favr_members_always_open_pages` lets Favr Payments add its pay page).
- **Elementor and the page switch** (`Integration\Elementor`): the switch has one home, the post
  meta. Elementor leaves settings that still have their default out of a save, and builds one
  set of controls per kind of document, so: the control's default is always off with
  `save_default`; `documentConfig()` shows the page's real state when the editor opens;
  `documentSaving()` takes the switch out of what Elementor stores; `documentSaved()` applies it
  only when the save mentioned it and was the page itself (not an autosave). Never read
  "not mentioned" as "off". Decide by the document being rendered (`buildingId()`), never
  `get_the_ID()`: header, footer and loop templates render on every page.
- **Shared code:** import from `FavrMembers\Vendor\FavrCore\…`. Never edit `vendor-prefixed/`; change
  favr/core instead and re-run `composer update favr/core`.
