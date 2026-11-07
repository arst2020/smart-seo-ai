/* Smart SEO AI – content studio. */
(function () {
	'use strict';
	var S = window.SSAI || {};
	var C = window.SSAI_CS || {};

	function request(action, data) {
		var fd = new FormData();
		fd.append('action', 'ssai_cs_' + action);
		fd.append('nonce', S.nonce);
		Object.keys(data || {}).forEach(function (k) { if (data[k] !== undefined && data[k] !== null) fd.append(k, data[k]); });
		return fetch(S.ajax, { method: 'POST', body: fd, credentials: 'same-origin' })
			.then(function (r) { return r.json().catch(function () { return { success: false, data: { message: 'پاسخ نامعتبر از سرور (' + r.status + ')' } }; }); })
			.then(function (j) {
				if (!j || !j.success) throw new Error((j && j.data && j.data.message) || 'خطای ناشناخته');
				return j.data;
			});
	}

	function h(tag, attrs, kids) {
		var e = document.createElement(tag);
		Object.keys(attrs || {}).forEach(function (k) {
			if (k === 'text') e.textContent = attrs[k];
			else if (k === 'html') e.innerHTML = attrs[k];
			else if (k.slice(0, 2) === 'on') e.addEventListener(k.slice(2), attrs[k]);
			else if (attrs[k] === true) e.setAttribute(k, '');
			else if (attrs[k] !== false && attrs[k] !== null && attrs[k] !== undefined) e.setAttribute(k, attrs[k]);
		});
		(kids || []).forEach(function (c) { if (c) e.appendChild(typeof c === 'string' ? document.createTextNode(c) : c); });
		return e;
	}

	function msg(text, type) {
		var box = document.getElementById('ssai-cs-msg');
		if (!box) return;
		box.innerHTML = '';
		if (!text) return;
		box.appendChild(h('div', { class: 'notice notice-' + (type || 'info') + ' inline' }, [h('p', { text: text })]));
		box.scrollIntoView({ block: 'nearest' });
	}

	function busy(btn, on, label) {
		if (!btn) return;
		if (on) { btn.dataset.label = btn.textContent; btn.disabled = true; if (label) btn.textContent = label; }
		else { btn.disabled = false; if (btn.dataset.label) btn.textContent = btn.dataset.label; }
	}

	function goItem(id) { location.href = C.url + '&tab=write&item=' + id; }

	function options(sel, map, value) {
		Object.keys(map).forEach(function (k) { sel.appendChild(h('option', { value: k, text: map[k], selected: String(k) === String(value) })); });
		return sel;
	}

	/* ------------------------------------------------------------------ Ideas */
	function initIdeas() {
		var form = document.getElementById('ssai-cs-new');
		if (form) {
			form.addEventListener('submit', function (e) {
				e.preventDefault();
				var b = form.querySelector('button');
				busy(b, true, 'در حال ساخت…');
				request('item_save', { topic: form.topic.value, keyword: form.keyword.value || form.topic.value, type: form.type.value, mode: form.mode.value, length: form.mode.value === 'section' ? 'short' : 'medium', images: form.mode.value === 'section' ? 1 : 3, source: 'manual' })
					.then(function (d) { goItem(d.item.id); })
					.catch(function (er) { msg(er.message, 'error'); busy(b, false); });
			});
		}
		document.querySelectorAll('.ssai-cs-ideas').forEach(function (box) {
			var src = box.dataset.source;
			request('ideas', { source: src }).then(function (d) {
				box.innerHTML = '';
				if (!d.ideas.length) { box.appendChild(h('p', { text: src === 'gsc' ? 'فرصت تازه‌ای پیدا نشد.' : 'نوشته‌ای که نیاز فوری به به‌روزرسانی داشته باشد پیدا نشد.' })); return; }
				var tb = h('tbody');
				d.ideas.forEach(function (it) {
					var btn = h('button', { type: 'button', class: 'button button-small', text: it.taken ? 'در صف هست' : (src === 'gsc' ? 'ساخت محتوا' : 'به‌روزرسانی'), disabled: !!it.taken });
					btn.addEventListener('click', function () {
						busy(btn, true, '…');
						var data = src === 'gsc'
							? { topic: it.query, keyword: it.query, type: 'article', source: 'gsc' }
							: { topic: it.title, keyword: it.keyword || it.title, type: it.type, source: 'refresh', target: it.id };
						request('item_save', data).then(function (r) { goItem(r.item.id); }).catch(function (er) { msg(er.message, 'error'); busy(btn, false); });
					});
					var cells = src === 'gsc'
						? [h('td', {}, [h('strong', { text: it.query })]), h('td', { text: it.impressions.toLocaleString('fa-IR') }), h('td', { text: it.position.toLocaleString('fa-IR') }), h('td', { class: 'description', text: it.why + (it.page ? ' («' + it.page + '»)' : '') })]
						: [h('td', {}, [h('strong', { text: it.title })]), h('td', { class: 'description', text: it.why + (it.elementor ? ' — صفحه‌ساز (المنتور یا آوادا): برای بازنویسی از «گزارش سئو › بازنویسی محتوا» استفاده کنید' : '') })];
					cells.push(h('td', {}, [btn]));
					tb.appendChild(h('tr', {}, cells));
				});
				var head = src === 'gsc' ? ['جستجو', 'نمایش', 'جایگاه', 'چرا', ''] : ['نوشته', 'چرا', ''];
				box.appendChild(h('table', { class: 'widefat striped' }, [h('thead', {}, [h('tr', {}, head.map(function (t) { return h('th', { text: t }); }))]), tb]));
			}).catch(function (er) { box.innerHTML = ''; box.appendChild(h('p', { class: 'ssai-err', text: er.message })); });
		});
	}

	/* ------------------------------------------------------------------ Writer */
	var item = null;

	function initWrite() {
		var root = document.getElementById('ssai-cs-editor');
		if (!root || !root.dataset.item || root.dataset.item === '0') return;
		request('item_get', { item: root.dataset.item }).then(function (d) { item = d.item; render(); })
			.catch(function (er) { root.innerHTML = ''; root.appendChild(h('div', { class: 'ssai-card' }, [h('p', { class: 'ssai-err', text: er.message })])); });
	}

	function field(label, input, hint) {
		return h('label', { class: 'ssai-cs-field' }, [h('span', { text: label }), input, hint ? h('small', { class: 'description', text: hint }) : null]);
	}

	function counter(input, min, max) {
		var c = h('small', { class: 'ssai-cs-count' });
		var upd = function () {
			var n = input.value.length;
			c.textContent = n + ' کاراکتر';
			c.className = 'ssai-cs-count ' + (n >= min && n <= max ? 'ssai-ok' : 'ssai-warn');
		};
		input.addEventListener('input', upd); upd();
		return c;
	}

	function render() {
		var root = document.getElementById('ssai-cs-editor');
		root.innerHTML = '';
		var isRefresh = !!item.target;
		/* ---- Step 1: brief */
		var f = {};
		f.type = options(h('select'), { article: C.types.article, service: C.types.service }, item.type);
		f.topic = h('input', { type: 'text', class: 'regular-text', value: item.topic });
		f.keyword = h('input', { type: 'text', class: 'regular-text', value: item.keyword });
		f.secondary = h('input', { type: 'text', class: 'regular-text', value: item.secondary, placeholder: 'با ویرگول جدا کنید' });
		f.audience = h('input', { type: 'text', class: 'regular-text', value: item.audience, placeholder: 'خالی = از پروفایل برند' });
		f.length = options(h('select'), { short: 'کوتاه (~۸۰۰ کلمه)', medium: 'متوسط (~۱۵۰۰ کلمه)', long: 'بلند (~۲۵۰۰ کلمه)' }, item.length);
		f.images = h('input', { type: 'number', min: 0, max: 15, value: item.images === undefined ? 3 : item.images, style: 'width:90px' });
		f.notes = h('textarea', { rows: 3, placeholder: 'هر چیزی که نویسنده باید بداند: نکات تخصصی، اطلاعات واقعی (قیمت، مدت، نام پزشک)، چیزهایی که نباید گفته شود…' });
		f.notes.value = item.notes || '';
		f.date = h('input', { type: 'datetime-local', value: item.date ? item.date.replace(' ', 'T') : '' });
		var isSection = item.mode === 'section';
		if (!isRefresh) f.mode = options(h('select'), { page: 'صفحه (پیش‌نویس وردپرس)', section: 'سکشن HTML (فقط کپی کد)' }, item.mode || 'page');
		var brief = [
			!isRefresh ? field('خروجی', f.mode, 'سکشن: یک بخش آماده برای چسباندن داخل صفحه‌ای که دارید.') : null,
			field('نوع', f.type), field('موضوع', f.topic), field('کلمه کلیدی اصلی', f.keyword), field('کلمات فرعی', f.secondary),
			field('مخاطب', f.audience), field('طول', f.length), field('تعداد تصویر داخل متن', f.images, '۰ = بدون تصویر')
		];
		if (!isRefresh) {
			f.post_type = options(h('select'), C.postTypes, item.type === 'service' && item.post_type === 'post' ? 'page' : item.post_type);
			f.category = options(h('select'), Object.assign({ 0: '— پیش‌فرض —' }, C.cats), item.category);
			f.output = options(h('select'), { gutenberg: 'پیش‌نویس گوتنبرگ (بلوک)', elementor: 'پیش‌نویس المنتور از روی صفحه نمونه' }, item.output);
			f.template = options(h('select'), Object.assign({ 0: '— صفحه نمونه را انتخاب کنید —' }, C.templates), item.template);
			var pageOnly = [field('نوع نوشته', f.post_type), field('دسته', f.category), field('قالب پیش‌نویس', f.output), field('صفحه نمونه المنتور', f.template, 'تیترها و متن‌ها به ترتیب در همین طراحی می‌نشینند.')];
			pageOnly.forEach(function (el) { el.classList.add('ssai-cs-pageonly'); brief.push(el); });
			if (!Object.keys(C.templates).length) { f.output.querySelector('[value=elementor]').disabled = true; }
		}
		if (C.langs && Object.keys(C.langs).length) {
			f.lang = options(h('select'), Object.assign({ '': 'زبان پیش‌فرض سایت' }, C.langs), item.lang);
			brief.push(field('زبان', f.lang));
		}
		var dateField = field('تاریخ برنامه‌ریزی (اختیاری)', f.date, 'فقط برنامه است؛ انتشار با تأیید شما در پایین.');
		dateField.classList.add('ssai-cs-pageonly');
		brief.push(dateField);
		brief.push(h('label', { class: 'ssai-cs-field ssai-cs-wide' }, [h('span', { text: 'یادداشت برای نویسنده' }), f.notes]));

		brief = brief.filter(Boolean);
		var toggleMode = function () {
			var sec = f.mode && f.mode.value === 'section';
			brief.forEach(function (el) { if (el.classList && el.classList.contains('ssai-cs-pageonly')) el.hidden = sec; });
		};
		if (f.mode) { f.mode.addEventListener('change', toggleMode); setTimeout(toggleMode, 0); }
		var briefData = function () {
			var d = { item: item.id };
			Object.keys(f).forEach(function (k) { d[k] = f[k].value; });
			d.date = f.date.value ? f.date.value.replace('T', ' ').slice(0, 16) : '';
			return d;
		};
		var btnSaveBrief = h('button', { type: 'button', class: 'button', text: 'ذخیره مشخصات' });
		var btnOutline = h('button', { type: 'button', class: 'button button-primary', text: item.outline.length ? '✦ ساخت دوباره سرفصل' : '✦ ساخت سرفصل با هوش مصنوعی' });
		btnSaveBrief.addEventListener('click', function () {
			busy(btnSaveBrief, true, 'ذخیره…');
			request('item_save', briefData()).then(function (d) { item = d.item; msg(d.message, 'success'); render(); }).catch(function (er) { msg(er.message, 'error'); busy(btnSaveBrief, false); });
		});
		btnOutline.addEventListener('click', function () {
			if (item.sections && Object.keys(item.sections).length && !confirm('سرفصل جدید جای سرفصل و متن‌های فعلی را می‌گیرد. ادامه؟')) return;
			busy(btnOutline, true, 'در حال طراحی سرفصل… (تا یک دقیقه)');
			request('item_save', briefData()).then(function () { return request('outline', { item: item.id }); })
				.then(function (d) { item = d.item; msg(d.message, 'success'); render(); })
				.catch(function (er) { msg(er.message, 'error'); busy(btnOutline, false); });
		});
		var del = h('button', { type: 'button', class: 'button-link ssai-cs-del', text: 'حذف این مورد' });
		del.addEventListener('click', function () {
			if (!confirm('این مورد از صف حذف شود؟ (پیش‌نویس ساخته‌شده دست نمی‌خورد)')) return;
			request('item_delete', { item: item.id }).then(function () { location.href = C.url + '&tab=write'; }).catch(function (er) { msg(er.message, 'error'); });
		});
		root.appendChild(h('section', { class: 'ssai-card' }, [
			h('h2', { text: '۱. مشخصات' + (isRefresh ? ' (به‌روزرسانی نوشته موجود)' : '') }),
			h('div', { class: 'ssai-cs-grid' }, brief),
			h('p', {}, [btnSaveBrief, ' ', btnOutline, ' ', del])
		]));

		if (!item.outline.length) return;

		/* ---- Step 2: outline */
		var titleSel = h('select');
		(item.titles || []).forEach(function (t) { titleSel.appendChild(h('option', { value: t, text: t, selected: t === item.title })); });
		var titleIn = h('input', { type: 'text', class: 'large-text', value: item.title });
		titleSel.addEventListener('change', function () { titleIn.value = titleSel.value; });
		var rows = h('div', { class: 'ssai-cs-outline' });
		item.outline.forEach(function (o, i) { rows.appendChild(outlineRow(o, i)); });
		var imgCount = h('p', { class: 'description' });
		var countImgs = function () {
			var n = 0;
			rows.querySelectorAll('.ssai-cs-row').forEach(function (r) { if (!r.querySelector('.ssai-cs-img').hidden) n++; });
			var want = parseInt(f.images.value, 10) || 0;
			rows.querySelectorAll('.ssai-cs-addimg').forEach(function (b) { b.disabled = n >= want; b.title = n >= want ? 'به تعداد تصویر انتخابی رسیده‌اید؛ عدد «تعداد تصویر» را بیشتر کنید یا جای تصویر دیگری را حذف کنید.' : ''; });
			imgCount.textContent = 'تصویرها: ' + n + ' از ' + want + (n > want ? ' — بیشتر از تعداد انتخابی شماست؛ تصویر اضافه را حذف کنید.' : '');
			imgCount.className = n > want ? 'ssai-warn' : 'description';
		};
		rows.addEventListener('input', countImgs); rows.addEventListener('click', function () { setTimeout(countImgs, 0); });
		f.images.addEventListener('input', countImgs);
		var add = h('button', { type: 'button', class: 'button button-small', text: '+ افزودن بخش' });
		add.addEventListener('click', function () { rows.appendChild(outlineRow({ level: 2, heading: '', notes: '', image: null }, -1)); });

		var seo = item.seo || {};
		var sTitle = h('input', { type: 'text', class: 'large-text', value: seo.seo_title || '' });
		var sMeta = h('textarea', { rows: 2, class: 'large-text' }); sMeta.value = seo.meta || '';
		var sSlug = h('input', { type: 'text', class: 'regular-text', dir: 'ltr', value: seo.slug || '' });
		var sKw = h('input', { type: 'text', class: 'large-text', value: (seo.keywords || []).join('، ') });

		var collect = function () {
			var list = [];
			rows.querySelectorAll('.ssai-cs-row').forEach(function (r) {
				var img = r.querySelector('.ssai-cs-img-desc').value.trim();
				var imgIdV = parseInt(r.querySelector('.ssai-cs-img-id').value, 10) || 0;
				list.push({
					from: parseInt(r.dataset.from, 10),
					level: parseInt(r.querySelector('.ssai-cs-lvl').value, 10),
					heading: r.querySelector('.ssai-cs-h').value,
					notes: r.querySelector('.ssai-cs-notes').value,
					image: (img || imgIdV) ? { desc: img, alt: r.querySelector('.ssai-cs-img-alt').value, id: imgIdV } : null
				});
			});
			return {
				item: item.id, title: titleIn.value, outline: JSON.stringify(list),
				seo: JSON.stringify({ seo_title: sTitle.value, meta: sMeta.value, slug: sSlug.value, keywords: sKw.value.split(/[,،]/).map(function (x) { return x.trim(); }).filter(Boolean) })
			};
		};
		var saveOutline = h('button', { type: 'button', class: 'button', text: 'ذخیره سرفصل' });
		saveOutline.addEventListener('click', function () {
			busy(saveOutline, true, 'ذخیره…');
			request('item_save', collect()).then(function (d) { item = d.item; msg('سرفصل ذخیره شد.', 'success'); render(); }).catch(function (er) { msg(er.message, 'error'); busy(saveOutline, false); });
		});
		var written = Object.keys(item.sections || {}).length;
		var writeAll = h('button', { type: 'button', class: 'button button-primary', text: written ? (written < item.outline.length ? '✦ نوشتن بخش‌های باقی‌مانده' : '✦ نوشتن دوباره همه بخش‌ها') : '✦ نوشتن متن همه بخش‌ها' });
		var progress = h('div', { class: 'ssai-cs-progress' });
		writeAll.addEventListener('click', function () {
			var redo = written >= item.outline.length;
			busy(writeAll, true, 'در حال نوشتن…');
			request('item_save', collect()).then(function (d) {
				item = d.item;
				var todo = [];
				item.outline.forEach(function (o, i) { if (redo || !item.sections[i]) todo.push(i); });
				return writeSections(todo, progress);
			}).then(function () { msg('همه بخش‌ها نوشته شد. پیش‌نمایش را بخوانید و علامت‌های زرد را بررسی کنید.', 'success'); render(); })
				.catch(function (er) { msg(er.message + ' — بخش‌های نوشته‌شده ذخیره شده‌اند؛ دوباره بزنید تا ادامه دهد.', 'error'); request('item_get', { item: item.id }).then(function (d) { item = d.item; render(); }); });
		});
		setTimeout(countImgs, 0);
		root.appendChild(h('section', { class: 'ssai-card' }, [
			h('h2', { text: '۲. سرفصل' }),
			field(isSection ? 'عنوان سکشن (H2)' : 'عنوان صفحه (H1)', titleIn), item.titles && item.titles.length > 1 ? field('عنوان‌های پیشنهادی', titleSel) : null,
			h('p', { class: 'description', text: 'تیترها را ویرایش، جابه‌جا یا حذف کنید. «یادداشت» می‌گوید آن بخش چه چیزی را پوشش دهد. فقط بخش‌هایی که جای تصویر دارند (به تعداد انتخابی شما) کادر تصویر دارند؛ برای آن‌ها از گالری عکس انتخاب کنید. بخش‌های دیگر با «+ تصویر برای این بخش» تصویر می‌گیرند. متن بخش‌هایی که تیترشان عوض نشود حفظ می‌شود.' }),
			imgCount, rows, h('p', {}, [add]),
			isSection ? null : h('h3', { text: 'سئو' }),
			isSection ? null : h('div', { class: 'ssai-cs-grid' }, [
				h('label', { class: 'ssai-cs-field ssai-cs-wide' }, [h('span', { text: 'عنوان سئو' }), sTitle, counter(sTitle, 30, 60)]),
				h('label', { class: 'ssai-cs-field ssai-cs-wide' }, [h('span', { text: 'توضیحات متا' }), sMeta, counter(sMeta, 120, 160)]),
				field('نامک (URL)', sSlug), field('کلمات کلیدی (اولی = اصلی)', sKw)
			]),
			h('p', {}, [saveOutline, ' ', writeAll]), progress
		]));

		if (!written) return;

		/* ---- Step 3: preview */
		var prev = h('div', { class: 'ssai-cs-preview' });
		prev.innerHTML = '<h1>' + escapeHtml(item.title) + '</h1>' + item.preview; // Built and sanitised on the server.
		root.appendChild(h('section', { class: 'ssai-card' }, [
			h('h2', { text: '۳. پیش‌نمایش' }),
			item.marks ? h('p', { class: 'ssai-warn', text: item.marks + ' مورد «نیاز به بررسی» دارد؛ ' + (isSection ? 'بعد از چسباندن کد' : 'بعد از ساخت پیش‌نویس') + '، در ویرایشگر با اطلاعات واقعی جایگزین یا حذف کنید.' }) : h('p', { class: 'ssai-ok', text: 'موردی برای بررسی علامت نخورده است؛ با این حال متن را کامل بخوانید.' }),
			prev
		]));

		/* ---- Step 4 (section): copy the HTML */
		if (isSection) {
			if (written < item.outline.length) {
				root.appendChild(h('section', { class: 'ssai-card' }, [h('h2', { text: '۴. کد HTML سکشن' }), h('p', { class: 'description', text: 'برای گرفتن کد، اول همه بخش‌ها را بنویسید.' })]));
				return;
			}
			var code = h('textarea', { class: 'ssai-cs-code', rows: 14, readonly: true, dir: 'ltr' });
			code.value = item.section_html;
			var copyBtn = h('button', { type: 'button', class: 'button button-primary', text: '📋 کپی کد HTML' });
			copyBtn.addEventListener('click', function () {
				var ok = function () { copyBtn.textContent = '✓ کپی شد'; setTimeout(function () { copyBtn.textContent = '📋 کپی کد HTML'; }, 2000); };
				if (navigator.clipboard && window.isSecureContext) navigator.clipboard.writeText(code.value).then(ok, function () { code.select(); document.execCommand('copy'); ok(); });
				else { code.select(); document.execCommand('copy'); ok(); }
			});
			root.appendChild(h('section', { class: 'ssai-card' }, [
				h('h2', { text: '۴. کد HTML سکشن' }),
				item.marks ? h('p', { class: 'ssai-warn', text: 'کد ' + item.marks + ' علامت «نیاز به بررسی» و شاید کادر «تصویر پیشنهادی» دارد؛ بعد از چسباندن، آن‌ها را با اطلاعات واقعی جایگزین یا حذف کنید.' }) : null,
				h('p', { class: 'description', text: 'این کد را در ویجت «HTML» یا «ویرایشگر متن» المنتور (حالت کد)، المان «Text» آوادا یا بلوک «HTML سفارشی» گوتنبرگ بچسبانید. عنوان سکشن H2 و زیربخش‌ها H3 هستند تا با H1 صفحه شما تداخل نداشته باشند.' }),
				code, h('p', {}, [copyBtn])
			]));
			return;
		}

		/* ---- Step 4: save / schedule */
		var actions = [];
		if (written < item.outline.length) {
			actions.push(h('p', { class: 'description', text: 'برای ساخت پیش‌نویس، اول همه بخش‌ها را بنویسید.' }));
		} else {
			var saveBtn = h('button', { type: 'button', class: 'button button-primary', text: isRefresh ? 'اعمال روی همان نوشته (قابل برگشت)' : (item.post_id ? 'به‌روزرسانی پیش‌نویس' : 'ساخت پیش‌نویس') });
			saveBtn.addEventListener('click', function () {
				if (isRefresh && !confirm('محتوای فعلی این نوشته با نسخه جدید جایگزین شود؟ (از «تاریخچه تغییرات» گزارش سئو قابل برگشت است)')) return;
				busy(saveBtn, true, 'در حال ذخیره…');
				request('item_save', briefData()).then(function () { return request('save', { item: item.id }); })
					.then(function (d) { item = d.item; msg(d.message, 'success'); render(); })
					.catch(function (er) { msg(er.message, 'error'); busy(saveBtn, false); });
			});
			actions.push(h('p', {}, [saveBtn]));
		}
		if (item.post_id) {
			actions.push(h('p', {}, [
				h('a', { class: 'button', href: item.edit_url, target: '_blank', rel: 'noopener', text: 'باز کردن در ویرایشگر' }), ' ',
				h('a', { class: 'button', href: item.view_url, target: '_blank', rel: 'noopener', text: 'پیش‌نمایش در سایت' })
			]));
			if (!isRefresh && C.canPublish && item.status !== 'published') {
				var when = h('input', { type: 'datetime-local', value: item.date ? item.date.replace(' ', 'T') : '' });
				var sch = h('button', { type: 'button', class: 'button button-primary', text: 'تأیید و زمان‌بندی' });
				var now = h('button', { type: 'button', class: 'button', text: 'تأیید و انتشار الان' });
				var go = function (btn, date) {
					busy(btn, true, '…');
					request('schedule', { item: item.id, date: date }).then(function (d) { item = d.item; msg(d.message, 'success'); render(); }).catch(function (er) { msg(er.message, 'error'); busy(btn, false); });
				};
				sch.addEventListener('click', function () { if (!when.value) { msg('تاریخ و ساعت را انتخاب کنید.', 'error'); return; } go(sch, when.value.replace('T', ' ').slice(0, 16)); });
				now.addEventListener('click', function () { if (confirm('همین الان منتشر شود؟')) go(now, 'now'); });
				actions.push(h('p', {}, [when, ' ', sch, ' ', now]));
				actions.push(h('p', { class: 'description', text: 'قبل از تأیید، پیش‌نویس را در ویرایشگر بخوانید، علامت‌های زرد و کادرهای «تصویر پیشنهادی» را تکمیل یا حذف و ذخیره کنید؛ تا آن‌ها باقی‌اند انتشار انجام نمی‌شود.' }));
			}
		}
		root.appendChild(h('section', { class: 'ssai-card' }, [h('h2', { text: '۴. ' + (isRefresh ? 'اعمال' : 'پیش‌نویس و انتشار') + ' — وضعیت: ' + (C.statuses[item.status] || item.status) + (item.date ? ' — ' + item.date : '') })].concat(actions)));
	}

	function escapeHtml(s) { var d = document.createElement('div'); d.textContent = s || ''; return d.innerHTML; }

	function outlineRow(o, i) {
		var lvl = options(h('select', { class: 'ssai-cs-lvl' }), { 0: 'مقدمه', 2: 'H2', 3: 'H3' }, o.level);
		var head = h('input', { type: 'text', class: 'ssai-cs-h large-text', value: o.level ? o.heading : '', placeholder: o.level ? 'تیتر' : '(بدون تیتر)' });
		var notes = h('textarea', { class: 'ssai-cs-notes', rows: 2, placeholder: 'این بخش چه چیزهایی را پوشش دهد' }); notes.value = o.notes || '';
		var im = o.image || {};
		var imgId = h('input', { type: 'hidden', class: 'ssai-cs-img-id', value: im.id || '' });
		var imgD = h('input', { type: 'text', class: 'ssai-cs-img-desc', value: im.desc || '', placeholder: 'چه تصویری این‌جا مناسب است (راهنما)' });
		var imgA = h('input', { type: 'text', class: 'ssai-cs-img-alt', value: im.alt || '', placeholder: 'متن alt' });
		var thumb = h('img', { class: 'ssai-cs-thumb', alt: '', src: im.thumb || '', hidden: !im.thumb });
		var pick = h('button', { type: 'button', class: 'button button-small', text: im.id ? 'تغییر تصویر' : '🖼 انتخاب از گالری' });
		var drop = h('button', { type: 'button', class: 'button-link ssai-cs-del', text: 'حذف تصویر', hidden: !im.id });
		var altAi = h('button', { type: 'button', class: 'button button-small', text: '✦ پیشنهاد alt' });
		pick.addEventListener('click', function () {
			if (!window.wp || !wp.media) { msg('کتابخانه رسانه بارگذاری نشد؛ صفحه را تازه کنید.', 'error'); return; }
			var frame = wp.media({ title: 'انتخاب تصویر برای این بخش', library: { type: 'image' }, multiple: false, button: { text: 'استفاده در این بخش' } });
			frame.on('select', function () {
				var a = frame.state().get('selection').first().toJSON();
				imgId.value = a.id;
				thumb.src = a.sizes && a.sizes.thumbnail ? a.sizes.thumbnail.url : a.url;
				thumb.hidden = false; drop.hidden = false; pick.textContent = 'تغییر تصویر';
				if (!imgA.value && a.alt) imgA.value = a.alt;
			});
			frame.open();
		});
		var slotX = h('button', { type: 'button', class: 'button-link ssai-cs-del', text: 'حذف جای تصویر', title: 'این بخش تصویر نداشته باشد' });
		var hasImg = !!(im.id || im.desc);
		var imgBox = h('div', { class: 'ssai-cs-img', hidden: !hasImg }, [imgId, thumb, pick, drop, imgD, imgA, altAi, slotX]);
		var addImg = h('button', { type: 'button', class: 'button-link ssai-cs-addimg', text: '+ تصویر برای این بخش', hidden: hasImg });
		addImg.addEventListener('click', function () { imgBox.hidden = false; addImg.hidden = true; imgD.focus(); });
		slotX.addEventListener('click', function () {
			imgId.value = ''; imgD.value = ''; imgA.value = ''; thumb.hidden = true; thumb.removeAttribute('src');
			drop.hidden = true; pick.textContent = '🖼 انتخاب از گالری'; imgBox.hidden = true; addImg.hidden = false;
		});
		drop.addEventListener('click', function () { imgId.value = ''; thumb.hidden = true; thumb.removeAttribute('src'); drop.hidden = true; pick.textContent = '🖼 انتخاب از گالری'; });
		altAi.addEventListener('click', function () {
			busy(altAi, true, '…');
			request('alt', { item: item.id, heading: head.value, desc: imgD.value, att: imgId.value })
				.then(function (d) { imgA.value = d.alt; imgA.classList.add('ssai-cs-flash'); busy(altAi, false); })
				.catch(function (er) { msg(er.message, 'error'); busy(altAi, false); });
		});
		var done = i >= 0 && item.sections && item.sections[i];
		var up = h('button', { type: 'button', class: 'button-link', title: 'بالا', text: '▲' });
		var down = h('button', { type: 'button', class: 'button-link', title: 'پایین', text: '▼' });
		var rm = h('button', { type: 'button', class: 'button-link ssai-cs-del', title: 'حذف', text: '✕' });
		var row = h('div', { class: 'ssai-cs-row lvl-' + o.level, 'data-from': i }, [
			h('div', { class: 'ssai-cs-row-top' }, [lvl, head, h('span', { class: done ? 'ssai-ok' : 'description', text: done ? '✓ نوشته شده' : '' }), up, down, rm]),
			notes, imgBox, addImg
		]);
		if (done && i >= 0) {
			var redo = h('button', { type: 'button', class: 'button button-small', text: 'بازنویسی این بخش' });
			redo.addEventListener('click', function () {
				busy(redo, true, 'در حال نوشتن…');
				request('section', { item: item.id, index: i }).then(function (d) { item = d.item; msg('بخش بازنویسی شد.', 'success'); render(); }).catch(function (er) { msg(er.message, 'error'); busy(redo, false); });
			});
			row.querySelector('.ssai-cs-row-top').appendChild(redo);
		}
		lvl.addEventListener('change', function () { row.className = 'ssai-cs-row lvl-' + lvl.value; });
		up.addEventListener('click', function () { if (row.previousElementSibling) row.parentNode.insertBefore(row, row.previousElementSibling); });
		down.addEventListener('click', function () { if (row.nextElementSibling) row.parentNode.insertBefore(row.nextElementSibling, row); });
		rm.addEventListener('click', function () { row.remove(); });
		return row;
	}

	function writeSections(todo, box) {
		var n = 0;
		box.innerHTML = '';
		var bar = h('progress', { max: todo.length, value: 0 });
		var label = h('span');
		box.appendChild(bar); box.appendChild(label);
		var step = function () {
			if (n >= todo.length) return Promise.resolve();
			var i = todo[n];
			label.textContent = ' بخش ' + (n + 1) + ' از ' + todo.length + ': ' + (item.outline[i].level ? item.outline[i].heading : 'مقدمه') + ' …';
			return request('section', { item: item.id, index: i }).then(function (d) {
				item = d.item; n++; bar.value = n; return step();
			});
		};
		return step();
	}

	/* ------------------------------------------------------------------ Short texts */
	function initShort() {
		var box = document.getElementById('ssai-cs-shorts');
		if (!box) return;
		var kindSel = document.getElementById('ssai-cs-kind');
		var thin = document.getElementById('ssai-cs-thin');
		var notes = document.getElementById('ssai-cs-short-notes');
		var load = function () {
			var kind = kindSel.value;
			box.innerHTML = '<p>در حال بارگذاری…</p>';
			if (thin) thin.parentNode.style.display = kind === 'product' ? '' : 'none';
			request('shorts', { kind: kind === 'product' ? 'product' : 'term', tax: kind === 'product_cat' ? 'product_cat' : 'category', thin: thin && thin.checked ? '1' : '0' }).then(function (d) {
				box.innerHTML = '';
				if (!d.rows.length) { box.appendChild(h('p', { text: 'موردی نیست.' })); return; }
				var all = h('input', { type: 'checkbox' });
				var tb = h('tbody');
				var api = { kind: kind === 'product' ? 'product' : 'term', tax: kind === 'product_cat' ? 'product_cat' : 'category' };
				d.rows.forEach(function (r) {
					var cb = h('input', { type: 'checkbox', class: 'ssai-cs-cb', value: r.id });
					var out = h('div', { class: 'ssai-cs-short-out' });
					var apply = h('button', { type: 'button', class: 'button button-small button-primary', text: 'اعمال', hidden: true });
					var undo = h('button', { type: 'button', class: 'button button-small', text: 'برگرداندن متن قبلی', hidden: !r.undo });
					apply.addEventListener('click', function () {
						busy(apply, true, '…');
						request('short_apply', Object.assign({ id: r.id }, api)).then(function (x) { out.appendChild(h('p', { class: 'ssai-ok', text: '✓ ' + x.message })); apply.hidden = true; undo.hidden = false; }).catch(function (er) { out.appendChild(h('p', { class: 'ssai-err', text: er.message })); busy(apply, false); });
					});
					undo.addEventListener('click', function () {
						if (!confirm('متن قبلی برگردانده شود؟')) return;
						request('short_undo', Object.assign({ id: r.id }, api)).then(function (x) { out.appendChild(h('p', { class: 'ssai-ok', text: '✓ ' + x.message })); undo.hidden = true; }).catch(function (er) { out.appendChild(h('p', { class: 'ssai-err', text: er.message })); });
					});
					var tr = h('tr', { 'data-id': r.id }, [h('th', { class: 'check-column' }, [cb]), h('td', {}, [h('strong', { text: r.title }), h('div', { class: 'description', text: r.info }), out]), h('td', {}, [apply, ' ', undo])]);
					tr._gen = function () {
						out.innerHTML = '<p class="description">در حال نوشتن…</p>';
						return request('short', Object.assign({ id: r.id, notes: notes.value }, api)).then(function (x) {
							out.innerHTML = '';
							if (x.short) { out.appendChild(h('div', { class: 'ssai-cs-short-box' }, [h('b', { text: 'توضیح کوتاه:' }), h('div', { html: x.short })])); }
							out.appendChild(h('div', { class: 'ssai-cs-short-box' }, [h('b', { text: kind === 'product' ? 'توضیح کامل:' : 'متن معرفی:' }), h('div', { html: x.long })]));
							if (x.meta) out.appendChild(h('p', { class: 'description', text: 'متا: ' + x.meta }));
							apply.hidden = false;
						}).catch(function (er) { out.innerHTML = ''; out.appendChild(h('p', { class: 'ssai-err', text: er.message })); });
					};
					tb.appendChild(tr);
				});
				all.addEventListener('change', function () { tb.querySelectorAll('.ssai-cs-cb').forEach(function (c) { c.checked = all.checked; }); });
				var gen = h('button', { type: 'button', class: 'button button-primary', text: '✦ ساخت پیش‌نمایش برای تیک‌خورده‌ها' });
				var applyAll = h('button', { type: 'button', class: 'button', text: 'اعمال همه پیش‌نمایش‌ها' });
				gen.addEventListener('click', function () {
					var trs = Array.prototype.filter.call(tb.querySelectorAll('tr'), function (tr) { return tr.querySelector('.ssai-cs-cb').checked; });
					if (!trs.length) { msg('حداقل یک ردیف را تیک بزنید.', 'error'); return; }
					busy(gen, true, 'در حال نوشتن…');
					var k = 0;
					var next = function () {
						if (k >= trs.length) { busy(gen, false); msg('پیش‌نمایش‌ها آماده است؛ بخوانید و موارد دلخواه را اعمال کنید.', 'success'); return; }
						gen.textContent = 'در حال نوشتن ' + (k + 1) + ' از ' + trs.length + '…';
						trs[k++]._gen().then(next);
					};
					next();
				});
				applyAll.addEventListener('click', function () {
					var btns = Array.prototype.filter.call(tb.querySelectorAll('button.button-primary'), function (b) { return !b.hidden && !b.disabled; });
					if (!btns.length) { msg('پیش‌نمایشی برای اعمال نیست.', 'error'); return; }
					if (!confirm(btns.length + ' مورد اعمال شود؟')) return;
					btns.forEach(function (b) { b.click(); });
				});
				box.appendChild(h('p', {}, [gen, ' ', applyAll]));
				box.appendChild(h('table', { class: 'widefat striped ssai-cs-shorts' }, [h('thead', {}, [h('tr', {}, [h('td', { class: 'check-column' }, [all]), h('th', { text: 'مورد' }), h('th', { text: '' })])]), tb]));
			}).catch(function (er) { box.innerHTML = ''; box.appendChild(h('p', { class: 'ssai-err', text: er.message })); });
		};
		kindSel.addEventListener('change', load);
		if (thin) thin.addEventListener('change', load);
		load();
	}

	/* ------------------------------------------------------------------ Calendar & brand */
	function initCalendar() {
		var f = document.getElementById('ssai-cs-cal');
		if (f) {
			f.addEventListener('submit', function (e) {
				e.preventDefault();
				var days = Array.prototype.map.call(f.querySelectorAll('[name=days]:checked'), function (c) { return c.value; }).join(',');
				request('calendar_save', { per_week: f.per_week.value, days: days, time: f.time.value }).then(function (d) { msg(d.message, 'success'); }).catch(function (er) { msg(er.message, 'error'); });
			});
		}
		var a = document.getElementById('ssai-cs-autofill');
		if (a) a.addEventListener('click', function () {
			busy(a, true, '…');
			request('autofill', {}).then(function (d) { msg(d.message, 'success'); setTimeout(function () { location.reload(); }, 900); }).catch(function (er) { msg(er.message, 'error'); busy(a, false); });
		});
	}

	function initBrand() {
		var f = document.getElementById('ssai-cs-brand');
		if (!f) return;
		f.addEventListener('submit', function (e) {
			e.preventDefault();
			var d = {};
			Array.prototype.forEach.call(f.elements, function (el) { if (el.name) d[el.name] = el.value; });
			request('profile_save', d).then(function (r) { msg(r.message, 'success'); }).catch(function (er) { msg(er.message, 'error'); });
		});
		var b = document.getElementById('ssai-cs-learn');
		if (b) b.addEventListener('click', function () {
			var ids = Array.prototype.map.call(document.getElementById('ssai-cs-learn-ids').selectedOptions, function (o) { return o.value; });
			if (!ids.length) { msg('چند نوشته انتخاب کنید.', 'error'); return; }
			busy(b, true, 'در حال تحلیل…');
			request('profile_learn', { ids: ids.join(',') }).then(function (r) {
				Object.keys(r.profile).forEach(function (k) { if (f.elements[k] && r.profile[k]) f.elements[k].value = r.profile[k]; });
				msg(r.message, 'success'); busy(b, false);
			}).catch(function (er) { msg(er.message, 'error'); busy(b, false); });
		});
	}

	document.addEventListener('DOMContentLoaded', function () {
		initIdeas(); initWrite(); initShort(); initCalendar(); initBrand();
	});
})();
