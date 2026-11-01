/* Smart SEO AI – page report: PageSpeed Insights (run in the admin's browser) + MDN HTTP Observatory. */
(function () {
	'use strict';
	var R = window.SSAI_REPORT, S = window.SSAI;
	if (!R || !S) return;

	function el(tag, attrs, kids) {
		var n = document.createElement(tag);
		Object.keys(attrs || {}).forEach(function (k) {
			if (k === 'class') n.className = attrs[k]; else if (k === 'text') n.textContent = attrs[k]; else n.setAttribute(k, attrs[k]);
		});
		[].concat(kids || []).forEach(function (c) { if (c != null && c !== false) n.appendChild(typeof c === 'string' ? document.createTextNode(c) : c); });
		return n;
	}
	function post(action, data) {
		var fd = new FormData();
		fd.append('action', action); fd.append('nonce', S.nonce);
		Object.keys(data || {}).forEach(function (k) { fd.append(k, data[k]); });
		return fetch(S.ajax, { method: 'POST', body: fd, credentials: 'same-origin' }).then(function (r) { return r.json(); })
			.then(function (j) { if (!j || !j.success) throw new Error((j && j.data && j.data.message) || 'خطا'); return j.data; });
	}
	var fa = function (n, d) { return Number(n).toLocaleString('fa-IR', { maximumFractionDigits: d || 0, minimumFractionDigits: d || 0 }); };
	var CAT = { fast: ['good', 'خوب'], average: ['warning', 'نیاز به بهبود'], slow: ['error', 'ضعیف'] };
	function scoreCls(s) { return s >= 90 ? 'good' : (s >= 50 ? 'warning' : 'error'); }
	function scoreTxt(s) { return s >= 90 ? 'خوب' : (s >= 50 ? 'متوسط' : 'ضعیف'); }

	/* ---------------------------------------------------------------- PageSpeed */
	var FIELD = {
		lcp: ['LARGEST_CONTENTFUL_PAINT_MS', 'LCP – بارگذاری بخش اصلی', function (v) { return fa(v / 1000, 1) + ' ثانیه'; }],
		inp: ['INTERACTION_TO_NEXT_PAINT', 'INP – پاسخ به کلیک/لمس', function (v) { return fa(v) + ' میلی‌ثانیه'; }],
		cls: ['CUMULATIVE_LAYOUT_SHIFT_SCORE', 'CLS – جابه‌جایی صفحه', function (v) { return fa(v / 100, 2); }],
		fcp: ['FIRST_CONTENTFUL_PAINT_MS', 'FCP – اولین نمایش', function (v) { return fa(v / 1000, 1) + ' ثانیه'; }],
		ttfb: ['EXPERIMENTAL_TIME_TO_FIRST_BYTE', 'TTFB – پاسخ سرور', function (v) { return fa(v / 1000, 2) + ' ثانیه'; }]
	};
	var LAB = { lcp: ['largest-contentful-paint', 'LCP'], tbt: ['total-blocking-time', 'TBT – زمان مسدود بودن'], cls: ['cumulative-layout-shift', 'CLS'], fcp: ['first-contentful-paint', 'FCP'], si: ['speed-index', 'Speed Index'] };

	/** Reduce the large PageSpeed response to the summary we keep. */
	function summarize(j) {
		var lh = j.lighthouseResult || {}, cats = lh.categories || {}, audits = lh.audits || {};
		var sum = { scores: {}, field: {}, lab: {}, opps: [], origin: false };
		['performance', 'seo', 'accessibility', 'best-practices'].forEach(function (c) {
			if (cats[c] && cats[c].score != null) sum.scores[c] = Math.round(cats[c].score * 100);
		});
		var le = j.loadingExperience && j.loadingExperience.metrics ? j.loadingExperience : null;
		if ((!le || !Object.keys(le.metrics).length) && j.originLoadingExperience && j.originLoadingExperience.metrics) { le = j.originLoadingExperience; sum.origin = true; }
		if (le) {
			Object.keys(FIELD).forEach(function (k) {
				var m = le.metrics[FIELD[k][0]];
				if (m) sum.field[k] = { value: m.percentile, cat: String(m.category || '').toLowerCase(), text: FIELD[k][2](m.percentile) };
			});
		}
		Object.keys(LAB).forEach(function (k) {
			var a = audits[LAB[k][0]];
			if (a) sum.lab[k] = { value: a.numericValue, cat: a.score >= 0.9 ? 'fast' : (a.score >= 0.5 ? 'average' : 'slow'), text: a.displayValue || '' };
		});
		var opps = [];
		Object.keys(audits).forEach(function (id) {
			var a = audits[id];
			if (a && a.details && a.details.type === 'opportunity' && a.score != null && a.score < 0.9) {
				opps.push({ title: a.title, save: a.displayValue || '', ms: a.details.overallSavingsMs || 0 });
			}
		});
		opps.sort(function (a, b) { return b.ms - a.ms; });
		sum.opps = opps.slice(0, 8).map(function (o) { return { title: o.title, save: o.save }; });
		return sum;
	}

	function renderPsi(strategy) {
		var box = document.getElementById('ssai-psi-' + strategy);
		if (!box) return;
		box.textContent = '';
		var d = (R.psi || {})[strategy];
		if (!d) { box.appendChild(el('p', { class: 'description', text: 'هنوز بررسی نشده است.' })); return; }
		var names = { performance: 'سرعت (Performance)', seo: 'سئو فنی', accessibility: 'دسترس‌پذیری', 'best-practices': 'استانداردها' };
		var tiles = el('div', { class: 'ssai-psi-scores' });
		Object.keys(names).forEach(function (c) {
			if (d.scores[c] == null) return;
			var s = d.scores[c];
			tiles.appendChild(el('div', { class: 'ssai-psi-score' }, [
				el('span', { class: 'ssai-badge ssai-' + scoreCls(s), text: fa(s) }),
				el('div', {}, [el('strong', { text: names[c] }), el('div', { class: 'description', text: scoreTxt(s) })])
			]));
		});
		box.appendChild(tiles);

		var fk = Object.keys(d.field || {});
		if (fk.length) {
			box.appendChild(el('h4', { text: 'تجربه کاربران واقعی (Core Web Vitals، ۲۸ روز اخیر' + (d.origin ? '، کل سایت چون این صفحه به‌تنهایی داده کافی ندارد' : '') + ')' }));
			var ul = el('ul', { class: 'ssai-list' });
			fk.forEach(function (k) {
				var m = d.field[k], c = CAT[m.cat] || ['warning', ''];
				ul.appendChild(el('li', { class: 'ssai-' + c[0] }, [el('span', { class: 'ssai-dot' }), FIELD[k][1] + ': ', el('strong', { text: m.text }), ' — ' + c[1]]));
			});
			box.appendChild(ul);
		} else {
			box.appendChild(el('p', { class: 'description', text: 'گوگل برای این صفحه هنوز داده کاربران واقعی (Core Web Vitals) ندارد؛ معمولاً برای سایت‌های کم‌بازدید پیش می‌آید. اعداد آزمایشی زیر ملاک است.' }));
		}
		var lk = Object.keys(d.lab || {});
		if (lk.length) {
			box.appendChild(el('h4', { text: 'آزمایش گوگل (Lighthouse)' }));
			var ul2 = el('ul', { class: 'ssai-list' });
			lk.forEach(function (k) {
				var m = d.lab[k], c = CAT[m.cat] || ['warning', ''];
				ul2.appendChild(el('li', { class: 'ssai-' + c[0] }, [el('span', { class: 'ssai-dot' }), LAB[k][1] + ': ', el('strong', { text: m.text }), ' — ' + c[1]]));
			});
			box.appendChild(ul2);
		}
		if (d.opps && d.opps.length) {
			box.appendChild(el('h4', { text: 'مهم‌ترین پیشنهادهای افزایش سرعت' }));
			var ol = el('ol', { class: 'ssai-opps' });
			d.opps.forEach(function (o) { ol.appendChild(el('li', {}, [o.title, o.save ? el('span', { class: 'description', text: ' — صرفه‌جویی: ' + o.save }) : null])); });
			box.appendChild(ol);
		}
		box.appendChild(el('p', { class: 'description', text: 'آخرین بررسی: ' + new Date(d.time * 1000).toLocaleString('fa-IR') }));
	}

	function runPsi(strategy, btn) {
		var status = document.getElementById('ssai-psi-status');
		btn.disabled = true;
		status.className = 'ssai-msg ssai-msg-info';
		status.textContent = 'در حال بررسی ' + (strategy === 'mobile' ? 'موبایل' : 'دسکتاپ') + ' توسط گوگل… (۲۰ تا ۶۰ ثانیه)';
		var q = 'url=' + encodeURIComponent(R.url) + '&strategy=' + strategy + '&locale=fa';
		['performance', 'seo', 'accessibility', 'best-practices'].forEach(function (c) { q += '&category=' + c; });
		if (R.psiKey) q += '&key=' + encodeURIComponent(R.psiKey);
		fetch('https://www.googleapis.com/pagespeedonline/v5/runPagespeed?' + q)
			.then(function (r) { return r.json().then(function (j) { return { ok: r.ok, status: r.status, j: j }; }); })
			.then(function (res) {
				if (!res.ok || res.j.error) {
					var m = res.j.error && res.j.error.message ? res.j.error.message : ('HTTP ' + res.status);
					if (res.status === 429) m = 'سهمیه رایگان بدون کلید تمام شده؛ در تنظیمات افزونه یک کلید PageSpeed وارد کنید. (' + m + ')';
					throw new Error(m);
				}
				var sum = summarize(res.j);
				sum.time = Math.floor(Date.now() / 1000);
				R.psi = R.psi || {};
				R.psi[strategy] = sum;
				renderPsi(strategy);
				status.className = 'ssai-msg ssai-msg-ok';
				status.textContent = 'بررسی انجام و ذخیره شد.';
				return post('ssai_save_psi', { post_id: R.postId, strategy: strategy, summary: JSON.stringify(sum) });
			})
			.catch(function (e) {
				status.className = 'ssai-msg ssai-msg-error';
				status.textContent = 'بررسی ناموفق بود: ' + e.message + (e.message === 'Failed to fetch' ? ' (مرورگر شما به سرور گوگل دسترسی نداشت)' : '');
			})
			.then(function () { btn.disabled = false; });
	}

	/* ---------------------------------------------------------------- Observatory */
	function renderObs(o, err) {
		var box = document.getElementById('ssai-obs');
		if (!box) return;
		box.textContent = '';
		if (err) { box.appendChild(el('div', { class: 'ssai-msg ssai-msg-error', text: err })); }
		if (!o) { if (!err) box.appendChild(el('p', { class: 'description', text: 'هنوز بررسی نشده است.' })); return; }
		var g = String(o.grade || '?'), cls = /^A/.test(g) ? 'good' : (/^[BC]/.test(g) ? 'warning' : 'error');
		box.appendChild(el('div', { class: 'ssai-psi-score' }, [
			el('span', { class: 'ssai-badge ssai-' + cls, text: g }),
			el('div', {}, [
				el('strong', { text: 'نمره امنیت هدرهای سایت (' + o.host + ')' }),
				el('div', { class: 'description', text: 'امتیاز ' + fa(o.score) + ' — ' + fa(o.passed) + ' آزمون موفق، ' + fa(o.failed) + ' ناموفق' })
			])
		]));
		box.appendChild(el('p', { class: 'description' }, [
			'بررسی: ' + new Date(o.time * 1000).toLocaleString('fa-IR') + ' — ',
			el('a', { href: o.details_url, target: '_blank', rel: 'noopener', text: 'جزئیات و راه‌حل هر مورد' })
		]));
	}

	function runObs(btn, force) {
		btn.disabled = true;
		renderObs(null, null);
		document.getElementById('ssai-obs').textContent = 'در حال بررسی…';
		post('ssai_observatory', { force: force ? 1 : 0 })
			.then(function (o) { R.obs = o; renderObs(o); })
			.catch(function (e) { renderObs(R.obs, e.message); })
			.then(function () { btn.disabled = false; });
	}

	function start() {
		['mobile', 'desktop'].forEach(function (s) {
			renderPsi(s);
			var b = document.getElementById('ssai-psi-run-' + s);
			if (b) b.addEventListener('click', function () { runPsi(s, b); });
		});
		renderObs(R.obs);
		var ob = document.getElementById('ssai-obs-run');
		if (ob) ob.addEventListener('click', function () { runObs(ob, true); });
	}
	if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start); else start();
})();
