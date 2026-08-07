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
	});

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
