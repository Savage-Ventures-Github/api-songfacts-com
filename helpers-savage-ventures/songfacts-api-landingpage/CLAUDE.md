# CLAUDE.md

Guidance for working on this plugin specifically. See `../README.md` for the overall
Songfacts API landing page → Worker → WordPress pipeline and Milestone 1/2 context (Milestone 2 —
routing the Worker straight here instead of through n8n — shipped and was verified end-to-end on
2026-08-07; n8n is no longer in this path).

## What this is

**Songfacts API Landing Page** (slug `songfacts-api-landingpage`) — a plain PHP/JS WordPress
plugin, no build step, no composer, no npm. Edit the files directly; there's nothing to compile.
Deployed manually to `helpers.savage.ventures` (WP File Manager / direct file copy) — no CI, no
`wp-cli`, no automated deploy. **After any change, tell the user explicitly which files changed
so they know what to push.**

## Layout

- `songfacts-api-landingpage.php` — bootstrap: constants, `require_once`s, activation hook,
  wires `SF_LP_REST_Controller::register_routes` to `rest_api_init` and `SF_LP_Admin::init` to
  `init`.
- `includes/class-sf-jwt.php` — minimal HS256 JWT verify (signature + `exp`/`nbf`), no library
  dependency. Verifies against the same secret the Cloudflare Worker signs with.
- `includes/class-sf-db.php` — all `$wpdb` access. Table `{prefix}sflp_submissions`, created via
  `dbDelta` in `install()` (called on activation only — there's no upgrade routine, so a schema
  change requires deactivate/reactivate or a manual `ALTER TABLE` on an already-live install).
- `includes/class-sf-rest-controller.php` — `POST /wp-json/songfacts-crm/v1/submissions`, the
  landing-page → Worker → **here** endpoint (direct since Milestone 2). `check_auth()` is the
  `permission_callback`.
- `includes/class-sf-list-table.php` — `WP_List_Table` subclass for the Submissions screen.
  `single_row()` renders each row plus a sibling `<tr class="sf-lp-detail-row">` (hidden by
  default) for the click-to-expand detail view.
- `includes/class-sf-admin.php` — admin menu registration, Settings page (JWT secret + sample-data
  buttons), all `wp_ajax_sf_lp_*` handlers.
- `includes/class-sf-access-control.php` — role-based access to the Submissions screen, plus the
  admin-only Access Control page that manages it (see "Admin access model" below).
- `admin/js/admin.js`, `admin/css/admin.css` — vanilla JS/CSS, no jQuery dependency, enqueued only
  on `sf-lp-*` pages (see gotcha below).

## Gotchas learned the hard way

**Bump `SF_LP_VERSION` on every `admin.js`/`admin.css` change.** It's the cache-busting query
string WordPress appends (`admin.js?ver=<version>`). Forgetting this was the root cause of a long
debugging loop: a fresh/incognito browser profile always fetched the real file and "just worked,"
while an everyday browser profile that had already visited the Settings page kept serving an old
cached copy from that exact versioned URL — identical symptom (button does nothing) even after the
server-side file was already fixed. If a fix "works for me but not for the user," suspect this
before anything else.

**`enqueue_assets()` gates on `$_GET['page']`, not the `$hook` suffix WordPress passes in.**
`admin_enqueue_scripts` callbacks receive a hook suffix whose exact string depends on how
`add_menu_page`/`add_submenu_page` were called — easy to get subtly wrong, and getting it wrong
means the JS/CSS silently never loads on that page (no error, buttons just don't respond).
Checking `$_GET['page']` directly (any `sf-lp-` prefixed page — `sf-lp-submissions`,
`sf-lp-settings`, `sf-lp-access-control`) sidesteps the ambiguity entirely. Don't "simplify" this
back to a hook-suffix string match.

**`admin.js` binds its click handlers via an `onReady()` helper that checks
`document.readyState`, not a bare `document.addEventListener("DOMContentLoaded", ...)`.** The
script is enqueued in the footer (loads last), so on a slow-loading page — more installed plugins,
more competing scripts, a heavier browser profile — it can genuinely execute *after*
`DOMContentLoaded` has already fired. A bare listener registered after the event already fired
never runs, so the buttons silently get no click handlers. Reproduced locally by injecting the
script dynamically after firing `DOMContentLoaded` manually; confirmed `onReady()` handles both
orderings. Don't revert to the bare listener form.

**There is no local WordPress/MySQL environment for this project** (no `wp-cli`, no Docker daemon
running, as of this writing). A standalone `php` binary *is* available now (Homebrew PHP 8.5 at
`/opt/homebrew/bin/php`), so `php -l` works for syntax checks — but it has no WordPress loaded, so
nothing in this plugin can actually be executed. Verification during development means: `php -l`
each changed PHP file and `node -c` the JS, then live-testing against the actual
`helpers.savage.ventures` site through the chrome-devtools MCP tools once the user is signed in and
hands over the browser. There's no way to exercise this plugin end-to-end without that live site.

## Admin access model

Deliberately the **same schema as the sibling Savage Media Indexing Database plugin's
`SMID_Access_Control`** — keep the two in step if either changes.

Grants live in a single option, `sf_lp_access_control`, shaped `[ role_key ][ grant_key ] => 0|1`.
The matching capabilities are *synthesized per request* by a `user_has_cap` filter
(`SF_LP_Access_Control::filter_caps`) and are **never written into the roles table** — so there's
nothing to clean up on deactivate, and nothing persists if the plugin is removed. Two grants are
configurable:

| Grant key | Capability | What it unlocks |
| --- | --- | --- |
| `submissions` | `sf_lp_access_submissions` | Sees the menu + the Submissions list/detail rows |
| `submissions_edit` | `sf_lp_access_submissions_edit` | The "Mark as Completed" button and its AJAX handler |

Plus `sf_lp_any_access`, which gates top-level menu visibility only. It's true for any role holding
a grant flagged `'menu' => true` in `get_configurable_pages()` — currently just `submissions`.

**`submissions_edit` implies `submissions`** (the `'implies'` key, applied in `resolve_grants()`).
This is a fix, not decoration: the first cut treated the two toggles as independent, so switching on
*Edit Submissions* alone left a role holding an edit cap while `sf_lp_any_access` stayed false — the
menu invisible, the grant apparently doing nothing, and no error anywhere to explain it. The
invariant to preserve is **any sf_lp grant held ⇔ menu visible**; there's a test for all four toggle
combinations in the harness described below. Adding a third grant means deciding its `implies` too.

Four details that look like they could be simplified but can't:

- **`filter_caps()` writes an explicit `false`, not just a `true` on grant.** Setting the cap to
  `false` is what overrides an `sf_lp_access_*` cap that somebody may have stored directly in the
  role or user capabilities in the DB during testing. Dropping the else-branch silently makes those
  stale DB caps authoritative again.
- **`SF_LP_Access_Control::init()` is called at file-load time in the bootstrap, not from
  `SF_LP_Admin::init()` on `init`.** The `user_has_cap` filter has to be registered for *every*
  request that will ask about these caps — including `admin-ajax.php` — before anything checks
  them.
- **The filter runs at priority `9999` (`self::FILTER_PRIORITY`), not `10`.** A role-manager or
  membership plugin that rebuilds `$allcaps` wholesale at a lower priority would otherwise discard
  our grants, with the same symptom as no grants at all. SMID still uses 10; if you sync the two,
  move SMID up rather than moving this one down.
- **Grants default to off and activation does not seed them.** Deactivate/reactivate therefore
  changes nothing about visibility — the option has to be saved from the Access Control screen. An
  `admin_notices` nag (`maybe_no_grants_notice()`) says so on the plugin's own pages, because
  "reactivate the plugin" is the natural first thing to try and it cannot work.

## Debugging "role X can't see the menu"

The Access Control page has a collapsed **Diagnostics** block (`render_diagnostics()`) built for
exactly this, since the failure is invisible from outside: it prints the raw stored option, the
stored-vs-effective grant per role (so an implication or a wrong role key is obvious), a
username/email lookup showing that user's **actual assigned role keys** next to a live `user_can()`
result, and every callback registered on `user_has_cap` with its priority. A mismatch between
"resolved from settings" and the live `user_can()` means another plugin is overriding the caps; a
role you granted that isn't listed means its role key differs from the one you toggled. Each column
header on the toggle table also shows the underlying `role_key`.

There's a standalone harness for the cap logic that runs without WordPress — it stubs `get_option`,
`WP_User` etc. and calls `filter_caps()` directly, covering the admin short-circuit, every
role/grant combination, the menu-visibility invariant, stale-DB-cap overrides, multi-role users, and
fresh-install defaults. It lives in the scratchpad rather than the repo (there's no test tooling
here to hang it off); recreate it before touching `resolve_grants()` or `filter_caps()`, because
none of that logic can be exercised on this machine any other way.

Administrators always get every `sf_lp_*` cap (the `manage_options` short-circuit at the top of
`filter_caps()`). Settings and Access Control are hard-wired to `manage_options` and appear as
locked rows in the Access Control table — never make them grantable, Settings exposes the JWT
signing secret.

## REST auth model

The `/wp-json/songfacts-crm/v1/submissions` endpoint verifies an `Authorization: Bearer <JWT>`
header against `SF_LP_Admin::OPTION_JWT_SECRET` (option `sf_lp_jwt_secret`, set at wp-admin →
Songfacts API CRM → Settings). This must exactly match the Cloudflare Worker's
`JWT_SIGNING_SECRET` — that secret lives outside this repo entirely (see root `CLAUDE.md`) and is
never something Claude has access to or can retrieve; if it's lost, the fix is generating a new
one and setting it in both places; **plaintext JWT secrets are never sent to Claude and this
plugin does not need it accessed for development.**

**If real traffic starts getting `401`s from this endpoint, suspect a secret mismatch first.**
This is the exact failure hit during the Milestone 2 end-to-end test: the Worker's own code has no
401 of its own (it uses 403/405/429/400/502), so a `401` reaching the browser is WordPress
rejecting the JWT, passed straight through by the Worker's `return new Response(upstreamBody,
{ status: upstream.status, ... })`. Diagnosed by tailing the live Worker
(`wrangler tail songfacts-api-interest-submission --format json` from
`~/WebstormProjects/songfacts-api-interest-submission`) and reading the POST event's
`response.status` — the Worker's pretty-format tail output only shows a runtime "Ok"/"Error"
outcome, not the actual HTTP status, so it looked fine there even while returning 401. Root cause
was the Worker's `JWT_SIGNING_SECRET` and this plugin's `sf_lp_jwt_secret` option drifting out of
sync after a secret rotation. Fix: `wrangler secret put JWT_SIGNING_SECRET` on the Worker with a
value that exactly matches the WP Settings field.

## Sample data

Settings page has "Populate Sample Submissions" (inserts 10 fixed fake rows, `is_sample = 1`) and
"Delete Sample Submissions" (`DELETE ... WHERE is_sample = 1`). Real submissions from the REST
endpoint always insert `is_sample = 0` and are never touched by the delete action. Safe to
populate/delete repeatedly for testing the admin UI.
