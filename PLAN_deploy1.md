# Deploy Plan 1 — Turnstile + Worker + Pages (api-draft.songfacts.com)

Fresh setup: Wrangler authenticated to **Savage Ventures Main**. This repo has the site files; the previous `n8n-webhook-proxy` local Worker copy is gone and no longer referenced anywhere.

> **Secrets handling note:** the n8n webhook URL and the signing secret you provided in chat are sensitive. They will be stored only via `wrangler secret put` (encrypted, server-side) and never written into any file in this repo — this repo is public. If this session's transcript is a concern, consider rotating the secret in n8n after setup is done.

## Phase 0 — Decisions to confirm before starting

- [x] Worker name: Cloudflare Worker names must be lowercase, no spaces — proposing `songfacts-api-interest-submission` as the slug for "Songfacts API Interest Submission". Confirm or adjust.
- [x] Confirm the value you gave (`xwUR57n...`) is the **shared signing secret** used the same way the old Worker used `JWT_SIGNING_SECRET` — Worker signs a short-lived HS256 JWT per request, n8n's "JWT Auth" credential on the Webhook node verifies it with this same secret. (It's not a literal JWT itself — no `header.payload.signature` structure — so this is the most likely intent. Flag if you actually meant something else, e.g. a static bearer token.)
- [x] Confirm the `songfacts.com` zone already lives in the Savage Ventures Main Cloudflare account, so Pages can attach `api-draft.songfacts.com` and issue a cert without manual DNS elsewhere.
- [x] Confirm allowed origins for the Worker's CORS allowlist: `https://api-draft.songfacts.com` plus `localhost` / `127.0.0.1` / `null` for local dev (same pattern as before).

## Phase 1 — Cloudflare Turnstile widget

- [x] Create a new Turnstile widget (Cloudflare dashboard → Turnstile → Add widget, or via API) scoped to the new account/domain:
  - Domains: `api-draft.songfacts.com`, `localhost`, `127.0.0.1`
  - Widget mode: Managed (matches current site behavior)
- [x] Record the **Site Key** (public — goes into `script.js`)
- [x] Record the **Secret Key** (goes only into the Worker's secret store, never into this repo)

## Phase 2 — Create and configure the Worker

- [x] Scaffold a new Worker project (outside this repo, per the "not tracked in this repo" convention) named `songfacts-api-interest-submission` — created at `/Users/mervinhernandez/WebstormProjects/songfacts-api-interest-submission`
- [x] Rebuild the proxy logic per the architecture already documented in `CLAUDE.md`:
  1. CORS allowlist (prod domain + localhost/127.0.0.1/null)
  2. Rate limiting by client IP (Workers rate-limit binding)
  3. Payload validation (known fields only, required vs optional, length caps, email format)
  4. Turnstile `siteverify` check (action + hostname)
  5. Sign short-lived HS256 JWT, forward sanitized payload to the n8n webhook
  6. Relay n8n's response status/body straight back to the browser
- [x] Set secrets via `wrangler secret put` (never in `wrangler.jsonc`, never committed):
  - `TURNSTILE_SECRET` (from Phase 1)
  - `JWT_SIGNING_SECRET` (the value you provided)
  - `N8N_WEBHOOK_URL` = `https://n8n.wingmanwp.com/webhook/interest-form-2026-08`
- [x] `npx wrangler deploy` and record the resulting `*.workers.dev` URL — `https://songfacts-api-interest-submission.shane-df2.workers.dev`. Smoke-tested: CORS allowlist, missing-field validation, and bad-Turnstile-token rejection all behave correctly.

## Phase 3 — Update the site form

- [x] Update `script.js`: `WEBHOOK_URL` → new Worker URL, `TURNSTILE_SITEKEY` → new site key
- [x] Update `CLAUDE.md`'s architecture section with the new Worker hostname (no secrets, no webhook URL)
- [x] Serve locally (`python3 -m http.server`) and test the flow: Turnstile widget renders correctly (had to add `localhost`/`127.0.0.1` to the widget's domain allowlist via the Turnstile API — it only had `songfacts.com` at creation). Automated browser verification correctly fails (Turnstile's bot detection catching the DevTools-driven browser, by design) and the Worker correctly rejects it with a 403 — same behavior already confirmed via curl. A real human click-through is still recommended before considering this fully proven end-to-end into n8n.

## Phase 4 — Publish via Cloudflare Pages

- [x] Create a Cloudflare Pages project for this repo — `songfacts-api-draft`, production branch `main`
- [x] Deploy static files: `wrangler pages deploy .` → live at `https://songfacts-api-draft.pages.dev`
- [x] Add custom domain `api-draft.songfacts.com` to the Pages project (via API — status `pending`, needs a CNAME)
- [x] **Action needed from you:** add a DNS record in the `songfacts.com` zone (Wrangler's token has no DNS write scope, so I can't create this one): CNAME `api-draft` → `songfacts-api-draft.pages.dev`, proxied
- [x] Verify TLS cert issues and the domain resolves once the CNAME is live
- [x] Confirm the existing GitHub Pages setup (`CNAME` → `testpage.wingmanwp.com`) is untouched — this is a separate, parallel deployment target

## Phase 5 — End-to-end verification

- [x] Submit the live form at `https://api-draft.songfacts.com`, confirm Turnstile passes, Worker validates + signs + forwards, n8n receives it, response relays back to the browser
- [x] Spot-check rate limiting and CORS behavior
- [ ] `git status` / `git diff` — confirm no secrets, webhook URLs, or Worker source got staged
- [ ] Update `CLAUDE.md` with final non-secret details (Worker name, hostname, Turnstile site key)
