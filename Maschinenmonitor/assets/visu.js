/*
 * Maschinenmonitor – Kachel-Skript
 * - Bereiche mit data-zoom per Tippen/Klicken vergrößern
 * - Fadenkreuz mit Messwerten im vergrößerten Verlauf
 * - Aktualisierung über die Symcon-Kachel (handleMessage)
 *   ohne dass eine geöffnete Vergrößerung zugeht
 */
(function () {
    'use strict';

    // Nur einmal initialisieren (falls das Skript doppelt geladen wird)
    if (window.__mmrInit) { return; }
    window.__mmrInit = true;

    // Offene Vergrößerung nur im Speicher merken (nicht über Neuladen hinweg)
    var zoomKey = null;

    function root() { return document.getElementById('mmr-root'); }
    function fhd() { var r = root(); return r ? r.querySelector('.fhd') : null; }
    function layer() { var f = fhd(); return f ? f.querySelector('.fhd-zoomlayer') : null; }

    function fmt(v, d) {
        if (v === null || v === undefined || isNaN(v)) { return '–'; }
        var s = Number(v).toFixed(d).split('.');
        s[0] = s[0].replace(/\B(?=(\d{3})+(?!\d))/g, '.');
        return s.join(',');
    }

    function esc(s) {
        return String(s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    // ---------- Fadenkreuz ----------

    function initChart(card) {
        var ch = card.querySelector('.fhd-chart[data-pts]');
        if (!ch) { return; }

        var data;
        try { data = JSON.parse(ch.getAttribute('data-pts')); } catch (e) { return; }
        if (!data || !data.p || data.p.length < 2) { return; }

        var xh = ch.querySelector('.fhd-xh');
        var tip = ch.querySelector('.fhd-tip');
        if (!xh || !tip) { return; }

        function nearest(t) {
            var lo = 0, hi = data.p.length - 1;
            while (hi - lo > 1) {
                var mid = (lo + hi) >> 1;
                if (data.p[mid][0] < t) { lo = mid; } else { hi = mid; }
            }
            return (t - data.p[lo][0]) <= (data.p[hi][0] - t) ? lo : hi;
        }

        function show(clientX) {
            var r = ch.getBoundingClientRect();
            if (r.width <= 0) { return; }

            var f = Math.min(1, Math.max(0, (clientX - r.left) / r.width));
            var p = data.p[nearest(f * data.span)];
            var x = 100 * p[0] / data.span;

            xh.style.left = x + '%';
            xh.style.display = 'block';

            var h = '<b>' + esc(p[5]) + ' Uhr</b>'
                + '<br><i style="background:#2563eb"></i>Leistung ' + fmt(p[1], 1) + ' kW'
                + (data.cool ? '<br><i style="background:#0d9488"></i>Kälte ' + fmt(p[2], 1) + ' kW' : '')
                + '<br><i style="background:#d97706"></i>Auslastung ' + fmt(p[3], 1) + ' %'
                + (p[4] !== null && data.rz && data.rz[p[4]] !== undefined
                    ? '<br><i style="background:#7c3aed"></i>Rezept ' + esc(data.rz[p[4]]) : '');

            tip.innerHTML = h;
            tip.style.display = 'block';

            if (x > 55) {
                tip.style.left = '';
                tip.style.right = 'calc(' + (100 - x) + '% + 10px)';
            } else {
                tip.style.right = '';
                tip.style.left = 'calc(' + x + '% + 10px)';
            }
        }

        function hide() {
            xh.style.display = 'none';
            tip.style.display = 'none';
        }

        ch.addEventListener('mousemove', function (e) { show(e.clientX); });
        ch.addEventListener('mouseleave', hide);
        ch.addEventListener('click', function (e) { show(e.clientX); });
        ch.addEventListener('touchstart', function (e) {
            if (e.touches && e.touches[0]) { show(e.touches[0].clientX); }
        }, { passive: true });
        ch.addEventListener('touchmove', function (e) {
            if (e.touches && e.touches[0]) { show(e.touches[0].clientX); }
        }, { passive: true });
    }

    // ---------- Vergrößern ----------

    function closeZoom() {
        var l = layer();
        if (l) { l.parentNode.removeChild(l); }
        zoomKey = null;
    }

    function openZoom(key, keepScroll) {
        var f = fhd();
        if (!f) { return; }

        var src = f.querySelector('[data-zoom="' + key + '"]');
        if (!src) { zoomKey = null; return; }

        var old = layer();
        if (old) { old.parentNode.removeChild(old); }

        var l = document.createElement('div');
        l.className = 'fhd-zoomlayer';

        // Leiste mit gut treffbarer Schließen-Taste (feste Mindestgröße)
        var bar = document.createElement('div');
        bar.className = 'fhd-zoombar';
        var hint = document.createElement('span');
        hint.className = 'fhd-zoomhint';
        hint.textContent = key === 'chart'
            ? 'Im Diagramm tippen zeigt Messwerte · Doppeltippen oder außerhalb tippen schließt'
            : 'Zum Schließen irgendwo tippen';
        var b = document.createElement('button');
        b.type = 'button';
        b.className = 'fhd-close';
        b.setAttribute('aria-label', 'Schließen');
        b.textContent = '✕  Schließen';
        b.addEventListener('click', function (e) {
            e.preventDefault();
            e.stopPropagation();
            closeZoom();
        });
        bar.appendChild(hint);
        bar.appendChild(b);

        var card = src.cloneNode(true);
        card.classList.add('fhd-zoomed');
        card.removeAttribute('title');
        card.removeAttribute('data-zoom');

        // Bedienleiste UNTEN: der obere Rand der Kachel wird von der
        // Symcon-Titelleiste überdeckt und nimmt dort keine Klicks an.
        l.appendChild(card);
        l.appendChild(bar);

        f.appendChild(l);
        zoomKey = key;
        initChart(card);

        if (keepScroll) {
            card.scrollTop = keepScroll.top;
            l.scrollTop = keepScroll.layer;
            var w = card.querySelectorAll('.fhd-tablewrap');
            for (var i = 0; i < w.length && i < keepScroll.wraps.length; i++) {
                w[i].scrollLeft = keepScroll.wraps[i];
            }
        }
    }

    document.addEventListener('click', function (e) {
        var t = e.target;
        if (!t || !t.closest) { return; }

        var l = t.closest('.fhd-zoomlayer');

        if (l) {
            if (t.closest('.fhd-close')) { closeZoom(); return; }
            if (t.closest('.fhd-chart')) { return; }   // Fadenkreuz
            if (t.closest('a, button, input, select')) { return; }
            closeZoom();
            return;
        }

        var z = t.closest('[data-zoom]');
        if (z && root() && root().contains(z)) {
            openZoom(z.getAttribute('data-zoom'));
        }
    });

    document.addEventListener('dblclick', function (e) {
        if (e.target && e.target.closest && e.target.closest('.fhd-zoomlayer')) { closeZoom(); }
    });

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && layer()) { closeZoom(); }
    });

    // ---------- Aktualisierung ----------

    function render(html) {
        var r = root();
        if (!r) { return; }

        var keep = null;
        var l = layer();
        if (l && zoomKey) {
            var c = l.querySelector('.fhd-zoomed');
            var wraps = [];
            if (c) {
                var w = c.querySelectorAll('.fhd-tablewrap');
                for (var i = 0; i < w.length; i++) { wraps.push(w[i].scrollLeft); }
            }
            keep = { top: c ? c.scrollTop : 0, layer: l.scrollTop, wraps: wraps };
        }

        r.innerHTML = html;

        if (zoomKey) { openZoom(zoomKey, keep); }
    }

    window.mmrRender = render;

})();

/* Symcon Kachel-Visualisierung (HTML-SDK) */
function handleMessage(data) {
    var d = data;
    if (typeof d === 'string') {
        try { d = JSON.parse(d); } catch (e) { return; }
    }
    if (d && typeof d.html === 'string' && window.mmrRender) {
        window.mmrRender(d.html);
    }
}
