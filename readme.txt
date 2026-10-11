=== Favr Members ===
Contributors: favrsites
Tags: members, membership, chamber of commerce, association, member dashboard
Requires at least: 6.7
Tested up to: 7.0
Requires PHP: 8.1
Stable tag: 1.2.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Member records (individual or business), member logins and a member dashboard for Chambers of Commerce and associations.

== Description ==

* One member record per membership: Individual or Business, with level, status, member since and renewal date.
* Staff create logins and email invites in one click; businesses can have several representatives.
* Front-end login, password reset, optional applications with staff approval, and a member dashboard other plugins can add tabs to.
* Members-only content: a block, a shortcode and a "Members only" switch on pages and posts.
* Optional automatic lapsing after the renewal date.
* Works with Favr Directory: business members get a linked listing whose membership details stay in sync.
* Dues, invoices and renewals are added by the separate Favr Payments plugin; without it, membership status is managed by staff.

== Changelog ==

= 1.2.2 =
* Admin wording in sentence case, like the rest of Favr: "All members", "Add member", "Edit member", "Search members"; "Membership levels" with "Add level" and "Edit level"; "Member settings"; "Import & export".
* Moving a member to the Trash or back says "1 member moved to the Trash." instead of "1 post…".
* The Level column of the Members list shows each level with its own color, like the Membership level box.
* Membership levels use word for word the labels Favr Directory gives them, so the Membership levels screen reads the same whichever of the two plugins a site runs.
* The Members list, Membership levels, Settings, Import & export and (with Favr Payments) Invoices screens no longer stop with the script error "sortable is not a function".
* Fixed: updating the plugin no longer removes capabilities another plugin or the site owner added to the Membership Manager and Member roles. An update adds what a role is missing and keeps the rest.

= 1.2.1 =
* Members-only pages and posts: hidden text could still reach non-members by side routes (a Latest Posts block showing full posts, a members-only Elementor page embedded in another page, Elementor Pro's load-more address, the excerpt in the page source and on Elementor Loop Grid cards, comment feeds, and search matching hidden words). All closed.
* Saving a members-only page in Elementor no longer switches the protection off, and flipping the switch takes effect when you publish, not at the next autosave. Looking at a revision, visiting Site Settings or publishing from a tab left open no longer changes it either.
* A template saved from a page is members-only whenever that page is.
* Elementor Pro's load-more and filter addresses can no longer be used to render protected content.
* The site header and footer are no longer replaced by the members box on members-only pages and on lists.
* The members box tells a signed-in person where they stand (no membership, application in review, lapsed, not active yet).
* Staff see "Members only" next to the title in the Pages and Posts lists and a note on the page itself. Editors who cannot edit a page are still let in.
* The log-in, account and join pages can never be members-only. Members-only pages are left out of the sitemap.

= 1.2.0 =
* Hooks for Favr Payments: extra member statuses, one shared way to activate a membership, fields on the join form and details in Approvals. No change for sites without Favr Payments.

= 1.1.0 =
* Members card (active members, renewals in the next 30 days) and "Add member" button on the Favr dashboard (Favr Sites plugin).

= 1.0.0 =
* First release.
