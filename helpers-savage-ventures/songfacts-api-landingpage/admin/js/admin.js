(function () {
	"use strict";

	function onReady(fn) {
		if (document.readyState === "loading") {
			document.addEventListener("DOMContentLoaded", fn);
		} else {
			// DOMContentLoaded already fired by the time this script executed
			// (it's a footer-enqueued script, so this can legitimately race
			// depending on page weight/extensions) — run immediately instead.
			fn();
		}
	}

	onReady(function () {
		var table = document.querySelector(".wp-list-table");
		if (table) {
			table.addEventListener("click", function (event) {
				var markBtn = event.target.closest(".sf-lp-mark-completed");
				if (markBtn) {
					event.preventDefault();
					event.stopPropagation();
					markCompleted(markBtn);
					return;
				}

				var row = event.target.closest("tr.sf-lp-row");
				if (row) {
					toggleDetail(row);
				}
			});
		}

		var populateBtn = document.getElementById("sf-lp-populate-samples");
		if (populateBtn) {
			populateBtn.addEventListener("click", function () {
				runSampleAction(populateBtn, "sf_lp_populate_samples", function (data) {
					return "Added " + data.inserted + " sample submissions.";
				});
			});
		}

		var deleteBtn = document.getElementById("sf-lp-delete-samples");
		if (deleteBtn) {
			deleteBtn.addEventListener("click", function () {
				if (!window.confirm("Delete all sample submissions?")) {
					return;
				}
				runSampleAction(deleteBtn, "sf_lp_delete_samples", function (data) {
					return "Removed " + data.deleted + " sample submissions.";
				});
			});
		}

		initRecipients();
		initTestNotification();
		initClearLog();
	});

	/* ── Notifications to Administrators: recipient repeater ── */

	function initRecipients() {
		var table = document.getElementById("sf-lp-recipients");
		var template = document.getElementById("sf-lp-recipient-template");
		var addBtn = document.getElementById("sf-lp-add-recipient");

		if (!table || !template || !addBtn) {
			return;
		}

		var body = table.querySelector("tbody");
		var emptyBody = table.querySelector(".sf-lp-notify-empty");

		function syncEmptyState() {
			if (!emptyBody) {
				return;
			}
			emptyBody.style.display = body.querySelector("tr") ? "none" : "";
		}

		// Row indexes only have to be unique within the posted array — PHP
		// re-keys it on save — so a monotonic counter is enough, and removing a
		// row never needs the remaining ones renumbered.
		var nextIndex = body.querySelectorAll("tr").length;

		addBtn.addEventListener("click", function () {
			var markup = template.innerHTML.split("__INDEX__").join(String(nextIndex));
			nextIndex += 1;

			var holder = document.createElement("tbody");
			holder.innerHTML = markup;

			var row = holder.querySelector("tr");
			if (!row) {
				return;
			}

			body.appendChild(row);
			syncEmptyState();

			var email = row.querySelector('input[type="email"]');
			if (email) {
				email.focus();
			}
		});

		table.addEventListener("click", function (event) {
			var removeBtn = event.target.closest(".sf-lp-remove-recipient");
			if (!removeBtn) {
				return;
			}
			event.preventDefault();
			var row = removeBtn.closest("tr");
			if (row) {
				row.parentNode.removeChild(row);
				syncEmptyState();
			}
		});

		syncEmptyState();
	}

	function initTestNotification() {
		var button = document.getElementById("sf-lp-send-test");
		var status = document.getElementById("sf-lp-test-status");

		if (!button) {
			return;
		}

		button.addEventListener("click", function () {
			if (!window.confirm("Send a test notification to every switched-on recipient?")) {
				return;
			}

			button.disabled = true;
			if (status) {
				status.textContent = "Sending...";
				status.className = "sf-lp-inline-status";
			}

			ajaxPost("sf_lp_send_test_notification", {})
				.then(function (json) {
					var data = json.data || {};
					var message = "Sent " + data.sent + " test email" + (data.sent === 1 ? "" : "s") + ".";
					if (data.failed) {
						message += " " + data.failed + " failed — see the log below.";
					}
					if (status) {
						status.textContent = message + " Reloading...";
						status.className = "sf-lp-inline-status " + (data.failed ? "is-error" : "is-ok");
					}
					window.setTimeout(function () {
						window.location.reload();
					}, 1200);
				})
				.catch(function (err) {
					button.disabled = false;
					if (status) {
						status.textContent = err.message || "Something went wrong.";
						status.className = "sf-lp-inline-status is-error";
					}
				});
		});
	}

	function initClearLog() {
		var button = document.getElementById("sf-lp-clear-log");
		var status = document.getElementById("sf-lp-log-status");

		if (!button) {
			return;
		}

		button.addEventListener("click", function () {
			if (!window.confirm("Clear the notification email log?")) {
				return;
			}

			button.disabled = true;
			if (status) {
				status.textContent = "Clearing...";
			}

			ajaxPost("sf_lp_clear_email_log", {})
				.then(function () {
					window.location.reload();
				})
				.catch(function (err) {
					button.disabled = false;
					if (status) {
						status.textContent = err.message || "Something went wrong.";
					}
				});
		});
	}

	function toggleDetail(row) {
		var detail = document.getElementById(row.id + "-detail");
		if (!detail) {
			return;
		}
		var isOpen = detail.style.display !== "none";
		detail.style.display = isOpen ? "none" : "table-row";
		row.classList.toggle("sf-lp-row-expanded", !isOpen);
	}

	function markCompleted(button) {
		var id = button.getAttribute("data-id");
		button.disabled = true;
		button.textContent = "Saving...";

		ajaxPost("sf_lp_mark_completed", { id: id })
			.then(function (json) {
				var cell = button.closest("td");
				if (cell) {
					cell.innerHTML = '<span class="sf-lp-badge sf-lp-badge-completed">Completed</span>';
				}
			})
			.catch(function (err) {
				button.disabled = false;
				button.textContent = "Mark as Completed";
				window.alert(err.message || "Something went wrong.");
			});
	}

	function runSampleAction(button, action, messageFor) {
		var status = document.getElementById("sf-lp-sample-status");
		var originalText = button.textContent;

		button.disabled = true;
		if (status) {
			status.textContent = "Working...";
		}

		ajaxPost(action, {})
			.then(function (json) {
				if (status) {
					status.textContent = messageFor(json.data);
				}
				window.setTimeout(function () {
					window.location.reload();
				}, 800);
			})
			.catch(function (err) {
				if (status) {
					status.textContent = "";
				}
				window.alert(err.message || "Something went wrong.");
			})
			.finally(function () {
				button.disabled = false;
				button.textContent = originalText;
			});
	}

	function ajaxPost(action, params) {
		var body = new URLSearchParams();
		body.set("action", action);
		body.set("nonce", window.sfLpAdmin ? window.sfLpAdmin.nonce : "");
		Object.keys(params).forEach(function (key) {
			body.set(key, params[key]);
		});

		return fetch(window.sfLpAdmin ? window.sfLpAdmin.ajaxUrl : "", {
			method: "POST",
			credentials: "same-origin",
			headers: { "Content-Type": "application/x-www-form-urlencoded" },
			body: body.toString(),
		})
			.then(function (response) {
				return response.json();
			})
			.then(function (json) {
				if (!json.success) {
					throw new Error((json.data && json.data.message) || "Request failed");
				}
				return json;
			});
	}
})();
