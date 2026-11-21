/* Smart SEO AI – admin UI (no dependencies). */
(function () {
	'use strict';

	var S = window.SSAI || {};

	function el(tag, attrs, children) {
		var n = document.createElement(tag);
		attrs = attrs || {};
		Object.keys(attrs).forEach(function (k) {
			if (k === 'class') n.className = attrs[k];
			else if (k === 'text') n.textContent = attrs[k];
			else if (k.indexOf('on') === 0) n.addEventListener(k.slice(2), attrs[k]);
			else n.setAttribute(k, attrs[k]);
		});
		[].concat(children || []).forEach(function (c) {
			if (c === null || c === undefined || c === false) return;
			n.appendChild(typeof c === 'string' ? document.createTextNode(c) : c);
		});
		return n;
	}

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

	function scoreClass(s) { return s >= 80 ? 'good' : (s >= 50 ? 'warning' : 'error'); }

	/* ------------------------------------------------------------ Settings page */
	function initSettings() {
		var sel = document.getElementById('ssai-provider');
		if (sel) {
			var upd = function () {
				var o = sel.options[sel.selectedIndex];
				document.getElementById('ssai-model-default').textContent = o.dataset.model || '—';
				document.getElementById('ssai-base-default').textContent = o.dataset.base || '—';
				var help = document.getElementById('ssai-provider-help');
				help.textContent = '';
				if (o.dataset.help) {
					help.appendChild(document.createTextNode('دریافت کلید / راهنما: '));
					help.appendChild(el('a', { href: o.dataset.help, target: '_blank', rel: 'noopener', text: o.dataset.help }));
				}
			};
			sel.addEventListener('change', function () {
				document.querySelectorAll('.ssai-backup-main').forEach(function (n) { n.hidden = true; });
				var m = document.querySelector('.ssai-backup-' + sel.value + ' .ssai-backup-main');
				if (m) m.hidden = false;
			});
			sel.addEventListener('change', upd);
			upd();
		}
		var fu = document.getElementById('ssai-fetch-updates');
		if (fu) {
			fu.addEventListener('click', function () {
				var out = document.getElementById('ssai-fetch-result');
				out.textContent = ' در حال دریافت…'; fu.disabled = true;
				request('fetch_updates', {}).then(function (d) { out.className = 'ssai-ok'; out.textContent = ' ' + d.message; })
					.catch(function (e) { out.className = 'ssai-err'; out.textContent = ' ' + e.message; })
					.then(function () { fu.disabled = false; });
			});
		}
		var ix = document.getElementById('ssai-inx-test');
		if (ix) {
			ix.addEventListener('click', function () {
				var out = document.getElementById('ssai-inx-result');
				out.textContent = ' در حال ارسال…'; ix.disabled = true;
				request('indexnow_test', {}).then(function (d) { out.className = 'ssai-ok'; out.textContent = ' ' + d.message; })
					.catch(function (e) { out.className = 'ssai-err'; out.textContent = ' ' + e.message; })
					.then(function () { ix.disabled = false; });
			});
		}
		var btn = document.getElementById('ssai-test');
		if (btn) {
			btn.addEventListener('click', function () {
				var out = document.getElementById('ssai-test-result');
				out.className = '';
				out.style.whiteSpace = 'pre-line';
				out.style.display = 'block';
				out.textContent = ' در حال آزمایش…';
				btn.disabled = true;
				request('test', {}).then(function (d) {
					out.className = 'ssai-ok'; out.textContent = ' ' + d.message;
				}).catch(function (e) {
					out.className = 'ssai-err'; out.textContent = ' ' + e.message;
				}).then(function () { btn.disabled = false; });
			});
		}
	}

	/* ------------------------------------------------------------ Meta box */
	var box, state = { rules: null, ai: S.lastAi || null, rewrite: null };

	function msg(text, type) {
		var m = box.querySelector('.ssai-msg');
		if (!m) return;
		m.className = 'ssai-msg ssai-msg-' + (type || 'info');
		m.textContent = text || '';
	}

	function busy(btn, on, label) {
		if (!btn) return;
		if (on) { btn.dataset.label = btn.textContent; btn.textContent = label || 'لطفاً صبر کنید…'; btn.disabled = true; }
		else { btn.textContent = btn.dataset.label || btn.textContent; btn.disabled = false; }
	}

	function applyChange(field, value, extra, btn) {
		var label = (S.fields && S.fields[field === 'content' && S.isBuilt ? 'elementor_text' : field]) || field;
		var saveHint = S.elementor
			? 'اگر در المنتور تغییر ذخیره‌نشده دارید، اول دکمه «به‌روزرسانی/انتشار» المنتور را بزنید.'
			: 'اگر تغییر ذخیره‌نشده‌ای در ویرایشگر دارید، اول آن را ذخیره کنید.';
		if (!window.confirm('«' + label + '» این صفحه تغییر کند؟\n\nپس از اعمال، ویرایشگر دوباره بارگذاری می‌شود. ' + saveHint + '\nاز بخش «تاریخچه» می‌توانید تغییر را برگردانید.')) return;
		var data = Object.assign({ post_id: S.postId, field: field, value: value }, extra || {});
		busy(btn, true, 'در حال اعمال…');
		request('apply', data).then(function (d) {
			msg(d.message + ' بارگذاری مجدد…', 'ok');
			showInlineError(btn, '');
			var ok = btn && btn.parentNode && btn.parentNode.querySelector('.ssai-inline-err');
			if (ok) { ok.className = 'ssai-msg ssai-msg-ok ssai-inline-err'; ok.textContent = d.message + ' در حال بارگذاری مجدد…'; }
			reloadEditor();
		}).catch(function (e) {
			msg('اعمال نشد: ' + e.message, 'error');
			busy(btn, false);
			showInlineError(btn, 'اعمال نشد: ' + e.message);
		});
	}

	// Show an error right next to the button that was clicked (the main message box may be off-screen).
	function showInlineError(btn, text) {
		if (!btn || !btn.parentNode) return;
		var old = btn.parentNode.querySelector('.ssai-inline-err');
		if (old) old.remove();
		var e = el('div', { class: 'ssai-msg ssai-msg-error ssai-inline-err', text: text });
		btn.parentNode.insertBefore(e, btn.parentNode.firstChild);
	}

	function reloadEditor() {
		// The editor still holds the old values in memory, so a fresh load is required
		// (otherwise the next "Update" click would overwrite the applied change).
		if (S.elementor) { try { sessionStorage.setItem('ssai-open', '1'); } catch (e) { /* ignore */ } }
		setTimeout(function () { window.location.reload(); }, 600);
	}

	function counter(input, min, max) {
		var c = el('span', { class: 'ssai-count' });
		var upd = function () {
			var l = input.value.length;
			c.textContent = l + ' کاراکتر';
			c.className = 'ssai-count ' + (l >= min && l <= max ? 'ssai-ok' : 'ssai-err');
		};
		input.addEventListener('input', upd);
		upd();
		return c;
	}

	function suggestionRow(field, label, current, suggested, opts) {
		opts = opts || {};
		if (!suggested) return null;
		var input = opts.multiline
			? el('textarea', { rows: 3, class: 'large-text' })
			: el('input', { type: 'text', class: 'large-text', dir: opts.ltr ? 'ltr' : 'auto' });
		input.value = suggested;
		var btn = S.canApply ? el('button', { type: 'button', class: 'button button-primary', text: 'اعمال', onclick: function () {
			if (!input.value.trim()) return;
			applyChange(field, input.value, opts.extra, btn);
		} }) : null;
		return el('div', { class: 'ssai-sugg' }, [
			el('div', { class: 'ssai-sugg-label', text: label }),
			current !== undefined ? el('div', { class: 'ssai-current' }, [el('span', { text: 'فعلی: ' }), el('em', { text: current || '(خالی)' })]) : null,
			input,
			el('div', { class: 'ssai-sugg-actions' }, [opts.min ? counter(input, opts.min, opts.max) : null, btn])
		]);
	}

	function renderAi() {
		var wrap = box.querySelector('.ssai-ai');
		wrap.textContent = '';
		var ai = state.ai;
		if (!ai) {
			wrap.appendChild(el('p', { class: 'description', text: 'هنوز تحلیل هوش مصنوعی انجام نشده است. دکمه «تحلیل با هوش مصنوعی» را بزنید.' }));
			return;
		}
		var cur = (state.rules && state.rules.current) || {};
		var head = el('div', { class: 'ssai-ai-head' }, [
			el('h3', { text: 'تحلیل هوش مصنوعی' }),
			ai.score !== null && ai.score !== undefined ? el('span', { class: 'ssai-badge ssai-' + scoreClass(ai.score), text: String(ai.score) }) : null,
			el('span', { class: 'description', text: (ai.model || '') + (ai.time ? ' – ' + new Date(ai.time * 1000).toLocaleString('fa-IR') : '') })
		]);
		wrap.appendChild(head);
		if (ai.summary) wrap.appendChild(el('p', { class: 'ssai-summary', text: ai.summary }));

		if (ai.issues && ai.issues.length) {
			var ul = el('ul', { class: 'ssai-issues' });
			var sevName = { high: 'مهم', medium: 'متوسط', low: 'کم' };
			ai.issues.forEach(function (i) {
				ul.appendChild(el('li', { class: 'ssai-sev-' + i.severity }, [
					el('span', { class: 'ssai-sev', text: sevName[i.severity] || i.severity }),
					el('strong', { text: i.message }),
					i.fix ? el('div', { class: 'ssai-fix', text: '← ' + i.fix }) : null
				]));
			});
			wrap.appendChild(ul);
		}

		var s = ai.suggestions || {};
		var sugg = el('div', { class: 'ssai-suggestions' }, [el('h4', { text: 'پیشنهادهای قابل اعمال' + (S.canApply ? '' : ' (شما مجوز اعمال ندارید)') })]);
		[
			suggestionRow('focus_keyword', 'کلمات کلیدی (اولی = اصلی)', (cur.keywords || []).join('، '), s.keywords || s.focus_keyword),
			suggestionRow('seo_title', 'عنوان سئو (در نتایج گوگل)', cur.seo_title, s.seo_title, { min: 30, max: 60 }),
			suggestionRow('title', 'عنوان صفحه (H1)', cur.title, s.title, { min: 20, max: 70 }),
			suggestionRow('meta_description', 'توضیحات متا', cur.meta_description, s.meta_description, { multiline: true, min: 120, max: 160 }),
			suggestionRow('slug', 'نامک (URL) – آدرس قدیمی خودکار به آدرس جدید ریدایرکت می‌شود', cur.slug, s.slug, { ltr: true })
		].forEach(function (r) { if (r) sugg.appendChild(r); });

		(s.image_alts || []).forEach(function (ia) {
			var curAlt = '';
			(cur.images || []).forEach(function (im) { if (im.src === ia.src) curAlt = im.alt; });
			var row = suggestionRow('image_alt', 'متن alt تصویر', curAlt, ia.alt, { extra: { src: ia.src } });
			if (row) {
				row.insertBefore(el('img', { src: ia.src, class: 'ssai-thumb', alt: '' }), row.firstChild);
				sugg.appendChild(row);
			}
		});
		wrap.appendChild(sugg);

		var tipBtn = function (text, opts) {
			if (!S.canApply) return null;
			var b = el('button', { type: 'button', class: 'button button-small ssai-tip-btn', text: text });
			b.addEventListener('click', function () { runRewrite(opts); });
			return b;
		};
		if (s.content_tips && s.content_tips.length) {
			wrap.appendChild(el('div', { class: 'ssai-tips-head' }, [
				el('h4', { text: 'نکات بهبود محتوا' }),
				s.content_tips.length > 1 ? tipBtn('اجرای همه نکات', { instructions: s.content_tips.join('\n') }) : null
			]));
			var tl = el('ul', { class: 'ssai-tips' });
			s.content_tips.forEach(function (t) {
				tl.appendChild(el('li', {}, [el('span', { text: t }), ' ', tipBtn('اجرا با هوش مصنوعی', { instructions: t })]));
			});
			wrap.appendChild(tl);
		}
		if (s.internal_link_ideas && s.internal_link_ideas.length) {
			wrap.appendChild(el('h4', { text: 'ایده‌های لینک داخلی' }));
			var ll = el('ul', { class: 'ssai-tips' });
			s.internal_link_ideas.forEach(function (t) {
				ll.appendChild(el('li', {}, [el('span', { text: t }), ' ', tipBtn('افزودن لینک', { link_ideas: t, instructions: 'افزودن لینک داخلی برای: ' + t })]));
			});
			wrap.appendChild(ll);
		}
		if (S.canApply && ((s.content_tips && s.content_tips.length) || (s.internal_link_ideas && s.internal_link_ideas.length))) {
			wrap.appendChild(el('p', { class: 'description', text: 'دکمه‌های «اجرا» یک پیشنهاد بازنویسی در بخش «بازنویسی محتوا» (پایین همین پنل) می‌سازند؛ تا آن را تأیید نکنید چیزی تغییر نمی‌کند. لینک‌ها فقط به صفحه‌هایی که واقعاً در سایت وجود دارند اضافه می‌شوند. نکاتی مثل «Schema» یا «ساخت صفحه جدید» کار فنی/طراحی است و با بازنویسی متن انجام نمی‌شود.' }));
		}
	}

	var rwInput = null, rwBtn = null;

	function rwStatus(text, type) {
		var st = box.querySelector('.ssai-rw-status');
		if (!st) return;
		st.className = 'ssai-msg ssai-rw-status ssai-msg-' + (type || 'info');
		st.textContent = text || '';
	}

	function runRewrite(opts) {
		opts = opts || {};
		if (!rwBtn) return;
		if (opts.instructions !== undefined && rwInput) rwInput.value = opts.instructions;
		var section = box.querySelector('.ssai-rewrite');
		if (section && section.scrollIntoView) section.scrollIntoView({ behavior: 'smooth', block: 'start' });
		busy(rwBtn, true, 'در حال بازنویسی… (ممکن است یک دقیقه طول بکشد)');
		rwStatus('در حال ارسال به هوش مصنوعی و دریافت پیشنهاد… لطفاً صبر کنید.', 'info');
		var data = { post_id: S.postId, instructions: rwInput ? rwInput.value : '' };
		if (opts.link_ideas) data.link_ideas = opts.link_ideas;
		request('ai_rewrite', data).then(function (d) {
			state.rewrite = d;
			renderRewrite();
			rwStatus('پیشنهاد آماده است. موارد را بررسی و در پایین دکمه «اعمال» را بزنید.', 'ok');
			var res = box.querySelector('.ssai-rw-result');
			if (res && res.scrollIntoView) res.scrollIntoView({ behavior: 'smooth', block: 'start' });
		}).catch(function (e) { rwStatus(e.message, 'error'); busy(rwBtn, false); });
	}

	function renderRewrite() {
		var wrap = box.querySelector('.ssai-rewrite');
		wrap.textContent = '';
		if (!S.canApply) return;
		var prevVal = rwInput ? rwInput.value : '';
		var ins = el('textarea', { rows: 3, class: 'large-text ssai-rw-input', placeholder: 'دستور اضافه (اختیاری)، مثلاً: لحن رسمی‌تر، یک بخش پرسش‌وپاسخ اضافه کن…' });
		ins.value = prevVal;
		var btn = el('button', { type: 'button', class: 'button button-primary', text: (S.isBuilt ? 'پیشنهاد بازنویسی متن‌های صفحه با هوش مصنوعی' : 'پیشنهاد بازنویسی کامل محتوا با هوش مصنوعی') });
		rwInput = ins; rwBtn = btn;
		btn.addEventListener('click', function () { runRewrite({}); });
		wrap.appendChild(el('h3', { text: 'بازنویسی محتوا' }));
		wrap.appendChild(el('p', { class: 'description', text: S.isBuilt
			? 'صفحه ' + (S.builder || 'المنتور') + ': متن تیترها و ویجت‌های متنی جداگانه بازنویسی می‌شود و طراحی صفحه دست نمی‌خورد. پیشنهادها ابتدا فقط نمایش داده می‌شوند و هر کدام را بخواهید انتخاب و اعمال می‌کنید.'
			: 'متن پیشنهادی ابتدا فقط نمایش داده می‌شود و تا شما تأیید نکنید چیزی تغییر نمی‌کند. نسخه قبلی در «نسخه‌ها»ی وردپرس و تاریخچه همین افزونه می‌ماند.' }));
		wrap.appendChild(ins);
		wrap.appendChild(btn);
		wrap.appendChild(el('div', { class: 'ssai-msg ssai-rw-status' }));

		var rw = state.rewrite;
		if (!rw) return;
		if (rw.mode === 'elementor') { renderElementorRewrite(wrap, rw); return; }
		var prev = el('div', { class: 'ssai-preview' });
		prev.innerHTML = rw.html; // Sanitised server-side with wp_kses_post.
		var applyBtn = el('button', { type: 'button', class: 'button button-primary', text: 'اعمال این بازنویسی' });
		applyBtn.addEventListener('click', function () { applyChange('content', '', { token: rw.token }, applyBtn); });
		var discard = el('button', { type: 'button', class: 'button', text: 'رد کردن', onclick: function () { state.rewrite = null; renderRewrite(); } });
		wrap.appendChild(el('div', { class: 'ssai-rw-result' }, [
			el('p', {}, [el('strong', { text: 'تعداد کلمات: ' }), rw.old_words + ' ← ' + rw.new_words]),
			rw.notes && rw.notes.length ? el('ul', { class: 'ssai-tips' }, rw.notes.map(function (n) { return el('li', { text: n }); })) : null,
			rw.lost && rw.lost.length ? el('div', { class: 'ssai-msg ssai-msg-error' }, ['هشدار: این موارد در متن جدید پیدا نشد و حذف خواهند شد: ' + rw.lost.join('، ')]) : null,
			rw.blocks ? el('p', { class: 'description', text: 'این نوشته با ویرایشگر بلوکی ساخته شده؛ متن جدید به صورت یک بلوک «کلاسیک» ذخیره می‌شود و می‌توانید در ویرایشگر آن را «تبدیل به بلوک‌ها» کنید.' }) : null,
			prev,
			el('div', { class: 'ssai-sugg-actions' }, [applyBtn, discard])
		]));
	}

	function renderElementorRewrite(wrap, rw) {
		var list = el('div', { class: 'ssai-el-items' });
		var boxes = [];
		rw.items.forEach(function (it) {
			var cb = el('input', { type: 'checkbox' });
			cb.checked = true;
			cb.value = it.id;
			boxes.push(cb);
			var oldDiv = el('div', { class: 'ssai-el-old' });
			oldDiv.textContent = it.old.replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').trim();
			var newDiv = el('div', { class: 'ssai-el-new' });
			newDiv.innerHTML = it.new; // Sanitised server-side (wp_kses_post / plain text).
			list.appendChild(el('div', { class: 'ssai-el-item' }, [
				el('label', { class: 'ssai-el-head' }, [cb, ' ', el('strong', { text: it.label })]),
				el('div', { class: 'ssai-el-cols' }, [
					el('div', {}, [el('div', { class: 'description', text: 'فعلی' }), oldDiv]),
					el('div', {}, [el('div', { class: 'description', text: 'پیشنهادی' }), newDiv])
				]),
				it.lost && it.lost.length ? el('div', { class: 'ssai-msg ssai-msg-error' }, ['هشدار: این لینک/تصویر در متن جدید نیست: ' + it.lost.join('، ')]) : null
			]));
		});
		var applyBtn = el('button', { type: 'button', class: 'button button-primary', text: 'اعمال موارد انتخاب‌شده' });
		applyBtn.addEventListener('click', function () {
			var ids = boxes.filter(function (b) { return b.checked; }).map(function (b) { return b.value; });
			if (!ids.length) { msg('هیچ موردی انتخاب نشده است.', 'error'); return; }
			applyChange('content', '', { token: rw.token, ids: ids.join(',') }, applyBtn);
		});
		var discard = el('button', { type: 'button', class: 'button', text: 'رد کردن', onclick: function () { state.rewrite = null; renderRewrite(); } });
		wrap.appendChild(el('div', { class: 'ssai-rw-result' }, [
			el('p', {}, [el('strong', { text: rw.items.length + ' پیشنهاد تغییر' })]),
			rw.notes && rw.notes.length ? el('ul', { class: 'ssai-tips' }, rw.notes.map(function (n) { return el('li', { text: n }); })) : null,
			list,
			el('div', { class: 'ssai-sugg-actions' }, [applyBtn, discard])
		]));
	}

	function renderHistory(list) {
		var wrap = box.querySelector('.ssai-history');
		wrap.textContent = '';
		if (!list || !list.length) return;
		var det = el('details', {}, [el('summary', { text: 'تاریخچه تغییرات اعمال‌شده (' + list.length + ')' })]);
		var tbl = el('table', { class: 'widefat striped' });
		tbl.appendChild(el('thead', {}, el('tr', {}, ['زمان', 'کاربر', 'بخش', 'قبلی', 'جدید', ''].map(function (h) { return el('th', { text: h }); }))));
		var tb = el('tbody');
		list.forEach(function (h) {
			var act;
			if (h.undone) act = el('span', { class: 'description', text: 'برگردانده شد' });
			else if (S.canApply) {
				act = el('button', { type: 'button', class: 'button button-small', text: 'برگرداندن' });
				act.addEventListener('click', function () {
					if (!window.confirm('این تغییر برگردانده شود؟ صفحه دوباره بارگذاری می‌شود.')) return;
					busy(act, true, '…');
					request('undo', { post_id: S.postId, entry: h.id }).then(function (d) { msg(d.message, 'ok'); reloadEditor(); })
						.catch(function (e) { msg(e.message, 'error'); busy(act, false); });
				});
			}
			tb.appendChild(el('tr', {}, [
				el('td', { text: h.time }), el('td', { text: h.user }), el('td', { text: (S.fields && S.fields[h.field]) || h.field }),
				el('td', { text: h.old }), el('td', { text: h.new }), el('td', {}, act)
			]));
		});
		tbl.appendChild(tb);
		det.appendChild(tbl);
		wrap.appendChild(det);
	}

	var FIX_LABEL = {
		seo_title: 'پیشنهاد عنوان سئو با هوش مصنوعی',
		meta: 'پیشنهاد توضیحات متا با هوش مصنوعی',
		slug: 'پیشنهاد URL با هوش مصنوعی',
		keywords: 'پیشنهاد کلمات کلیدی با هوش مصنوعی',
		alts: 'پیشنهاد alt تصاویر با هوش مصنوعی',
		links: 'افزودن لینک داخلی با هوش مصنوعی',
		rewrite: 'با هوش مصنوعی رفع کنید'
	};
	var FIELD_OF = { seo_title: 'seo_title', meta: 'meta_description', slug: 'slug', keywords: 'focus_keyword' };
	var LIMITS = { seo_title: [30, 60], meta: [120, 160] };

	function kwList(v) {
		return String(v || '').split(/\s*[,،]\s*/).map(function (x) { return x.trim(); }).filter(Boolean);
	}

	// Inline AI suggestions for one target, shown right under the check that needs it.
	function runFix(target, holder, btn) {
		var cur = (state.rules && state.rules.current) || {};
		holder.textContent = '';
		if (target === 'rewrite' || target === 'links') return;
		busy(btn, true, 'در حال دریافت پیشنهاد…');
		request('ai_fix', { post_id: S.postId, target: target }).then(function (d) {
			busy(btn, false);
			holder.textContent = '';
			if (d.target === 'alts') {
				d.image_alts.forEach(function (ia) {
					var curAlt = '';
					(cur.images || []).forEach(function (im) { if (im.src === ia.src) curAlt = im.alt; });
					var row = suggestionRow('image_alt', 'متن alt تصویر', curAlt, ia.alt, { extra: { src: ia.src } });
					if (row) { row.insertBefore(el('img', { src: ia.src, class: 'ssai-thumb', alt: '' }), row.firstChild); holder.appendChild(row); }
				});
				return;
			}
			var field = FIELD_OF[d.target];
			var current = { seo_title: cur.seo_title, meta: cur.meta_description, slug: cur.slug, keywords: (cur.keywords || []).join('، ') }[d.target];
			d.options.forEach(function (o, i) {
				var lim = LIMITS[d.target] || [];
				var lbl = (d.target === 'keywords' && d.data && i === 0)
					? 'پیشنهاد از روی جستجوهای واقعی گوگل' + (d.data === 'page' ? ' برای این صفحه' : ' (خروجی کل سایت)')
					: 'گزینه ' + (i + 1) + (d.target === 'keywords' && d.data ? ' (هوش مصنوعی)' : '');
				var row = suggestionRow(field, lbl, i === 0 ? current : undefined, o, { min: lim[0], max: lim[1], multiline: d.target === 'meta', ltr: d.target === 'slug' });
				if (row && d.target === 'seo_title' && S.canApply) {
					var input = row.querySelector('input');
					var b2 = el('button', { type: 'button', class: 'button', text: 'به عنوان عنوان صفحه (H1)' });
					b2.addEventListener('click', function () { applyChange('title', input.value, null, b2); });
					row.querySelector('.ssai-sugg-actions').appendChild(b2);
				}
				if (row) holder.appendChild(row);
			});
		}).catch(function (e) { busy(btn, false); holder.appendChild(el('div', { class: 'ssai-msg ssai-msg-error', text: e.message })); });
	}

	/* SEO review list: re-check all pages in batches of 5, then reload. */
	document.addEventListener('click', function (e) {
		var b = e.target.closest ? e.target.closest('#ssai-rescan') : null;
		if (!b) return;
		var prog = document.getElementById('ssai-rescan-progress');
		b.disabled = true;
		prog.textContent = ' در حال آماده‌سازی…';
		request('rescan_ids', {}).then(function (d) {
			var ids = d.ids, done = 0;
			var next = function () {
				if (done >= ids.length) { prog.textContent = ' ✓ تمام شد'; location.reload(); return; }
				var part = ids.slice(done, done + 5);
				prog.textContent = ' بررسی ' + Math.min(done + part.length, ids.length) + ' از ' + ids.length + '…';
				request('rescan', { ids: part.join(',') }).then(function () { done += part.length; next(); })
					.catch(function (er) { prog.textContent = ' ✗ ' + er.message + ' (بررسی‌شده‌ها ذخیره شده‌اند؛ دوباره بزنید)'; b.disabled = false; });
			};
			next();
		}).catch(function (er) { prog.textContent = ' ✗ ' + er.message; b.disabled = false; });
	});

	/* Report page: canonical / viewport fix buttons (explain, confirm, apply, reload). */
	document.addEventListener('click', function (e) {
		var b = e.target.closest ? e.target.closest('.ssai-tech-fix') : null;
		if (!b) return;
		if (!window.confirm(b.getAttribute('data-confirm'))) return;
		b.disabled = true;
		request('tech_fix', { post_id: b.getAttribute('data-post') || S.postId, kind: b.getAttribute('data-kind') }).then(function (r) {
			b.insertAdjacentElement('afterend', el('div', { class: 'ssai-msg ssai-msg-success', text: '✓ ' + r.message + ' — صفحه دوباره بررسی می‌شود…' }));
			setTimeout(function () { location.reload(); }, 1800);
		}).catch(function (er) { b.disabled = false; b.insertAdjacentElement('afterend', el('div', { class: 'ssai-msg ssai-msg-error', text: er.message })); });
	});

	function fixAction(it, holder) {
		if (it.action && S.canApply) {
			var tb = el('button', { type: 'button', class: 'ssai-fix-btn', text: '🔧 ' + it.action.label });
			tb.addEventListener('click', function () {
				if (!window.confirm(it.action.confirm)) return;
				busy(tb, true);
				request('tech_fix', { post_id: S.postId, kind: it.action.kind }).then(function (r) {
					holder.appendChild(el('div', { class: 'ssai-msg ssai-msg-success', text: '✓ ' + r.message }));
					tb.remove();
					setTimeout(loadRules, 1500);
				}).catch(function (e) { busy(tb, false); holder.appendChild(el('div', { class: 'ssai-msg ssai-msg-error', text: e.message })); });
			});
			return el('div', {}, [tb, it.hint ? el('div', { class: 'ssai-hint', text: it.hint }) : null]);
		}
		if (!it.fix && it.hint) return el('div', { class: 'ssai-hint', text: '💡 ' + it.hint });
		if (!it.fix || !S.canApply) return it.hint ? el('div', { class: 'ssai-hint', text: it.hint }) : null;
		var b = el('button', { type: 'button', class: 'ssai-fix-btn', text: '✦ ' + (FIX_LABEL[it.fix] || FIX_LABEL.rewrite) });
		b.addEventListener('click', function () {
			var cur = (state.rules && state.rules.current) || {};
			if (it.fix === 'rewrite') { runRewrite({ instructions: it.hint }); return; }
			if (it.fix === 'links') {
				var ideas = (cur.keywords || []).join('\n') || cur.title;
				runRewrite({ link_ideas: ideas, instructions: 'به صفحه‌های مرتبط سایت که در internal_link_candidates آمده، لینک داخلی طبیعی اضافه کن.' });
				return;
			}
			runFix(it.fix, holder, b);
		});
		var wrapEl = el('div', {}, [b]);
		if (it.hint) wrapEl.appendChild(el('div', { class: 'ssai-hint', text: it.hint }));
		return wrapEl;
	}

	function renderSnippet(cur) {
		var t = cur.seo_title || cur.title || '';
		var d = cur.meta_description || '';
		var tc = t.length, dc = d.length;
		return el('div', { class: 'ssai-snippet' }, [
			el('div', { class: 'ssai-snippet-head' }, [
				el('span', { class: 'description', text: 'پیش‌نمایش در گوگل' }),
				el('span', { class: 'ssai-count ' + (tc >= 30 && tc <= 60 ? 'ssai-ok' : 'ssai-err'), text: 'عنوان ' + tc + '/60' }),
				el('span', { class: 'ssai-count ' + (dc >= 120 && dc <= 160 ? 'ssai-ok' : 'ssai-err'), text: 'توضیحات ' + dc + '/160' })
			]),
			el('div', { class: 'ssai-snippet-url', dir: 'ltr', text: decodeURI(cur.url || '') }),
			el('div', { class: 'ssai-snippet-title', text: t.length > 65 ? t.slice(0, 62) + '…' : t }),
			el('div', { class: 'ssai-snippet-desc', text: d ? (d.length > 165 ? d.slice(0, 160) + '…' : d) : '(توضیحات متا ندارد – گوگل بخشی از متن صفحه را نشان می‌دهد)' })
		]);
	}

	function renderRules(r) {
		var wrap = box.querySelector('.ssai-rules');
		wrap.textContent = '';
		box.querySelector('.ssai-score').className = 'ssai-badge ssai-score ssai-' + scoreClass(r.score);
		box.querySelector('.ssai-score').textContent = r.score;
		var cur = r.current;

		wrap.appendChild(renderSnippet(cur));

		// Keywords: first = primary, others = secondary.
		var kws = cur.keywords || [];
		var kw = el('input', { type: 'text', class: 'regular-text', placeholder: 'کلمه کلیدی اصلی، کلمه فرعی ۱، کلمه فرعی ۲' });
		kw.value = kws.join('، ');
		var chips = el('div', { class: 'ssai-chips' });
		var drawChips = function () {
			chips.textContent = '';
			kwList(kw.value).forEach(function (k, i) {
				chips.appendChild(el('span', { class: 'ssai-chip' + (i === 0 ? ' ssai-chip-main' : '') }, [i === 0 ? '★ ' : '', k]));
			});
		};
		kw.addEventListener('input', drawChips);
		drawChips();
		var kwBox = el('div', { class: 'ssai-kw-box' }, [
			el('label', { text: 'کلمات کلیدی (اولی = اصلی، بقیه = فرعی؛ با ویرگول جدا کنید):' }),
			kw, chips
		]);
		var kwHolder = el('div', { class: 'ssai-fix-holder' });
		if (S.canApply) {
			var save = el('button', { type: 'button', class: 'button', text: 'ذخیره کلمات کلیدی' });
			save.addEventListener('click', function () { applyChange('focus_keyword', kw.value, null, save); });
			var sug = el('button', { type: 'button', class: 'ssai-fix-btn', text: '✦ ' + FIX_LABEL.keywords });
			sug.addEventListener('click', function () { runFix('keywords', kwHolder, sug); });
			kwBox.appendChild(el('div', { class: 'ssai-sugg-actions' }, [r.seoPlugin ? el('span', { class: 'description', text: 'همگام با ' + r.seoPlugin }) : null, sug, save]));
		}
		kwBox.appendChild(kwHolder);
		wrap.appendChild(kwBox);

		// Grouped checks.
		var groups = r.groups || {};
		Object.keys(groups).forEach(function (g) {
			var list = r.items.filter(function (it) { return it.group === g; });
			if (!list.length) return;
			var errs = list.filter(function (it) { return it.status === 'error'; }).length;
			var warns = list.filter(function (it) { return it.status === 'warning'; }).length;
			var badge = errs ? el('span', { class: 'ssai-gbadge ssai-gbadge-err', text: errs + ' × خطا' })
				: (warns ? el('span', { class: 'ssai-gbadge ssai-gbadge-warn', text: warns + ' هشدار' }) : el('span', { class: 'ssai-gbadge ssai-gbadge-ok', text: '✓ همه چیز خوب است' }));
			var det = el('details', { class: 'ssai-group' }, [el('summary', {}, [el('strong', { text: groups[g] }), ' ', badge])]);
			if (errs || warns) det.open = true;
			var ul = el('ul', { class: 'ssai-list' });
			list.forEach(function (it) {
				var holder = el('div', { class: 'ssai-fix-holder' });
				var li = el('li', { class: 'ssai-' + it.status }, [
					el('span', { class: 'ssai-dot' }),
					el('div', { class: 'ssai-li-body' }, [el('div', { text: it.message }), it.status !== 'good' ? fixAction(it, holder) : null, holder])
				]);
				ul.appendChild(li);
			});
			det.appendChild(ul);
			wrap.appendChild(det);
		});
		renderHistory(r.history);
	}

	function loadRules() {
		return request('rules', { post_id: S.postId }).then(function (r) {
			state.rules = r;
			renderRules(r);
			renderAi();
		}).catch(function (e) { msg(e.message, 'error'); });
	}

	function initMetaBox() {
		box = document.getElementById('ssai-box');
		if (!box || !S.postId || !box.querySelector('.ssai-loading')) return;
		box.textContent = '';

		var btnRefresh = el('button', { type: 'button', class: 'button', text: 'بررسی مجدد' });
		var btnAi = el('button', { type: 'button', class: 'button button-primary', text: 'تحلیل با هوش مصنوعی' });
		btnRefresh.addEventListener('click', function () { busy(btnRefresh, true, '…'); loadRules().then(function () { busy(btnRefresh, false); }); });
		btnAi.addEventListener('click', function () {
			busy(btnAi, true, 'در حال تحلیل… (ممکن است تا یک دقیقه طول بکشد)');
			msg('');
			request('ai_analyze', { post_id: S.postId }).then(function (d) {
				state.ai = d; renderAi(); msg('تحلیل انجام شد. پیشنهادها را بررسی کنید و در صورت تمایل اعمال کنید.', 'ok');
			}).catch(function (e) { msg(e.message, 'error'); }).then(function () { busy(btnAi, false); });
		});

		box.appendChild(el('div', { class: 'ssai-top' }, [
			el('div', { class: 'ssai-score-wrap' }, [el('span', { class: 'ssai-badge ssai-score', text: '…' }), el('span', { text: ' امتیاز سئو (قوانین داخلی)' })]),
			el('div', { class: 'ssai-actions' }, [S.reportUrl ? el('a', { class: 'button', href: S.reportUrl, target: S.elementor ? '_blank' : '_self', text: 'گزارش کامل و رتبه در گوگل' }) : null, btnRefresh, btnAi])
		]));
		box.appendChild(el('p', { class: 'description', text: 'امتیاز بر اساس آخرین نسخه ذخیره‌شده محاسبه می‌شود؛ پس از ویرایش، نوشته را ذخیره و «بررسی مجدد» را بزنید.' }));
		box.appendChild(el('div', { class: 'ssai-msg' }));
		box.appendChild(el('div', { class: 'ssai-rules' }));
		box.appendChild(el('div', { class: 'ssai-ai' }));
		box.appendChild(el('div', { class: 'ssai-rewrite' }));
		box.appendChild(el('div', { class: 'ssai-history' }));

		renderRewrite();
		loadRules();
	}

	/* ------------------------------------------------------------ Elementor editor panel */
	function initElementor() {
		if (!S.elementor || !S.postId || document.getElementById('ssai-el-fab')) return;
		var drawer = el('div', { id: 'ssai-el-drawer', class: 'ssai-el-drawer', dir: 'rtl', 'aria-hidden': 'true' });
		var close = el('button', { type: 'button', class: 'ssai-el-close', 'aria-label': 'بستن', text: '×' });
		drawer.appendChild(el('div', { class: 'ssai-el-bar' }, [el('strong', { text: 'Smart SEO AI' }), close]));
		var inner = el('div', { id: 'ssai-box', class: 'ssai ssai-el-body', dir: 'rtl' }, [el('div', { class: 'ssai-loading', text: 'در حال بررسی…' })]);
		drawer.appendChild(inner);
		var fab = el('button', { type: 'button', id: 'ssai-el-fab', class: 'ssai-el-fab', title: 'بررسی سئو', text: 'SEO' });
		var open = function () {
			drawer.classList.add('is-open');
			drawer.setAttribute('aria-hidden', 'false');
			initMetaBox();
		};
		fab.addEventListener('click', function () { if (drawer.classList.contains('is-open')) { drawer.classList.remove('is-open'); } else { open(); } });
		close.addEventListener('click', function () { drawer.classList.remove('is-open'); drawer.setAttribute('aria-hidden', 'true'); });
		document.body.appendChild(drawer);
		document.body.appendChild(fab);
		var reopen = false;
		try { reopen = sessionStorage.getItem('ssai-open') === '1'; sessionStorage.removeItem('ssai-open'); } catch (e) { /* ignore */ }
		if (reopen) open();
	}

	function start() { initSettings(); if (S.elementor) initElementor(); else initMetaBox(); }
	if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start); else start();
	// The block editor may render meta boxes after DOMContentLoaded.
	window.addEventListener('load', function () { if (!S.elementor) initMetaBox(); });
	/* Copy a page address before opening Search Console (its links can no longer carry the address). */
	document.addEventListener('click', function (e) {
		var a = e.target.closest ? e.target.closest('[data-ssai-copy]') : null;
		if (!a) return;
		var txt = a.getAttribute('data-ssai-copy');
		var done = function () {
			var n = document.createElement('span');
			n.className = 'ssai-ok'; n.textContent = ' ✓ آدرس کپی شد — در سرچ کنسول Ctrl+V';
			a.insertAdjacentElement('afterend', n);
			setTimeout(function () { n.remove(); }, 6000);
		};
		if (navigator.clipboard && window.isSecureContext) {
			navigator.clipboard.writeText(txt).then(done, function () {});
		} else {
			var t = document.createElement('textarea'); t.value = txt; document.body.appendChild(t); t.select();
			try { document.execCommand('copy'); done(); } catch (er) {}
			t.remove();
		}
	});
})();
