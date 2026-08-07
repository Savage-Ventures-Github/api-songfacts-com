# CLAUDE.md

Guidance for working on this plugin specifically. See `../README.md` for the overall
Songfacts API landing page → Worker → n8n → WordPress pipeline and Milestone 1/2 context.

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
  landing-page → Worker → n8n → **here** endpoint. `check_auth()` is the `permission_callback`.
- `includes/class-sf-list-table.php` — `WP_List_Table` subclass for the Submissions screen.
  `single_row()` renders each row plus a sibling `<tr class="sf-lp-detail-row">` (hidden by
  default) for the click-to-expand detail view.
- `includes/class-sf-admin.php` — admin menu registration, Settings page (JWT secret + sample-data
  buttons), all `wp_ajax_sf_lp_*` handlers.
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
Checking `$_GET['page']` directly (`sf-lp-submissions` / `sf-lp-settings`) sidesteps the ambiguity
entirely. Don't "simplify" this back to a hook-suffix string match.

**`admin.js` binds its click handlers via an `onReady()` helper that checks
`document.readyState`, not a bare `document.addEventListener("DOMContentLoaded", ...)`.** The
script is enqueued in the footer (loads last), so on a slow-loading page — more installed plugins,
more competing scripts, a heavier browser profile — it can genuinely execute *after*
`DOMContentLoaded` has already fired. A bare listener registered after the event already fired
never runs, so the buttons silently get no click handlers. Reproduced locally by injecting the
script dynamically after firing `DOMContentLoaded` manually; confirmed `onReady()` handles both
orderings. Don't revert to the bare listener form.

**There is no local WordPress/PHP/MySQL environment for this project** (no `php` binary, no
`wp-cli`, no Docker daemon running, as of this writing). Verification during development means:
syntax-checking PHP by hand (brace/paren/bracket balance, since `php -l` isn't available) and JS
via `node -c`, then live-testing against the actual `helpers.savage.ventures` site through the
chrome-devtools MCP tools once the user is signed in and hands over the browser. There's no way to
exercise this plugin end-to-end without that live site.

## REST auth model

The `/wp-json/songfacts-crm/v1/submissions` endpoint verifies an `Authorization: Bearer <JWT>`
header against `SF_LP_Admin::OPTION_JWT_SECRET` (option `sf_lp_jwt_secret`, set at wp-admin →
Songfacts API CRM → Settings). This must exactly match the Cloudflare Worker's
`JWT_SIGNING_SECRET` — that secret lives outside this repo entirely (see root `CLAUDE.md`) and is
never something Claude has access to or can retrieve; if it's lost, the fix is generating a new
one and setting it in both places; **plaintext JWT secrets are never sent to Claude and this
plugin does not need it accessed for development.**

## Sample data

Settings page has "Populate Sample Submissions" (inserts 10 fixed fake rows, `is_sample = 1`) and
"Delete Sample Submissions" (`DELETE ... WHERE is_sample = 1`). Real submissions from the REST
endpoint always insert `is_sample = 0` and are never touched by the delete action. Safe to
populate/delete repeatedly for testing the admin UI.
