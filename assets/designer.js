/* Smart SEO AI – page designer (Elementor / Avada). */
(function () {
	'use strict';
	var S = window.SSAI || {};
	var C = window.SSAI_CS || {};
	var D = window.SSAI_DS || {};
	var item = null;
	var pages = [];

	function request(action, data) {
		var fd = new FormData();
		fd.append('action', action);
		fd.append('nonce', S.nonce);
		Object.keys(data || {}).forEach(function (k) { if (data[k] !== undefined && data[k] !== null) fd.append(k, data[k]); });
		return fetch(S.ajax, { method: 'POST', body: fd, credentials: 'same-origin' })
			.then(function (r) { return r.json().catch(function () { return { success: false, data: { message: 'پاسخ نامعتبر از سرور (' + r.status + ')' } }; }); })
			.then(function (j) { if (!j || !j.success) throw new Error((j && j.data && j.data.message) || 'خطای ناشناخته'); return j.data; });
	}

	function h(tag, attrs, kids) {
		var e = document.createElement(tag);
		Object.keys(attrs || {}).forEach(function (k) {
			var v = attrs[k];
			if (k === 'text') e.textContent = v;
			else if (k === 'html') e.innerHTML = v;
			else if (v === true) e.setAttribute(k, '');
			else if (v !== false && v !== null && v !== undefined) e.setAttribute(k, v);
		});
		(kids || []).forEach(function (c) { if (c) e.appendChild(typeof c === 'string' ? document.createTextNode(c) : c); });
		return e;
	}
	function msg(text, type) {
		var box = document.getElementById('ssai-cs-msg');
		box.innerHTML = '';
		if (text) { box.appendChild(h('div', { class: 'notice notice-' + (type || 'info') + ' inline' }, [h('p', { text: text })])); box.scrollIntoView({ block: 'nearest' }); }
	}
	function busy(b, on, label) { if (!b) return; if (on) { b.dataset.l = b.textContent; b.disabled = true; if (label) b.textContent = label; } else { b.disabled = false; if (b.dataset.l) b.textContent = b.dataset.l; } }
	function field(label, input, hint) { return h('label', { class: 'ssai-cs-field' }, [h('span', { text: label }), input, hint ? h('small', { class: 'description', text: hint }) : null]); }
	function select(map, value) { var s = h('select'); Object.keys(map).forEach(function (k) { s.appendChild(h('option', { value: k, text: map[k], selected: String(k) === String(value) })); }); return s; }
	function textarea(v, rows, ph) { var t = h('textarea', { rows: rows || 2, placeholder: ph || '' }); t.value = v || ''; return t; }
	function strip(html) { var d = document.createElement('div'); d.innerHTML = (html || '').replace(/<\/(p|li|h[1-6])>/gi, '$&\n\n').replace(/<br\s*\/?>/gi, '\n'); return d.textContent.replace(/\n{3,}/g, '\n\n').trim(); }

	function pickImages(multiple, cb) {
		if (!window.wp || !wp.media) { msg('کتابخانه رسانه بارگذاری نشد؛ صفحه را تازه کنید.', 'error'); return; }
		var f = wp.media({ title: multiple ? 'انتخاب عکس‌های گالری' : 'انتخاب عکس', library: { type: 'image' }, multiple: multiple, button: { text: 'انتخاب' } });
		f.on('select', function () { cb(f.state().get('selection').toJSON()); });
		f.open();
	}

	/* ------------------------------------------------------------------ Brief */
	function brief() {
		var f = {};
		f.kind = select(D.kinds, item ? item.kind : 'service');
		f.builder = select(D.builders, item ? item.builder : Object.keys(D.builders)[0]);
		f.topic = h('input', { type: 'text', class: 'regular-text', value: item ? item.topic : '', placeholder: 'مثلاً: ایمپلنت دندان (خالی = موضوع سایت)' });
		f.keyword = h('input', { type: 'text', class: 'regular-text', value: item ? item.keyword : '' });
		var srcMap = { 0: '— صفحه جدید —' };
		pages.forEach(function (p) { srcMap[p[0]] = p[1]; });
		f.source_page = select(srcMap, item ? item.source_page : 0);
		f.facts = textarea(item ? item.facts : '', 4, 'اطلاعات واقعی که باید در صفحه بیاید: خدمات، سابقه، تعداد، قیمت، ساعت کاری، مزیت‌ها… (هر چیزی این‌جا نباشد، هوش مصنوعی آن را نمی‌سازد و «نیاز به بررسی» می‌گذارد)');
		f.notes = textarea(item ? item.notes : '', 2, 'یادداشت برای طراح: لحن، تأکیدها، بلوکی که حتماً باشد…');
		f.form_code = h('input', { type: 'text', class: 'regular-text', dir: 'ltr', value: item ? item.form_code : '', placeholder: '[contact-form-7 id="123"]' });
		return f;
	}

	function briefData(f) {
		var d = {};
		Object.keys(f).forEach(function (k) { d[k] = f[k].value; });
		if (item && item.id) d.item = item.id;
		return d;
	}

	/* ------------------------------------------------------------------ Blocks */
	function blockEditor(b, list) {
		var lib = D.blocks[b.type] || { label: b.type, fields: [] };
		var fields = lib.fields;
		var box = h('div', { class: 'ssai-ds-blk' });
		box._b = b;
		var up = h('button', { type: 'button', class: 'button-link', text: '▲', title: 'بالا' });
		var down = h('button', { type: 'button', class: 'button-link', text: '▼', title: 'پایین' });
		var rm = h('button', { type: 'button', class: 'button-link ssai-cs-del', text: '✕ حذف بلوک' });
		up.addEventListener('click', function () { if (box.previousElementSibling) list.insertBefore(box, box.previousElementSibling); });
		down.addEventListener('click', function () { if (box.nextElementSibling) list.insertBefore(box.nextElementSibling, box); });
		rm.addEventListener('click', function () { box.remove(); });
		box.appendChild(h('div', { class: 'ssai-ds-blk-top' }, [h('strong', { text: lib.label }), h('span', { class: 'ssai-ds-sp' }), up, down, rm]));
		var grid = h('div', { class: 'ssai-ds-fields' });
		box.appendChild(grid);
		fields.forEach(function (fd) {
			if (fd === 'title') { var t = h('input', { type: 'text', class: 'large-text', value: b.title || '' }); t.addEventListener('input', function () { b.title = t.value; }); grid.appendChild(field(b.type === 'hero' ? 'تیتر اصلی (H1)' : (b.type === 'faq' ? 'تیتر بخش' : 'تیتر'), t)); }
			else if (fd === 'text') {
				var isHtml = b.type === 'intro' || b.type === 'contact';
				var ta = textarea(isHtml ? strip(b.text).trim() : b.text, isHtml ? 4 : 2);
				ta.addEventListener('input', function () { b.text = isHtml ? ta.value.split(/\n{2,}/).map(function (p) { return '<p>' + p.replace(/&/g, '&amp;').replace(/</g, '&lt;') + '</p>'; }).join('') : ta.value; });
				grid.appendChild(field(isHtml ? 'متن (پاراگراف‌ها را با یک خط خالی جدا کنید)' : 'متن', ta));
			}
			else if (fd === 'button') { var bt = h('input', { type: 'text', value: b.button || '' }); bt.addEventListener('input', function () { b.button = bt.value; }); grid.appendChild(field('متن دکمه (خالی = بدون دکمه)', bt)); }
			else if (fd === 'image') {
				var th = h('img', { class: 'ssai-cs-thumb', src: b.image_url || '', hidden: !b.image_url, alt: '' });
				var pick = h('button', { type: 'button', class: 'button button-small', text: b.image ? 'تغییر عکس' : '🖼 انتخاب عکس از گالری' });
				var del = h('button', { type: 'button', class: 'button-link ssai-cs-del', text: 'حذف عکس', hidden: !b.image });
				pick.addEventListener('click', function () { pickImages(false, function (sel) { var a = sel[0]; b.image = a.id; th.src = a.sizes && a.sizes.thumbnail ? a.sizes.thumbnail.url : a.url; th.hidden = false; del.hidden = false; pick.textContent = 'تغییر عکس'; }); });
				del.addEventListener('click', function () { b.image = 0; th.hidden = true; del.hidden = true; pick.textContent = '🖼 انتخاب عکس از گالری'; });
				grid.appendChild(field('عکس', h('div', { class: 'ssai-cs-img' }, [th, pick, del])));
			}
			else if (fd === 'images') {
				var wrap = h('div', { class: 'ssai-ds-thumbs' });
				var draw = function () {
					wrap.innerHTML = '';
					(b.image_urls || []).forEach(function (u, k) {
						var x = h('button', { type: 'button', class: 'button-link ssai-cs-del', text: '✕' });
						x.addEventListener('click', function () { b.images.splice(k, 1); b.image_urls.splice(k, 1); draw(); });
						wrap.appendChild(h('span', { class: 'ssai-ds-th' }, [h('img', { src: u, alt: '' }), x]));
					});
				};
				b.images = b.images || []; b.image_urls = b.image_urls || [];
				var add = h('button', { type: 'button', class: 'button button-small', text: '🖼 افزودن عکس از گالری' });
				add.addEventListener('click', function () { pickImages(true, function (sel) { sel.forEach(function (a) { b.images.push(a.id); b.image_urls.push(a.sizes && a.sizes.thumbnail ? a.sizes.thumbnail.url : a.url); }); draw(); }); });
				draw();
				grid.appendChild(field('عکس‌های گالری', h('div', {}, [wrap, add])));
			}
			else if (fd === 'map' || fd === 'form') {
				var cb = h('input', { type: 'checkbox', checked: !!b[fd] });
				cb.addEventListener('change', function () { b[fd] = cb.checked ? 1 : 0; });
				grid.appendChild(h('label', { class: 'ssai-ds-check' }, [cb, ' ', fd === 'map' ? 'نقشه' : (D.pro ? 'فرم (فرم المنتور Pro یا شورت‌کد بالا)' : 'فرم (شورت‌کد فرم بالا؛ بدون آن دکمه تماس)')]));
			}
			else if (fd.indexOf('items:') === 0) {
				var keys = fd.slice(6).split(',');
				var itemsBox = h('div', { class: 'ssai-ds-items' });
				b.items = b.items || [];
				var drawItems = function () {
					itemsBox.innerHTML = '';
					b.items.forEach(function (it, k) {
						var row = h('div', { class: 'ssai-ds-item' });
						keys.forEach(function (key) {
							if (key === 'icon') { var s = select(D.icons.reduce(function (o, x) { o[x] = x; return o; }, {}), it.icon); s.addEventListener('change', function () { it.icon = s.value; }); row.appendChild(s); }
							else if (key === 'link') { var pm = { 0: '— بدون لینک —' }; pages.forEach(function (p) { pm[p[0]] = p[1]; }); var ls = select(pm, it.link); ls.addEventListener('change', function () { it.link = parseInt(ls.value, 10); }); row.appendChild(ls); }
							else {
								var ph = key === 'title' ? (b.type === 'faq' ? 'سؤال' : (b.type === 'testimonials' ? 'نام مشتری' : 'عنوان')) : (b.type === 'faq' ? 'پاسخ' : (b.type === 'testimonials' ? 'متن نظر واقعی' : 'متن'));
								var val = key === 'text' && b.type === 'faq' ? strip(it.text) : it[key];
								var inp = key === 'text' ? textarea(val, 2, ph) : h('input', { type: 'text', value: val || '', placeholder: ph });
								inp.addEventListener('input', function () { it[key] = key === 'text' && b.type === 'faq' ? '<p>' + inp.value.replace(/&/g, '&amp;').replace(/</g, '&lt;') + '</p>' : inp.value; });
								row.appendChild(inp);
							}
						});
						var x = h('button', { type: 'button', class: 'button-link ssai-cs-del', text: '✕' });
						x.addEventListener('click', function () { b.items.splice(k, 1); drawItems(); });
						row.appendChild(x);
						itemsBox.appendChild(row);
					});
					var add = h('button', { type: 'button', class: 'button button-small', text: '+ مورد' });
					add.addEventListener('click', function () { b.items.push({ title: '', text: '', icon: 'check', link: 0 }); drawItems(); });
					itemsBox.appendChild(add);
				};
				drawItems();
				grid.appendChild(field(b.type === 'testimonials' ? 'نظرهای واقعی مشتریان (خودتان وارد کنید)' : 'موارد', itemsBox));
			}
		});
		if (b.type === 'posts') grid.appendChild(h('p', { class: 'description', text: 'نوشته‌های واقعی مرتبط با کلمه کلیدی (یا آخرین نوشته‌ها) خودکار قرار می‌گیرند.' }));
		return box;
	}

	function collectBlocks(list) {
		return Array.prototype.map.call(list.children, function (el) {
			var b = el._b;
			return { type: b.type, title: b.title, text: b.text, button: b.button, image: b.image || 0, images: b.images || [], items: b.items || [], map: b.map || 0, form: b.form || 0 };
		});
	}

	/* ------------------------------------------------------------------ Screen */
	function render() {
		var root = document.getElementById('ssai-ds');
		root.innerHTML = '';
		var f = brief();
		var planBtn = h('button', { type: 'button', class: 'button button-primary', text: item && item.blocks && item.blocks.length ? '✦ طراحی دوباره با هوش مصنوعی' : '✦ طراحی صفحه با هوش مصنوعی' });
		var saveBrief = h('button', { type: 'button', class: 'button', text: 'ذخیره' });
		root.appendChild(h('section', { class: 'ssai-card' }, [
			h('h2', { text: '۱. مشخصات صفحه' }),
			h('div', { class: 'ssai-cs-grid' }, [
				field('نوع صفحه', f.kind), field('صفحه‌ساز', f.builder), field('موضوع', f.topic), field('کلمه کلیدی اصلی', f.keyword),
				field('بازطراحی صفحه موجود (اختیاری)', f.source_page, 'متن آن صفحه استفاده می‌شود و یک پیش‌نویس جدید ساخته می‌شود؛ خود صفحه دست نمی‌خورد.'),
				field('شورت‌کد فرم (اختیاری)', f.form_code),
				h('label', { class: 'ssai-cs-field ssai-cs-wide' }, [h('span', { text: 'اطلاعات واقعی' }), f.facts]),
				h('label', { class: 'ssai-cs-field ssai-cs-wide' }, [h('span', { text: 'یادداشت' }), f.notes])
			]),
			h('p', {}, [saveBrief, ' ', planBtn])
		]));
		var after = function (d) { item = d.item; if (!/[?&]item=\d+/.test(location.search)) history.replaceState(null, '', C.url + '&tab=design&item=' + item.id); msg(d.message, 'success'); render(); };
		saveBrief.addEventListener('click', function () { busy(saveBrief, true, '…'); request('ssai_ds_save', briefData(f)).then(after).catch(function (er) { msg(er.message, 'error'); busy(saveBrief, false); }); });
		planBtn.addEventListener('click', function () {
			if (item && item.blocks && item.blocks.length && !confirm('طرح جدید جای بلوک‌ها و متن‌های فعلی را می‌گیرد (عکس‌های انتخاب‌شده تا جای ممکن می‌مانند). ادامه؟')) return;
			busy(planBtn, true, 'در حال طراحی… (تا یک دقیقه)');
			request('ssai_ds_plan', briefData(f)).then(after).catch(function (er) { msg(er.message, 'error'); busy(planBtn, false); });
		});
		if (!item || !item.blocks || !item.blocks.length) return;

		/* blocks */
		var list = h('div', { class: 'ssai-ds-list' });
		item.blocks.forEach(function (b) { list.appendChild(blockEditor(JSON.parse(JSON.stringify(b)), list)); });
		var addSel = select(Object.keys(D.blocks).reduce(function (o, k) { o[k] = D.blocks[k].label; return o; }, {}), 'cta');
		var addBtn = h('button', { type: 'button', class: 'button', text: '+ افزودن بلوک' });
		addBtn.addEventListener('click', function () {
			var t = addSel.value;
			list.appendChild(blockEditor({ type: t, title: '', text: '', button: '', image: 0, images: [], items: t === 'testimonials' ? [{ title: '', text: '' }] : [], map: 1, form: 1 }, list));
		});
		var title = h('input', { type: 'text', class: 'large-text', value: item.title || '' });
		var seo = item.seo || {};
		var sT = h('input', { type: 'text', class: 'large-text', value: seo.seo_title || '' });
		var sM = textarea(seo.meta || '', 2);
		var sS = h('input', { type: 'text', dir: 'ltr', value: seo.slug || '' });
		var sK = h('input', { type: 'text', class: 'large-text', value: (seo.keywords || []).join('، ') });
		var data = function () {
			var d = briefData(f);
			d.title = title.value;
			d.blocks = JSON.stringify(collectBlocks(list));
			d.seo = JSON.stringify({ seo_title: sT.value, meta: sM.value, slug: sS.value, keywords: sK.value.split(/[,،]/).map(function (x) { return x.trim(); }).filter(Boolean) });
			return d;
		};
		var saveBtn = h('button', { type: 'button', class: 'button', text: 'ذخیره و به‌روزرسانی پیش‌نمایش' });
		saveBtn.addEventListener('click', function () { busy(saveBtn, true, '…'); request('ssai_ds_save', data()).then(after).catch(function (er) { msg(er.message, 'error'); busy(saveBtn, false); }); });
		root.appendChild(h('section', { class: 'ssai-card' }, [
			h('h2', { text: '۲. بلوک‌ها و متن‌ها' }),
			field('عنوان برگه', title),
			h('p', { class: 'description', text: 'بلوک‌ها را جابه‌جا، حذف یا اضافه کنید و متن‌ها را ویرایش کنید. عکس بلوک‌ها را از گالری انتخاب کنید. «نظر مشتریان» را فقط با نظرهای واقعی پر کنید.' }),
			list, h('p', {}, [addSel, ' ', addBtn]),
			h('h3', { text: 'سئو' }),
			h('div', { class: 'ssai-cs-grid' }, [field('عنوان سئو', sT), field('توضیحات متا', sM), field('نامک', sS), field('کلمات کلیدی', sK)]),
			h('p', {}, [saveBtn])
		]));

		/* preview */
		var pv = h('div', { class: 'ssai-ds-preview' });
		pv.innerHTML = item.preview; // Built and escaped on the server.
		root.appendChild(h('section', { class: 'ssai-card' }, [
			h('h2', { text: '۳. پیش‌نمایش' }),
			item.marks ? h('p', { class: 'ssai-warn', text: item.marks + ' مورد «نیاز به بررسی» دارد؛ قبل از انتشار در صفحه‌ساز تکمیل کنید.' }) : null,
			h('p', { class: 'description', text: 'پیش‌نمایش ساده با رنگ‌های سایت است؛ ظاهر نهایی را قالب و تنظیمات سراسری صفحه‌ساز تعیین می‌کنند.' }),
			pv
		]));

		/* build */
		var build = h('button', { type: 'button', class: 'button button-primary', text: item.post_id ? 'ساخت دوباره پیش‌نویس' : 'ساخت پیش‌نویس در ' + (D.builders[f.builder.value] || '') });
		build.addEventListener('click', function () {
			busy(build, true, 'در حال ساخت…');
			request('ssai_ds_build', data()).then(after).catch(function (er) { msg(er.message, 'error'); busy(build, false); });
		});
		var links = item.post_id ? h('p', {}, [
			h('a', { class: 'button', href: item.edit_url, target: '_blank', rel: 'noopener', text: 'باز کردن در صفحه‌ساز' }), ' ',
			h('a', { class: 'button', href: item.view_url, target: '_blank', rel: 'noopener', text: 'پیش‌نمایش در سایت' })
		]) : null;
		root.appendChild(h('section', { class: 'ssai-card' }, [
			h('h2', { text: '۴. ساخت پیش‌نویس' }),
			h('p', { class: 'description', text: 'همیشه پیش‌نویس ساخته می‌شود. رنگ‌ها و فونت‌ها به تنظیمات سراسری صفحه‌ساز وصل‌اند. انتشار را خودتان در صفحه‌ساز انجام دهید؛ تا علامت «نیاز به بررسی» باقی است، افزونه اجازه انتشار نمی‌دهد.' }),
			h('p', {}, [build]), links
		]));
	}

	document.addEventListener('DOMContentLoaded', function () {
		var root = document.getElementById('ssai-ds');
		if (!root) return;
		try { pages = JSON.parse(root.dataset.pages || '[]'); } catch (e) { pages = []; }
		var id = root.dataset.item;
		if (id === 'new') { item = null; render(); return; }
		if (!id || id === '0') return;
		request('ssai_ds_get', { item: id }).then(function (d) { item = d.item; render(); }).catch(function (er) { msg(er.message, 'error'); });
	});
})();
