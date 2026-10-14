/* Urban Emirates admin: processes the queue one job at a time and shows live progress. */
(function () {
	'use strict';

	var box = document.getElementById('kj-progress');
	if (box && parseInt(box.dataset.remaining, 10) > 0) {
		var list = document.getElementById('kj-progress-list');
		var left = document.getElementById('kj-remaining');
		var done = document.getElementById('kj-progress-done');
		var failures = 0;
		var icons = { ready: '🟢', published: '✅', error: '⚠️', duplicate: '♻️', queued: '⏳' };
		var riskIcons = { green: '🟢', yellow: '🟡', red: '🔴' };

		var addLine = function (text, cls) {
			var li = document.createElement('li');
			li.textContent = text;
			if (cls) li.className = cls;
			list.appendChild(li);
		};

		var finish = function () {
			box.querySelector('h2').textContent = 'Finished checking jobs';
			done.hidden = false;
		};

		var next = function () {
			var body = new URLSearchParams({ action: 'kj_process_next', nonce: KJAdmin.nonce });
			fetch(KJAdmin.ajaxUrl, { method: 'POST', body: body, credentials: 'same-origin' })
				.then(function (r) { return r.json(); })
				.then(function (res) {
					if (!res.success) throw new Error(res.data || 'Request failed');
					failures = 0;
					var r = res.data.result;
					left.textContent = res.data.remaining;
					if (r) {
						var icon = r.risk ? riskIcons[r.risk] : (icons[r.state] || '•');
						addLine(icon + ' ' + r.title + ' — ' + r.message, 'kj-p-' + r.state);
						if (r.stop) {
							addLine('Stopped: fix the problem above, then reload this page.', 'kj-p-error');
							return;
						}
					}
					if (res.data.remaining > 0 && r) {
						next();
					} else {
						finish();
					}
				})
				.catch(function (err) {
					failures++;
					if (failures >= 3) {
						addLine('Connection problem (' + err.message + '). The rest will be processed in the background.', 'kj-p-error');
						return;
					}
					setTimeout(next, 5000);
				});
		};
		next();
	}

	document.addEventListener('click', function (e) {
		var btn = e.target.closest('[data-kj-copy]');
		if (!btn) return;
		var field = document.querySelector(btn.getAttribute('data-kj-copy'));
		field.select();
		var done = function () { btn.textContent = 'Copied ✓'; setTimeout(function () { btn.textContent = 'Copy message'; }, 2000); };
		if (navigator.clipboard) {
			navigator.clipboard.writeText(field.value).then(done, function () { document.execCommand('copy'); done(); });
		} else {
			document.execCommand('copy');
			done();
		}
	});
})();
