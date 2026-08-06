# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

A static marketing/demo site for the Songfacts API (WingManWP), served from GitHub Pages at
`testpage.wingmanwp.com` (see `CNAME`). No build step, no package manager, no bundler at the
repo root — `index.html`, `script.js`, and `style.css` are loaded directly by the browser.

## Commands

There is no build/lint/test tooling for the site itself — it's plain static files. To preview
locally, serve the directory with any static file server (e.g. `python3 -m http.server`) or open
`index.html` directly (the "Get Started" form still works via `file://` — the Worker's CORS
allowlist explicitly permits the `null` origin browsers send for local files).

For the Worker subproject (`n8n-webhook-proxy/plain-morning-dda2/`, not tracked in this repo — see
below), from inside that directory:

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
`index.html` and `trivia-examples.html` load `script.js` (the trivia pages need it for the
answer-reveal toggle, which does no scoring — there's no source of correct answers).

### Contact form → Cloudflare Worker → n8n pipeline

The "Get Started" form (`#api-form` in `index.html`, handled in `script.js`) cannot hold a secret
client-side, so it never calls n8n directly. Full chain:

```
Browser → Cloudflare Turnstile (client-side widget + server-side siteverify)
        → Cloudflare Worker (plain-morning-dda2.wingmanwp.workers.dev)
        → n8n webhook (JWT-authenticated)
```

The Worker (`n8n-webhook-proxy/plain-morning-dda2/src/index.ts`) is the only place any secret
exists. Per request it:

1. Enforces CORS against an origin allowlist (prod domain + `localhost`/`127.0.0.1`/`null` for
   local dev).
2. Rate-limits by client IP (native Workers rate-limit binding, `wrangler.jsonc`'s `ratelimits`
   config — 5 req/60s; documented by Cloudflare as approximate/eventually-consistent, not exact).
3. Validates the JSON payload shape (only known form fields, required vs. optional matching the
   HTML's actual `required` attributes, length caps, email format).
4. Verifies the Turnstile token via `siteverify`, checking `action` and `hostname` — rejects
   before anything is forwarded.
5. Signs a short-lived HS256 JWT (`JWT_SIGNING_SECRET`) and forwards the sanitized payload to the
   n8n webhook URL (`N8N_WEBHOOK_URL`), which requires that JWT via n8n's own "JWT Auth" credential
   on the Webhook node.
6. Relays n8n's response status/body straight back to the browser — it's a synchronous pass-through,
   not fire-and-forget, so n8n's Webhook node response mode determines what the visitor actually sees.

`docs/cloudflare-worker-setup.md` documents the base Worker/JWT pattern this was built from, but
predates the CORS allowlist, rate limiting, payload validation, and Turnstile — treat it as
background on the JWT-signing approach, not a description of the current `src/index.ts`.

### The Worker source is intentionally not in this git repo

`n8n-webhook-proxy/` is gitignored. The Worker is deployed independently via `wrangler deploy` —
deploys have no connection to this repo's git history, so removing/editing files here never
affects what's live on Cloudflare, and vice versa. The source exists on disk only as a local
working copy for editing before redeploying. Secrets (`JWT_SIGNING_SECRET`, `TURNSTILE_SECRET`)
are set via `wrangler secret put` and are never in `wrangler.jsonc` or git.

This repo (`WingManWP/testpage-wingmanwp-com`) is **public** on GitHub — keep the Worker source,
webhook URLs, and any secrets out of tracked files accordingly.
