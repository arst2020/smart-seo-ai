/* Smart SEO AI – Ahrefs tab: find & replace links, 301s, image alt, undo. */
(function () {
	'use strict';
	var S = window.SSAI || {};

	function request(action, data) {
		var fd = new FormData();
		fd.append('action', 'ssai_' + action);
		fd.append('nonce', S.nonce);
		Object.keys(data || {}).forEach(function (k) { fd.append(k, data[k]); });
		return fetch(S.ajax, { method: 'POST', body: fd, credentials: 'same-origin' })
			.then(function (r) { return r.json().catch(function () { return { success: false, data: { message: 'پاسخ نامعتبر از سرور (' + r.status + ')' } }; }); })
			.then(function (j) {
				if (!j || !j.success) throw new Error((j && j.data && j.data.message) || 'خطای ناشناخته');
				return j.data;
			});
	}

	function out(row, text, type) {
		var o = row.querySelector('.ssai-ah-out');
		if (!o) return;
		o.className = 'ssai-ah-out' + (type ? ' ssai-' + type : '');
		o.textContent = text;
	}

	function showLocations(row, locs) {
		var o = row.querySelector('.ssai-ah-out');
		o.className = 'ssai-ah-out';
		o.innerHTML = '';
		if (!locs.length) {
			o.textContent = 'در محتوا، المنتور، منوها و ابزارک‌ها پیدا نشد؛ احتمالاً در قالب (فایل PHP) یا افزونه دیگری است.';
			return;
		}
		var ul = document.createElement('ul');
		ul.className = 'ssai-ah-locs';
		locs.forEach(function (l) {
			var li = document.createElement('li');
			var a = document.createElement('a');
			a.href = l.edit; a.target = '_blank'; a.rel = 'noopener'; a.textContent = l.title;
			li.appendChild(document.createTextNode(l.kind + ': '));
			li.appendChild(a);
			li.appendChild(document.createTextNode(' (' + l.count + ')'));
			ul.appendChild(li);
		});
		o.appendChild(ul);
	}

	document.addEventListener('click', function (e) {
		var t = e.target;
		if (!t.closest) return;
		var row = t.closest('tr');
		var btn;

		if ((btn = t.closest('.ssai-ah-scan'))) {
			btn.disabled = true; out(row, 'در حال جستجو…');
			request('linkfix_scan', { old: row.dataset.old })
				.then(function (d) { showLocations(row, d.locations); })
				.catch(function (er) { out(row, er.message, 'err'); })
				.then(function () { btn.disabled = false; });
			return;
		}

		if ((btn = t.closest('.ssai-ah-fix'))) {
			var nw = row.querySelector('.ssai-ah-new').value.trim();
			if (!nw) { out(row, 'آدرس جایگزین را وارد کنید.', 'err'); return; }
			if (btn.dataset.ready !== '1') {
				btn.disabled = true; out(row, 'در حال جستجو…');
				request('linkfix_scan', { old: row.dataset.old }).then(function (d) {
					showLocations(row, d.locations);
					if (d.locations.length) { btn.dataset.ready = '1'; btn.textContent = 'تأیید جایگزینی'; }
				}).catch(function (er) { out(row, er.message, 'err'); }).then(function () { btn.disabled = false; });
				return;
			}
			btn.disabled = true; out(row, 'در حال اصلاح…');
			request('linkfix_apply', { old: row.dataset.old, 'new': nw, note: 'Ahrefs' })
				.then(function (d) { out(row, '✓ ' + d.message, 'ok'); btn.textContent = 'انجام شد'; row.classList.add('ssai-done'); })
				.catch(function (er) { out(row, er.message, 'err'); btn.disabled = false; });
			return;
		}

		if ((btn = t.closest('.ssai-ah-301'))) {
			var to = row.querySelector('.ssai-ah-new').value.trim();
			if (!to) { out(row, 'آدرس مقصد را وارد کنید.', 'err'); return; }
			btn.disabled = true;
			request('ahrefs_redirect', { from: row.dataset.old, to: to })
				.then(function (d) { out(row, '✓ ' + d.message, 'ok'); })
				.catch(function (er) { out(row, er.message, 'err'); btn.disabled = false; });
			return;
		}

		if ((btn = t.closest('.ssai-ah-altsave'))) {
			var alt = row.querySelector('.ssai-ah-alt').value.trim();
			btn.disabled = true;
			request('ahrefs_alt', { src: row.dataset.src, alt: alt })
				.then(function (d) { out(row, '✓ ' + d.message, 'ok'); })
				.catch(function (er) { out(row, er.message, 'err'); })
				.then(function () { btn.disabled = false; });
			return;
		}

		if ((btn = t.closest('.ssai-ah-undo'))) {
			if (btn.dataset.sure !== '1') { btn.dataset.sure = '1'; btn.textContent = 'مطمئنید؟ دوباره بزنید'; return; }
			btn.disabled = true;
			request('linkfix_undo', { id: btn.dataset.id })
				.then(function (d) { out(row, '✓ ' + d.message, 'ok'); btn.remove(); })
				.catch(function (er) { out(row, er.message, 'err'); btn.disabled = false; });
		}
	});
})();
