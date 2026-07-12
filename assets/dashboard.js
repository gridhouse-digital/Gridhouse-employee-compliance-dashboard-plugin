/**
 * Employee dashboard behaviors. Currently: the self-service certificate
 * packet builder (init -> fetch x N -> merge -> download), adapted from the
 * admin plugin's initPdfPacket. Sequential fetches keep server load flat;
 * any failure is terminal (the server enforces the same ABORT policy).
 */
(function () {
  'use strict';

  function initCertPacket() {
    var modal = document.getElementById('ghca-ecd-pdf-modal');
    if (!modal || !window.ghcaEcd) return;

    var bar = modal.querySelector('[data-ghca-ecd-pdf-bar]');
    var track = modal.querySelector('[data-ghca-ecd-pdf-track]');
    var label = modal.querySelector('[data-ghca-ecd-pdf-label]');
    var running = false;
    var cancelled = false;

    function t(key, fallback) {
      return (window.ghcaEcd.i18n && window.ghcaEcd.i18n[key]) || fallback;
    }

    function sprintf2(str, a, b) {
      return str.replace('%1$s', a).replace('%2$s', b).replace('%s', a);
    }

    function setProgress(pct, text) {
      bar.style.width = pct + '%';
      track.setAttribute('aria-valuenow', String(pct));
      label.textContent = text;
    }

    function openModal() {
      cancelled = false;
      bar.classList.remove('is-error');
      modal.hidden = false;
      modal.setAttribute('aria-hidden', 'false');
    }

    function closeModal() {
      modal.hidden = true;
      modal.setAttribute('aria-hidden', 'true');
      running = false;
    }

    function failState(msg) {
      bar.classList.add('is-error');
      setProgress(100, msg || t('error', 'Packet generation failed. No packet was created. Please try again.'));
      running = false;
    }

    function post(params) {
      params.append('nonce', window.ghcaEcd.nonce);
      return fetch(window.ghcaEcd.ajaxUrl, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
        body: params.toString()
      }).then(function (res) { return res.json(); });
    }

    function run() {
      if (running) return;
      running = true;
      openModal();
      setProgress(3, t('preparing', 'Preparing your packet…'));

      var initParams = new URLSearchParams();
      initParams.append('action', 'ghca_ecd_pkt_init');

      post(initParams).then(function (json) {
        if (cancelled) return;
        if (!json || !json.success || !json.data || !json.data.job_id) {
          failState(json && json.data && json.data.message);
          return;
        }

        var jobId = json.data.job_id;
        var total = parseInt(json.data.total, 10) || 0;

        function mergeJob() {
          setProgress(92, t('merging', 'Merging documents…'));
          var mp = new URLSearchParams();
          mp.append('action', 'ghca_ecd_pkt_merge');
          mp.append('job_id', jobId);
          post(mp).then(function (mj) {
            if (cancelled) return;
            if (mj && mj.success && mj.data && mj.data.download_url) {
              setProgress(100, t('done', 'Done! Starting download…'));
              window.location.assign(mj.data.download_url);
              window.setTimeout(closeModal, 1500);
            } else {
              failState(mj && mj.data && mj.data.message);
            }
          }).catch(function () { failState(); });
        }

        function fetchNext(i) {
          if (cancelled) return;
          if (i >= total) { mergeJob(); return; }

          setProgress(5 + Math.round((i / total) * 85), sprintf2(t('fetching', 'Fetching certificate %1$s of %2$s…'), String(i + 1), String(total)));

          var fp = new URLSearchParams();
          fp.append('action', 'ghca_ecd_pkt_fetch');
          fp.append('job_id', jobId);
          fp.append('index', String(i));
          post(fp).then(function (fj) {
            if (cancelled) return;
            // ABORT policy: any fetch failure ends the job; the server has
            // already deleted the manifest and temp files at this point.
            if (!fj || !fj.success) { failState(fj && fj.data && fj.data.message); return; }
            fetchNext(i + 1);
          }).catch(function () { failState(); });
        }

        if (total === 0) { mergeJob(); } else { fetchNext(0); }
      }).catch(function () { failState(); });
    }

    document.addEventListener('click', function (e) {
      if (e.target.closest('[data-ghca-ecd-packet]')) {
        e.preventDefault();
        run();
        return;
      }
      if (e.target.closest('[data-ghca-ecd-pdf-cancel]')) {
        e.preventDefault();
        cancelled = true;
        closeModal();
      }
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initCertPacket);
  } else {
    initCertPacket();
  }
})();
