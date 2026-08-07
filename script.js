// ---------------------------------------------------------------------------
// ⚙️  Configuration
// ---------------------------------------------------------------------------
// Cloudflare Worker proxy — it signs a JWT server-side and forwards to the
// prod n8n webhook. See docs/cloudflare-worker-setup.md.
const WEBHOOK_URL = "https://songfacts-api-interest-submission.shane-df2.workers.dev";

// Turnstile sitekey (public, safe to ship client-side — the secret half
// lives only in the Worker). Registered for api-draft.songfacts.com +
// localhost/127.0.0.1.
const TURNSTILE_SITEKEY = "0x4AAAAAAEItXaalyJ7kIYuB";

// ---------------------------------------------------------------------------
// 📨 "Get Started" contact form -> webhook
// ---------------------------------------------------------------------------
const form = document.getElementById("api-form");

let turnstileWidgetId = null;

// Explicit render (rather than the auto-render data-sitekey div) because
// this form submits via fetch(), not a native navigation — we need to hold
// onto the widget ID to read its token and reset it between attempts.
window.onTurnstileLoad = function () {
  const container = document.getElementById("turnstile-widget");
  if (container && window.turnstile) {
    turnstileWidgetId = turnstile.render(container, {
      sitekey: TURNSTILE_SITEKEY,
      action: "contact",
    });
  }
};

if (form) {
  const formStatus = document.getElementById("form-status");
  const defaultNote = formStatus ? formStatus.textContent : "";

  form.addEventListener("submit", async (event) => {
    event.preventDefault();

    if (!WEBHOOK_URL || WEBHOOK_URL.startsWith("REPLACE_ME")) {
      if (formStatus) {
        formStatus.textContent = "⚠️ No webhook configured yet.";
        formStatus.classList.add("form-note-error");
      }
      return;
    }

    const turnstileToken =
      turnstileWidgetId !== null && window.turnstile ? turnstile.getResponse(turnstileWidgetId) : "";
    if (!turnstileToken) {
      if (formStatus) {
        formStatus.textContent = "⚠️ Please complete the verification check.";
        formStatus.classList.add("form-note-error");
      }
      return;
    }

    const payload = {
      firstName: form.firstName.value,
      lastName: form.lastName.value,
      email: form.email.value,
      company: form.company.value,
      message: form.message.value,
      submittedAt: new Date().toISOString(),
      turnstileToken,
    };

    const submitBtn = form.querySelector("button[type=submit]");
    submitBtn.disabled = true;
    if (formStatus) {
      formStatus.textContent = "Sending...";
      formStatus.classList.remove("form-note-error", "form-note-success");
    }

    try {
      const response = await fetch(WEBHOOK_URL, {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify(payload),
      });

      if (!response.ok) {
        throw new Error(`Webhook responded with ${response.status}`);
      }

      if (formStatus) {
        formStatus.textContent = "✅ Thanks — we'll be in touch.";
        formStatus.classList.add("form-note-success");
      }
      form.reset();
    } catch (err) {
      console.error(err);
      if (formStatus) {
        formStatus.textContent = "❌ Something went wrong sending that. Try again?";
        formStatus.classList.add("form-note-error");
      }
    } finally {
      submitBtn.disabled = false;
      // Tokens are single-use — reset so the next attempt gets a fresh one.
      if (turnstileWidgetId !== null && window.turnstile) {
        turnstile.reset(turnstileWidgetId);
      }
    }
  });
}

// ---------------------------------------------------------------------------
// ❓ Trivia answer buttons (Trivia Examples page) — just a selection toggle,
// no scoring logic since the source design doesn't specify correct answers.
// ---------------------------------------------------------------------------
document.querySelectorAll(".trivia-card").forEach((card) => {
  const buttons = card.querySelectorAll(".trivia-answer");
  buttons.forEach((button) => {
    button.addEventListener("click", () => {
      buttons.forEach((b) => b.classList.remove("is-selected"));
      button.classList.add("is-selected");
    });
  });
});
