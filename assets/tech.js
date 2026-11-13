/* Smart SEO AI – site-wide technical check (canonical / viewport). */
(function () {
	'use strict';
	var S = window.SSAI || {};

	function request(action, data) {
		var fd = new FormData();
		fd.append('action', 'ssai_techscan_' + action);
		fd.append('nonce', S.nonce);
		Object.keys(data || {}).forEach(function (k) { fd.append(k, data[k]); });
		return fetch(S.ajax, { method: 'POST', body: fd, credentials: 'same-origin' })
			.then(function (r) { return r.json().catch(function () { return { success: false, data: { message: 'پاسخ نامعتبر از سرور (' + r.status + ')' } }; }); })
			.then(function (j) { if (!j || !j.success) throw new Error((j && j.data && j.data.message) || 'خطای ناشناخته'); return j.data; });
	}

	function batches(list, size) {
		var out = [];
		for (var i = 0; i < list.length; i += size) out.push(list.slice(i, i + size));
		return out;
	}

	function run(all) {
		var prog = document.getElementById('ssai-tech-progress');
		var btns = document.querySelectorAll('#ssai-tech-run, #ssai-tech-all');
		btns.forEach(function (b) { b.disabled = true; });
		prog.textContent = 'در حال آماده‌سازی…';
		request('ids', all ? { all: 1 } : {}).then(function (d) {
			if (!d.ids.length) { prog.textContent = '✓ صفحه جدید یا تغییرکرده‌ای نیست.'; btns.forEach(function (b) { b.disabled = false; }); return; }
			var parts = batches(d.ids, 5), done = 0;
			var next = function () {
				if (!parts.length) { prog.textContent = '✓ تمام شد؛ در حال بارگذاری نتیجه…'; location.reload(); return; }
				var p = parts.shift();
				prog.textContent = 'بررسی ' + Math.min(done + p.length, d.ids.length) + ' از ' + d.ids.length + ' صفحه…';
				request('scan', { ids: p.join(','), fresh: all ? 1 : 0 }).then(function () { done += p.length; next(); })
					.catch(function (e) { prog.textContent = '✗ ' + e.message + ' — بررسی‌شده‌ها ذخیره شده‌اند؛ دوباره بزنید تا ادامه دهد.'; btns.forEach(function (b) { b.disabled = false; }); });
			};
			next();
		}).catch(function (e) { prog.textContent = '✗ ' + e.message; btns.forEach(function (b) { b.disabled = false; }); });
	}

	document.addEventListener('DOMContentLoaded', function () {
		var r = document.getElementById('ssai-tech-run');
		if (!r) return;
		r.addEventListener('click', function () { run(false); });
		var a = document.getElementById('ssai-tech-all');
		if (a) a.addEventListener('click', function () { run(true); });
		var all = document.getElementById('ssai-tech-cball');
		if (all) all.addEventListener('change', function () { document.querySelectorAll('.ssai-tech-cb').forEach(function (c) { c.checked = all.checked; }); });
		var bulk = document.getElementById('ssai-tech-bulk');
		if (bulk) bulk.addEventListener('click', function () {
			var ids = Array.prototype.map.call(document.querySelectorAll('.ssai-tech-cb:checked'), function (c) { return c.value; });
			if (!ids.length) { window.alert('هیچ صفحه‌ای تیک نخورده است.'); return; }
			if (!window.confirm('canonical ' + ids.length + ' صفحه روی خود همان صفحه تنظیم می‌شود.\n\n- canonical دستی (این افزونه، Rank Math، Yoast) پاک می‌شود تا هر صفحه به خودش اشاره کند.\n- صفحه‌هایی که اصلاً canonical نداشتند، از این به بعد canonical را از این افزونه می‌گیرند.\n- هر تغییر در «تاریخچه تغییرات» همان صفحه ثبت می‌شود و قابل برگشت است.\n\nادامه؟')) return;
			bulk.disabled = true;
			var prog = document.getElementById('ssai-tech-progress');
			var parts = batches(ids, 10), ok = 0, errs = [];
			var next = function () {
				if (!parts.length) {
					window.alert('✓ ' + ok + ' صفحه اصلاح شد.' + (errs.length ? '\n\nخطاها:\n' + errs.join('\n') : '') + '\n\nاگر افزونه کش دارید، کش سایت را پاک کنید.');
					location.reload();
					return;
				}
				var p = parts.shift();
				bulk.textContent = 'در حال اصلاح… (' + (ok + errs.length) + ' از ' + ids.length + ')';
				request('fix', { ids: p.join(',') }).then(function (d) { ok += d.ok; errs = errs.concat(d.errors); next(); })
					.catch(function (e) { errs.push(e.message); next(); });
			};
			if (prog) prog.textContent = '';
			next();
		});
	});
})();
