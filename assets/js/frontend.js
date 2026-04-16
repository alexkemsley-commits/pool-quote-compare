(function () {
	'use strict';

	document.addEventListener('DOMContentLoaded', function () {
		var form = document.getElementById('pqc-form');
		if (!form) return;

		var priorityInputs = form.querySelectorAll('input[data-pqc-priority]');
		var priorityWarn   = document.getElementById('pqc-priority-warning');
		function enforcePriorityLimit() {
			var checked = form.querySelectorAll('input[data-pqc-priority]:checked');
			var atMax   = checked.length >= 2;
			for (var i = 0; i < priorityInputs.length; i++) {
				if (!priorityInputs[i].checked) {
					priorityInputs[i].disabled = atMax;
					priorityInputs[i].parentNode.classList.toggle('is-disabled', atMax);
				}
			}
			if (priorityWarn) {
				priorityWarn.hidden = !atMax;
				if (atMax) {
					priorityWarn.textContent = 'Two picked. Untick one to swap.';
				}
			}
		}
		for (var p = 0; p < priorityInputs.length; p++) {
			priorityInputs[p].addEventListener('change', enforcePriorityLimit);
		}

		var status      = document.getElementById('pqc-status');
		var result      = document.getElementById('pqc-result');
		var resultTitle = document.getElementById('pqc-result-title');
		var resultBody  = document.getElementById('pqc-result-body');
		var sentNote    = document.getElementById('pqc-sent-note');
		var submitBtn   = form.querySelector('.pqc-submit');
		var filesInput  = document.getElementById('pqc-files');
		var spinner     = document.getElementById('pqc-spinner');
		var progressTxt = document.getElementById('pqc-progress-text');
		var progressMeta = document.getElementById('pqc-progress-meta');
		var progressBox = document.getElementById('pqc-progress');

		var pollTimer = null;
		var startedAt = 0;

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

		function fmtSecs(s) {
			if (s < 60) return s + 's';
			var m = Math.floor(s / 60);
			var r = s % 60;
			return m + 'm ' + (r < 10 ? '0' + r : r) + 's';
		}

		function setProgress(label) {
			if (progressTxt) progressTxt.textContent = label;
		}

		function tickMeta() {
			if (!progressMeta) return;
			var elapsed = Math.floor((Date.now() - startedAt) / 1000);
			var chars   = resultBody.textContent.length;
			progressMeta.textContent = 'Elapsed ' + fmtSecs(elapsed) + (chars ? ' · ' + chars.toLocaleString() + ' characters written' : '');
		}

		function stopPolling() {
			if (pollTimer) {
				clearTimeout(pollTimer);
				pollTimer = null;
			}
		}

		function showResult(text) {
			result.hidden = false;
			resultBody.textContent = text || '';
		}

		function pollOnce(id, token) {
			var url = PQC.ajaxUrl + '?action=pqc_status&id=' + encodeURIComponent(id) + '&token=' + encodeURIComponent(token);
			fetch(url, { credentials: 'same-origin' })
				.then(function (r) { return r.json().then(function (j) { return { ok: r.ok, json: j }; }); })
				.then(function (res) {
					if (!res.ok || !res.json || !res.json.success) {
						pollTimer = setTimeout(function () { pollOnce(id, token); }, 4000);
						return;
					}
					var data = res.json.data;
					var st   = data.status;

					if (data.response) {
						showResult(data.response);
					}
					tickMeta();

					switch (st) {
						case 'queued':
							setProgress(PQC.i18n.queued);
							break;
						case 'processing':
							setProgress(PQC.i18n.reading);
							break;
						case 'streaming':
							setProgress(PQC.i18n.writing);
							break;
						case 'completed':
						case 'partial':
							setProgress(st === 'completed' ? PQC.i18n.done : PQC.i18n.donePartial);
							resultTitle.textContent = PQC.i18n.resultTitle;
							if (spinner) spinner.classList.add('is-done');
							if (data.emailed) {
								sentNote.hidden = false;
								sentNote.textContent = PQC.i18n.emailed;
							}
							submitBtn.disabled = false;
							stopPolling();
							return;
						case 'failed':
							setProgress(PQC.i18n.failed);
							progressBox.classList.add('is-error');
							if (data.error) {
								resultBody.textContent = data.error;
							}
							submitBtn.disabled = false;
							stopPolling();
							return;
					}

					pollTimer = setTimeout(function () { pollOnce(id, token); }, 2500);
				})
				.catch(function () {
					pollTimer = setTimeout(function () { pollOnce(id, token); }, 4000);
				});
		}

		form.addEventListener('submit', function (e) {
			e.preventDefault();
			hideStatus();
			result.hidden = true;
			resultBody.textContent = '';
			sentNote.hidden = true;
			if (progressBox) progressBox.classList.remove('is-error');
			if (spinner) spinner.classList.remove('is-done');

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
					hideStatus();
					var id    = res.json.data.id;
					var token = res.json.data.token;

					startedAt = Date.now();
					result.hidden = false;
					resultTitle.textContent = PQC.i18n.workingTitle;
					setProgress(PQC.i18n.queued);
					tickMeta();
					setInterval(tickMeta, 1000);

					setTimeout(function () { pollOnce(id, token); }, 800);
				})
				.catch(function () {
					submitBtn.disabled = false;
					showStatus(PQC.i18n.error, 'is-error');
				});
		});
	});
})();
