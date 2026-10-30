# Babilim API contract (plugin side)

This plugin talks to the Babilim translation service over the plugin's `BABILIM_API_BASE` constant (the product domain — the service's own API docs are the only place that literal is written). The **canonical contract** (JSON Schemas and request/response examples) lives in the service's private repository at `contract/v1/`; this file is a plain-English summary for anyone working on the plugin, and `tests/contract/v1/` in this repo is a pinned copy of that contract used by our own tests (see `tests/contract/PINNED`).

If this file and the schemas under `tests/contract/v1/schemas/` ever disagree, the schemas win — update this file to match, don't patch around it.

**Contract version:** 1.7.1

## Conventions

- Five endpoints need no `Authorization` header, each authenticated a different way: `POST /v1/sites` (registration itself), `POST /v1/sites/{id}/verify` (`{nonce, proof}` signature — no token exists yet at this point), `GET /v1/packages/{build_id}` (the `site`/`exp`/`sig` query-string signature — WordPress core's own `download_url()` update path never sends an `Authorization` header, so the signature has to be the only credential here), `GET /v1/health`, `GET /v1/meta/locales`. Every other endpoint sends `Authorization: Bearer <site_token>`.
- Every request sends `User-Agent: Babilim/{plugin_version}; {home_url}`.
- Errors are always `{"error": {"code": "...", "message": "...", "hint": "..."}}` — `hint` is always present; an empty string means there's no further hint (not `null`).
- Rate limits: 60 requests/minute per site (covers every `/v1/*` call except `GET /v1/packages/{build_id}`, which has its own independent bucket, and including `POST /v1/sites/{id}/verify`, keyed on the path's own `site_id` since no `site_token` exists yet at that point); 10 registrations/minute per IP; 10 verify attempts/minute per site (a second bucket layered on top of the 60/minute one, specific to `POST /v1/sites/{id}/verify`); `GET /v1/packages/{build_id}` has its own independent 60/minute-per-site bucket, keyed on the query string's `site` parameter rather than `Authorization` (this endpoint has none) — kept separate so a leaked signed download link can only ever exhaust its own budget, never the site's real Bearer-authenticated traffic; 500 downloads/day per site; the plugin's own `GET /wp-json/babilim/v1/challenge` self-throttles to 10/minute independently of all of the above. A `429` response carries `Retry-After` (seconds).
- The API never breaks old plugin versions. A retired call returns `426` with `plugin_outdated`; already-downloaded language files keep working regardless. Update the plugin to clear a `426`.
- IDs are fixed-format: `st_` + 12 lowercase hex (site), `bd_` + 12 (build), `rq_` + 12 (translation request), `fb_` + 12 (feedback), `tok_` + 32 (site_token).

## Loading priority

This is contractual, not just an implementation detail — the `status` values `inventory` returns (see below) and the plugin's own loading layer both have to agree with it, and `readme.txt` states it the same way for site owners:

- For a plugin's or theme's own text domain: a site-specific override (in `custom/`, if a site owner has set one) beats a matching translation Babilim provides, which in turn beats an official WordPress.org language pack or the plugin/theme's own bundled translation.
- For WordPress core's own `default` text domain: WordPress core's own official translation beats any supplementary strings Babilim provides for that domain.

A source only ever fills a gap left by everything ranked above it — it never overrides a higher-ranked source that already has the file.

## Endpoints

### `POST /v1/sites`
Register the site. No auth required.
Request: `home_url, wp_version, php_version, plugin_version, site_locale, locales[], install_pub`.
Response `201`: `{site_id, challenge: {nonce, expires_at}}`.

### `POST /v1/sites/{id}/verify`
Submit proof of ownership of the install key generated on first use — never on the activation hook itself, and never before the site owner has consented (this plugin's own `CLAUDE.md`, "Runtime constraints": "Zero outbound requests before consent"). No auth header — see Conventions.

The install key is an Ed25519 keypair (libsodium/`sodium_*`); `install_pub` sent to `POST /v1/sites` is base64 of the raw 32-byte public key (or the 44-byte SubjectPublicKeyInfo DER encoding of the same 32 bytes — the service normalizes either form before storing it; anything else is `422 unprocessable`). Request: `{nonce, proof}` — `proof` is `"b64:" + base64(...)` of the raw 64-byte Ed25519 detached signature (`sodium_crypto_sign_detached()`) of the ASCII bytes `"babilim-challenge-v1\n{site_id}\n{nonce}"`, produced with the private half of the keypair whose public half was sent as `install_pub`; it's the same value our own `GET /wp-json/babilim/v1/challenge?nonce=` endpoint would return for the same nonce.

Response `200`: `{site_token, verified, license: {kind, status}, quota}`.
`verified` is `false` (but `site_token` is still issued and the site still works) when the service can't reach `home_url` itself to double-check — e.g. local/intranet installs. Treat `verified:false` as informational only.
`401` `invalid_proof` (proof is malformed, or doesn't verify against `install_pub`; nonce stays pending, retry with a corrected proof), `400` `invalid_nonce` (nonce doesn't match this site's pending challenge), `410` `expired` (the 15-minute challenge TTL passed; re-register), `409` `conflict` (this site already has a `site_token` — use `rotate-token` instead; never retried automatically). This plugin bounds its own retries for `invalid_nonce`/`expired` to a small, fixed number of fresh-registration attempts before giving up and surfacing a stuck-verification flag, rather than looping forever.

### `POST /v1/sites/{id}/rotate-token`
Rotate the site token (old token stops working immediately). Auth required.
Request: `{}`. Response `200`: `{site_token, rotated_at}`.

### `GET /v1/sites/{id}`
Read current site status. Auth required.
Response `200`: `{site_id, host, verified, license, quota, last_seen_at, price?}`. (1.7.0) `price` is `{amount, currency}` (a decimal string and an ISO 4217 code), a one-time price per site domain; present only while the service offers checkout, and the same `price` may appear in the inventory response. Absent means the plugin shows no upgrade button.

### `POST /v1/sites/{id}/checkout`
(1.7.0) Ask for a checkout page for this site. Auth required. Request: `{}`. Response `201`: `{checkout_url, expires_at, price}`. The plugin opens `checkout_url` (the Babilim checkout page at babilim.org; the token is in the URL fragment and must be kept) in a new tab and does not retry this call. Errors: `already_licensed` (409), `checkout_unavailable` (403).

### `POST /v1/sites/{id}/restore`
(1.7.0) Move a purchase to this install using the restore code shown on the original one. Auth required. Request: `{restore_code}` (`rc_` + 32 hex characters). Response `200`: `{license: {kind, status}}`. Not retried. Errors: `not_found` (404, the code does not match), `rate_limited` (429).

### `POST /v1/sites/{id}/restore-code`
(1.7.0) Issue a fresh restore code; the previous one stops working. Auth required. Request: `{}`. Response `200`: `{restore_code}`. Not retried. Errors: `no_purchase` (409, this install is not bound to a purchase).

### `POST /v1/sites/{id}/inventory`
Sync installed components and locales; get back per-(component, locale) status. Auth required.
Request: `components[]` (each `kind, slug, version, text_domain, extra_domains[], is_wporg, bundled_locales[], installed{<locale>: {build_id, verified_at, integrity}}`), `locales[]`, optional `client_events[]` (max 20, error codes and coordinates only — never string content; disclosed item-by-item on the settings screen).
Response `200`: `components[]` (each `kind, slug, locales: {<locale>: {status, build_id?, build_no?, built_at?, update_available?, wporg_percent?, built_for_version?, newer_version?: {version, status}, reason?, hardcoded_text_warning?}}}`), `updates[]`, `license`, `quota`, `features: {checkout, upload}`, `contract`.

`status` is one of: `bundled`, `wporg`, `babilim_installed`, `babilim_available`, `requestable`, `queued`, `running`, `failed`, `unsupported`, `quota_exceeded`, `license_required`. There are no quality-grade fields anywhere in the contract — the plugin only ever shows these eleven states. `license_required` means free language packs are for WordPress.org components only and this component is not one; the plugin shows it and, on a free site that is offered an upgrade, puts an "Upgrade this site" button next to it (the Settings screen shows the same button).

(1.4.0) A `babilim_installed` entry may also carry `built_for_version` (the component version the installed build was made for) and `newer_version: {version, status}`, present only when that differs from the version this call reported and the reported version has no active build yet (an active build is expressed by `update_available` instead). `newer_version.status` is one of `queued`, `running`, `failed`, `requestable`, `license_required`, `quota_exceeded`. Whether a locale is installed on this site is decided locally from the plugin's own manifest, not from this status.

(1.6.0) A `babilim_installed` or `babilim_available` entry may carry `hardcoded_text_warning: true`: a considerable share of the user-visible text in the component source behind that entry's `build_id` does not go through a translation function, so a language pack cannot cover it. The field is only ever present as `true` and only on those two statuses; it follows the entry's own `build_id`. It is a limitation notice, not a quality grade or a coverage figure, and its absence does not mean the component is fully translatable. The plugin shows one fixed line of text under a cell whose entry carries it, changes nothing else about the cell, and shows nothing when the field is missing. Older clients that ignore unknown fields are unaffected.

Asking for a translation (`queued`/`running`/`failed`/`requestable`) is never gated by the free-download quota itself, but a free site must have a free slot for a component it has not yet bound. So `quota_exceeded` replaces what would otherwise be `babilim_available` (a build exists, this site hasn't downloaded it, and downloading it would need a free slot this site doesn't have: the component isn't already bound to one of its free slots, and that kind's free slots are full), and (1.4.0) also what would otherwise be `failed`/`requestable` for such an unbound component, since neither a request nor a retry could take a slot. The same free-slot check `POST /v1/downloads` does at download time is what decides this status ahead of time, so the two never disagree.

`updates[]` lists only (component, locale) pairs this site has **already installed** where the installed build differs from the service's current active build — including the service rolling back to an older build (a "downgrade" from the site's point of view). A pair the site has never installed never appears here, no matter its status (`babilim_available` is not an update — injecting a language pack the user never opted to download would bypass consent/quota). The plugin installs these itself during its own sync run, through its normal installer; it never touches WordPress's update transients.

### `POST /v1/downloads`
Exchange a `build_id` for a short-lived signed download link. Auth required.
Request: `{build_id}`. Response: `302` to `GET /v1/packages/{build_id}?site&exp&sig` (no body; `Location` is relative to the API base, see the `Location` header); `403` with `quota_exceeded`; `404` with `not_available`.

### `GET /v1/packages/{build_id}?site&exp&sig`
No auth header — see Conventions. Streams the actual zip. `410` once the signature's `exp` has passed — call `POST /v1/downloads` again for a fresh link.

Zip layout and the full `manifest.json` field list are in `tests/contract/v1/package-layout.md` (copied verbatim from the canonical contract) — that file is the only interface between how the service assembles a package and how this plugin's installer verifies one; don't invent a manifest field on this side without a contract change.

### `POST /v1/requests` / `GET /v1/requests/{id}`
Ask for a translation to be produced for a (component, version, locale), or poll one already asked for. Auth required.
Request (`POST` only): `{kind, slug, version, locale}`.
Response is one of, strictly tied to the HTTP status:
- `{request_id, status: "queued"|"running", eta_minutes}` — `POST` always answers this with `202`; `GET` answers this with `200` when the job is still in flight
- `{request_id, status: "failed", reason?}` — `GET` `200` only; `POST` never returns this shape (asking again after a failure always starts a fresh job, so `POST` only ever comes back `202` or `200`, never a `failed` body)
- `{status: "available", build_id}` — `POST` or `GET`, always `200`

#### 1.5.0: uploading a source package

`POST /v1/requests` now also accepts `client_caps` (an array; this plugin always sends `["upload"]`). For a component that is not listed on WordPress.org and has no accepted source package yet, the `202` response may carry an `upload` object `{upload_id, part_bytes, max_parts, max_bytes, expires_at}`; the service alone decides whether to ask. The plugin then sends the component's own files as a zip, in parts:

- `PUT /v1/uploads/{upload_id}/parts/{n}` — raw bytes (`Content-Type: application/octet-stream`); every part except the last is exactly `part_bytes`. Response `{n, size}`. A part re-sent before `complete` overwrites the earlier one. Not retried by the HTTP layer.
- `POST /v1/uploads/{upload_id}/complete` — request `{parts, size}`, response `202 {state}`. Idempotent.
- `DELETE /v1/uploads/{upload_id}` — gives the upload up. Response `{state}`.

An `inventory` entry whose status is `queued` may carry `upload_pending: true` (the service is waiting for, or processing, this site's source package); it is a flag alongside the status, not a twelfth state. When the last source upload was rejected or expired, the entry is `requestable` with reason `source_rejected` or `upload_expired`; polling a request whose upload did not succeed can also answer `failed` with reason `source_rejected`, `upload_expired` or `upload_cancelled`. In every case the site may simply request again.

### `POST /v1/feedback`
Report a problem with a specific installed/downloaded build. Auth required.
Request: `{kind, slug, locale, build_id, category, msgid?, msgctxt?, plural_index?, current_msgstr?, suggested?, note?, screen_path?}`.
`category` is one of: `mistranslation`, `terminology`, `placeholder`, `context`, `plural`, `negation`, `loading`, `other`.
`build_id` must be a build this site has downloaded or installed, else `422`.
Response `201`: `{feedback_id}`.

### `GET /v1/meta/locales`
The full WordPress.org locale registry, unranked. No auth required.
Response: `[{wp_locale, english_name, native_name}]`.

### `GET /v1/health`
Liveness probe; also reports which contract version this deployment speaks. No auth required.
Response: `{ok, version, contract}`.

### `GET /wp-json/babilim/v1/challenge?nonce=`
This plugin exposes this endpoint itself (not part of `/v1/`) so the service can call back and confirm domain ownership during verification. `nonce` must be a hex string (16-64 hex characters), and must be the nonce this site is currently waiting to verify, or the plugin returns `400` with `invalid_nonce`. Self-throttled to 10 requests/minute, independent of the service's own per-site limit.
Response: `{nonce, proof}` — `proof` is the same `"b64:" + base64(...)` Ed25519 signature described under `POST /v1/sites/{id}/verify` above.

## Not in this contract

Payment processing itself happens on the Babilim checkout page at babilim.org, where Paddle acts as merchant of record; the plugin only opens the returned `checkout_url` and later reads the resulting `license` from the site and inventory responses.

## Error codes

| code | HTTP | meaning |
|---|---|---|
| `unauthorized` | 401 | missing/invalid/rotated site token |
| `invalid_proof` | 401 | `proof` is malformed, or doesn't verify against `install_pub` (`POST /v1/sites/{id}/verify`) |
| `quota_exceeded` | 403 | free quota exhausted |
| `license_required` | 403 | (1.4.0) free licenses cover WordPress.org components only and the service decided this component is not one (`POST /v1/requests`, `POST /v1/downloads`) |
| `not_reported` | 422 | (1.4.0) this (kind, slug, version) was not reported by this site's recent inventory; sync first, then request (`POST /v1/requests`) |
| `version_unknown` | 422 | (1.4.0) the component is on WordPress.org but this version is not in its version list (`POST /v1/requests`) |
| `wporg_unavailable` | 503 | (1.4.0) the service cannot decide right now whether the component is on WordPress.org; temporary, carries `Retry-After: 30` (`POST /v1/requests`, `POST /v1/downloads`) |
| `capacity_exhausted` | 503 | (1.4.0) the service's daily limit for new translation jobs is used up; carries `Retry-After` (`POST /v1/requests`) |
| `retry_exhausted` | 403 | (1.4.0) this (component version, locale) failed too many times and no longer accepts a new request (`POST /v1/requests`) |
| `payload_too_large` | 413 | (1.5.0) an upload part exceeds the allowed size (`PUT /v1/uploads/{id}/parts/{n}`) |
| `upload_sealed` | 409 | (1.5.0) the upload was already completed or cancelled (`PUT /v1/uploads/{id}/parts/{n}`, and `DELETE /v1/uploads/{id}` while the service is verifying) |
| `already_licensed` | 409 | (1.7.0) this site already holds a paid license (`POST /v1/sites/{id}/checkout`) |
| `checkout_unavailable` | 403 | (1.7.0) the service does not offer checkout to this site right now (`POST /v1/sites/{id}/checkout`) |
| `no_purchase` | 409 | (1.7.0) this install is not bound to a purchase (`POST /v1/sites/{id}/restore-code`) |
| `not_available` | 404 | build disabled or unknown |
| `not_found` | 404 | resource doesn't exist |
| `conflict` | 409 | this site already has a `site_token` — `verify` won't issue a second one; use `rotate-token` (`POST /v1/sites/{id}/verify`) |
| `expired` | 410 | a signed download link's `exp` has passed (`GET /v1/packages/{build_id}`); or the registration challenge's 15-minute TTL passed (`POST /v1/sites/{id}/verify`) — re-register |
| `rate_limited` | 429 | throttled, see `Retry-After` |
| `plugin_outdated` | 426 | this plugin version is too old for the call |
| `invalid_nonce` | 400 | `nonce` isn't a hex string (`GET /wp-json/babilim/v1/challenge`); or doesn't match this site's pending challenge (`POST /v1/sites/{id}/verify`) |
| `unprocessable` | 422 | e.g. `feedback.build_id` doesn't belong to this site; `install_pub` isn't a legal Ed25519 public key (`POST /v1/sites`) |
| `validation_error` | 422 | the request body didn't match the endpoint's own schema (missing/extra/mistyped field) — distinct from `unprocessable`, which is a value that's shaped correctly but semantically invalid |

## Example fixtures (for our own tests, not part of the wire protocol)

Every example under `tests/contract/v1/examples/` (copied from the canonical contract) carries a machine-readable `setup` (the sites/builds/jobs/etc. fixtures the example assumes) and `stable_fields` (which response fields our tests should assert exact values for vs. shape-only). Our seam tests (plugin talking to a real local service — this plugin's own `CLAUDE.md`, "Testing rules": "A seam test against a real local instance of the service ... runs for real, locally — never mocked across that boundary") build their fixtures from these instead of inventing separate ones.

## Local validation

```bash
make contract-check
```
Checks that `tests/contract/v1/` still matches `tests/contract/PINNED` (contract version + a hash of the upstream `SHA256SUMS` file), that every file inside it still matches its own recorded checksum, and that the file set on disk matches what `SHA256SUMS` lists (so a copy that's missing a file, or that picked up a stray extra one, doesn't pass silently). Pure shell, no network, no Python dependency — see `tests/contract/check_pinned.sh`.

To pick up a new contract version: copy `contract/v1/` from the service repository over `tests/contract/v1/` here, then update `tests/contract/PINNED` (`version=` and `sha256sums_sha256=$(sha256sum tests/contract/v1/SHA256SUMS | cut -d' ' -f1)`), then update this file to match.
