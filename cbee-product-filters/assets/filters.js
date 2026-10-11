(function () {
	var GRID = '#main .ct-container > section';
	var ctrl;

	document.addEventListener('click', function (e) {
		var btn = e.target.closest('.cbee-pf-more');
		if (btn) {
			var list = btn.previousElementSibling;
			var open = list.classList.toggle('is-collapsed') === false;
			btn.setAttribute('aria-expanded', open);
			btn.textContent = open ? 'Weniger anzeigen' : 'Mehr anzeigen';
			return;
		}
		var a = e.target.closest('.cbee-pf a, ' + GRID + ' .ct-pagination a');
		if (!a || e.button || e.ctrlKey || e.metaKey || e.shiftKey || e.altKey || !window.fetch || !window.AbortController) return;
		e.preventDefault();
		a.classList.add('is-busy');
		var links = Array.prototype.slice.call(document.querySelectorAll('.cbee-pf a'));
		load(a.href, true, links.indexOf(a));
	});

	window.addEventListener('popstate', function () {
		load(location.href, false, -1);
	});

	function load(url, push, focusIndex) {
		var grid = document.querySelector(GRID);
		var panels = document.querySelectorAll('.cbee-pf');
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
				var newPanels = doc.querySelectorAll('.cbee-pf');
				if (!newGrid || newPanels.length !== panels.length) throw new Error('markup');

				var expanded = Array.prototype.map.call(panels, function (p) {
					var l = p.querySelector('.cbee-pf-list');
					return l && !l.classList.contains('is-collapsed');
				});
				grid.replaceWith(newGrid);
				Array.prototype.forEach.call(panels, function (p, i) {
					p.replaceWith(newPanels[i]);
					var l = newPanels[i].querySelector('.cbee-pf-list.is-collapsed');
					var b = newPanels[i].querySelector('.cbee-pf-more');
					if (expanded[i] && l && b) {
						l.classList.remove('is-collapsed');
						b.setAttribute('aria-expanded', 'true');
						b.textContent = 'Weniger anzeigen';
					}
				});
				document.title = doc.title;
				if (push) history.pushState(null, '', url);
				if (newGrid.getBoundingClientRect().top < 0) newGrid.scrollIntoView();
				var f = focusIndex >= 0 && document.querySelectorAll('.cbee-pf a')[focusIndex];
				if (f) f.focus({ preventScroll: true });
				document.documentElement.classList.remove('cbee-pf-busy');
				document.dispatchEvent(new CustomEvent('cbee-pf:updated'));
			})
			.catch(function (err) {
				if (err && err.name === 'AbortError') return;
				location.href = url;
			});
	}
})();
