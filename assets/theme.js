/* Smart SEO AI – light / dark theme switch (remembered per browser, default = operating system). */
(function () {
	'use strict';
	var KEY = 'ssai-theme';
	function saved() { try { return localStorage.getItem(KEY); } catch (e) { return null; } }
	function store(v) { try { localStorage.setItem(KEY, v); } catch (e) {} }
	function systemDark() { return !!(window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches); }
	function isDark() { var s = saved(); return s ? s === 'dark' : systemDark(); }
	function apply() {

		document.documentElement.classList.toggle('ssai-dark', isDark());
		var b = document.querySelector('.ssai-theme-toggle');
		if (b) b.textContent = isDark() ? '☀️ روشن' : '🌙 تیره';
	}
	// As early as possible to avoid a light flash.
	apply();
	document.addEventListener('DOMContentLoaded', function () {
		var h1 = document.querySelector('.wrap.ssai h1');
		if (h1 && !document.querySelector('.ssai-theme-toggle')) {
			var b = document.createElement('button');
			b.type = 'button';
			b.className = 'ssai-theme-toggle';
			b.title = 'حالت روشن / تیره';
			b.addEventListener('click', function () { store(isDark() ? 'light' : 'dark'); apply(); });
			h1.appendChild(b);
		}
		apply();
		if (window.matchMedia) {
			var mq = window.matchMedia('(prefers-color-scheme: dark)');
			var on = function () { if (!saved()) apply(); };
			if (mq.addEventListener) mq.addEventListener('change', on); else if (mq.addListener) mq.addListener(on);
		}
	});
})();
