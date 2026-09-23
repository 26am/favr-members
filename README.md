# Favr Members

Member records, member logins and a member dashboard for **Chambers of Commerce** and
**associations**. It's part of Favr Sites, alongside [Favr Directory](https://github.com/26am/favr-directory),
and is designed for sites with a mix of **individual** and **business** members.

- **Requires:** WordPress 6.7+, PHP 8.1+
- **No payments:** membership status and renewal dates are managed by staff.
- **Design:** see the suite spec in Favr Directory:
  [`docs/specs/2026-09-23-favr-suite.md`](https://github.com/26am/favr-directory/blob/main/docs/specs/2026-09-23-favr-suite.md)

## The model

Every membership is one **Member** record (`favr_member`) with a type chosen once:

| | Individual | Business |
| --- | --- | --- |
| Staff fill in | Name, email, phone, level, status, dates | Business name, level, status, dates |
| Who logs in | The member | One or more **representatives** |
| Directory listing | None | Linked automatically (with Favr Directory) |

The edit screen only shows the fields for the chosen type, so individual members stay simple.

## Features

**Staff (wp-admin → Members)**
- Tabbed member form (Details, Address, Membership), level picker, and a **Login access** box:
  - create a login from the record in one click, and email an invite to set a password
  - add business representatives by email
  - resend login emails and remove access
- List with type, level, status, overdue renewals, logins and contact details. Filters by
  status/type/level, a pending-applications count in the menu, and a Memberships column on the
  Users screen.
- Membership levels are shared with Favr Directory (display order and badge color).
- Settings: member pages, invite-only or open applications, directory behaviour, automatic lapsing
  with a grace period, and the email sender name.
- CSV import/export with a test run (`logins` column: emails separated by `|`).

**Members (front end)**
- `[favr_login]`: login, forgot password, and choose a new password. Invite and reset emails
  link here, never to wp-admin.
- `[favr_account]`: the dashboard. The Overview tab shows each membership held or represented
  (status, level, renewal, member ID). The My Profile tab has contact details and a password
  change.
- `[favr_register]`: membership application. Individual or business, with a honeypot and rate
  limiting. Applications arrive as **Pending** and applicants are emailed when approved.
- People with only the Member role never see wp-admin or the admin bar.

**Members-only content**
- The **Members Only** block wraps any content.
- `[favr_members_only]…[/favr_members_only]`
- A "Members only" toggle in the page/post sidebar hides the whole content and adds noindex.
- Staff who can edit the page always see everything.

## With Favr Directory

- Saving an **active** business member creates its listing. For records staff create or import, an
  existing unlinked listing with exactly the same name is linked instead; applications are never
  linked automatically (staff choose "Use an existing listing" on the member screen). Pending
  applications never reach the public directory.
- **Representatives of an active business member edit its listing** from the dashboard's
  **My Listing** tab (Directory's field policy decides what goes live and what staff approve).
  Approved listing claims and staff invites from the listing screen add the person to the member
  record.
- Level, member since, renewal date and member ID are copied onto the listing, which shows them
  read-only with an "Edit member →" link.
- Optionally, lapsed or inactive businesses' listings are hidden and restored when they renew.

## Approvals

With Favr Directory or Favr Events active, staff get one **Approvals** screen. Members adds
**Membership applications**: approving activates the membership (and member-since date), emails
the applicant and, for businesses, creates the listing. Declining marks the application inactive
and emails the note.

## For developers

```php
favr_members_is_active( $user_id );        // Active individual membership OR represents an active business.
favr_members_get_memberships( $user_id );  // list<FavrMembers\Model\Member>

// Add a dashboard tab (Favr Directory's "My Listing" and Favr Events' "My Events" use this).
add_filter( 'favr_members_dashboard_tabs', function ( array $tabs, WP_User $user ) {
	$tabs['events'] = array(
		'label'    => 'My Events',
		'priority' => 30,
		'render'   => fn ( WP_User $user ): string => '<p>…</p>',
	);
	return $tabs;
}, 10, 2 );
```

Hooks: `favr_members_member_saved` (member, context), `favr_members_fields`, `favr_members_tabs`,
`favr_members_is_active`, `favr_members_invite_email`, `favr_members_approval_email`,
`favr_members_membership_card`, `favr_members_after_tab`, `favr_members_template`,
`favr_members_loaded`. Templates are overridable from `yourtheme/favr-members/`.

WP-CLI: `wp favr-members seed | import <file> [--dry-run] [--send-invites] | export [--file=] | lapse`.

## Development

```bash
composer install   # also rebuilds vendor-prefixed/ (Strauss) and assets/core/ from favr/core
composer test
composer lint
```

Shared code comes from [favr/core](https://github.com/26am/favr-core). It's bundled under
`FavrMembers\Vendor\FavrCore\…` by Strauss and committed in `vendor-prefixed/`, so the plugin
installs from a plain zip, and different Favr plugins can ship different core versions safely.
