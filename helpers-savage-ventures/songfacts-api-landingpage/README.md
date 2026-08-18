# Songfacts API Landing Page

WordPress plugin that receives relayed Songfacts API interest-form submissions (Cloudflare Worker
→ this plugin) and manages them from wp-admin — Submissions list, visitor auto-reply, admin
notifications, and the JWT-authenticated REST endpoint the Worker posts to. See `CLAUDE.md` for
full architecture, gotchas, and implementation notes; this file is deployment/versioning info only.

## Versioning

This plugin does **not** use semantic versioning. Versions are dated:

```
vYYYY.MM.DD
```

Whenever a change is deployed to `helpers.savage.ventures` and confirmed working live, bump the
`Version:` header in `songfacts-api-landingpage.php` **and** the `SF_LP_VERSION` constant right
below it to that day's date. If a second (or third) fix goes out on that same calendar day, append
a two-digit patch suffix starting at `.01` — don't reuse the bare date for more than one deploy:

```
v2026.08.18       first version confirmed working that day
v2026.08.18.01    a same-day patch after that
v2026.08.18.02    another same-day patch
v2026.08.19       next day resets to no suffix
```

`Version:` and `SF_LP_VERSION` always move together — never bump one without the other.

**Why this matters beyond bookkeeping:** `SF_LP_VERSION` is also the cache-busting query string
`wp_enqueue_script()`/`wp_enqueue_style()` append to `admin.js`/`admin.css`
(`SF_LP_Admin::enqueue_assets()`). If a JS/CSS edit ships without a version bump, a browser that
already loaded wp-admin keeps serving its stale cached copy from that exact versioned URL —
identical symptom to the change simply not having deployed. This has already caused a live
debugging session on this plugin; see `CLAUDE.md`'s cache-busting gotcha for the full story. Bump
the version on **every** deploy, not just ones that touch `admin.js`/`admin.css`, so the datestamp
stays a reliable single answer to "what's actually live right now."

Current version: **v2026.08.18** — visitor auto-reply (Settings tab, tokens, test-send), admin
notification recipients/subject/Sender & Preview tab, and the Post SMTP-backed Visitor
Acknowledgement status on the Submissions detail row.
