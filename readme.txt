=== Affiniti WP Support ===
Contributors: dataaffiniti
Donate link: https://dataaffiniti.com/
Tags: support, tickets, service desk, complaints, rest api
Requires at least: 6.4
Tested up to: 6.8
Requires PHP: 8.0
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Self-contained product service/complaint ticket system. Guest ticket form via shortcode, staff admin panel, and a versioned REST API (wpsd/v1) for mobile apps and partner systems. No external SaaS required.

== Description ==

Affiniti WP Support handles product service and complaint tickets end to end:

* **Guest ticket form** — add `[wpsd_ticket_form]` to any page. Cascading District → Thana → Route → Service Center dropdowns, dynamic Brand → Product list, category-aware problem types, Bangladesh mobile validation, and an inline ticket-number confirmation. No page reload.
* **Guest status lookup** — add `[wpsd_ticket_lookup]` so customers can check a ticket with its number plus the mobile number used at submission (ownership-checked, never an open lookup).
* **Staff admin panel** — Support Desk menu with ticket DataTable (status/district/service-center/product/assignee filters, search, pagination), staff ticket creation and full ticket editing (customer, location with hierarchy re-check, product with snapshot refresh), single-ticket thread with internal notes, status/priority/assignment controls, lookup-table manager with proper per-table columns and add/edit forms, API-client manager (generate/rotate/revoke keys, scopes, rate limits), dashboard counts plus average resolution time (cached 5 minutes), and a wp-admin dashboard widget.
* **REST API (`wpsd/v1`)** — the React UIs and external integrators consume the same endpoints: ticket create/list/read, replies, admin status updates, cascading lookups, admin lookup CRUD, admin API-client CRUD. Consistent `{success, data, error}` envelope with correct HTTP status codes. See "REST API reference" below or `docs/openapi.yaml` in the plugin folder.
* **Integrator auth** — per-client API key + secret (`X-WPSD-API-Key` / `X-WPSD-API-Secret`), optional HMAC-SHA256 replay protection for mobile apps, per-client rate limits and scopes (`create_only`, `create_and_read`, `full`), optional IP allow-lists, HTTPS enforced in production.
* **Notifications** — pluggable SMS gateway (`wpsd_send_sms` filter / `wpsd_sms_gateway` action; wire SSL Wireless, Alpha SMS, D7, or Twilio without editing core), email fallback, customer confirmation on creation, SMS on status change, email to newly assigned agents.
* **Data model** — proper relational tables via `$wpdb` (location hierarchy, product catalog, problem categories, ticket threads, attachments, API clients) with foreign-key-consistent validation on every write path and indexed queries. Product/brand names are snapshotted onto each ticket so catalog renames never rewrite history.

No Node.js is required on the production server. The admin and public apps ship as committed compiled bundles (`assets/admin-dist/`, `assets/public-dist/`); the React/Vite sources (`assets/admin-src/`, `assets/public-src/`, `package.json`) are included for developers. Rebuild with `npm run build` (see `docs/api.md`).

Spec decisions locked for V1 (from specs.md open questions): a route may serve **many** service centers (filtered list; auto-selected when exactly one exists); brand is **dynamic per product**; problem types are **category-specific with global fallback**; SMS goes through a **pluggable gateway** (email fallback); guest **status lookup is included**; external list access requires the **`full` scope**; per-client **scopes are enforced**; strings are **i18n-ready English** (Bangla translations welcome via the `.pot` file).

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/` (folder name must stay `affiniti-wp-support`).
2. Activate the plugin through the 'Plugins' menu in WordPress. Tables, the "Support Agent" role, capabilities, and sample lookup data are created automatically.
3. Add `[wpsd_ticket_form]` to a page for the public form (optionally `[wpsd_ticket_lookup]` for status checks).
4. Open **Support Desk** in wp-admin to manage tickets. Go to **Support Desk → Import / Settings** for CSV imports and rate-limit settings.

== Frequently Asked Questions ==

= What are the requirements? =

WordPress 6.4 or higher and PHP 8.0 or higher. No Node.js, no external SaaS, no extra plugins.

= How do guest users submit tickets? =

Publish a page containing the `[wpsd_ticket_form]` shortcode. Submissions are validated server-side (including the full location hierarchy) regardless of what the browser sends.

= How do customers check ticket status? =

Publish `[wpsd_ticket_lookup]`. They enter the ticket number plus the mobile number from the ticket. Both must match.

= How do external apps authenticate? =

Create a client under Support Desk → API Clients tab. Send `X-WPSD-API-Key` and `X-WPSD-API-Secret` headers. Mobile apps should additionally sign requests (`X-WPSD-Timestamp` + `X-WPSD-Signature` = HMAC-SHA256 of `timestamp.body`). See `docs/api.md` and `docs/openapi.yaml`.

= Which SMS gateway is used? =

None is hardcoded. Out of the box the plugin sends email and fires the `wpsd_send_sms` filter so you can plug in SSL Wireless, Alpha SMS, D7, Twilio, or others with a few lines in your theme or a small add-on. `docs/api.md` includes a sample integration.

= Does uninstall delete my data? =

No. Data is preserved by default. Tick "Delete all ticket data on uninstall" under Support Desk → Import / Settings first, and tables are dropped on uninstall only then.

== Screenshots ==

1. Public ticket form with cascading location dropdowns (shortcode).
2. Admin ticket list with filters, search, and pagination.
3. Single ticket view with reply thread and status controls.
4. API client manager with one-time secret display.

== Changelog ==

= 1.0.0 =
* Initial release: ticket CRUD, cascading lookups, admin SPA, public form + lookup shortcodes, versioned REST API with key/secret/HMAC auth, rate limiting, CSV import/seed, notifications, attachments.

== Upgrade Notice ==

= 1.0.0 =
First release. No upgrade steps.

== REST API reference ==

Base: `/wp-json/wpsd/v1/`. Every response uses `{ "success": bool, "data": object|null, "error": {"code","message"}|null }` with correct HTTP codes (400/401/403/404/409/429/500).

* `POST /tickets` — create (web, public UI, external; identical validation; guest web calls include `wpsd_nonce`; external calls use API headers). 201 + `{ticket}`.
* `GET /tickets/{ticket_number}?mobile=` — guest read with ownership check; staff get the full thread; scoped API clients get read access per scope.
* `GET /tickets` — list/search, paginated (`page`, `per_page` default 20 max 100; filters: `status`, `district_id`, `service_center_id`, `product_id`, `assigned_agent_id`, `search`). Staff only, or `full`-scope API clients.
* `POST /tickets/{id}/replies` — staff-only reply / internal note.
* `PATCH /tickets/{id}` — staff-only status/priority/assignment plus editable customer/location/product fields. Never exposed to API clients in V1.
* `GET /tickets/by-id/{id}` — staff-only single view with full thread and attachments.
* `DELETE /tickets/{id}` — `wpsd_delete_tickets` cap only (administrators).
* `POST /tickets/{id}/attachments` — staff-only file upload (JPG/PNG/WEBP/PDF, 5 MB).
* `GET /lookups/districts | /lookups/thanas?district_id= | /lookups/routes?thana_id= | /lookups/service-centers?route_id= | /lookups/products?brand= | /lookups/problem-types?product_id=` — public, cached.
* `GET|POST /admin/lookups/{type}`, `PUT|DELETE /admin/lookups/{type}/{id}` — staff-only reference-data management.
* `GET|POST /admin/api-clients`, `DELETE /admin/api-clients/{id}`, `POST /admin/api-clients/{id}` (`{"action":"rotate"}`) — staff-only. Secrets are returned once.
* `GET /admin/stats` — staff-only dashboard numbers (5-minute cache).

Error codes include `wpsd_validation_failed` (with per-field details), `wpsd_bad_hierarchy`, `wpsd_duplicate_ticket` (409), `wpsd_rate_limited` (429 + `Retry-After`), `wpsd_bad_nonce`, `wpsd_unauthorized`, `wpsd_forbidden`, `wpsd_https_required`, `wpsd_owner_required`, `wpsd_not_found`, `wpsd_in_use`.

== Developer notes ==

* Build frontend: `npm install && npm run build` (outputs committed under `assets/*-dist/`).
* CSV samples: `includes/data/seed-*.csv` (header-row format documented in Import / Settings).
* Actions/filters: `wpsd_ticket_created`, `wpsd_ticket_updated`, `wpsd_send_sms`, `wpsd_sms_gateway`, `wpsd_public_rate_limit_per_ip`, `wpsd_public_rate_limit_per_mobile`, `wpsd_duplicate_window_seconds`, `wpsd_require_ssl`, `wpsd_allowed_attachment_mimes`, `wpsd_max_attachment_bytes`.
* Capabilities: `wpsd_view_tickets`, `wpsd_manage_tickets`, `wpsd_assign_tickets`, `wpsd_delete_tickets`; role `wpsd_support_agent` (view/manage/assign, no delete).
