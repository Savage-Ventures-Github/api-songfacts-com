# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

A static marketing/demo site for the Songfacts API (WingManWP), served from GitHub Pages at
`testpage.wingmanwp.com` (see `CNAME`). No build step, no package manager, no bundler at the
repo root — `index.html`, `script.js`, and `style.css` are loaded directly by the browser.

## Commands

There is no build/lint/test tooling for the site itself — it's plain static files. To preview
locally, serve the directory with any static file server (e.g. `python3 -m http.server`) rather
than opening `index.html` directly. The Worker's CORS allowlist does permit the `null` origin
browsers send for `file://` pages, but the Cloudflare Turnstile widget itself cannot: `file://`
has no hostname at all, so Turnstile fails to render ("Unable to connect to website") regardless
of the widget's domain allowlist — there's no code fix for this, it's inherent to `file://`
having no origin for Turnstile to check. Serving over `http://localhost:<port>/` (already in the
widget's registered domains, alongside `127.0.0.1`) is required to exercise the full form locally,
Turnstile included.

The Cloudflare Worker backing the contact form lives in a separate project, not tracked in this
repo (see below). From inside its local working copy:

```bash
npm install
npx wrangler dev              # local dev
npx wrangler deploy           # deploy to Cloudflare
npx wrangler types            # regenerate worker-configuration.d.ts after changing wrangler.jsonc bindings
npx tsc --noEmit               # type-check
npx vitest                    # run tests
```

The test suite under `test/` is unmodified `wrangler init` boilerplate (tests a "Hello World"
handler) — it does not exercise the actual proxy logic in `src/index.ts`.

## Architecture

### Pages

`index.html` is the main landing page (has the "Get Started" contact form and loads
`script.js`). The `*-examples.html` files (`artistfacts-`, `blurbs-`, `categories-`,
`music-history-calendar-`, `quotes-`, `songfacts-`, `trivia-`) are standalone demo pages showing
sample API output for each Songfacts endpoint category. All of them share `style.css`; only
`index.html` loads `script.js`. Every example page is static markup — nothing on them is wired
up. The controls that look interactive are deliberate mockups of what a client would build: the
calendar's date/artist search fields query nothing, the trivia answers are pre-revealed via
`.trivia-answer.is-correct` (the v7 design supplies the correct answers, so the old click-to-
select toggle was removed), and the trivia audio players have no audio behind them.

The page designs come from `landingpage_v7.pdf` in the repo root (8 pages: the landing page plus
one per example page) — treat it as the source of truth when the two disagree.

### Images and brand colors

`img/` filenames encode their slot: `home_*` (landing hero), `sf_*` / `af_*` (Songfacts /
ArtistFacts tiles), `cf_<level>_<n>` (the Categories rabbit-hole chain), `bf_song_*` /
`bf_artist_*` (Blurbs columns), `sf_q_*` (Quotes avatars), `trivia_*` (the player artwork, which
is a flat image of a player, not a built component), `page_bg` (the bubble texture behind every
dark panel). Designer-supplied art is downsized with `sips` and stored as JPEG — 400px for the
large tiles, 200px for thumbnails and avatars — since the originals arrive as 1–10MB Getty files.
Non-square art is cropped by CSS `object-fit: cover`, with `object-position: center top` where a
centered crop would cut off a face.

The designer's palette lives at the top of `style.css` as `--brand-*` custom properties holding
her exact hex codes, with role variables (`--orange`, `--purple`, `--pink`, …) mapped onto them.
Change a role, never a `--brand-*` value. Two roles have no direct equivalent in the supplied
palette and are documented inline where they're defined: `--purple-hover`, and `--pink`, which
uses Medium Purple because Purple fails contrast at small text sizes on the dark panel.

### Contact form → Cloudflare Worker → WordPress pipeline

The "Get Started" form (`#api-form` in `index.html`, handled in `script.js`) cannot hold a secret
client-side, so it never calls the destination directly. Full chain (as of 2026-08-07 — see
`helpers-savage-ventures/README.md` for the Milestone 1/2 history; this used to route through an
n8n webhook, removed in Milestone 2 and verified end-to-end):

```
Browser → Cloudflare Turnstile (client-side widget + server-side siteverify)
        → Cloudflare Worker (songfacts-api-interest-submission.shane-df2.workers.dev)
        → WordPress REST endpoint (helpers.savage.ventures, JWT-authenticated)
```

The Worker (`src/index.ts` in its own project, `~/WebstormProjects/songfacts-api-interest-submission`
on this machine) is the only place any secret exists. Per request it:

1. Enforces CORS against an origin allowlist (prod domain + `localhost`/`127.0.0.1`/`null` for
   local dev).
2. Rate-limits by client IP (native Workers rate-limit binding, `wrangler.jsonc`'s `ratelimits`
   config — 5 req/60s; documented by Cloudflare as approximate/eventually-consistent, not exact).
3. Validates the JSON payload shape (only known form fields, required vs. optional matching the
   HTML's actual `required` attributes, length caps, email format).
4. Verifies the Turnstile token via `siteverify`, checking `action` and `hostname` — rejects
   before anything is forwarded.
5. Signs a short-lived HS256 JWT (`JWT_SIGNING_SECRET`) and POSTs the sanitized payload straight to
   `https://helpers.savage.ventures/wp-json/songfacts-crm/v1/submissions`, which verifies that same
   JWT (see `helpers-savage-ventures/songfacts-api-landingpage/includes/class-sf-jwt.php`).
6. Relays WordPress's response status/body straight back to the browser — it's a synchronous
   pass-through, not fire-and-forget.

If real submissions start getting `401`s, it's almost always the Worker's `JWT_SIGNING_SECRET` and
WordPress's `sf_lp_jwt_secret` option (wp-admin → Songfacts API CRM → Settings) having drifted out
of sync after a secret rotation — the Worker itself has no 401 of its own, so a 401 reaching the
browser is WordPress rejecting the JWT, passed through verbatim. Diagnose with
`wrangler tail songfacts-api-interest-submission --format json` (pretty-format tail only shows a
runtime Ok/Error outcome, not the actual HTTP status).

`docs/cloudflare-worker-setup.md` documents the base Worker/JWT pattern this was built from, but
predates the CORS allowlist, rate limiting, payload validation, Turnstile, and the WordPress
destination — treat it as background on the JWT-signing approach, not a description of the current
`src/index.ts`.

### The Worker source is intentionally not in this git repo

The Worker's source lives in its own separate project, outside this repo entirely, and is
deployed independently via `wrangler deploy` — deploys have no connection to this repo's git
history, so removing/editing files here never affects what's live on Cloudflare, and vice versa.
Secrets (`JWT_SIGNING_SECRET`, `TURNSTILE_SECRET`) are set via `wrangler secret put` and are never
in `wrangler.jsonc` or git.

This repo (`WingManWP/testpage-wingmanwp-com`) is **public** on GitHub — keep the Worker source,
webhook URLs, and any secrets out of tracked files accordingly.
