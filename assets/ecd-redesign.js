/**
 * Redesigned employee screens: full-bleed sizing, the ODP bulletin ticker and side drawer, and the certificate
 * viewer. The drawer and viewer follow the admin plugin's implementations (gh-bulletins.js and the slice 9 viewer
 * in dashboard.js) so both dashboards behave the same. The certificate packet buttons are handled by dashboard.js.
 */
(function () {
  'use strict';

  var FOCUSABLE = 'a[href], button:not([disabled]), input:not([disabled]), select, textarea, iframe, [tabindex]:not([tabindex="-1"])';

  /* ---- Overlay stack: Escape and the page scroll lock belong to the top-most overlay. ---------------------- */
  var stack = [];
  var overlays = {
    open: function (layer) {
      if (stack.indexOf(layer) < 0) stack.push(layer);
      document.documentElement.classList.add('ghca-ecd--overlay-open');
    },
    close: function (layer) {
      stack = stack.filter(function (l) { return l !== layer; });
      if (!stack.length) document.documentElement.classList.remove('ghca-ecd--overlay-open');
    },
    isTop: function (layer) { return stack.length > 0 && stack[stack.length - 1] === layer; }
  };

  function trapFocus(container, initial) {
    function items() {
      return Array.prototype.slice.call(container.querySelectorAll(FOCUSABLE)).filter(function (el) {
        return !el.closest('[hidden]') && (el.offsetWidth > 0 || el.offsetHeight > 0);
      });
    }
    function onKey(e) {
      if (e.key !== 'Tab') return;
      var f = items();
      if (!f.length) { e.preventDefault(); return; }
      var first = f[0];
      var last = f[f.length - 1];
      if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
      else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
      else if (!container.contains(document.activeElement)) { e.preventDefault(); first.focus(); }
    }
    container.addEventListener('keydown', onKey);
    var target = initial || items()[0] || container;
    try { target.focus({ preventScroll: true }); } catch (err) { target.focus(); }
    return function () { container.removeEventListener('keydown', onKey); };
  }

  /*
   * Overlays live at the end of <body>, in a copy of the themed wrapper (same theme and agency colours). Page
   * builders (Elementor, BuddyBoss) often give sections a `transform`, which turns `position: fixed` into
   * "fixed to that section": the drawer then opened clipped inside the page or behind a sticky header.
   */
  function portal(layer) {
    var app = layer.closest('.gh-app');
    if (!app || layer.closest('.ghca-ecd-ui--portal')) return layer;
    var wrap = document.createElement('div');
    wrap.className = 'ghca-ecd-ui ghca-ecd-ui--portal';
    var shell = document.createElement('div');
    shell.className = app.className;
    shell.setAttribute('data-theme', app.getAttribute('data-theme') || 'gridhouse');
    if (app.getAttribute('style')) shell.setAttribute('style', app.getAttribute('style'));
    wrap.appendChild(shell);
    shell.appendChild(layer);
    document.body.appendChild(wrap);
    return layer;
  }

  /* ---- Full bleed --------------------------------------------------------------------------------------- */
  // Full-bleed width is 100vw minus the page scrollbar, so the wrapper never causes a sideways scroll.
  function setScrollbarWidth() {
    var sbw = Math.max(0, window.innerWidth - document.documentElement.clientWidth);
    document.querySelectorAll('.ghca-ecd-ui--bleed').forEach(function (el) {
      el.style.setProperty('--ghca-ecd-ui-sbw', sbw + 'px');
    });
  }

  /* ---- Ticker --------------------------------------------------------------------------------------------- */
  function reducedMotion() {
    return window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  }

  function initTickers() {
    // The admin plugin's ticker markup (data-gh-ticker) has one set of items; the loop needs an inert copy.
    document.querySelectorAll('.ghca-ecd-ui [data-gh-ticker]').forEach(function (ticker) {
      if (reducedMotion()) { ticker.setAttribute('data-motion', 'reduce'); return; }
      var track = ticker.querySelector('.gh-ticker-track');
      if (!track || track.children.length < 2) return;
      Array.prototype.slice.call(track.children).forEach(function (item) {
        // The copy is hidden from assistive tech and the tab order but stays clickable: with `inert` a click on
        // the copy (half of what scrolls past) did nothing.
        var copy = item.cloneNode(true);
        copy.setAttribute('aria-hidden', 'true');
        copy.querySelectorAll('a, button').forEach(function (n) { n.setAttribute('tabindex', '-1'); });
        track.appendChild(copy);
      });
      ticker.classList.add('is-animated');
    });
    // This plugin's own fallback ticker already renders the copy.
    document.querySelectorAll('.ghca-ecd-ui [data-ghca-ecd-ticker]').forEach(function (t) { t.classList.add('is-animated'); });

    // "Saved copy" shows as its clock icon on phones: keep the words as tooltip and accessible name.
    document.querySelectorAll('.ghca-ecd-ui .gh-ticker-state').forEach(function (s) {
      var words = (s.textContent || '').replace(/\s+/g, ' ').trim();
      if (words) { s.setAttribute('title', words); s.setAttribute('aria-label', words); }
    });

    // The loop moves the track by half its width (one full set of headlines). Keep the speed constant
    // (about 45px per second) whatever the width, and recalculate on resize and orientation change.
    function setSpeeds() {
      document.querySelectorAll('.ghca-ecd-ui .gh-ticker.is-animated .gh-ticker-track').forEach(function (track) {
        var distance = track.scrollWidth / 2;
        if (distance > 0) track.style.animationDuration = Math.max(12, Math.round(distance / 45)) + 's';
      });
    }
    setSpeeds();
    var speedTimer = 0;
    function onResize() { window.clearTimeout(speedTimer); speedTimer = window.setTimeout(setSpeeds, 150); }
    window.addEventListener('resize', onResize);
    window.addEventListener('orientationchange', onResize);

    document.addEventListener('click', function (e) {
      var btn = e.target.closest('.ghca-ecd-ui [data-gh-ticker-pause], .ghca-ecd-ui [data-ghca-ecd-ticker-pause]');
      if (!btn) return;
      var ticker = btn.closest('.gh-ticker');
      var paused = !ticker.classList.contains('is-paused');
      ticker.classList.toggle('is-paused', paused);
      btn.setAttribute('aria-pressed', paused ? 'true' : 'false');
      btn.setAttribute('aria-label', btn.getAttribute(paused ? 'data-label-play' : 'data-label-pause') || '');
      var icon = btn.querySelector('.gh-i');
      if (icon) {
        icon.classList.toggle('i-pause', !paused);
        icon.classList.toggle('i-play', paused);
      }
    });
  }

  /* ---- ODP bulletin side drawer (ODP-Drawer-Employee) ------------------------------------------------------ */
  function initBulletinDrawer() {
    var drawer = document.querySelector('.ghca-ecd-ui #gh-odp-drawer');
    var payloadNode = document.querySelector('.ghca-ecd-ui #gh-odp-data');
    var hasTicker = !!document.querySelector('.ghca-ecd-ui .gh-ticker');
    if (!drawer || !payloadNode) {
      if (hasTicker && window.console) {
        console.warn('[ghca-ecd] Bulletin drawer not set up: drawer markup ' + (drawer ? 'found' : 'missing') + ', bulletin data ' + (payloadNode ? 'found' : 'missing') + '. The admin plugin (PR #19 build) supplies both; headlines open their full page instead.');
      }
      return;
    }
    var payload = {};
    try { payload = JSON.parse(payloadNode.textContent || '{}'); } catch (err) {
      payload = {};
      if (window.console) console.warn('[ghca-ecd] Bulletin data could not be read (a caching or minify plugin may have changed it).', err);
    }
    portal(drawer);
    var panel = drawer.querySelector('.gh-drawer');
    var currentId = '';
    var lastFocus = null;
    var release = null;

    function bulletins() { return payload.bulletins || {}; }
    function order() { return Array.isArray(payload.order) ? payload.order : Object.keys(bulletins()); }

    function fill(id) {
      var item = bulletins()[id];
      if (!item) return false;
      currentId = id;
      var q = function (s) { return drawer.querySelector(s); };
      q('[data-gh-drawer-title]').textContent = item.headline || '';
      var meta = q('[data-gh-drawer-meta]');
      meta.textContent = item.date_long || item.date_label || '';
      meta.setAttribute('datetime', item.published_at || '');
      var body = q('[data-gh-drawer-body]');
      body.textContent = '';
      (item.paragraphs || []).forEach(function (text) {
        var p = document.createElement('p');
        p.textContent = text;
        body.appendChild(p);
      });
      var full = q('[data-gh-drawer-full]');
      if (full) full.setAttribute('href', item.url || full.getAttribute('href'));
      var ids = order();
      var index = ids.indexOf(id);
      var pos = q('[data-gh-drawer-pos]');
      if (pos) pos.textContent = ids.length > 1 && index > -1 ? (index + 1) + ' of ' + ids.length : '';
      drawer.querySelectorAll('[data-gh-drawer-step]').forEach(function (b) {
        var target = index + parseInt(b.getAttribute('data-gh-drawer-step'), 10);
        b.disabled = index < 0 || target < 0 || target >= ids.length;
      });
      var wrap = q('[data-gh-drawer-source-wrap]');
      var source = q('[data-gh-drawer-source]');
      var host = q('[data-gh-drawer-source-host]');
      if (wrap) wrap.hidden = true;
      if (wrap && source && item.source_url && /^https?:\/\//i.test(item.source_url)) {
        source.setAttribute('href', item.source_url);
        if (host) host.textContent = item.source_host ? 'Opens ' + item.source_host + ' in a new tab' : '';
        wrap.hidden = false;
      }
      var bd = q('.gh-bul-bd');
      if (bd) bd.scrollTop = 0;
      return true;
    }

    function open(id, opener) {
      if (!fill(id)) return false;
      if (!drawer.hidden) return true;
      lastFocus = opener || document.activeElement;
      drawer.hidden = false;
      overlays.open(drawer);
      release = trapFocus(panel, drawer.querySelector('[data-gh-drawer-x]'));
      return true;
    }

    function close() {
      if (drawer.hidden) return;
      drawer.hidden = true;
      overlays.close(drawer);
      if (release) { release(); release = null; }
      if (lastFocus && lastFocus.isConnected) lastFocus.focus();
      lastFocus = null;
      currentId = '';
    }

    document.addEventListener('click', function (e) {
      var opener = e.target.closest('.ghca-ecd-ui [data-gh-open-bulletin]');
      if (opener) {
        // Without the payload entry the link still goes to the full bulletin page.
        var bid = opener.getAttribute('data-gh-open-bulletin');
        if (open(bid, opener)) { e.preventDefault(); }
        else if (window.console) { console.warn('[ghca-ecd] No drawer data for bulletin "' + bid + '"; opening its page.'); }
        return;
      }
      if (drawer.hidden || !drawer.contains(e.target)) return;
      var step = e.target.closest('[data-gh-drawer-step]');
      if (step) {
        var ids = order();
        var next = ids[ids.indexOf(currentId) + parseInt(step.getAttribute('data-gh-drawer-step'), 10)];
        if (next) {
          fill(next);
          if (step.disabled) { var x = drawer.querySelector('[data-gh-drawer-x]'); if (x) x.focus(); }
        }
        return;
      }
      if (e.target.closest('[data-gh-drawer-close]')) { e.preventDefault(); close(); }
    });
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && !drawer.hidden && overlays.isTop(drawer)) { e.preventDefault(); close(); }
    });
  }

  /* ---- Certificate viewer (Modal-CertViewer) -------------------------------------------------------------- */
  // The PDF is fetched from the employee's own certificate link into memory and shown from a same-page blob URL
  // in the browser's PDF viewer. If the browser can't show PDFs inline the viewer says so and offers Download;
  // it never downloads on its own. Download saves the bytes already fetched.
  function isPdf(contentType, firstBytes) {
    if (!/^application\/pdf\s*(;|$)/i.test(String(contentType || '').trim())) return false;
    var magic = '%PDF-';
    if (!firstBytes || firstBytes.length < magic.length) return false;
    for (var i = 0; i < magic.length; i++) { if (firstBytes[i] !== magic.charCodeAt(i)) return false; }
    return true;
  }

  function initCertViewer() {
    var layer = document.querySelector('.ghca-ecd-ui [data-ghca-cv]');
    if (!layer) return;
    portal(layer);
    var dialog = layer.querySelector('[data-ghca-cv-dialog]');
    var frame = layer.querySelector('[data-ghca-cv-frame]');
    var bar = layer.querySelector('[data-ghca-cv-bar]');
    var titleEl = layer.querySelector('[data-ghca-cv-heading]');
    var subEl = layer.querySelector('[data-ghca-cv-sub]');
    var dl = layer.querySelector('[data-ghca-cv-dl]');
    var chip = layer.querySelector('[data-ghca-cv-chip]');
    var msg = layer.querySelector('[data-ghca-cv-msg]');
    var pagesEl = layer.querySelector('[data-ghca-cv-pages]');
    var zoomEl = layer.querySelector('[data-ghca-cv-zoomval]');
    var st = { trigger: null, url: '', blob: null, objectUrl: '', file: 'certificate.pdf', page: 1, pages: 1, zoom: 100, fit: true, aspect: 0, widthPt: 0, request: 0, release: null };

    function show(state) {
      layer.querySelectorAll('[data-ghca-cv-pane]').forEach(function (el) { el.hidden = el.getAttribute('data-ghca-cv-pane') !== state; });
      layer.setAttribute('data-ghca-cv-current', state);
      var ready = state === 'ready';
      bar.querySelectorAll('button').forEach(function (b) { b.disabled = !ready; });
      layer.querySelectorAll('[data-ghca-cv-newtab]').forEach(function (b) { b.disabled = !st.blob; });
      layer.querySelectorAll('.gh-cv-ft [data-ghca-cv-download]').forEach(function (b) { b.disabled = !st.blob; });
      if (ready) {
        bar.querySelector('[data-ghca-cv-page="prev"]').disabled = st.page <= 1;
        bar.querySelector('[data-ghca-cv-page="next"]').disabled = st.page >= st.pages;
      }
    }
    function details(t) {
      var rows = [
        ['Course', t.getAttribute('data-cv-title')],
        ['Completed', t.getAttribute('data-cv-completed')],
        ['Expires', t.getAttribute('data-cv-expires')],
        ['Source', 'LearnDash'],
        ['File', '']
      ];
      dl.textContent = '';
      rows.forEach(function (r) {
        if (r[0] !== 'File' && !r[1]) return;
        var d = document.createElement('div');
        var dt = document.createElement('dt'); dt.textContent = r[0];
        var dd = document.createElement('dd'); dd.textContent = r[1] || '—';
        if (r[0] === 'File') dd.setAttribute('data-ghca-cv-file', '');
        d.appendChild(dt); d.appendChild(dd); dl.appendChild(d);
      });
    }
    // Fit to width: the frame is as tall as the page at the frame's width, so a landscape certificate shows whole.
    function fitHeight() {
      if (!st.fit || !st.aspect || frame.hidden) { frame.style.height = ''; return; }
      frame.style.height = '';
      var max = frame.clientHeight;
      var w = frame.clientWidth;
      if (!max || !w) return;
      frame.style.height = Math.min(max, Math.round(w * st.aspect) + 6) + 'px';
    }
    function frameSrc() {
      if (!st.objectUrl) return;
      // Browser PDF viewers read page and zoom from the fragment only on load, so each change loads a fresh frame.
      // Their own side panel and toolbar are off: this viewer has its own page, zoom, fit and print controls.
      var fresh = frame.cloneNode(false);
      fresh.hidden = frame.hidden;
      fresh.src = st.objectUrl + '#page=' + st.page + '&navpanes=0&toolbar=0' + (st.fit ? '&view=FitH&zoom=page-width' : '&zoom=' + st.zoom);
      frame.parentNode.replaceChild(fresh, frame);
      frame = fresh;
      fitHeight();
      zoomEl.textContent = st.fit ? 'Fit' : st.zoom + '%';
      pagesEl.textContent = st.page + ' / ' + st.pages;
    }
    function pdfText(buf) {
      try { return new TextDecoder('latin1').decode(new Uint8Array(buf)); } catch (e) { return ''; }
    }
    function pageBox(text) {
      var m = text.match(/\/MediaBox\s*\[\s*(-?[\d.]+)\s+(-?[\d.]+)\s+(-?[\d.]+)\s+(-?[\d.]+)\s*\]/);
      if (!m) return null;
      var w = Math.abs(parseFloat(m[3]) - parseFloat(m[1]));
      var h = Math.abs(parseFloat(m[4]) - parseFloat(m[2]));
      var r = text.match(/\/Rotate\s+(-?\d+)/);
      if (r && Math.abs(parseInt(r[1], 10)) % 180 === 90) { var t = w; w = h; h = t; }
      return w > 0 && h > 0 ? { w: w, h: h } : null;
    }
    function countPages(text) {
      var m = text.match(/\/Type\s*\/Page(?![a-z])/g);
      return m && m.length ? m.length : 1;
    }
    function cleanup() {
      st.request++;
      frame.removeAttribute('src');
      frame.hidden = true;
      frame.style.height = '';
      if (st.objectUrl) URL.revokeObjectURL(st.objectUrl);
      st.objectUrl = ''; st.blob = null;
    }
    function load() {
      var mine = ++st.request;
      st.blob = null;
      msg.textContent = '';
      show('loading');
      var url;
      try { url = new URL(st.url, window.location.href); } catch (e) { url = null; }
      if (!url || url.origin !== window.location.origin) { show('error'); return; }
      fetch(url.href, { credentials: 'same-origin' }).then(function (res) {
        var type = res.headers.get('Content-Type') || '';
        return res.arrayBuffer().then(function (buf) { return { res: res, type: type, buf: buf }; });
      }).then(function (r) {
        if (mine !== st.request) return;
        if (r.res.status === 401 || r.res.status === 403) { show('denied'); return; }
        if (r.res.status === 404) { show('unavailable'); return; }
        if (!r.res.ok || !isPdf(r.type, new Uint8Array(r.buf.slice(0, 5)))) { show('error'); return; }
        st.blob = new Blob([r.buf], { type: 'application/pdf' });
        st.objectUrl = URL.createObjectURL(st.blob);
        var text = pdfText(r.buf);
        st.pages = countPages(text);
        st.page = 1; st.zoom = 100; st.fit = true;
        var box = pageBox(text);
        st.aspect = box ? box.h / box.w : 0;
        st.widthPt = box ? box.w : 0;
        var fileDd = layer.querySelector('[data-ghca-cv-file]');
        if (fileDd) fileDd.textContent = 'PDF · ' + st.pages + (st.pages === 1 ? ' page · ' : ' pages · ') + Math.max(1, Math.round(r.buf.byteLength / 1024)) + ' KB';
        if (navigator.pdfViewerEnabled === false) { show('blocked'); return; }
        frame.hidden = false;
        show('ready');
        frameSrc();
      }).catch(function () {
        if (mine === st.request) show('error');
      });
    }
    function open(t) {
      cleanup();
      st.trigger = t;
      st.url = t.getAttribute('data-cv-url') || '';
      st.file = t.getAttribute('data-cv-file') || 'certificate.pdf';
      titleEl.textContent = 'Certificate · ' + (t.getAttribute('data-cv-title') || '');
      subEl.textContent = st.file;
      chip.className = 'gh-st gh-st--' + (t.getAttribute('data-cv-chip') || 'completed');
      chip.textContent = t.getAttribute('data-cv-chip-label') || 'Earned';
      details(t);
      layer.hidden = false;
      layer.setAttribute('aria-hidden', 'false');
      overlays.open(layer);
      // Start on the close control shown at this width (top-left on phones, top-right otherwise).
      var closers = Array.prototype.filter.call(dialog.querySelectorAll('.gh-cv-x, .gh-cv-x-phone'), function (b) { return b.offsetParent !== null; });
      st.release = trapFocus(dialog, closers[0] || dialog.querySelector('[data-ghca-cv-close]'));
      load();
    }
    function close() {
      if (layer.hidden) return;
      cleanup();
      layer.hidden = true;
      layer.setAttribute('aria-hidden', 'true');
      overlays.close(layer);
      if (st.release) { st.release(); st.release = null; }
      if (st.trigger && st.trigger.isConnected) st.trigger.focus();
    }
    function save() {
      if (!st.blob) return;
      var u = URL.createObjectURL(st.blob);
      var a = document.createElement('a');
      a.href = u; a.download = st.file;
      document.body.appendChild(a); a.click(); a.remove();
      window.setTimeout(function () { URL.revokeObjectURL(u); }, 30000);
      msg.textContent = 'Saved ' + st.file + '.';
    }

    document.addEventListener('click', function (e) {
      var t = e.target.closest('.ghca-ecd-ui [data-ghca-ecd-cv-open]');
      if (t) { e.preventDefault(); open(t); return; }
      if (layer.hidden || !layer.contains(e.target)) return;
      if (e.target.closest('[data-ghca-cv-close]')) { e.preventDefault(); close(); return; }
      if (e.target.closest('[data-ghca-cv-download]')) { e.preventDefault(); save(); return; }
      if (e.target.closest('[data-ghca-cv-retry]')) { e.preventDefault(); load(); return; }
      if (e.target.closest('[data-ghca-cv-newtab]')) {
        e.preventDefault();
        if (st.objectUrl) window.open(st.objectUrl, '_blank', 'noopener');
        return;
      }
      if (e.target.closest('[data-ghca-cv-print]')) {
        e.preventDefault();
        try { frame.contentWindow.focus(); frame.contentWindow.print(); } catch (err) { msg.textContent = 'This browser can’t print from here. Use Open in new tab, then print.'; }
        return;
      }
      var z = e.target.closest('[data-ghca-cv-zoom]');
      if (z) {
        var k = z.getAttribute('data-ghca-cv-zoom');
        if (k === 'fit') { st.fit = true; }
        else {
          if (st.fit) {
            // Leaving "Fit": step from the size shown now (100% = 96 px per inch), in 25% steps.
            var now = st.widthPt ? frame.clientWidth / (st.widthPt * 96 / 72) * 100 : 100;
            st.zoom = k === 'in' ? Math.floor(now / 25) * 25 + 25 : Math.ceil(now / 25) * 25 - 25;
          } else {
            st.zoom += k === 'in' ? 25 : -25;
          }
          st.fit = false;
          st.zoom = Math.max(25, Math.min(300, st.zoom));
        }
        frameSrc(); return;
      }
      var p = e.target.closest('[data-ghca-cv-page]');
      if (p) {
        st.page = Math.max(1, Math.min(st.pages, st.page + (p.getAttribute('data-ghca-cv-page') === 'next' ? 1 : -1)));
        frameSrc(); show('ready');
      }
    });
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && !layer.hidden && overlays.isTop(layer)) { e.preventDefault(); close(); }
    });
    var stage = frame.parentNode;
    if (window.ResizeObserver) {
      var lastW = 0;
      new ResizeObserver(function () { var w = stage.clientWidth; if (w !== lastW) { lastW = w; if (!layer.hidden) fitHeight(); } }).observe(stage);
    } else {
      window.addEventListener('resize', function () { if (!layer.hidden) fitHeight(); });
    }
  }

  // Build marker: type ghcaEcdRedesign in the browser console to see which script build the page runs.
  window.ghcaEcdRedesign = { build: '1.6.0', drawer: false, viewer: false };

  function init() {
    window.ghcaEcdRedesign.drawer = !!document.querySelector('.ghca-ecd-ui #gh-odp-drawer');
    window.ghcaEcdRedesign.viewer = !!document.querySelector('.ghca-ecd-ui [data-ghca-cv]');
    setScrollbarWidth();
    window.addEventListener('resize', setScrollbarWidth);
    initTickers();
    initBulletinDrawer();
    initCertViewer();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
