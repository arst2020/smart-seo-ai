/* Smart SEO AI – per-page schema builder (forms, AI suggestion, checks). */
(function () {
	'use strict';
	var S = window.SSAI || {};
	var holder = document.getElementById('ssai-schema-app');
	var dataEl = document.getElementById('ssai-schema-data');
	if (!holder || !dataEl) return;
	var D = JSON.parse(dataEl.textContent);

	function el(tag, attrs, kids) {
		var n = document.createElement(tag);
		Object.keys(attrs || {}).forEach(function (k) {
			if (k === 'class') n.className = attrs[k];
			else if (k === 'text') n.textContent = attrs[k];
			else if (k.indexOf('on') === 0) n.addEventListener(k.slice(2), attrs[k]);
			else n.setAttribute(k, attrs[k]);
		});
		[].concat(kids || []).forEach(function (c) {
			if (c === null || c === undefined || c === false) return;
			n.appendChild(typeof c === 'string' ? document.createTextNode(c) : c);
		});
		return n;
	}

	function request(action, data) {
		var fd = new FormData();
		fd.append('action', 'ssai_' + action);
		fd.append('nonce', S.nonce);
		fd.append('post_id', D.postId);
		Object.keys(data || {}).forEach(function (k) { fd.append(k, data[k]); });
		return fetch(S.ajax, { method: 'POST', body: fd, credentials: 'same-origin' })
			.then(function (r) { return r.json().catch(function () { return { success: false, data: { message: 'پاسخ نامعتبر از سرور (' + r.status + ')' } }; }); })
			.then(function (j) {
				if (!j || !j.success) throw new Error((j && j.data && j.data.message) || 'خطای ناشناخته');
				return j.data;
			});
	}

	var editorBox = el('div', { class: 'ssai-sch-editor' });
	var listBox = el('div', { class: 'ssai-sch-list' });
	var msg = el('p', { class: 'ssai-msg', role: 'status' });

	function say(text, type) { msg.textContent = text || ''; msg.className = 'ssai-msg' + (type ? ' ssai-msg-' + type : ''); }

	function checkList(chk) {
		if (!chk) return null;
		var ul = el('ul', { class: 'ssai-sch-check' });
		(chk.errors || []).forEach(function (e) { ul.appendChild(el('li', { class: 'ssai-error', text: '✗ ' + e })); });
		(chk.warnings || []).forEach(function (w) { ul.appendChild(el('li', { class: 'ssai-warning', text: '! ' + w })); });
		return ul.children.length ? ul : null;
	}

	/* ---------------------------------------------------------- form */
	function fieldInput(key, f, value) {
		var label = f[0], kind = f[1], need = f[2], opts = f[4] || {};
		var id = 'ssai-sf-' + key + '-' + Math.random().toString(36).slice(2, 7);
		var tag = need === 'req' ? ' *' : '';
		var wrap = el('div', { class: 'ssai-sch-field ssai-sch-' + kind });
		var getter;
		if (kind === 'faq') {
			wrap.appendChild(el('strong', { text: label + tag }));
			var rows = el('div');
			var addRow = function (q, a) {
				var qi = el('input', { type: 'text', class: 'large-text', placeholder: 'پرسش', 'aria-label': 'پرسش' }); qi.value = q || '';
				var ai = el('textarea', { class: 'large-text', rows: '2', placeholder: 'پاسخ', 'aria-label': 'پاسخ' }); ai.value = a || '';
				var row = el('div', { class: 'ssai-faq-edit' }, [qi, ai, el('button', { type: 'button', class: 'button-link ssai-del', text: 'حذف', onclick: function () { row.remove(); } })]);
				rows.appendChild(row);
			};
			(Array.isArray(value) ? value : []).forEach(function (r) { addRow(r.q, r.a); });
			if (!rows.children.length) addRow('', '');
			wrap.appendChild(rows);
			wrap.appendChild(el('button', { type: 'button', class: 'button button-small', text: '+ پرسش', onclick: function () { addRow('', ''); } }));
			getter = function () {
				return [].slice.call(rows.children).map(function (r) {
					return { q: r.querySelector('input').value.trim(), a: r.querySelector('textarea').value.trim() };
				}).filter(function (x) { return x.q && x.a; });
			};
		} else if (kind === 'price') {
			var v = value && typeof value === 'object' ? value : { amount: '', currency: 'IRR' };
			var amt = el('input', { type: 'text', id: id, dir: 'ltr', class: 'regular-text', placeholder: 'مثلاً 25000000' }); amt.value = v.amount || '';
			var cur = el('select', { 'aria-label': 'واحد پول' });
			Object.keys(opts).forEach(function (k) { var o = el('option', { value: k, text: opts[k] }); if (k === (v.currency || 'IRR')) o.selected = true; cur.appendChild(o); });
			wrap.appendChild(el('label', { for: id, text: label + tag }));
			wrap.appendChild(el('div', { class: 'ssai-sch-price' }, [amt, cur]));
			wrap.appendChild(el('p', { class: 'description', text: 'فقط اگر قیمت در صفحه اعلام شده است؛ قیمت ساختگی ممکن است جریمه گوگل داشته باشد.' }));
			getter = function () { return { amount: amt.value.trim(), currency: cur.value }; };
		} else if (kind === 'select') {
			var sel = el('select', { id: id });
			Object.keys(opts).forEach(function (k) { var o = el('option', { value: k, text: opts[k] }); if (k === (value || '')) o.selected = true; sel.appendChild(o); });
			wrap.appendChild(el('label', { for: id, text: label + tag }));
			wrap.appendChild(sel);
			getter = function () { return sel.value; };
		} else {
			var inp = kind === 'textarea' || kind === 'lines' ? el('textarea', { id: id, class: 'large-text', rows: kind === 'lines' ? '4' : '3' }) : el('input', { id: id, type: kind === 'date' ? 'datetime-local' : 'text', class: 'large-text' });
			if (kind === 'url' || kind === 'date') inp.setAttribute('dir', 'ltr');
			inp.value = value === undefined || value === null ? '' : (kind === 'date' ? String(value).slice(0, 16) : value);
			wrap.appendChild(el('label', { for: id, text: label + tag }));
			wrap.appendChild(inp);
			getter = function () { return inp.value.trim(); };
		}
		return { node: wrap, get: getter };
	}

	function openEditor(type, fields, index, intro) {
		var def = D.types[type];
		if (!def) return;
		editorBox.innerHTML = '';
		var inputs = {};
		var box = el('div', { class: 'ssai-sch-form' });
		box.appendChild(el('h3', { text: (index >= 0 ? 'ویرایش: ' : '') + def.label }));
		if (intro) box.appendChild(el('p', { class: 'ssai-sch-reason', text: '✨ ' + intro }));
		box.appendChild(el('p', { class: 'description', text: def.help + ' فیلدهای ستاره‌دار را گوگل لازم دارد. متغیرهایی مثل %title% و %seo_description% و %url% موقع نمایش با مقدار واقعی صفحه پر می‌شوند.' }));
		Object.keys(def.fields).forEach(function (k) {
			var f = def.fields[k];
			var val = fields && fields[k] !== undefined ? fields[k] : (index < 0 && !fields ? f[3] : '');
			var fi = fieldInput(k, f, val);
			inputs[k] = fi;
			box.appendChild(fi.node);
		});
		var status = el('span', { class: 'ssai-faq-msg', role: 'status' });
		var save = el('button', { type: 'button', class: 'button button-primary', text: 'ذخیره Schema' });
		save.addEventListener('click', function () {
			var out = {};
			Object.keys(inputs).forEach(function (k) { out[k] = inputs[k].get(); });
			save.disabled = true; status.textContent = 'در حال ذخیره…'; status.className = 'ssai-faq-msg';
			request('schema_save', { type: type, index: index, fields: JSON.stringify(out) })
				.then(function (d) { D.items = d.items; renderList(); editorBox.innerHTML = ''; say(d.message, 'ok'); })
				.catch(function (e) { status.textContent = e.message; status.className = 'ssai-faq-msg ssai-err'; })
				.then(function () { save.disabled = false; });
		});
		var cancel = el('button', { type: 'button', class: 'button', text: 'انصراف', onclick: function () { editorBox.innerHTML = ''; } });
		box.appendChild(el('p', {}, [save, ' ', cancel, ' ', status]));
		editorBox.appendChild(box);
		box.scrollIntoView({ behavior: 'smooth', block: 'start' });
	}

	/* ---------------------------------------------------------- list */
	function renderList() {
		listBox.innerHTML = '';
		if (!D.items.length) {
			listBox.appendChild(el('p', { class: 'description', text: 'هنوز Schema اختصاصی برای این صفحه ثبت نشده است.' }));
			return;
		}
		D.items.forEach(function (it) {
			var src = it.source === 'builder' ? 'ساخته‌شده در این افزونه' : (it.source === 'rankmath' ? 'منتقل‌شده از Rank Math' : 'کد دستی');
			var code = el('pre', { class: 'ssai-sch-code', dir: 'ltr', hidden: 'hidden', text: it.json });
			var actions = el('div', { class: 'ssai-sch-actions' });
			if (it.builder && D.canApply) {
				actions.appendChild(el('button', { type: 'button', class: 'button button-small', text: 'ویرایش', onclick: function () { openEditor(it.builder, it.fields, it.index); } }));
			}
			actions.appendChild(el('button', { type: 'button', class: 'button button-small', text: 'نمایش کد', onclick: function () { code.hidden = !code.hidden; } }));
			if (D.canApply) {
				actions.appendChild(el('button', {
					type: 'button', class: 'button-link ssai-del', text: 'حذف', onclick: function (e) {
						var b = e.currentTarget;
						if (b.dataset.sure !== '1') { b.dataset.sure = '1'; b.textContent = 'مطمئنید؟ دوباره بزنید'; return; }
						request('schema_delete', { index: it.index }).then(function (d) { D.items = d.items; renderList(); say(d.message, 'ok'); }).catch(function (er) { say(er.message, 'error'); });
					}
				}));
			}
			var card = el('div', { class: 'ssai-sch-item' }, [
				el('div', { class: 'ssai-sch-head' }, [el('span', { class: 'ssai-chip ssai-chip-main', text: it.type }), ' ', el('strong', { text: it.name || '' }), ' ', el('span', { class: 'description', text: '— ' + src })]),
				checkList(it.check),
				actions,
				code
			]);
			listBox.appendChild(card);
		});
	}

	/* ---------------------------------------------------------- toolbar */
	var bar = el('div', { class: 'ssai-sch-bar' });
	if (D.canApply) {
		var ai = el('button', { type: 'button', class: 'button button-primary', text: '✨ پیشنهاد Schema با هوش مصنوعی' });
		ai.addEventListener('click', function () {
			ai.disabled = true; say('هوش مصنوعی در حال خواندن صفحه…');
			editorBox.innerHTML = '';
			request('schema_suggest', {}).then(function (d) {
				say('');
				d.suggestions.forEach(function (sg, i) {
					if (i === 0) { openEditor(sg.type, sg.fields, -1, sg.reason + (d.suggestions.length > 1 ? ' (پیشنهاد دوم: ' + D.types[d.suggestions[1].type].label + ')' : '')); }
				});
				if (d.suggestions.length > 1) {
					var second = d.suggestions[1];
					editorBox.appendChild(el('p', {}, [el('button', { type: 'button', class: 'button', text: 'باز کردن پیشنهاد دوم: ' + D.types[second.type].label, onclick: function () { openEditor(second.type, second.fields, -1, second.reason); } })]));
				}
			}).catch(function (e) { say(e.message, 'error'); }).then(function () { ai.disabled = false; });
		});
		var sel = el('select', { 'aria-label': 'نوع Schema' });
		Object.keys(D.types).forEach(function (k) { sel.appendChild(el('option', { value: k, text: D.types[k].label })); });
		var add = el('button', { type: 'button', class: 'button', text: 'افزودن دستی', onclick: function () { openEditor(sel.value, null, -1); } });
		bar.appendChild(ai); bar.appendChild(document.createTextNode(' یا ')); bar.appendChild(sel); bar.appendChild(add);
	}
	bar.appendChild(el('a', { href: D.test, target: '_blank', rel: 'noopener', class: 'ssai-sch-test', text: 'آزمایش در Rich Results Test گوگل ↗' }));

	holder.appendChild(bar);
	holder.appendChild(msg);
	holder.appendChild(editorBox);
	holder.appendChild(listBox);
	renderList();
})();
