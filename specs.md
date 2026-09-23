# WordPress Plugin Spec: Support Ticket Management System
## (Product Service Requests — Web + REST API + React Admin/Public UI)

## Objective
Build a self-contained WordPress plugin ("wp-support-desk") for handling
product service/complaint tickets. Customers submit structured service
requests via a React-powered web form OR via REST API (external apps —
mobile apps, partner systems, IVR/call-center tools). Staff manage tickets
from a React-powered wp-admin panel. No external SaaS dependency for V1.

---

## Tech Constraints
- Pure PHP backend, WordPress Plugin API standards (hooks/filters, no
  framework bypass). PHP 8.0+, WordPress 6.4+.
- Use $wpdb with custom tables — NOT custom post types or ACF. This data
  has real relational structure (location hierarchy, product catalog,
  problem categories, ticket threads) that needs proper FKs and indexed
  queries, not postmeta.
- All DB writes via $wpdb->prepare(); no raw string concatenation, ever.
- Both the admin panel and the public form are React SPAs, built with
  Vite, using shadcn/ui components on Tailwind CSS. Neither ships raw
  JSX/TSX to the browser — only compiled bundles. No Node required on
  the production server; build happens in CI/dev only.
- Cascading dropdowns (District -> Thana -> Route -> Service Center) and
  all data operations go through the REST API (below) — React consumes
  the same wpsd/v1 endpoints as external integrators, not a separate
  admin-ajax pathway, except where WP-native auth (nonce) differs from
  external API-key auth (see Authentication).
- Namespace all PHP functions/classes under WPSD_.

---

## Data Model

### Lookup/reference tables (admin-managed, seeded via CSV import)
1. wp_wpsd_districts — id, name
2. wp_wpsd_thanas — id, district_id (FK), name
3. wp_wpsd_routes — id, thana_id (FK), name
4. wp_wpsd_service_centers — id, route_id (FK), name, address, contact_phone
5. wp_wpsd_products — id, brand, model_name, category
6. wp_wpsd_problem_types — id, product_category_id (FK, nullable if global), label

### Transactional tables
7. wp_wpsd_tickets
   - id, ticket_number (public-facing, e.g. TKT-2026-00123, unique)
   - customer_name, mobile, alternative_mobile (nullable)
   - district_id, thana_id, route_id, service_center_id (FK, required)
   - address (max 50 chars — enforce client + server side)
   - product_id (FK), brand_snapshot, product_name_snapshot (denormalized
     text captured at submission time — product catalogs change, don't let
     old tickets silently reflect renamed/discontinued products)
   - barcode (nullable, free text)
   - problem_type_id (FK)
   - comments (nullable, textarea)
   - status (enum: new/assigned/in_progress/resolved/closed/cancelled)
   - priority (low/med/high — not customer-facing; default 'med', agent-editable)
   - assigned_agent_id (nullable)
   - source (enum: web/api — track where each ticket originated)
   - created_at, updated_at
8. wp_wpsd_replies — id, ticket_id (FK), author_id, author_type (agent/
   customer/system), message, is_internal_note (bool), created_at
9. wp_wpsd_attachments — id, ticket_id, reply_id (nullable), file_path,
   original_filename, mime_type, uploaded_by, created_at
10. wp_wpsd_api_clients — id, client_name, api_key, api_secret_hash,
    is_active, rate_limit_per_minute, scope (create_only/create_and_read/
    full), allowed_ips (nullable), created_at, last_used_at

Indexes required: status, assigned_agent_id, district_id, service_center_id,
created_at, ticket_number (unique), api_key (unique).

---

## REST API (core deliverable — used by BOTH the React frontends and
## external third-party consumers)

### Namespace & Versioning
- wpsd/v1 — versioned from day one.

### Authentication (two distinct schemes — do not blur them)
- **Admin React app**: WP REST nonce (wp_create_nonce, injected via
  wp_localize_script), tied to the logged-in admin's WP capabilities.
- **Public React form**: no auth required for ticket creation (it's a
  guest-facing form), but protected by nonce + rate limiting to prevent
  bot abuse — this is NOT the same as external API-key auth.
- **External third-party consumers**: dedicated API key + secret per
  client (wp_wpsd_api_clients table), sent via custom headers
  (X-WPSD-API-Key / X-WPSD-API-Secret), with HMAC signing over
  body+timestamp if any consumer is a mobile app (prevents replay attacks
  from extracted keys). Reject all non-HTTPS requests in production
  (is_ssl() check, fail closed).
- Admin screen to generate/revoke/rotate API keys per client — never a
  hardcoded key in wp-config.php.

### Endpoints (V1)
- POST   /wpsd/v1/tickets                    — create ticket (web form,
  React public UI, and external API all hit this same endpoint; identical
  validation regardless of caller, including full location-hierarchy check)
- GET    /wpsd/v1/tickets/{ticket_number}    — fetch single ticket
  (ownership check required: matching mobile number, or elevated API scope
  — never an open lookup-by-ID with no ownership check)
- GET    /wpsd/v1/tickets                    — list/search (admin: full
  access; external API clients: scoped to what their `scope` allows —
  a partner should not enumerate every ticket in the system)
- POST   /wpsd/v1/tickets/{id}/replies       — add reply/comment
- PATCH  /wpsd/v1/tickets/{id}                — admin-only status/priority/
  assignment update (nonce-authenticated, capability-checked; NOT exposed
  to external API clients in V1)
- GET    /wpsd/v1/lookups/districts
- GET    /wpsd/v1/lookups/thanas?district_id=
- GET    /wpsd/v1/lookups/routes?thana_id=
- GET    /wpsd/v1/lookups/service-centers?route_id=
- GET    /wpsd/v1/lookups/products
- GET    /wpsd/v1/lookups/problem-types?product_id=
- CRUD /wpsd/v1/admin/lookups/{type}          — admin-only management of
  lookup tables (districts/thanas/routes/service-centers/products/problem-types)
- CRUD /wpsd/v1/admin/api-clients              — admin-only API client management

### Explicitly OUT of scope for REST API V1
- External clients performing status/assignment changes or deletions
  (admin-only, via PATCH above, nonce-authenticated)
- OAuth2 flows — API key + HMAC is sufficient for V1 server-to-server
- Webhooks — flag as V2 if requested, don't build speculatively

### Rate Limiting & Abuse Prevention
- Per-API-key rate limiting (transient-based or dedicated counter table),
  configurable per client
- Same duplicate/spam prevention (same mobile + same problem type within
  a short window = reject/flag) applies to web AND API submissions equally

### Response Format
- Consistent JSON envelope: { "success": bool, "data": {...}|null,
  "error": {"code": string, "message": string}|null }
- Correct HTTP status codes (400/401/403/404/429/500) — never 200 with a
  buried error object

### Documentation
- OpenAPI/Swagger spec or a clear README section: every endpoint, headers,
  request/response shapes, error codes — external integrators need this
  without reading PHP source

---

## Frontend: React + shadcn/ui (BOTH admin and public — locked decision)

### Shared setup
- Vite build, Tailwind CSS with a shared design-tokens file (colors,
  spacing, radius, font) so admin and public UI feel like one product
- shadcn/ui components copied into /assets/{admin,public}-src/components/ui/
  per shadcn convention (not an npm package)
- Tailwind scoped/prefixed (e.g. prefix 'wpsd-') to avoid bleeding into
  or being overridden by wp-admin's native styles
- react-hook-form + zod for all form validation (client-side; server-side
  validation in the REST layer is still mandatory and authoritative —
  client validation is UX only, never trust it alone)

### Admin React App (mounted in wp-admin via a single root div)
- Screens: Ticket list (DataTable, filters: status/district/service-center/
  product/assigned agent, search by ticket number/mobile/name), Single
  ticket view (reply thread, internal notes, status/priority/assignment
  controls), Lookup table management (CRUD screens for all reference
  tables), API Client management (generate/revoke/rotate keys, set scope
  and rate limits, view last-used + basic request log), Dashboard widgets
  (ticket counts by status, avg resolution time — cached via transients,
  5 min TTL)
- Auth: WP REST nonce tied to logged-in admin capabilities
  (wpsd_view_tickets, wpsd_manage_tickets, wpsd_assign_tickets,
  wpsd_delete_tickets — register a "Support Agent" role with view/manage/
  assign but not delete; site admins get all)

### Public React App (mounted via shortcode, e.g. [wpsd_ticket_form])
- Section 1 — Basic Information: Name*, Mobile* (server-side BD phone
  format validation), Alternative Mobile, District* -> Thana* -> Route*
  (cascading, each disabled until parent selected, powered by the lookup
  endpoints), Address (House/Road)* (max 50 chars), Product*, Service
  Center* (behavior depends on Open Question #1 below — resolve before
  building this control's logic)
- Section 2 — Incident Information: Brand* (behavior depends on Open
  Question #2), Barcode (optional), Select your problem* (dropdown,
  required), Comments (optional textarea)
- Submit: client-side validation via react-hook-form/zod, then POST to
  /wpsd/v1/tickets, inline field errors on failure, ticket confirmation
  number + SMS/email confirmation on success — no full page reload
- Explicit trade-off acknowledged and accepted: this bundle adds JS
  weight to a public-facing page hit by mobile/guest users, and has no
  no-JS fallback. Accepted per product decision; mitigate with route-level
  code-splitting and keeping the public bundle as lean as possible
  (don't import unused shadcn components into this bundle)

### Build & Deliverables
- /assets/admin-src/, /assets/admin-dist/ (Vite output, enqueued via
  wp_enqueue_script)
- /assets/public-src/, /assets/public-dist/ (same pattern)
- package.json, vite.config.js, tailwind.config.js committed, with a
  documented build command in readme.txt

---

## Notifications
- SMS on ticket creation (confirm gateway — e.g. SSL Wireless/Alpha SMS
  for BD — before build; email as fallback, not primary, given mobile-
  first user base)
- Notify assigned service center/agent on new ticket in their jurisdiction
- SMS on status change

---

## Security Requirements (non-negotiable)
- Nonce verification: admin React app calls + public form submission
- API key + HMAC + HTTPS enforcement: all external REST access
- Sanitize all input (sanitize_text_field, sanitize_email, phone
  validation, wp_kses for comments); escape all output
- Rate-limit: public form (per IP/mobile) AND external API (per key)
- Server-side validation of the full district->thana->route->service-
  center hierarchy on every ticket creation path (web, admin, API) —
  never trust client-sent IDs, disabled dropdowns are a UX hint, not a
  security control
- File upload validation: MIME whitelist, randomized filenames, block
  direct PHP execution in the upload directory

---

## Explicitly OUT of scope for V1 (plugin-wide)
- SLA automation / escalation rules
- Live chat
- Knowledge base / FAQ builder
- Multi-language support (confirm upfront — see Open Questions)
- OAuth2 / webhooks for REST API
- External API clients modifying ticket state (status/assignment/deletion)

---

## Performance
- Cache all lookup tables via WP transients, shared source for both
  React apps' lookup calls and any external API lookup calls
- Paginate: admin ticket list AND GET /tickets (default 20/page, max
  100/page — never let a client request unlimited rows)
- Code-split the public React bundle; keep it as small as realistically
  possible given the mobile/field-user context

---

## Open Questions to Resolve Before Build (do not let Muse silently
## assume answers to any of these)
1. Is Service Center auto-assigned 1:1 with Route, or a filtered list if
   multiple centers serve one route? Changes the cascading dropdown/API logic.
2. Is Brand always fixed (single-brand site, e.g. "WALTON" hardcoded) or
   dynamic per Product selection?
3. Are Problem Types global or product-category-specific?
4. SMS gateway provider, budget, and rate limits?
5. Does the web/public form need a "check ticket status" guest lookup
   feature, or is it fire-and-forget submission only? (Not present in
   the original mockup — confirm intentional, not forgotten.)
6. Who are the actual external REST API consumers (internal mobile app?
   third-party partner? call-center software?) — determines whether
   "list all tickets" scope should exist for any external client at all.
7. Per-client API scoping needed (Client A create-only, Client B
   create+read) or is one uniform permission level acceptable for V1?
8. Multi-language (Bangla/English) requirement — in scope for V1 or not?

---

## Deliverables
- Plugin folder structure per WP standards (main plugin file, /includes,
  /admin, /public, /assets)
- Activation hook: create custom tables via dbDelta(), seed lookup tables
  via CSV import mechanism
- Uninstall hook: optional data cleanup with confirmation
- Inline PHPDoc on all PHP classes/methods
- OpenAPI/Swagger doc or README API reference
- readme.txt in WP.org plugin format