(function () {
	var GRID = '#main .ct-container > section';
	var ctrl;
	var wide = window.matchMedia ? window.matchMedia('(min-width: 640px)') : { matches: true };
	var state = { full: false, group: null };
	var pop, scrim;
	var pending = {}; // URL parameter => value (null removes it), collected while a popover or the full view is open
	var pendingSel = {}; // attribute => selected term slugs

	function selectedIn(attr) {
		var r = root();
		var list = r ? r.querySelectorAll('.pfb-opts[data-attr="' + attr + '"] .pfb-opt.is-on') : [];
		return Array.prototype.map.call(list, function (o) {
			return o.getAttribute('data-term');
		});
	}

	function pendingUrl() {
		var u = new URL(location.href);
		u.pathname = u.pathname.replace(/\/page\/\d+\/?/, '/');
		u.searchParams.delete('paged');
		u.searchParams.delete('product-page');
		Object.keys(pending).forEach(function (k) {
			if (pending[k] === null) u.searchParams.delete(k);
			else u.searchParams.set(k, pending[k]);
		});
		return u;
	}

	function same(a, b) {
		function norm(u) {
			var p = Array.from(u.searchParams.entries()).sort().join('&');
			return u.pathname + '?' + p;
		}
		return norm(a) === norm(b);
	}

	/** Applies what was picked while the popover or full view was open: one reload instead of one per click. */
	function commit() {
		if (!Object.keys(pending).length) return;
		var u = pendingUrl();
		var unchanged = same(u, new URL(location.href));
		pending = {};
		pendingSel = {};
		if (!unchanged) load(u.toString(), true);
	}

	function labelButtons() {
		var dirty = Object.keys(pending).length > 0;
		document.querySelectorAll('.pfb-show').forEach(function (b) {
			b.textContent = dirty ? 'Anwenden' : b.getAttribute('data-label') || b.textContent;
			if (!b.getAttribute('data-label')) b.setAttribute('data-label', b.textContent);
		});
	}

	function pick(o) {
		var box = o.closest('.pfb-opts');
		var attr = box.getAttribute('data-attr');
		var cur = pendingSel[attr] || selectedIn(attr);
		var term = o.getAttribute('data-term');
		var i = cur.indexOf(term);
		if (i < 0) cur.push(term);
		else cur.splice(i, 1);
		pendingSel[attr] = cur;
		o.classList.toggle('is-on', i < 0);
		o.setAttribute('aria-checked', i < 0);
		pending['filter_' + attr] = cur.length ? cur.join(',') : null;
		pending['query_type_' + attr] = cur.length ? 'or' : null;
		labelButtons();
	}

	function root() {
		return document.querySelector('.cbee-pfb');
	}

	function lock(on) {
		document.documentElement.classList.toggle('pfb-lock', on);
	}

	function ensurePop() {
		if (pop) return;
		pop = document.createElement('div');
		pop.className = 'pfp';
		pop.setAttribute('role', 'dialog');
		scrim = document.createElement('div');
		scrim.className = 'pfp-scrim';
		document.body.appendChild(scrim);
		document.body.appendChild(pop);
	}

	function closeAll() {
		var r = root();
		commit();
		state.full = false;
		state.group = null;
		if (r && r.querySelector('.pfb-full')) r.querySelector('.pfb-full').classList.remove('is-open');
		if (pop) {
			pop.classList.remove('is-open');
			scrim.classList.remove('is-open');
		}
		lock(false);
	}

	function openFull() {
		var r = root();
		if (!r) return;
		ensurePop();
		closeAll();
		state.full = true;
		r.querySelector('.pfb-full').classList.add('is-open');
		scrim.classList.add('is-open');
		lock(!wide.matches);
	}

	/** Fills the popover (desktop) or bottom sheet (phone) with the options of state.group, copied from the full view. */
	function fillPop() {
		var r = root();
		var acc = r && r.querySelector('.pfb-acc[data-group="' + state.group + '"]');
		var pill = r && r.querySelector('.pfb-pl[data-group="' + state.group + '"]');
		if (!acc || !pill) return closeAll();
		var x = r.querySelector('.pfb-x[data-for="' + state.group + '"]');
		var h = document.createElement('div');
		h.className = 'pfp-h';
		h.innerHTML = '<b></b>';
		h.firstChild.textContent = acc.getAttribute('data-title');
		if (x) {
			var c = document.createElement('a');
			c.href = x.href;
			c.rel = 'nofollow';
			c.textContent = 'Zurücksetzen';
			h.appendChild(c);
		}
		var b = document.createElement('div');
		b.className = 'pfp-b';
		b.appendChild(acc.querySelector('.pfb-opts').cloneNode(true));
		pop.textContent = '';
		pop.appendChild(h);
		pop.appendChild(b);
		var sheet = !wide.matches;
		if (sheet) {
			var f = document.createElement('div');
			f.className = 'pfp-f';
			var show = r.querySelector('.pfb-show').cloneNode(true);
			f.appendChild(show);
			pop.appendChild(f);
		}
		pop.classList.toggle('is-sheet', sheet);
		pop.classList.add('is-open');
		scrim.classList.toggle('is-open', sheet);
		lock(sheet);
		if (!sheet) {
			var rect = pill.closest('.pfb-pill').getBoundingClientRect();
			var left = Math.min(rect.left, window.innerWidth - pop.offsetWidth - 12);
			pop.style.left = Math.max(12, left) + window.pageXOffset + 'px';
			pop.style.top = rect.bottom + window.pageYOffset + 6 + 'px';
		}
	}

	/** After the no-reload swap replaced the bar: reopen whatever was open. */
	function restore() {
		var r = root();
		if (!r) return;
		if (state.full) r.querySelector('.pfb-full').classList.add('is-open');
		else if (state.group) fillPop();
	}

	/** Fade and edge padding only while the row actually scrolls, so a roomy desktop row ends cleanly at the sort pill. */
	function fitRow() {
		var row = document.querySelector('.pfb-row');
		if (row) row.classList.toggle('is-overflow', row.scrollWidth > row.clientWidth + 1);
	}
	fitRow();
	window.addEventListener('resize', fitRow);
	document.addEventListener('DOMContentLoaded', fitRow);
	document.addEventListener('cbee-pf:updated', fitRow);

	document.addEventListener('keydown', function (e) {
		if (e.key === 'Escape') closeAll();
	});

	document.addEventListener('click', function (e) {
		if (state.group && !e.target.closest('.pfp, [data-group]')) closeAll();
		var g = e.target.closest('.pfb-pl[data-group]');
		if (g) {
			e.preventDefault();
			ensurePop();
			var key = g.getAttribute('data-group');
			if (state.group === key) return closeAll();
			closeAll();
			state.group = key;
			fillPop();
			return;
		}
		if (e.target.closest('[data-full]')) {
			e.preventDefault();
			return openFull();
		}
		var opt = e.target.closest('.pfb-opt[data-term]');
		if (opt && (opt.closest('.pfp') || opt.closest('.pfb-full'))) {
			e.preventDefault();
			return pick(opt);
		}
		var sw = e.target.closest('.pfb-tg[data-param]');
		if (sw) {
			e.preventDefault();
			var on = sw.getAttribute('aria-checked') !== 'true';
			sw.setAttribute('aria-checked', on);
			pending[sw.getAttribute('data-param')] = on ? '1' : null;
			if (sw.getAttribute('data-param') === 'sofort') pending.sofort_verfuegbar = null;
			labelButtons();
			return;
		}
		if (e.target.closest('.pfp a') && !e.target.closest('[data-close]')) {
			pending = {};
			pendingSel = {};
			state.group = null;
			closeAll();
		}
		if (e.target.closest('[data-close]')) {
			e.preventDefault();
			return closeAll();
		}
		if (e.target === scrim) return closeAll();
		var a = e.target.closest('.cbee-pfb a, .pfp a, ' + GRID + ' .ct-pagination a');
		if (!a || a.getAttribute('href').charAt(0) === '#' || e.button || e.ctrlKey || e.metaKey || e.shiftKey || e.altKey || !window.fetch || !window.AbortController) return;
		e.preventDefault();
		a.classList.add('is-busy');
		load(a.href, true);
	});

	window.addEventListener('popstate', function () {
		load(location.href, false);
	});

	function load(url, push) {
		var grid = document.querySelector(GRID);
		var bars = document.querySelectorAll('.cbee-pfb');
		if (ctrl) ctrl.abort();
		ctrl = new AbortController();
		grid.classList.add('cbee-pf-loading');
		document.documentElement.classList.add('cbee-pf-busy');

		fetch(url, { credentials: 'same-origin', signal: ctrl.signal })
			.then(function (r) {
				if (!r.ok) throw new Error(r.status);
				return r.text();
			})
			.then(function (html) {
				var doc = new DOMParser().parseFromString(html, 'text/html');
				var newGrid = doc.querySelector(GRID);
				var newBars = doc.querySelectorAll('.cbee-pfb');
				if (!newGrid || newBars.length !== bars.length) throw new Error('markup');

				grid.replaceWith(newGrid);
				Array.prototype.forEach.call(bars, function (p, i) {
					if (!p.isConnected) return; // inside the grid just replaced: the new copy came with it
					p.replaceWith(newBars[i]);
				});
				restore();
				document.title = doc.title;
				if (push) history.pushState(null, '', url);
				if (newGrid.getBoundingClientRect().top < 0) newGrid.scrollIntoView();
				document.documentElement.classList.remove('cbee-pf-busy');
				document.dispatchEvent(new CustomEvent('cbee-pf:updated'));
			})
			.catch(function (err) {
				if (err && err.name === 'AbortError') return;
				location.href = url;
			});
	}
})();
