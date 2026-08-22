(function () {
  'use strict';

  function post(action, id) {
    var body = new URLSearchParams();
    body.set('action', action);
    body.set('nonce', VCS.nonce);
    body.set('id', id);
    return fetch(VCS.ajax, { method: 'POST', credentials: 'same-origin', body: body })
      .then(function (r) { return r.json(); });
  }

  function statusEl(id) {
    var btn = document.querySelector('.vcs-sync[data-id="' + id + '"]');
    return btn ? btn.parentNode.querySelector('.vcs-status') : null;
  }
  function diffCell(id) {
    var row = document.querySelector('.vcs-diff-row[data-id="' + id + '"]');
    return { row: row, cell: row ? row.querySelector('.vcs-diff-cell') : null };
  }
  function setStatus(id, text, cls) {
    var s = statusEl(id);
    if (s) { s.textContent = text || ''; s.className = 'vcs-status' + (cls ? ' ' + cls : ''); }
  }

  // Bấm "Đồng bộ" → preview diff
  document.addEventListener('click', function (e) {
    var syncBtn = e.target.closest('.vcs-sync');
    if (syncBtn) {
      var id = syncBtn.dataset.id;
      setStatus(id, 'Đang tải & so sánh…', 'busy');
      syncBtn.disabled = true;
      post('vcs_preview', id).then(function (res) {
        syncBtn.disabled = false;
        var d = diffCell(id);
        if (!res.success) { setStatus(id, res.data && res.data.msg ? res.data.msg : 'Lỗi', 'error'); return; }
        setStatus(id, res.data.changes > 0 ? (res.data.changes + ' thay đổi') : 'Không có thay đổi', res.data.changes > 0 ? 'ok' : '');
        if (d.cell) { d.cell.innerHTML = res.data.html; d.row.style.display = ''; }
      }).catch(function () { syncBtn.disabled = false; setStatus(id, 'Lỗi kết nối', 'error'); });
      return;
    }

    // Huỷ
    var cancel = e.target.closest('.vcs-cancel');
    if (cancel) {
      var drow = cancel.closest('.vcs-diff-row');
      if (drow) { drow.style.display = 'none'; drow.querySelector('.vcs-diff-cell').innerHTML = ''; }
      return;
    }

    // Chấp nhận → apply
    var accept = e.target.closest('.vcs-accept');
    if (accept) {
      var drow2 = accept.closest('.vcs-diff-row');
      var id2 = drow2.dataset.id;
      accept.disabled = true; accept.textContent = 'Đang ghi…';
      post('vcs_apply', id2).then(function (res) {
        if (!res.success) { accept.disabled = false; accept.textContent = 'Chấp nhận đồng bộ'; setStatus(id2, res.data && res.data.msg ? res.data.msg : 'Lỗi ghi', 'error'); return; }
        setStatus(id2, '✔ ' + res.data.msg, 'ok');
        drow2.style.display = 'none';
        drow2.querySelector('.vcs-diff-cell').innerHTML = '';
      }).catch(function () { accept.disabled = false; accept.textContent = 'Chấp nhận đồng bộ'; setStatus(id2, 'Lỗi kết nối', 'error'); });
    }
  });
})();
