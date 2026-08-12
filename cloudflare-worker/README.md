# Cloudflare Worker — reference copy (redacted)

This is a **redacted, reference-only copy** of the relay Worker that backs the
"Get Started" contact form on this site. It is here for documentation and code
review only.

> ⚠️ **This is not the deployed source.** The canonical Worker lives in its own
> separate project (`~/WebstormProjects/songfacts-api-interest-submission` on the
> maintainer's machine) and is deployed independently via `wrangler deploy`.
> Editing or deploying from *this* copy has **no effect** on what is live on
> Cloudflare. Keep the canonical project as the source of truth.

## Redactions

Because this repo is **public**, the following was replaced with a placeholder:

- `WORDPRESS_SUBMISSIONS_URL` → `https://<WORDPRESS_HOST>/wp-json/songfacts-crm/v1/submissions`

Secrets are **never** in source in either copy — `JWT_SIGNING_SECRET` and
`TURNSTILE_SECRET` are set via `wrangler secret put` and read from `env` at runtime.

## What the Worker does

Browser → Turnstile (client widget + server siteverify) → this Worker → WordPress
REST endpoint (JWT-authenticated). Per request it:

1. Enforces CORS against an origin allowlist (`STATIC_ALLOWED_ORIGINS` + localhost).
2. Rate-limits by client IP (Workers rate-limit binding, 5 req/60s).
3. Validates the JSON payload shape (known fields, required/optional, length caps, email).
4. Verifies the Turnstile token via `siteverify`, checking `action` and `hostname`
   (`ALLOWED_HOSTNAMES`).
5. Signs a short-lived HS256 JWT and POSTs the sanitized payload to WordPress.
6. Relays WordPress's status/body straight back to the browser (synchronous pass-through).

## Allowed origins / hostnames

Both the CORS origin check (`STATIC_ALLOWED_ORIGINS`) and the Turnstile hostname
check (`ALLOWED_HOSTNAMES`) are locked to production only:

- `api.songfacts.com` (production)

Local/staging origins (`localhost`, `127.0.0.1`, `dev-api`, `api-draft`, and the
`null` file:// affordance) were removed for the prod cutover. To temporarily add
an environment back, add it to **both** sets and `wrangler deploy` from the
canonical project.
