// ---------------------------------------------------------------------------
// ⚙️  Configuration
// ---------------------------------------------------------------------------
// Same Cloudflare Worker proxy as before — it signs a JWT server-side and
// forwards to the prod n8n webhook. See docs/cloudflare-worker-setup.md.
const WEBHOOK_URL = "https://plain-morning-dda2.wingmanwp.workers.dev";

// ---------------------------------------------------------------------------
// 📨 "Get Started" contact form -> webhook
// ---------------------------------------------------------------------------
const form = document.getElementById("api-form");

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

    const payload = {
      firstName: form.firstName.value,
      lastName: form.lastName.value,
      email: form.email.value,
      company: form.company.value,
      industry: form.industry.value,
      estimatedUsage: form.usage.value,
      submittedAt: new Date().toISOString(),
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
