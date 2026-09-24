# Affiniti WP Support — integrator & developer guide

## 1. Spec decisions (specs.md open questions — resolved, not assumed silently)

| # | Question | V1 decision |
|---|----------|-------------|
| 1 | Service Center 1:1 with Route, or filtered list? | Filtered list (1:many supported). When exactly one center serves a route the UI pre-selects it, but the API still enforces center-in-route on every write. |
| 2 | Brand fixed or dynamic per Product? | Dynamic. `products.brand` drives the Brand dropdown; Product list filters by brand; both are snapshotted onto the ticket. |
| 3 | Problem Types global or per-category? | Per-category with global fallback (`product_category` nullable; lookup returns category matches + globals). |
| 4 | SMS gateway? | Pluggable. No provider hardcoded. Hook `wpsd_send_sms` / `wpsd_sms_gateway`; email is the built-in fallback. Sample integration below. |
| 5 | Guest "check ticket status"? | Included: `[wpsd_ticket_lookup]` shortcode + lookup tab logic in the public bundle (ticket number + mobile ownership check). |
| 6 | Who are the external consumers? | Unknown at build time, so least privilege: only `full`-scope clients may list/enumerate; narrower scopes are enforced server-side. |
| 7 | Per-client scoping or uniform? | Per-client scopes enforced: `create_only` / `create_and_read` / `full`. |
| 8 | Multi-language V1? | i18n-ready English V1. All strings wrapped (`affiniti-wp-support` domain, `.pot` shipped); no hardcoded Bangla. |

## 2. REST API

Base URL: `https://example.com/wp-json/wpsd/v1/`

Envelope (always): `{ "success": bool, "data": object|null, "error": {"code": string, "message": string}|null }`
with correct HTTP codes. Full machine-readable contract: `docs/openapi.yaml`.

### 2.1 Ticket creation (shared by web, public UI, external)

`POST /tickets` — `Content-Type: application/json`

Guest web body: all ticket fields + `wpsd_nonce` (localized as `WPSD_PUBLIC_CONFIG.nonce`, action `wpsd_public_form`).
External body: same fields, no nonce; instead send headers:

```
X-WPSD-API-Key: wpsd_...
X-WPSD-API-Secret: wpss_...
```

Optional replay protection (recommended for mobile apps):

```
X-WPSD-Timestamp: 1710000000
X-WPSD-Signature: hex(hmac_sha256(timestamp + "." + raw_body, api_secret))
```

Timestamp window: ±300 s. HTTPS is required in production (`is_ssl()` fail-closed;
relax only for local dev via `add_filter('wpsd_require_ssl','__return_false')`).

Example:

```bash
curl -X POST https://example.com/wp-json/wpsd/v1/tickets \
  -H 'Content-Type: application/json' \
  -H 'X-WPSD-API-Key: wpsd_xxx' \
  -H 'X-WPSD-API-Secret: wpss_yyy' \
  -d '{"customer_name":"Rahim Uddin","mobile":"01712345678","district_id":1,"thana_id":1,"route_id":1,"service_center_id":1,"address":"House 5 Road 2","product_id":1,"problem_description":"Not cooling","comments":"Not cooling"}'
```

→ `201 {"success":true,"data":{"ticket":{...,"ticket_number":"TKT-2026-00123"}},"error":null}`

Validation notes: BD mobiles (`01XXXXXXXXX` / `+8801XXXXXXXXX` normalized server-side),
address ≤ 50 chars (client + server), full location-hierarchy check on every path,
duplicate guard (same mobile + problem description within 10 min → `409 wpsd_duplicate_ticket`),
per-IP/mobile rate limits (web) and per-key limits (API) → `429 wpsd_rate_limited` with `Retry-After`.

### 2.2 Reading tickets

* Guest: `GET /tickets/{ticket_number}?mobile=01712345678` (both must match; mismatch
  returns a generic message to prevent enumeration).
* Staff (cookie + `X-WP-Nonce`): full ticket + internal notes + attachments.
* API clients: `create_and_read`/`full` may read single tickets; only `full` may `GET /tickets` list.

List: `GET /tickets?status=new&page=1&per_page=20&search=TKT&district_id=&service_center_id=&product_id=&assigned_agent_id=&orderby=created_at&order=desc`
(`per_page` default 20, max 100).

### 2.3 Staff-only writes (never exposed to API clients in V1)

* `PATCH /tickets/{id}` `{"status":"in_progress","priority":"high","assigned_agent_id":5}`
  (nonce + `wpsd_manage_tickets`; assignment additionally needs `wpsd_assign_tickets`).
  Staff may also correct customer fields (`customer_name`, `mobile`,
  `alternative_mobile`, `address`, `barcode`, `comments`), relocate a ticket by
  sending a complete `district_id`/`thana_id`/`route_id`/`service_center_id` set
  (hierarchy revalidated), change `product_id` (snapshot refreshes), or edit
  `problem_description` independently. Partial location updates are rejected.
* `GET /tickets/by-id/{id}` — staff-only single view with full thread (internal
  notes included) and attachments. Used by the admin app.
* `POST /tickets/{id}/replies` `{"message":"...","is_internal_note":false}`.
* `POST /tickets/{id}/attachments` multipart `file` (JPG/PNG/WEBP/PDF ≤ 5 MB).
* `DELETE /tickets/{id}` (`wpsd_delete_tickets` — administrators).

### 2.4 Lookups (staff session, API key, or the public-form nonce)

`GET /lookups/districts`, `/lookups/thanas?district_id=`, `/lookups/routes?thana_id=`,
`/lookups/service-centers?route_id=`, `/lookups/products?search=` (live WooCommerce catalog).
Not fully open — see `can_use_lookup()` in `class-wpsd-rest.php`.

### 2.5 Admin lookup & API-client CRUD

* `GET|POST /admin/lookups/{type}` and `PUT|DELETE /admin/lookups/{type}/{id}`
  (`{type}` = districts, thanas, routes, service-centers). Products come from
  WooCommerce; problem is free text on the ticket — neither is managed here.
  Delete is refused with `409 wpsd_in_use` when tickets reference the row.
* `GET|POST /admin/api-clients`, `DELETE /admin/api-clients/{id}`,
  `POST /admin/api-clients/{id}` `{"action":"rotate"}`.
  The secret is returned **once** on create/rotate (stored `password_hash`ed only).

## 3. SMS gateway integration (sample)

```php
// Theme functions.php or small add-on plugin.
add_filter( 'wpsd_send_sms', function ( $sent, $to, $message ) {
    $response = wp_remote_post( 'https://api.example-sms.com/send', array(
        'timeout' => 15,
        'body'    => array(
            'api_key' => defined( 'MY_SMS_KEY' ) ? MY_SMS_KEY : '',
            'to'      => $to,
            'message' => $message,
        ),
    ) );
    if ( ! is_wp_error( $response ) && 200 === wp_remote_retrieve_response_code( $response ) ) {
        return true; // Mark as sent.
    }
    return $sent;
}, 10, 3 );
```

## 4. Frontend builds

Requirements (dev/CI only): Node 18+.

```bash
npm install
npm run build            # or: vite build --config vite.admin.config.js
                         #      vite build --config vite.public.config.js
```

Outputs are committed (`assets/admin-dist/wpsd-admin.{js,css}`,
`assets/public-dist/wpsd-public.{js,css}`) so production servers need no Node.
Tailwind uses the `wpsd-` prefix; shared tokens live in `assets/shared/tokens.css`;
shadcn-style primitives are copied per convention into the `*-src` trees
(`styles.css` + component files), not installed as an npm UI package.

Shortcodes: `[wpsd_ticket_form class="..."]`, `[wpsd_ticket_lookup class="..."]`.
Bundles enqueue only on pages containing the shortcodes.

## 5. Data, import, uninstall

* Tables are created with `dbDelta()` on activation; sample data seeds from
  `includes/data/seed-*.csv` (idempotent — skips non-empty tables).
* Re-import/extend via **Affiniti Support → Import / Settings** (header-row CSVs;
  thanas/routes/centers resolve parents by name).
* Uninstall preserves data unless "Delete all ticket data on uninstall" is enabled.

## 6. Hooks reference

Actions: `wpsd_ticket_created ($ticket)`, `wpsd_ticket_updated ($before, $after)`,
`wpsd_sms_gateway ($to, $message)`.
Filters: `wpsd_send_sms ($sent, $to, $message)`, `wpsd_public_rate_limit_per_ip`,
`wpsd_public_rate_limit_per_mobile`, `wpsd_public_rate_window_ip`,
`wpsd_duplicate_window_seconds`, `wpsd_require_ssl`, `wpsd_allowed_attachment_mimes`,
`wpsd_max_attachment_bytes`.
Capabilities: `wpsd_view_tickets`, `wpsd_manage_tickets`, `wpsd_assign_tickets`,
`wpsd_delete_tickets`; role `wpsd_support_agent` (no delete).
