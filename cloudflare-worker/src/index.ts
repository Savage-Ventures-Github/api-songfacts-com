// NOTE: This is a REDACTED REFERENCE COPY of the relay Worker whose canonical
// source lives outside this repo and is deployed independently via `wrangler deploy`.
// The internal WordPress destination URL has been replaced with a placeholder
// (<WORDPRESS_HOST>) because this repo is public. See ../README.md.

const ALLOWED_HOSTNAMES = new Set([
	"api.songfacts.com",
	"api-draft.songfacts.com",
	"dev-api.songfacts.com",
	"localhost",
	"127.0.0.1",
]);
const STATIC_ALLOWED_ORIGINS = new Set([
	"https://api.songfacts.com",
	"https://api-draft.songfacts.com",
	"https://dev-api.songfacts.com",
	"null",
]);
const LOCAL_ORIGIN_RE = /^https?:\/\/(localhost|127\.0\.0\.1)(:\d+)?$/;

const TURNSTILE_ACTION = "contact";
const TURNSTILE_VERIFY_URL = "https://challenges.cloudflare.com/turnstile/v0/siteverify";
const JWT_TTL_SECONDS = 60;
// REDACTED: real host is the internal WordPress instance, kept out of this public repo.
const WORDPRESS_SUBMISSIONS_URL = "https://<WORDPRESS_HOST>/wp-json/songfacts-crm/v1/submissions";

const EMAIL_RE = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

class ValidationError extends Error {}

interface FormFields {
	firstName: string;
	lastName: string;
	email: string;
	company: string;
	message: string;
	submittedAt: string;
}

function isAllowedOrigin(origin: string | null): origin is string {
	if (!origin) return false;
	return STATIC_ALLOWED_ORIGINS.has(origin) || LOCAL_ORIGIN_RE.test(origin);
}

function corsHeaders(origin: string): HeadersInit {
	return {
		"Access-Control-Allow-Origin": origin,
		"Access-Control-Allow-Methods": "POST, OPTIONS",
		"Access-Control-Allow-Headers": "Content-Type",
		Vary: "Origin",
	};
}

function jsonResponse(body: unknown, status: number, headers: HeadersInit): Response {
	return new Response(JSON.stringify(body), {
		status,
		headers: { ...headers, "Content-Type": "application/json" },
	});
}

function readField(
	record: Record<string, unknown>,
	key: keyof FormFields | "turnstileToken",
	{ required, maxLength }: { required: boolean; maxLength: number },
): string {
	const value = record[key];
	if (value === undefined || value === null || value === "") {
		if (required) throw new ValidationError(`Missing field: ${key}`);
		return "";
	}
	if (typeof value !== "string") throw new ValidationError(`Invalid field: ${key}`);
	if (value.length > maxLength) throw new ValidationError(`Field too long: ${key}`);
	return value;
}

function validatePayload(
	body: unknown,
): { ok: true; data: FormFields; turnstileToken: string } | { ok: false; error: string } {
	if (typeof body !== "object" || body === null) {
		return { ok: false, error: "Invalid payload" };
	}
	const record = body as Record<string, unknown>;
	const allowedKeys = new Set([
		"firstName",
		"lastName",
		"email",
		"company",
		"message",
		"submittedAt",
		"turnstileToken",
	]);
	for (const key of Object.keys(record)) {
		if (!allowedKeys.has(key)) return { ok: false, error: `Unexpected field: ${key}` };
	}

	try {
		const firstName = readField(record, "firstName", { required: true, maxLength: 200 });
		const lastName = readField(record, "lastName", { required: true, maxLength: 200 });
		const email = readField(record, "email", { required: true, maxLength: 254 });
		if (!EMAIL_RE.test(email)) throw new ValidationError("Invalid field: email");
		const company = readField(record, "company", { required: false, maxLength: 200 });
		const message = readField(record, "message", { required: false, maxLength: 5000 });
		const submittedAt = readField(record, "submittedAt", { required: false, maxLength: 64 });
		const turnstileToken = readField(record, "turnstileToken", { required: true, maxLength: 2048 });

		return {
			ok: true,
			data: { firstName, lastName, email, company, message, submittedAt },
			turnstileToken,
		};
	} catch (err) {
		if (err instanceof ValidationError) return { ok: false, error: err.message };
		throw err;
	}
}

async function verifyTurnstile(token: string, remoteIp: string, secret: string): Promise<boolean> {
	const form = new URLSearchParams();
	form.set("secret", secret);
	form.set("response", token);
	form.set("remoteip", remoteIp);

	const res = await fetch(TURNSTILE_VERIFY_URL, {
		method: "POST",
		headers: { "Content-Type": "application/x-www-form-urlencoded" },
		body: form,
	});
	if (!res.ok) return false;

	const result = (await res.json()) as { success: boolean; action?: string; hostname?: string };
	if (!result.success) return false;
	if (result.action !== TURNSTILE_ACTION) return false;
	if (!result.hostname || !ALLOWED_HOSTNAMES.has(result.hostname)) return false;
	return true;
}

function base64UrlEncodeBytes(bytes: Uint8Array): string {
	let binary = "";
	for (const byte of bytes) binary += String.fromCharCode(byte);
	return btoa(binary).replace(/\+/g, "-").replace(/\//g, "_").replace(/=+$/, "");
}

function base64UrlEncode(input: string): string {
	return base64UrlEncodeBytes(new TextEncoder().encode(input));
}

async function signJwt(secret: string): Promise<string> {
	const encoder = new TextEncoder();
	const now = Math.floor(Date.now() / 1000);
	const headerB64 = base64UrlEncode(JSON.stringify({ alg: "HS256", typ: "JWT" }));
	const payloadB64 = base64UrlEncode(
		JSON.stringify({
			iat: now,
			exp: now + JWT_TTL_SECONDS,
			iss: "songfacts-api-interest-submission",
		}),
	);
	const signingInput = `${headerB64}.${payloadB64}`;

	const key = await crypto.subtle.importKey(
		"raw",
		encoder.encode(secret),
		{ name: "HMAC", hash: "SHA-256" },
		false,
		["sign"],
	);
	const signature = await crypto.subtle.sign("HMAC", key, encoder.encode(signingInput));

	return `${signingInput}.${base64UrlEncodeBytes(new Uint8Array(signature))}`;
}

export default {
	async fetch(request, env): Promise<Response> {
		const origin = request.headers.get("Origin");
		if (!isAllowedOrigin(origin)) {
			return new Response("Forbidden", { status: 403 });
		}
		const headers = corsHeaders(origin);

		if (request.method === "OPTIONS") {
			return new Response(null, { status: 204, headers });
		}
		if (request.method !== "POST") {
			return jsonResponse({ error: "Method not allowed" }, 405, headers);
		}

		const clientIp = request.headers.get("cf-connecting-ip") ?? "unknown";
		const { success: withinLimit } = await env.IP_RATE_LIMITER.limit({ key: clientIp });
		if (!withinLimit) {
			return jsonResponse({ error: "Too many requests" }, 429, headers);
		}

		let body: unknown;
		try {
			body = await request.json();
		} catch {
			return jsonResponse({ error: "Invalid JSON" }, 400, headers);
		}

		const validation = validatePayload(body);
		if (!validation.ok) {
			return jsonResponse({ error: validation.error }, 400, headers);
		}
		const { data, turnstileToken } = validation;

		const turnstileOk = await verifyTurnstile(turnstileToken, clientIp, env.TURNSTILE_SECRET);
		if (!turnstileOk) {
			return jsonResponse({ error: "Verification failed" }, 403, headers);
		}

		const jwt = await signJwt(env.JWT_SIGNING_SECRET);

		let upstream: Response;
		try {
			upstream = await fetch(WORDPRESS_SUBMISSIONS_URL, {
				method: "POST",
				headers: {
					"Content-Type": "application/json",
					Authorization: `Bearer ${jwt}`,
				},
				body: JSON.stringify(data),
			});
		} catch {
			return jsonResponse({ error: "Upstream unavailable" }, 502, headers);
		}

		const upstreamBody = await upstream.text();
		return new Response(upstreamBody, {
			status: upstream.status,
			headers: {
				...headers,
				"Content-Type": upstream.headers.get("Content-Type") ?? "application/json",
			},
		});
	},
} satisfies ExportedHandler<Env>;
