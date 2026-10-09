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
a post's text is handed out (about 260 checks, including whole pages fetched over HTTP, an
Elementor page and an Elementor Pro Loop Grid). It makes its own posts and people and removes
them, so it can run on any local site. Run it after any change to `Restrict` or `Elementor`.
Not covered by it: page caches that serve logged-in users, Elementor's alpha Markdown
experiment for per-element visibility rules, and SEO plugins (checked by hand with Yoast SEO
and Rank Math on 2026-10-09: nothing hidden in their tags; Rank Math's sitemap not run).
After a change to the Elementor switch, also drive the real editor: look at a revision then
publish, visit Site Settings with an unpublished switch, and publish from a tab that was open
while the flag changed elsewhere.

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
     cron or WP-CLI) gets such a post as a **copy** whose text and excerpt are the placeholder
     sentence (`withhold()`, `withholdCurrent()`), for code that reads `$post->post_excerpt`
     itself (the theme's description tag, Elementor's page config, Elementor Pro's Post Excerpt
     on a Loop Grid card, SEO plugins). `neverSaveWithheld()` stops that sentence being saved
     over the real text. The object cache keeps the real post (core re-reads a filtered query).
  3. `the_content` in two halves (priority 5 empties, 99999 prints the box, so `wpautop` cannot
     mangle it), plus comments, search, sitemaps (core's, Yoast's, Rank Math's), noindex.
  Search (`searchTitlesOnly()`): for anyone who may not read it, a members-only post is matched
  by its title alone, for the words asked for **and** the words excluded with "-". Never let
  WordPress's own search clause run against hidden text.
  Who gets in: current members, and staff (`isStaff()`): whoever wrote the post, can edit it, or
  edits other people's posts of that type (Favr Sites' page lock denies `edit_post` to Editors).
  A revision or autosave is as members-only as its page. `alwaysOpen()`: the log-in, account and
  join pages are never members-only (`favr_members_always_open_pages` lets Favr Payments add its
  pay page). Known wrinkle: such a page, if flagged, is still left out of search and sitemaps.
- **Elementor and the page switch** (`Integration\Elementor`). The switch has one home, the post
  meta; the page never stores a copy. What makes this hard, all learned from real failures:
  Elementor leaves settings that still have their default out of a save; it builds one set of
  controls per kind of document (nothing per page); showing a revision resets every setting to
  its default; switching to Site Settings or the header autosaves and reloads the page's
  settings; `after_save` also fires for autosaves. So:
  - the switch and a hidden companion (`favr_members_only_was`: "how it stood when the editor
    opened|session token") both default to empty with `save_default`, and `documentConfig()`
    fills them in whenever the editor loads the page;
  - a save changes the flag only when the switch differs from how it stood, or from what this
    editing session last did (`change()`; the last few sessions are remembered in post meta
    `_favr_members_switch`, not in a transient, so a cleared cache cannot lose it). A reset
    switch, a tab left open, or a tool's save that does not mention the switch changes nothing;
  - `documentSaving()` strips both from everything Elementor stores, with one exception: an
    autosave of a published page keeps the switch **when the person changed it**, as their
    unpublished change, and `documentConfig()` shows it again. Never keep a value there that
    `change()` does not call a change: Elementor autosaves by itself after a revision is
    applied and a minute after any edit, and a reset or stale switch kept in the autosave
    comes back looking deliberate;
  - the flag is set on `elementor/document/before_save` (after Elementor's permission check,
    before the text and status are stored), so a page first published with the switch on is
    never announced as published while still public.
  - Accepted wrinkle: after a revision has been shown, the panel shows the switch off although
    the page is still protected, until the editor reloads (a Site Settings round trip does).
  - Copies: a template saved from a page records the page (`_favr_members_copy_of`,
    `templateSaved()`). Which page: the one the editor is on (`editor_post_id` in the request);
    the save dialog's own `post_id` goes stale when the person moves to another page inside the
    editor. Protection spreads from a page to all its copies whenever the page's flag is set
    (`Restrict::copiesFollow()`); it is lifted again only from copies made while the page was
    public. A copy made while the page was members-only holds members-only text and is marked
    (`_favr_members_copy_kept`): it stays protected, also when the page is made public or
    deleted. The template library is readable by visitors through its feed and search, and
    anyone who can write posts may export a template (`guardTemplateExport()`,
    `guardTemplateBulkExport()`). An exported file imported again is a new, unprotected template.
  - Elementor Pro's render-one-element REST routes (posts-widget, refresh-loop, refresh-search)
    skip every page-view check. `guardRenderRoute()` covers any Elementor route that names a
    page. Four real bypasses shaped it, all differences between how the guard and Elementor
    read one request: letter case of the address; the page number's sign (Elementor uses
    `absint`); naming a second element (`element_id` and `widget_id`); and the parts of a
    request disagreeing (a JSON `null` beats the address for `get_params()` but not for
    `get_param()`). So `askedFor()` picks no winner: every page and element named in any part
    of the request is checked, and elements are looked up through Elementor's own
    `find_element_recursive/inner_elements` filter (components keep their insides elsewhere).
  Decide by the document being rendered (`buildingId()`), never `get_the_ID()`: header, footer
  and loop templates render on every page.
- **Shared code:** import from `FavrMembers\Vendor\FavrCore\…`. Never edit `vendor-prefixed/`; change
  favr/core instead and re-run `composer update favr/core`.
