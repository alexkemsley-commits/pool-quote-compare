(function () {
	'use strict';

	document.addEventListener('DOMContentLoaded', function () {
		var form = document.getElementById('pqc-form');
		if (!form) return;

		var priorityInputs = form.querySelectorAll('input[data-pqc-priority]');
		var priorityWarn   = document.getElementById('pqc-priority-warning');
		var PRIORITY_MAX   = 2;

		function countChecked() {
			return form.querySelectorAll('input[data-pqc-priority]:checked').length;
		}

		function enforcePriorityLimit(e) {
			// If the user just checked a box that puts us over the cap, revert it.
			if (e && e.target && e.target.checked && countChecked() > PRIORITY_MAX) {
				e.target.checked = false;
				if (priorityWarn) {
					priorityWarn.hidden = false;
					priorityWarn.textContent = 'You can only pick ' + PRIORITY_MAX + '. Untick one to swap.';
				}
				// Flash a brief highlight so the user sees what happened.
				if (e.target.parentNode) {
					e.target.parentNode.classList.add('pqc-priority-flash');
					setTimeout(function () {
						if (e.target.parentNode) e.target.parentNode.classList.remove('pqc-priority-flash');
					}, 600);
				}
				return;
			}

			var atMax = countChecked() >= PRIORITY_MAX;
			for (var i = 0; i < priorityInputs.length; i++) {
				var input = priorityInputs[i];
				var parent = input.parentNode;
				if (input.checked) {
					input.disabled = false;
					if (parent) parent.classList.remove('is-disabled');
				} else {
					input.disabled = atMax;
					if (parent) parent.classList.toggle('is-disabled', atMax);
				}
			}
			if (priorityWarn) {
				if (atMax) {
					priorityWarn.hidden = false;
					priorityWarn.textContent = PRIORITY_MAX + ' picked. Untick one to swap.';
				} else {
					priorityWarn.hidden = true;
					priorityWarn.textContent = '';
				}
			}
		}

		for (var p = 0; p < priorityInputs.length; p++) {
			priorityInputs[p].addEventListener('change', enforcePriorityLimit);
		}
		enforcePriorityLimit();

		var status     = document.getElementById('pqc-status');
		var submitBtn  = form.querySelector('.pqc-submit');
		var filesInput = document.getElementById('pqc-files');
		var thanks     = document.getElementById('pqc-thankyou');
		var thanksEmail = document.getElementById('pqc-thankyou-email');
		var emailInput = document.getElementById('pqc-email');

		function showStatus(msg, cls) {
			status.hidden = false;
			status.className = 'pqc-status ' + (cls || '');
			status.textContent = msg;
		}
		function hideStatus() {
			status.hidden = true;
			status.className = 'pqc-status';
			status.textContent = '';
		}

		function showInlineThanks(email) {
			form.hidden = true;
			thanks.hidden = false;
			if (email && thanksEmail) {
				thanksEmail.hidden = false;
				thanksEmail.textContent = 'We will email your comparison to: ' + email;
			}
			thanks.scrollIntoView({ behavior: 'smooth', block: 'start' });
		}

		form.addEventListener('submit', function (e) {
			e.preventDefault();
			hideStatus();

			if (!emailInput.value || !/.+@.+\..+/.test(emailInput.value)) {
				showStatus('Please enter a valid email address. The comparison is delivered by email.', 'is-error');
				emailInput.focus();
				return;
			}

			var files = filesInput.files;
			if (!files || files.length < 2) {
				showStatus(PQC.i18n.tooFew, 'is-error');
				return;
			}
			if (files.length > PQC.maxFiles) {
				showStatus(PQC.i18n.tooMany, 'is-error');
				return;
			}
			for (var i = 0; i < files.length; i++) {
				if (files[i].size > PQC.maxBytes) {
					showStatus(PQC.i18n.tooBig, 'is-error');
					return;
				}
			}

			var fd = new FormData(form);
			fd.append('action', 'pqc_submit');
			fd.append('nonce', PQC.nonce);

			submitBtn.disabled = true;
			showStatus(PQC.i18n.uploading, 'is-loading');

			fetch(PQC.ajaxUrl, {
				method: 'POST',
				body: fd,
				credentials: 'same-origin'
			})
				.then(function (r) { return r.json().then(function (j) { return { ok: r.ok, json: j }; }); })
				.then(function (res) {
					if (!res.ok || !res.json || !res.json.success) {
						submitBtn.disabled = false;
						var msg = (res.json && res.json.data && res.json.data.message) ? res.json.data.message : PQC.i18n.error;
						showStatus(msg, 'is-error');
						return;
					}
					if (PQC.thankYouUrl) {
						var sep = PQC.thankYouUrl.indexOf('?') >= 0 ? '&' : '?';
						window.location.href = PQC.thankYouUrl + sep + 'pqc_submitted=1';
						return;
					}
					hideStatus();
					showInlineThanks(emailInput.value);
				})
				.catch(function () {
					submitBtn.disabled = false;
					showStatus(PQC.i18n.error, 'is-error');
				});
		});
	});
})();
