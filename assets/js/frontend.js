(function () {
	'use strict';

	document.addEventListener('DOMContentLoaded', function () {
		var form = document.getElementById('pqc-form');
		if (!form) return;

		var status = document.getElementById('pqc-status');
		var result = document.getElementById('pqc-result');
		var resultBody = document.getElementById('pqc-result-body');
		var sentNote = document.getElementById('pqc-sent-note');
		var submitBtn = form.querySelector('.pqc-submit');
		var filesInput = document.getElementById('pqc-files');

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

		form.addEventListener('submit', function (e) {
			e.preventDefault();
			hideStatus();
			result.hidden = true;
			resultBody.textContent = '';
			sentNote.hidden = true;

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
			showStatus(PQC.i18n.submitting, 'is-loading');

			fetch(PQC.ajaxUrl, {
				method: 'POST',
				body: fd,
				credentials: 'same-origin'
			})
				.then(function (r) { return r.json().then(function (j) { return { ok: r.ok, json: j }; }); })
				.then(function (res) {
					submitBtn.disabled = false;
					if (!res.ok || !res.json || !res.json.success) {
						var msg = (res.json && res.json.data && res.json.data.message) ? res.json.data.message : PQC.i18n.error;
						showStatus(msg, 'is-error');
						return;
					}
					hideStatus();
					result.hidden = false;
					resultBody.textContent = res.json.data.response || '';
					if (res.json.data.emailed) {
						sentNote.hidden = false;
						sentNote.textContent = 'A copy has been emailed to you.';
					}
					result.scrollIntoView({ behavior: 'smooth', block: 'start' });
				})
				.catch(function () {
					submitBtn.disabled = false;
					showStatus(PQC.i18n.error, 'is-error');
				});
		});
	});
})();
