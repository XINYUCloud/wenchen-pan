/* 文谶云盘 上传脚本：队列、拖拽、粘贴、进度/速度、秒传、分片断点续传 */
(function () {
  'use strict';
  var cfg = window.WC_UPLOAD;
  var zone = document.getElementById('dropZone');
  if (!cfg || !zone) { return; }

  var body = document.getElementById('queueBody');
  var pickInput = document.getElementById('pickInput');
  var seq = 0;
  var items = [];
  var running = false;
  var timer = null;

  function el(id) { return document.getElementById(id); }
  function opt(id) { var c = el(id); return c && c.checked; }
  function fmt(n) {
    n = Number(n) || 0;
    var u = ['B', 'KB', 'MB', 'GB'], i = 0;
    while (n >= 1024 && i < 4) { n /= 1024; i++; }
    return (i === 0 ? n : n.toFixed(n < 10 ? 2 : 1)) + ' ' + u[i];
  }
  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }
  function folderId() {
    var f = el('targetFolder');
    return f ? parseInt(f.value, 10) || 0 : 0;
  }

  /* ---------------- 队列渲染 ---------------- */
  function renderRow(it) {
    var tr = document.createElement('tr');
    tr.id = 'q' + it.key;
    tr.innerHTML = '<td width="26%"><span class="qName">' + esc(it.name) + '</span></td>'
      + '<td width="70" align="right">' + fmt(it.size) + '</td>'
      + '<td width="120"><div class="qBar"><i></i></div></td>'
      + '<td width="60" align="right" class="qPct">0%</td>'
      + '<td width="150" class="qSt">排队中</td>'
      + '<td width="120" class="qOp"></td>';
    body.appendChild(tr);
    var empty = body.querySelector('.qEmpty');
    if (empty) { empty.parentNode.removeChild(empty); }
    it.row = tr;
    updateRow(it);
  }

  function updateRow(it) {
    if (!it.row) { return; }
    var bar = it.row.querySelector('.qBar i');
    var pct = it.row.querySelector('.qPct');
    var st = it.row.querySelector('.qSt');
    var op = it.row.querySelector('.qOp');
    var p = it.size > 0 ? Math.min(100, Math.round(it.loaded / it.size * 100)) : 0;
    if (it.status === 'done') { p = 100; }
    bar.style.width = p + '%';
    pct.textContent = p + '%';
    it.row.className = 'q' + (it.status === 'done' ? 'Done' : (it.status === 'error' ? 'Fail' : ''));

    var txt = '';
    if (it.status === 'wait') { txt = '排队中'; }
    else if (it.status === 'sha') { txt = '计算 SHA1 ' + Math.round((it.shaProgress || 0) * 100) + '%'; }
    else if (it.status === 'fast') { txt = '秒传探测中…'; }
    else if (it.status === 'single') { txt = '上传中 ' + fmt(it.speed) + '/s' + (it.eta ? '　剩余 ' + it.eta + 's' : ''); }
    else if (it.status === 'chunk') {
      txt = '分片 ' + (it.nextPart + 1) + '/' + it.total + '　' + fmt(it.speed) + '/s' + (it.eta ? '　剩余 ' + it.eta + 's' : '');
    }
    else if (it.status === 'merge') { txt = '服务器合并分片中…'; }
    else if (it.status === 'paused') { txt = '<span style="color:#b35c00">已暂停（可继续）</span>'; }
    else if (it.status === 'error') { txt = '<span class="red">' + esc(it.msg || '失败') + '</span>'; }
    else if (it.status === 'done') {
      txt = it.fast ? '<span class="green">秒传成功</span>' : '<span class="green">上传完成</span>';
    }
    st.innerHTML = txt;

    var ops = '';
    if (it.status === 'chunk' || it.status === 'single') { ops += '<a href="javascript:;" data-act="pause">暂停</a> '; }
    if (it.status === 'paused') { ops += '<a href="javascript:;" data-act="resume">继续</a> '; }
    if (it.status === 'error' || it.status === 'paused') { ops += '<a href="javascript:;" data-act="retry">重试</a> '; }
    if (it.status === 'done' && it.result) {
      ops += '<a href="' + esc(it.result.preview) + '" target="_blank">预览</a> <a href="' + esc(it.result.share) + '">分享</a> '
        + '<a href="' + esc(it.result.download) + '">下载</a>';
    } else if (it.status !== 'wait') {
      ops += '<a href="javascript:;" data-act="cancel">移除</a>';
    }
    op.innerHTML = ops;
  }

  body.addEventListener('click', function (ev) {
    var a = ev.target;
    if (!a.dataset || !a.dataset.act) { return; }
    var key = parseInt(a.parentNode.parentNode.id.slice(1), 10);
    var it = find(key);
    if (!it) { return; }
    var act = a.dataset.act;
    if (act === 'pause') { pauseItem(it); }
    else if (act === 'resume' || act === 'retry') { it.status = 'wait'; it.loaded = 0; it.msg = ''; updateRow(it); pump(); }
    else if (act === 'cancel') { cancelItem(it); }
  });

  function find(key) {
    for (var i = 0; i < items.length; i++) { if (items[i].key === key) { return items[i]; } }
    return null;
  }

  /* ---------------- 加入队列 ---------------- */
  function addFiles(list) {
    var arr = Array.prototype.slice.call(list || []);
    if (!arr.length) { return; }
    for (var i = 0; i < arr.length; i++) {
      var f = arr[i];
      if (!f || typeof f.size !== 'number') { continue; }
      if (f.size === 0) { continue; }
      if (cfg.lock) { alert('邮箱未验证，暂时不能上传文件。'); return; }
      var it = {
        key: ++seq, file: f, name: f.name || 'clipboard.png', size: f.size,
        loaded: 0, status: 'wait', speed: 0, eta: 0, sha1: '', uploadId: '', total: 0,
        nextPart: 0, have: [], xhr: null, paused: false, fast: 0, msg: '', result: null
      };
      items.push(it);
      renderRow(it);
    }
    pump();
  }

  function pauseItem(it) {
    it.paused = true;
    if (it.xhr) { try { it.xhr.abort(); } catch (e) {} }
    it.status = 'paused';
    updateRow(it);
  }

  function cancelItem(it) {
    if (it.xhr) { try { it.xhr.abort(); } catch (e) {} }
    if (it.uploadId) { post(cfg.api + '?do=abort', { upload_id: it.uploadId }, function () {}); }
    remove(it);
  }

  function remove(it) {
    if (it.row && it.row.parentNode) { it.row.parentNode.removeChild(it.row); }
    for (var i = 0; i < items.length; i++) {
      if (items[i] === it) { items.splice(i, 1); break; }
    }
    if (!body.querySelector('tr')) { renderEmpty(); }
    running = false;
    pump();
  }

  function renderEmpty() {
    body.innerHTML = '<tr class="qEmpty"><td colspan="6" align="center">队列里没有文件</td></tr>';
  }

  /* ---------------- 调度：一次只跑一个任务，保证速度显示稳定 ---------------- */
  function pump() {
    if (running) { return; }
    var next = null;
    for (var i = 0; i < items.length; i++) { if (items[i].status === 'wait') { next = items[i]; break; } }
    if (!next) { return; }
    running = true;
    startItem(next);
  }

  function finish(it) {
    running = false;
    updateRow(it);
    setTimeout(pump, 60);
  }

  function startItem(it) {
    it.paused = false;
    it.loaded = 0;
    it.t0 = Date.now();
    it.bytesMark = 0;
    it.fast = 0;
    var useFast = opt('optFast') && typeof window.wcSha1File === 'function' && it.size <= cfg.maxSingle && it.size >= 4096;
    if (it.sha1 && useFast) { tryFast(it); return; }
    if (useFast) {
      it.status = 'sha';
      updateRow(it);
      window.wcSha1File(it.file, function (p) { it.shaProgress = p; updateRow(it); })
        .then(function (hex) { it.sha1 = hex; tryFast(it); })
        .catch(function () { normalStart(it); });
      return;
    }
    normalStart(it);
  }

  /* 秒传探测 */
  function tryFast(it) {
    it.status = 'fast';
    updateRow(it);
    post(cfg.api + '?do=fast', {
      sha1: it.sha1, size: it.size, name: it.name, folder: folderId()
    }, function (res) {
      if (res && res.ok && res.fast && res.file) {
        it.status = 'done';
        it.fast = 1;
        it.loaded = it.size;
        it.result = res.file;
        finish(it);
        return;
      }
      normalStart(it);
    }, function () { normalStart(it); });
  }

  function normalStart(it) {
    var needChunk = opt('optChunk') && (it.size > cfg.maxSingle || it.size > 6 * 1024 * 1024);
    if (needChunk) { chunkStart(it); } else { singleStart(it); }
  }

  /* 单请求上传 */
  function singleStart(it) {
    it.status = 'single';
    updateRow(it);
    var fd = new FormData();
    fd.append('do', 'one');
    fd.append('folder', folderId());
    fd.append('files[]', it.file, it.name);
    var xhr = newXhr(cfg.api + '?do=one');
    it.xhr = xhr;
    xhr.upload.onprogress = function (e) { if (e.lengthComputable) { track(it, e.loaded); } };
    xhr.onload = function () {
      it.xhr = null;
      var res = parse(xhr);
      if (res && res.ok) {
        it.status = 'done'; it.loaded = it.size; it.result = res.file;
        finish(it);
      } else {
        fail(it, res ? res.msg : ('服务器返回异常（HTTP ' + xhr.status + '）'));
      }
    };
    xhr.onerror = function () { it.xhr = null; fail(it, '网络错误，上传中断'); };
    xhr.onabort = function () { it.xhr = null; };
    xhr.send(fd);
  }

  /* 分片上传 */
  function chunkStart(it) {
    it.status = 'chunk';
    updateRow(it);
    post(cfg.api + '?do=init', {
      name: it.name, size: it.size, sha1: it.sha1 || '', chunk: cfg.chunkSize, folder: folderId()
    }, function (res) {
      if (!res || !res.ok) { fail(it, res ? res.msg : '初始化上传会话失败'); return; }
      it.uploadId = res.upload_id;
      it.total = res.total;
      it.chunk = res.chunk;
      it.have = res.have || [];
      it.nextPart = 0;
      sendNextPart(it);
    }, function () { fail(it, '初始化上传会话失败'); });
  }

  function sendNextPart(it) {
    if (it.paused) { it.status = 'paused'; finish(it); return; }
    if (it.nextPart >= it.total) { mergeParts(it); return; }
    if (it.have.indexOf(it.nextPart) >= 0) { it.nextPart++; updateRow(it); sendNextPart(it); return; }
    var start = it.nextPart * it.chunk;
    var end = Math.min(start + it.chunk, it.size);
    var blob = it.file.slice ? it.file.slice(start, end) : it.file.webkitSlice(start, end);
    var fd = new FormData();
    fd.append('do', 'put');
    fd.append('upload_id', it.uploadId);
    fd.append('i', it.nextPart);
    fd.append('files[]', blob, 'p' + it.nextPart);
    var xhr = newXhr(cfg.api + '?do=put');
    it.xhr = xhr;
    xhr.upload.onprogress = function (e) {
      if (e.lengthComputable) { track(it, start + e.loaded); }
    };
    xhr.onload = function () {
      it.xhr = null;
      var res = parse(xhr);
      if (it.paused) { it.status = 'paused'; finish(it); return; }
      if (res && res.ok) {
        it.have.push(it.nextPart);
        it.nextPart++;
        updateRow(it);
        setTimeout(function () { sendNextPart(it); }, 10);
      } else {
        fail(it, res ? res.msg : ('第 ' + (it.nextPart + 1) + ' 片上传失败'));
      }
    };
    xhr.onerror = function () { it.xhr = null; fail(it, '网络错误：分片中断，可点「继续」断点续传'); };
    xhr.onabort = function () { it.xhr = null; };
    xhr.send(fd);
  }

  function mergeParts(it) {
    it.status = 'merge';
    it.loaded = it.size;
    updateRow(it);
    post(cfg.api + '?do=merge', { upload_id: it.uploadId }, function (res) {
      if (res && res.ok && res.file) {
        it.status = 'done';
        it.result = res.file;
        finish(it);
      } else if (res && res.partial) {
        it.have = res.have || [];
        it.nextPart = 0;
        it.status = 'chunk';
        updateRow(it);
        sendNextPart(it);
      } else {
        fail(it, res ? res.msg : '合并失败');
      }
    }, function () { fail(it, '合并请求失败'); });
  }

  /* ---------------- 进度与速度 ---------------- */
  function track(it, loaded) {
    var now = Date.now();
    if (!it.lastMark) { it.lastMark = now; it.bytesMark = loaded; return; }
    var dt = (now - it.lastMark) / 1000;
    if (dt >= 0.5) {
      it.speed = Math.max(0, (loaded - it.bytesMark) / dt);
      it.lastMark = now;
      it.bytesMark = loaded;
      it.eta = it.speed > 0 ? Math.ceil((it.size - loaded) / it.speed) : 0;
    }
    if (loaded > it.loaded) {
      it.loaded = loaded;
      if (it.status !== 'sha') { updateRow(it); }
    }
  }

  /* ---------------- 通用请求 ---------------- */
  function newXhr(url) {
    var xhr = new XMLHttpRequest();
    xhr.open('POST', url, true);
    xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
    xhr.setRequestHeader('X-CSRF', cfg.csrf);
    return xhr;
  }

  function post(url, data, cb, errCb) {
    var xhr = newXhr(url);
    var fd = new FormData();
    for (var k in data) { if (data.hasOwnProperty(k)) { fd.append(k, data[k]); } }
    xhr.onload = function () {
      var res = parse(xhr);
      if (res && res.ok) { cb(res); } else if (errCb) { errCb(res); } else { cb(res); }
    };
    xhr.onerror = function () { if (errCb) { errCb(null); } else { cb(null); } };
    xhr.send(fd);
  }

  function parse(xhr) {
    try { return JSON.parse(xhr.responseText); } catch (e) { return null; }
  }

  function fail(it, msg) {
    it.status = 'error';
    it.msg = msg;
    finish(it);
  }

  /* ---------------- 交互绑定 ---------------- */
  document.getElementById('pickBtn').onclick = function () { pickInput.click(); };
  pickInput.onchange = function () { addFiles(pickInput.files); pickInput.value = ''; };

  document.getElementById('startBtn').onclick = function () {
    for (var i = 0; i < items.length; i++) {
      if (items[i].status === 'done' || items[i].status === 'wait') { continue; }
      if (items[i].status !== 'error') { continue; }
    }
    pump();
  };

  document.getElementById('clearBtn').onclick = function () {
    for (var i = items.length - 1; i >= 0; i--) {
      if (items[i].status === 'wait' || items[i].status === 'done' || items[i].status === 'error') { remove(items[i]); }
    }
    if (!items.length) { renderEmpty(); }
  };

  ['dragenter', 'dragover'].forEach(function (ev) {
    zone.addEventListener(ev, function (e) {
      e.preventDefault(); e.stopPropagation();
      zone.className = 'dropZone over';
    });
  });
  ['dragleave', 'drop'].forEach(function (ev) {
    zone.addEventListener(ev, function (e) {
      e.preventDefault(); e.stopPropagation();
      zone.className = 'dropZone';
    });
  });
  zone.addEventListener('drop', function (e) {
    var dt = e.dataTransfer;
    if (!dt) { return; }
    if (dt.files && dt.files.length) { addFiles(dt.files); return; }
    if (dt.items) {
      var out = [];
      for (var i = 0; i < dt.items.length; i++) {
        if (dt.items[i].kind === 'file') {
          var f = dt.items[i].getAsFile();
          if (f) { out.push(f); }
        }
      }
      if (out.length) { addFiles(out); }
    }
  });
  zone.addEventListener('click', function () { pickInput.click(); });

  document.addEventListener('paste', function (e) {
    var cd = e.clipboardData || window.clipboardData;
    if (!cd || !cd.items) { return; }
    var out = [];
    for (var i = 0; i < cd.items.length; i++) {
      if (cd.items[i].kind === 'file' || cd.items[i].type.indexOf('image/') === 0) {
        var f = cd.items[i].getAsFile();
        if (f) {
          var name = f.name;
          if (!name || name === 'image.png') {
            var ext = (f.type.split('/')[1] || 'png').replace('jpeg', 'jpg');
            name = 'paste_' + Date.now() + '.' + ext;
          }
          try { f = new File([f], name, { type: f.type }); } catch (err) {}
          out.push(f);
        }
      }
    }
    if (out.length) { e.preventDefault(); addFiles(out); }
  });

  window.addEventListener('beforeunload', function (e) {
    for (var i = 0; i < items.length; i++) {
      var s = items[i].status;
      if (s === 'single' || s === 'chunk' || s === 'merge' || s === 'sha') {
        e.returnValue = '还有文件正在上传，分片会保留 2 天可续传，确定离开？';
        return e.returnValue;
      }
    }
  });
})();
