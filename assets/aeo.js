/* Smart SEO AI – AEO tab: AI FAQ generator with admin approval. */
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

	function h(tag, cls, text) {
		var n = document.createElement(tag);
		if (cls) n.className = cls;
		if (text !== undefined) n.textContent = text;
		return n;
	}

	function itemBox(list, q, a) {
		var box = h('div', 'ssai-faq-edit');
		var qi = h('input'); qi.type = 'text'; qi.className = 'large-text ssai-faq-q'; qi.value = q || ''; qi.placeholder = 'پرسش'; qi.setAttribute('aria-label', 'پرسش');
		var ai = h('textarea', 'large-text ssai-faq-a'); ai.rows = 3; ai.value = a || ''; ai.placeholder = 'پاسخ'; ai.setAttribute('aria-label', 'پاسخ');
		var del = h('button', 'button-link ssai-faq-del', 'حذف این پرسش'); del.type = 'button';
		del.addEventListener('click', function () { box.remove(); });
		box.appendChild(qi); box.appendChild(ai); box.appendChild(del);
		list.appendChild(box);
	}

	function render(cell, id, data) {
		cell.innerHTML = '';
		var note = h('p', 'description', 'پیشنهاد هوش مصنوعی بر اساس متن صفحه و جستجوهای واقعی. متن‌ها را بخوانید و اصلاح کنید؛ مخصوصاً هر عدد، قیمت یا ادعای پزشکی را با واقعیت چک کنید. چیزی تا «ذخیره» تغییر نمی‌کند.');
		cell.appendChild(note);
		var list = h('div', 'ssai-faq-list');
		cell.appendChild(list);
		data.items.forEach(function (f) { itemBox(list, f.q, f.a); });
		if (data.existing && data.existing.length) {
			var old = h('details');
			old.appendChild(h('summary', '', 'پرسش‌های ذخیره‌شده فعلی (' + data.existing.length + ')'));
			var ul = h('ul');
			data.existing.forEach(function (f) { ul.appendChild(h('li', '', f.q)); });
			old.appendChild(ul);
			var keep = h('button', 'button button-small', 'افزودن موارد فعلی به فهرست بالا'); keep.type = 'button';
			keep.addEventListener('click', function () { data.existing.forEach(function (f) { itemBox(list, f.q, f.a); }); keep.remove(); });
			old.appendChild(keep);
			cell.appendChild(old);
		}
		var bar = h('p', 'ssai-faq-bar');
		var add = h('button', 'button', '+ پرسش جدید'); add.type = 'button';
		add.addEventListener('click', function () { itemBox(list, '', ''); });
		var save = h('button', 'button button-primary', data.elementor ? 'ذخیره' : 'ذخیره و افزودن به صفحه'); save.type = 'button';
		var msg = h('span', 'ssai-faq-msg'); msg.setAttribute('role', 'status');
		save.addEventListener('click', function () {
			var items = [].slice.call(list.querySelectorAll('.ssai-faq-edit')).map(function (b) {
				return { q: b.querySelector('.ssai-faq-q').value.trim(), a: b.querySelector('.ssai-faq-a').value.trim() };
			}).filter(function (f) { return f.q && f.a; });
			save.disabled = true; msg.textContent = 'در حال ذخیره…'; msg.className = 'ssai-faq-msg';
			request('aeo_faq_save', { post_id: id, items: JSON.stringify(items) })
				.then(function (d) { msg.textContent = '✓ ' + d.message; msg.className = 'ssai-faq-msg ssai-ok'; })
				.catch(function (e) { msg.textContent = e.message; msg.className = 'ssai-faq-msg ssai-err'; })
				.then(function () { save.disabled = false; });
		});
		bar.appendChild(add); bar.appendChild(document.createTextNode(' ')); bar.appendChild(save); bar.appendChild(document.createTextNode(' ')); bar.appendChild(msg);
		cell.appendChild(bar);
		if (data.elementor) {
			cell.appendChild(h('p', 'description', 'این صفحه با المنتور ساخته شده: بعد از ذخیره، در المنتور یک ویجت «کد کوتاه» بگذارید و [ssai_faq] را در آن بنویسید. همین کد هم متن پرسش‌ها را نمایش می‌دهد و هم Schema پرسش و پاسخ (FAQPage) را.'));
		}
	}

	document.addEventListener('click', function (e) {
		var btn = e.target.closest ? e.target.closest('.ssai-faq-btn') : null;
		if (!btn) return;
		var id = btn.getAttribute('data-id');
		var row = document.getElementById('ssai-faq-' + id);
		if (!row) return;
		var cell = row.firstElementChild;
		row.hidden = false;
		cell.innerHTML = '';
		var st = h('p', 'ssai-faq-msg', 'هوش مصنوعی در حال نوشتن پرسش‌ها و پاسخ‌ها…'); st.setAttribute('role', 'status');
		cell.appendChild(st);
		btn.disabled = true;
		request('aeo_faq', { post_id: id })
			.then(function (d) { render(cell, id, d); })
			.catch(function (err) { st.textContent = err.message; st.className = 'ssai-faq-msg ssai-err'; })
			.then(function () { btn.disabled = false; });
	});
})();
