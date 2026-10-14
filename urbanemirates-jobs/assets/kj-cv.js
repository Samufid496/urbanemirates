/* Urban Emirates — CV builder (8 steps, autosave, AI help, preview and downloads).
   Plain JavaScript, no libraries. Talks to the plugin's REST API at KJCV.rest. */
(function () {
	'use strict';

	var app = document.getElementById('kj-cv-app');
	if (!app || typeof KJCV === 'undefined') return;

	var STEPS = [
		{ key: 'about', title: 'About you', sub: 'Gulf employers expect these details.' },
		{ key: 'target', title: 'Target job', sub: 'The job you want next. We use it to match jobs for you too.' },
		{ key: 'summary', title: 'Profile', sub: 'Two or three lines at the top of your CV.' },
		{ key: 'experience', title: 'Experience', sub: 'Most recent job first.' },
		{ key: 'education', title: 'Education', sub: 'Your highest qualification first.' },
		{ key: 'skills', title: 'Skills & languages', sub: 'Tools, machines, software and languages you know.' },
		{ key: 'licences', title: 'Licences & certificates', sub: 'Gulf employers look for these. Add only what you have.' },
		{ key: 'preview', title: 'Your CV is ready', sub: '' }
	];
	var GULF = ['United Arab Emirates', 'Saudi Arabia', 'Qatar', 'Kuwait', 'Oman', 'Bahrain'];
	var LICENCE_IDEAS = ['UAE driving licence', 'GCC driving licence', 'Indian driving licence', 'DHA licence', 'MOH licence', 'DOH licence', 'Prometric passed', 'NEBOSH', 'IOSH', 'Forklift licence'];

	var state = {
		id: 0,
		cv: null,
		template: 'gulf',
		title: '',
		downloads: {},
		step: 0,
		dirty: false,
		saving: false,
		saveError: false,
		moreDetails: false,
		aiLeft: null
	};
	var saveTimer = null;

	/* ---------- small helpers ---------- */

	function h(tag, attrs) {
		var el = document.createElement(tag);
		attrs = attrs || {};
		Object.keys(attrs).forEach(function (k) {
			var v = attrs[k];
			if (v === null || v === undefined || v === false) return;
			if (k === 'class') el.className = v;
			else if (k === 'text') el.textContent = v;
			else if (k === 'html') el.innerHTML = v;
			else if (k.slice(0, 2) === 'on') el.addEventListener(k.slice(2), v);
			else if (k === 'value') el.value = v;
			else if (k === 'checked') el.checked = !!v;
			else el.setAttribute(k, v === true ? '' : v);
		});
		for (var i = 2; i < arguments.length; i++) {
			var c = arguments[i];
			if (c === null || c === undefined || c === false) continue;
			if (Array.isArray(c)) c.forEach(function (x) { if (x) el.appendChild(typeof x === 'string' ? document.createTextNode(x) : x); });
			else el.appendChild(typeof c === 'string' ? document.createTextNode(c) : c);
		}
		return el;
	}

	var ICONS = {
		back: '<path d="M15 18l-6-6 6-6"/>',
		plus: '<path d="M12 5v14M5 12h14"/>',
		spark: '<path d="M12 3l1.8 4.7L18.5 9.5l-4.7 1.8L12 16l-1.8-4.7L5.5 9.5l4.7-1.8z"/><path d="M19 15l.8 2.2 2.2.8-2.2.8L19 21l-.8-2.2-2.2-.8 2.2-.8z"/>',
		trash: '<path d="M4 7h16M10 11v6M14 11v6M6 7l1 13h10l1-13M9 7V4h6v3"/>',
		download: '<path d="M12 3v12"/><path d="m7 10 5 5 5-5"/><path d="M5 21h14"/>',
		chevron: '<path d="m9 18 6-6-6-6"/>'
	};
	function icon(name) {
		var span = document.createElement('span');
		span.className = 'kj-cv-ic';
		span.setAttribute('aria-hidden', 'true');
		span.innerHTML = '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' + ICONS[name] + '</svg>';
		return span;
	}

	function get(path) {
		return path.split('.').reduce(function (o, k) { return o == null ? undefined : o[k]; }, state.cv);
	}
	function set(path, value) {
		var keys = path.split('.');
		var last = keys.pop();
		var obj = keys.reduce(function (o, k) { return o[k]; }, state.cv);
		obj[last] = value;
		changed();
	}

	function api(method, path, body) {
		return fetch(KJCV.rest + path, {
			method: method,
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': KJCV.nonce },
			body: body ? JSON.stringify(body) : undefined
		}).then(function (r) {
			return r.json().then(function (data) {
				if (!r.ok) throw new Error((data && data.message) || 'Something went wrong.');
				return data;
			});
		});
	}

	function toast(msg) {
		var t = h('div', { class: 'kj-cv-toast', role: 'status', text: msg });
		document.body.appendChild(t);
		setTimeout(function () { t.classList.add('is-out'); }, 3200);
		setTimeout(function () { t.remove(); }, 3700);
	}

	/* ---------- saving ---------- */

	function changed() {
		state.dirty = true;
		updateSaveLabel();
		clearTimeout(saveTimer);
		saveTimer = setTimeout(save, 900);
	}

	function save() {
		clearTimeout(saveTimer);
		if (!state.dirty || !state.id) return Promise.resolve();
		state.dirty = false;
		state.saving = true;
		updateSaveLabel();
		return api('PUT', 'cvs/' + state.id, { cv: state.cv, template: state.template, title: state.title })
			.then(function () { state.saving = false; state.saveError = false; updateSaveLabel(); })
			.catch(function () { state.saving = false; state.saveError = true; state.dirty = true; updateSaveLabel(); });
	}

	function updateSaveLabel() {
		var el = app.querySelector('.kj-cv-saved');
		if (!el) return;
		el.textContent = state.saveError ? 'Not saved — check internet' : (state.saving || state.dirty ? 'Saving…' : 'Saved');
		el.classList.toggle('is-error', state.saveError);
	}

	window.addEventListener('beforeunload', function (e) {
		if (state.dirty) { save(); e.preventDefault(); e.returnValue = ''; }
	});

	/* ---------- form controls ---------- */

	function field(label, path, opts) {
		opts = opts || {};
		var id = 'kj-cv-' + path.replace(/\./g, '-');
		var input;
		if (opts.textarea) {
			input = h('textarea', { id: id, rows: opts.rows || 4, placeholder: opts.placeholder || null, class: 'kj-input kj-textarea' });
			input.value = get(path) || '';
		} else if (opts.options) {
			input = h('select', { id: id, class: 'kj-input' },
				h('option', { value: '', text: opts.empty || 'Choose…' }),
				Object.keys(opts.options).map(function (k) { return h('option', { value: k, text: opts.options[k] }); }));
			input.value = get(path) || '';
		} else {
			input = h('input', { id: id, type: opts.type || 'text', class: 'kj-input', placeholder: opts.placeholder || null, autocomplete: opts.autocomplete || 'off', inputmode: opts.inputmode || null });
			input.value = get(path) || '';
		}
		input.addEventListener(opts.options ? 'change' : 'input', function () {
			set(path, input.value);
			if (opts.onChange) opts.onChange(input.value);
		});
		return h('label', { class: 'kj-input-label' + (opts.half ? ' kj-half' : ''), for: id }, label, opts.hint ? h('span', { class: 'kj-hint', text: opts.hint }) : null, input);
	}

	function row() {
		return h('div', { class: 'kj-cv-row' }, Array.prototype.slice.call(arguments));
	}

	function aiButton(label, onClick) {
		return h('button', { type: 'button', class: 'kj-cv-ai-btn', onclick: onClick }, icon('spark'), label);
	}

	/* ---------- AI sheet ---------- */

	function sheet(title, bodyNodes, actions) {
		closeSheet();
		var backdrop = h('div', { class: 'kj-cv-backdrop', onclick: closeSheet });
		var panel = h('section', { class: 'kj-cv-sheet', role: 'dialog', 'aria-modal': 'true', 'aria-label': title },
			h('div', { class: 'kj-cv-sheet-grip', 'aria-hidden': 'true' }),
			h('h2', { class: 'kj-cv-sheet-title' }, icon('spark'), title),
			h('div', { class: 'kj-cv-sheet-body' }, bodyNodes),
			actions ? h('div', { class: 'kj-cv-sheet-actions' }, actions) : null);
		document.body.appendChild(backdrop);
		document.body.appendChild(panel);
		document.body.classList.add('kj-cv-locked');
		var focusable = panel.querySelector('button');
		if (focusable) focusable.focus();
		document.addEventListener('keydown', escClose);
	}
	function escClose(e) { if (e.key === 'Escape') closeSheet(); }
	function closeSheet() {
		document.querySelectorAll('.kj-cv-backdrop, .kj-cv-sheet').forEach(function (n) { n.remove(); });
		document.body.classList.remove('kj-cv-locked');
		document.removeEventListener('keydown', escClose);
	}

	function runAI(action, extra, show) {
		sheet('Working on it…', [h('p', { class: 'kj-cv-thinking', text: 'Writing — this takes a few seconds.' })]);
		var body = Object.assign({ action: action, cv: state.cv }, extra || {});
		api('POST', 'cvs/' + state.id + '/ai', body)
			.then(function (res) { state.aiLeft = res.left; show(res); })
			.catch(function (err) {
				sheet('AI help', [h('p', { text: err.message })], [h('button', { type: 'button', class: 'kj-btn kj-btn-primary kj-btn-block', onclick: closeSheet, text: 'OK' })]);
			});
	}

	function honestNote() {
		return h('p', { class: 'kj-cv-note', text: 'Based only on what you wrote. Check it is true before using it.' + (state.aiLeft !== null ? ' AI help left today: ' + state.aiLeft + '.' : '') });
	}

	function suggestionActions(onRetry, onUse) {
		return [
			h('button', { type: 'button', class: 'kj-btn kj-btn-outline', onclick: onRetry, text: 'Try again' }),
			h('button', { type: 'button', class: 'kj-btn kj-btn-primary kj-btn-block', onclick: function () { onUse(); closeSheet(); render(); }, text: 'Use this' })
		];
	}

	function aiSummary() {
		runAI('summary', null, function (res) {
			sheet('Profile by AI', [h('p', { class: 'kj-cv-suggest', text: res.summary }), honestNote()],
				suggestionActions(aiSummary, function () { set('summary', res.summary); }));
		});
	}

	function aiImprove(i) {
		runAI('improve', { index: i }, function (res) {
			sheet('Improved by AI', [h('ul', { class: 'kj-cv-suggest' }, res.bullets.map(function (b) { return h('li', { text: b }); })), honestNote()],
				suggestionActions(function () { aiImprove(i); }, function () { set('experience.' + i + '.duties', res.bullets.join('\n')); }));
		});
	}

	function aiTailor(jobId) {
		runAI('tailor', { job: jobId }, function (res) {
			sheet('Tailored for this job', [
				h('h3', { class: 'kj-label', text: 'New profile' }), h('p', { class: 'kj-cv-suggest', text: res.summary }),
				h('h3', { class: 'kj-label', text: 'Skills, best first' }), h('p', { class: 'kj-cv-suggest', text: res.skills }),
				res.notes && res.notes.length ? h('h3', { class: 'kj-label', text: 'Tips' }) : null,
				res.notes && res.notes.length ? h('ul', { class: 'kj-cv-tips' }, res.notes.map(function (n) { return h('li', { text: n }); })) : null,
				honestNote()
			], suggestionActions(function () { aiTailor(jobId); }, function () { set('summary', res.summary); if (res.skills) set('skills', res.skills); }));
		});
	}

	function aiCheck() {
		runAI('check', null, function (res) {
			var score = Math.max(0, Math.min(10, res.score | 0));
			sheet('Gulf CV check', [
				h('div', { class: 'kj-cv-score' }, h('span', { class: 'kj-cv-score-num', text: score + '/10' }), h('span', { text: score >= 8 ? 'Strong CV' : score >= 5 ? 'Good start' : 'Needs more detail' })),
				res.tips && res.tips.length ? h('h3', { class: 'kj-label', text: 'To improve' }) : null,
				res.tips && res.tips.length ? h('ul', { class: 'kj-cv-tips' }, res.tips.map(function (t) { return h('li', { text: t }); })) : null,
				res.strengths && res.strengths.length ? h('h3', { class: 'kj-label', text: 'Strong points' }) : null,
				res.strengths && res.strengths.length ? h('ul', { class: 'kj-cv-tips is-good' }, res.strengths.map(function (t) { return h('li', { text: t }); })) : null
			], [h('button', { type: 'button', class: 'kj-btn kj-btn-primary kj-btn-block', onclick: closeSheet, text: 'OK' })]);
		});
	}

	/* ---------- steps ---------- */

	function stepAbout() {
		var p = state.cv.personal;
		var photo = h('div', { class: 'kj-cv-photo' });
		var fileInput = h('input', { type: 'file', accept: 'image/*', class: 'kj-sr', id: 'kj-cv-photo-file', onchange: function (e) { if (e.target.files[0]) loadPhoto(e.target.files[0]); } });
		if (p.photo) {
			photo.appendChild(h('img', { src: p.photo, alt: 'Your photo' }));
		} else {
			photo.appendChild(h('label', { for: 'kj-cv-photo-file', class: 'kj-cv-photo-add', 'aria-label': 'Add photo' }, icon('plus')));
		}
		var photoRow = h('div', { class: 'kj-cv-photo-row' }, photo, fileInput,
			h('div', null,
				h('strong', { text: p.photo ? 'Your photo' : 'Add a photo' }),
				h('span', { class: 'kj-hint', text: 'Optional · common for Gulf CVs' }),
				p.photo ? h('span', { class: 'kj-cv-inline-actions' },
					h('label', { for: 'kj-cv-photo-file', class: 'kj-link-btn', text: 'Change' }),
					h('button', { type: 'button', class: 'kj-link-btn is-danger', onclick: function () { set('personal.photo', ''); render(); }, text: 'Remove' })) : null));

		var where = h('fieldset', { class: 'kj-cv-choice' },
			h('legend', { text: 'Where are you now?' }),
			h('div', { class: 'kj-cv-choice-grid' }, [['india', 'In India'], ['gulf', 'In the Gulf'], ['other', 'Elsewhere']].map(function (o) {
				return h('label', { class: 'kj-cv-choice-opt' + (p.location_type === o[0] ? ' is-on' : '') },
					h('input', { type: 'radio', name: 'kj-loc', value: o[0], checked: p.location_type === o[0], onchange: function () {
						set('personal.location_type', o[0]);
						if (o[0] === 'india') { set('personal.country', 'India'); if (!p.visa_status) set('personal.visa_status', 'need'); }
						if (o[0] === 'gulf' && GULF.indexOf(p.country) < 0) set('personal.country', '');
						render();
					} }), o[1]);
			})));

		var place = [];
		if (p.location_type === 'gulf') {
			var gulfOptions = {};
			GULF.forEach(function (c) { gulfOptions[c] = c; });
			place.push(row(field('City', 'personal.city', { placeholder: 'e.g. Dubai', half: true }), field('Country', 'personal.country', { options: gulfOptions, half: true })));
			place.push(row(field('Visa status', 'personal.visa_status', { options: KJCV.visa, half: true }), field('Notice period', 'personal.notice', { options: KJCV.notice, half: true })));
			place.push(field('Phone / WhatsApp', 'personal.phone', { type: 'tel', placeholder: '+971 50 123 4567', autocomplete: 'tel' }));
		} else if (p.location_type) {
			place.push(row(field('City', 'personal.city', { placeholder: p.location_type === 'india' ? 'e.g. Mangaluru' : 'City', half: true }),
				p.location_type === 'india' ? field('Notice period', 'personal.notice', { options: KJCV.notice, half: true }) : field('Country', 'personal.country', { half: true })));
			place.push(field('Phone / WhatsApp', 'personal.phone', { type: 'tel', placeholder: p.location_type === 'india' ? '+91 98765 43210' : '+ country code', autocomplete: 'tel' }));
			if (p.location_type !== 'india') place.push(row(field('Visa status', 'personal.visa_status', { options: KJCV.visa, half: true }), field('Notice period', 'personal.notice', { options: KJCV.notice, half: true })));
		}

		var more = state.moreDetails
			? [row(field('Nationality', 'personal.nationality', { placeholder: 'e.g. Indian', half: true }), field('Date of birth', 'personal.dob', { type: 'date', half: true })),
				field('Driving licence', 'personal.driving_licence', { placeholder: 'e.g. UAE light vehicle, Indian LMV' }),
				field('Email on CV', 'personal.email', { type: 'email', autocomplete: 'email' })]
			: [h('button', { type: 'button', class: 'kj-link-btn kj-cv-more', onclick: function () { state.moreDetails = true; render(); }, text: '+ Nationality, date of birth, driving licence (optional)' })];

		return [photoRow, field('Full name', 'personal.name', { autocomplete: 'name' }), where].concat(place, more);
	}

	function loadPhoto(file) {
		var img = new Image();
		var url = URL.createObjectURL(file);
		img.onload = function () {
			var size = 320;
			var canvas = document.createElement('canvas');
			canvas.width = size; canvas.height = size;
			var s = Math.min(img.width, img.height);
			var ctx = canvas.getContext('2d');
			ctx.fillStyle = '#fff'; ctx.fillRect(0, 0, size, size);
			ctx.drawImage(img, (img.width - s) / 2, (img.height - s) / 2, s, s, 0, 0, size, size);
			URL.revokeObjectURL(url);
			set('personal.photo', canvas.toDataURL('image/jpeg', 0.82));
			render();
		};
		img.onerror = function () { toast('That file is not a photo we can use. Try a JPG or PNG.'); };
		img.src = url;
	}

	function stepTarget() {
		var t = state.cv.target;
		var chips = h('div', { class: 'kj-cv-chips' }, Object.keys(KJCV.countries).map(function (k) {
			var on = t.countries.indexOf(k) >= 0;
			return h('button', { type: 'button', class: 'kj-chip ' + (on ? 'kj-chip-active' : 'kj-chip-outline'), 'aria-pressed': on ? 'true' : 'false', onclick: function () {
				var list = t.countries.slice();
				if (on) list.splice(list.indexOf(k), 1); else list.push(k);
				set('target.countries', list);
				render();
			}, text: KJCV.countries[k] });
		}));
		return [
			field('Job title you want', 'target.title', { placeholder: 'e.g. Staff Nurse, Accountant, Heavy Driver', onChange: function (v) {
				if (!state.cv.personal.headline || state.cv.personal.headline === state._lastAutoHeadline) { state.cv.personal.headline = v; state._lastAutoHeadline = v; }
			} }),
			field('Job type', 'target.category', { options: KJCV.categories }),
			h('fieldset', { class: 'kj-cv-choice' }, h('legend', { text: 'Countries you want to work in' }), h('span', { class: 'kj-hint', text: 'Leave empty for any Gulf country.' }), chips),
			field('Title shown under your name', 'personal.headline', { placeholder: 'e.g. Registered Nurse · 5 years ICU', hint: 'Optional' })
		];
	}

	function tailorCard() {
		var jobs = (KJCV.savedJobs || []).slice();
		if (KJCV.tailorJob && !jobs.some(function (j) { return j.id === KJCV.tailorJob.id; })) jobs.unshift(KJCV.tailorJob);
		if (!jobs.length) {
			return h('div', { class: 'kj-cv-card is-soft' },
				h('strong', { text: 'Tailor your CV to a job' }),
				h('p', { class: 'kj-hint', text: 'Save a job (bookmark on any job page) and you can tailor your CV to it here.' }));
		}
		var select = h('select', { class: 'kj-input', 'aria-label': 'Job to tailor for' }, jobs.map(function (j) { return h('option', { value: j.id, text: j.label }); }));
		if (KJCV.tailorJob) select.value = KJCV.tailorJob.id;
		return h('div', { class: 'kj-cv-card is-soft' },
			h('strong', { text: 'Tailor your CV to a job' }),
			h('p', { class: 'kj-hint', text: 'AI rewrites your profile and orders your skills for the job you pick. Nothing is invented.' }),
			select,
			aiButton('Tailor for this job', function () { aiTailor(parseInt(select.value, 10)); }));
	}

	function stepSummary() {
		return [
			field('Profile', 'summary', { textarea: true, rows: 6, placeholder: 'e.g. Registered nurse with 5 years in ICU and emergency care, DHA eligible, available to join in 1 month.' }),
			aiButton(state.cv.summary ? 'Rewrite with AI' : 'Write it for me with AI', aiSummary),
			tailorCard()
		];
	}

	function repeater(listKey, blank, renderItem, addLabel) {
		var items = state.cv[listKey];
		var out = items.map(function (item, i) {
			return h('div', { class: 'kj-cv-card' }, renderItem(item, i),
				h('button', { type: 'button', class: 'kj-link-btn is-danger kj-cv-remove', onclick: function () {
					items.splice(i, 1); changed(); render();
				} }, icon('trash'), 'Remove'));
		});
		out.push(h('button', { type: 'button', class: 'kj-btn kj-btn-outline kj-cv-add', onclick: function () {
			items.push(JSON.parse(JSON.stringify(blank))); changed(); render();
			var cards = app.querySelectorAll('.kj-cv-card');
			var last = cards[cards.length - 1];
			if (last) { var f = last.querySelector('input'); if (f) f.focus(); }
		} }, icon('plus'), addLabel));
		return out;
	}

	function stepExperience() {
		if (!state.cv.experience.length) state.cv.experience.push({ title: '', company: '', city: '', start: '', end: '', current: false, duties: '' });
		return repeater('experience', { title: '', company: '', city: '', start: '', end: '', current: false, duties: '' }, function (e, i) {
			var base = 'experience.' + i + '.';
			var current = h('label', { class: 'kj-consent' }, h('input', { type: 'checkbox', checked: e.current, onchange: function (ev) { set(base + 'current', ev.target.checked); render(); } }), h('span', { text: 'I work here now' }));
			return [
				row(field('Job title', base + 'title', { half: true }), field('Company', base + 'company', { half: true })),
				field('City', base + 'city', { placeholder: 'e.g. Dubai' }),
				row(field('From', base + 'start', { type: 'month', half: true }), e.current ? h('div', { class: 'kj-input-label kj-half kj-cv-now', text: 'Until now' }) : field('To', base + 'end', { type: 'month', half: true })),
				current,
				field('What did you do?', base + 'duties', { textarea: true, rows: 4, placeholder: 'Write in your own words, one point per line. AI can tidy it up.' }),
				aiButton('Improve with AI', function () { aiImprove(i); })
			];
		}, 'Add another job');
	}

	function stepEducation() {
		if (!state.cv.education.length) state.cv.education.push({ degree: '', school: '', year: '' });
		return repeater('education', { degree: '', school: '', year: '' }, function (e, i) {
			var base = 'education.' + i + '.';
			return [
				field('Qualification', base + 'degree', { placeholder: 'e.g. B.Sc Nursing, ITI Electrician, B.Com' }),
				row(field('College / school', base + 'school', { half: true }), field('Year', base + 'year', { placeholder: 'e.g. 2018', inputmode: 'numeric', half: true }))
			];
		}, 'Add another qualification');
	}

	function stepSkills() {
		return [
			field('Skills', 'skills', { textarea: true, rows: 4, placeholder: 'e.g. Tally, SAP, VAT returns, Excel, Payroll', hint: 'Separate with commas' }),
			field('Languages', 'languages', { placeholder: 'e.g. English (fluent), Hindi, Konkani, Arabic (basic)' })
		];
	}

	function stepLicences() {
		var ideas = h('div', { class: 'kj-cv-chips' }, LICENCE_IDEAS.map(function (name) {
			return h('button', { type: 'button', class: 'kj-chip kj-chip-outline', onclick: function () {
				state.cv.licences.push({ name: name, detail: '' }); changed(); render();
			} }, icon('plus'), name);
		}));
		var list = repeater('licences', { name: '', detail: '' }, function (l, i) {
			var base = 'licences.' + i + '.';
			return [row(field('Licence or certificate', base + 'name', { half: true }), field('Details', base + 'detail', { placeholder: 'e.g. valid to 2027', half: true }))];
		}, 'Add another');
		return [h('p', { class: 'kj-hint', text: 'Tap to add:' }), ideas].concat(list);
	}

	function stepPreview() {
		var tabs = h('div', { class: 'kj-cv-tabs', role: 'tablist', 'aria-label': 'CV design' }, Object.keys(KJCV.templates).map(function (k) {
			return h('button', { type: 'button', role: 'tab', 'aria-selected': state.template === k ? 'true' : 'false', class: state.template === k ? 'is-on' : '', onclick: function () {
				state.template = k; changed(); render();
			}, text: KJCV.templates[k] });
		}));
		var paper = h('div', { class: 'kj-cv-paper' }, h('p', { class: 'kj-muted', text: 'Loading preview…' }));
		var frame = h('div', { class: 'kj-cv-paper-frame' }, paper);
		save().then(function () {
			return api('GET', 'cvs/' + state.id + '/preview?template=' + state.template);
		}).then(function (res) {
			paper.innerHTML = res.html;
			fitPaper(frame, paper);
		}).catch(function () { paper.innerHTML = '<p>Could not load the preview.</p>'; });

		var check = h('button', { type: 'button', class: 'kj-cv-check', onclick: aiCheck },
			h('span', { class: 'kj-cv-check-badge', text: 'AI' }),
			h('span', { class: 'kj-cv-check-text' }, h('strong', { text: 'Gulf CV check' }), h('span', { class: 'kj-hint', text: 'Get a score and tips to improve' })),
			icon('chevron'));

		return [tabs, frame, check];
	}

	function fitPaper(frame, paper) {
		var w = frame.clientWidth;
		var scale = Math.min(1, w / 794);
		paper.style.transform = 'scale(' + scale + ')';
		frame.style.height = Math.ceil(paper.scrollHeight * scale) + 'px';
	}

	var RENDER = [stepAbout, stepTarget, stepSummary, stepExperience, stepEducation, stepSkills, stepLicences, stepPreview];

	/* ---------- page ---------- */

	function go(step) {
		state.step = Math.max(0, Math.min(STEPS.length - 1, step));
		var url = new URL(window.location.href);
		url.searchParams.set('cv', state.id);
		url.searchParams.set('step', state.step + 1);
		url.searchParams.delete('new');
		history.replaceState(null, '', url.toString());
		render();
		window.scrollTo(0, 0);
		var h1 = app.querySelector('h1');
		if (h1) h1.focus();
	}

	function render() {
		var s = STEPS[state.step];
		var isLast = state.step === STEPS.length - 1;
		var pct = Math.round(((state.step + 1) / STEPS.length) * 100);

		var top = h('div', { class: 'kj-cv-top' },
			state.step > 0
				? h('button', { type: 'button', class: 'kj-icon-btn', 'aria-label': 'Back', onclick: function () { go(state.step - 1); } }, icon('back'))
				: h('a', { class: 'kj-icon-btn', href: KJCV.accountUrl, 'aria-label': 'Back to My account' }, icon('back')),
			h('span', { class: 'kj-cv-stepno', text: isLast ? 'Preview' : 'Step ' + (state.step + 1) + ' of ' + (STEPS.length - 1) }),
			h('span', { class: 'kj-cv-saved', 'aria-live': 'polite' }));

		var progress = h('div', { class: 'kj-cv-progress', role: 'progressbar', 'aria-valuemin': '0', 'aria-valuemax': '100', 'aria-valuenow': String(pct) }, h('div', { style: 'width:' + pct + '%' }));

		var head = h('div', { class: 'kj-cv-head' },
			h('h1', { class: 'kj-cv-title', tabindex: '-1', text: s.title }),
			s.sub ? h('p', { class: 'kj-cv-sub', text: s.sub }) : null);

		var body = h('div', { class: 'kj-cv-body' }, RENDER[state.step]());

		var bar;
		if (isLast) {
			bar = h('div', { class: 'kj-cv-bar' },
				h('a', { class: 'kj-btn kj-btn-primary kj-btn-block', href: state.downloads.pdf + '&template=' + state.template, onclick: function () { save(); } }, icon('download'), 'Download PDF'),
				h('div', { class: 'kj-cv-bar-row' },
					KJCV.docx ? h('a', { class: 'kj-btn kj-btn-outline', href: state.downloads.docx + '&template=' + state.template, onclick: function () { save(); }, text: 'Word (.docx)' }) : null,
					h('a', { class: 'kj-btn kj-btn-outline', href: state.downloads.txt, text: 'Plain text' })),
				h('a', { class: 'kj-cv-done', href: KJCV.accountUrl, onclick: function () { save(); }, text: 'Done — go to My account' }));
		} else {
			bar = h('div', { class: 'kj-cv-bar' },
				h('button', { type: 'button', class: 'kj-btn kj-btn-primary kj-btn-block', onclick: function () { save(); go(state.step + 1); }, text: 'Next: ' + STEPS[state.step + 1].title }));
		}

		app.innerHTML = '';
		app.appendChild(h('div', { class: 'kj-cv-wrap' }, top, progress, head, body, bar));
		updateSaveLabel();
	}

	window.addEventListener('resize', function () {
		var frame = app.querySelector('.kj-cv-paper-frame');
		var paper = app.querySelector('.kj-cv-paper');
		if (frame && paper) fitPaper(frame, paper);
	});

	/* ---------- start ---------- */

	function load(row) {
		state.id = row.id;
		state.cv = row.cv;
		state.template = row.template || 'gulf';
		state.title = row.title;
		state.downloads = row.downloads;
		state.moreDetails = !!(row.cv.personal.nationality || row.cv.personal.dob || row.cv.personal.driving_licence);
		var params = new URLSearchParams(window.location.search);
		var step = parseInt(params.get('step'), 10);
		if (params.get('tailor')) step = 3;
		go(step > 0 ? step - 1 : 0);
	}

	var params = new URLSearchParams(window.location.search);
	var wanted = parseInt(params.get('cv'), 10);
	var start;
	if (params.get('new')) start = api('POST', 'cvs');
	else if (wanted) start = api('GET', 'cvs/' + wanted);
	else if (KJCV.cvIds.length) start = api('GET', 'cvs/' + KJCV.cvIds[0]);
	else start = api('POST', 'cvs');

	start.then(load).catch(function (err) {
		app.innerHTML = '';
		app.appendChild(h('div', { class: 'kj-cv-wrap' },
			h('p', { class: 'kj-notice kj-notice-err', text: err.message }),
			h('a', { class: 'kj-btn kj-btn-outline', href: KJCV.accountUrl, text: 'Back to My account' })));
	});
})();
