/* 文谶云盘 · mdui 深色皮肤交互层
   主题/皮肤切换、抽屉、滚动模糊、骨架屏、直链复制对话框、snackbar、
   确认对话框、快捷键、拖拽移动、相册幻灯片、懒加载占位 */
(function () {
  'use strict';
  var W = window, D = document;
  function $(s, r) { return (r || D).querySelector(s); }
  function $$(s, r) { return Array.prototype.slice.call((r || D).querySelectorAll(s)); }
  function cookie(name, val, days) {
    if (val === undefined) {
      var m = D.cookie.match(new RegExp('(?:^|; )' + name + '=([^;]*)'));
      return m ? decodeURIComponent(m[1]) : '';
    }
    D.cookie = name + '=' + encodeURIComponent(val) + '; path=/; max-age=' + ((days || 365) * 86400) + '; SameSite=Lax';
  }
  function isMdui() { return D.body && D.body.dataset.skin === 'mdui'; }
  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  /* ---------- snackbar ---------- */
  W.wcSnack = function (msg, ms) {
    if (isMdui() && W.customElements && customElements.get('mdui-snackbar')) {
      var host = $('#wcSnackHost') || D.body;
      var sb = D.createElement('mdui-snackbar');
      sb.textContent = msg;
      sb.setAttribute('auto-close-delay', String(ms || 2600));
      host.appendChild(sb);
      sb.open = true;
      setTimeout(function () { sb.remove(); }, (ms || 2600) + 800);
      return;
    }
    var t = D.createElement('div');
    t.className = 'tip tipOk';
    t.style.cssText = 'position:fixed;left:50%;bottom:28px;transform:translateX(-50%);z-index:999;box-shadow:0 8px 30px rgba(0,0,0,.4)';
    t.textContent = msg;
    D.body.appendChild(t);
    setTimeout(function () { t.remove(); }, ms || 2600);
  };

  /* ---------- 确认对话框 ---------- */
  W.wcConfirm = function (msg, onOk) {
    if (isMdui() && customElements.get('mdui-dialog')) {
      var host = $('#wcDialogHost') || D.body;
      var dlg = D.createElement('mdui-dialog');
      dlg.setAttribute('headline', '请确认');
      dlg.innerHTML = '<div style="padding:4px 2px 12px">' + esc(msg) + '</div>'
        + '<mdui-button slot="action" variant="text" data-act="no">取消</mdui-button>'
        + '<mdui-button slot="action" variant="filled" data-act="yes">确定</mdui-button>';
      host.appendChild(dlg);
      dlg.addEventListener('click', function (ev) {
        var b = ev.target.closest('[data-act]');
        if (!b) { return; }
        dlg.open = false;
        setTimeout(function () { dlg.remove(); }, 250);
        if (b.dataset.act === 'yes' && onOk) { onOk(); }
      });
      dlg.open = true;
      return;
    }
    if (W.confirm(msg) && onOk) { onOk(); }
  };

  /* 拦截带 data-confirm 的链接/按钮 */
  D.addEventListener('click', function (ev) {
    var a = ev.target.closest('[data-confirm]');
    if (!a) { return; }
    ev.preventDefault();
    var href = a.getAttribute('href');
    W.wcConfirm(a.dataset.confirm, function () {
      if (href && href !== 'javascript:;') { location.href = href; }
      else if (a.form) { a.form.submit(); }
    });
  });

  /* ---------- 复制 ---------- */
  W.wcCopyText = function (text, okMsg) {
    function done() { W.wcSnack(okMsg || '已复制到剪贴板'); }
    if (navigator.clipboard && W.isSecureContext) {
      navigator.clipboard.writeText(text).then(done, function () { fallback(); });
    } else { fallback(); }
    function fallback() {
      var ta = D.createElement('textarea');
      ta.value = text; ta.style.cssText = 'position:fixed;left:-9999px';
      D.body.appendChild(ta); ta.select();
      try { D.execCommand('copy'); done(); } catch (e) { W.wcSnack('复制失败，请手动选择复制'); }
      ta.remove();
    }
  };

  /* ---------- 直链复制对话框 ---------- */
  W.wcCopyLinks = function (fileId) {
    var url = (W.WC_BASE || '') + 'api.php?do=links&id=' + encodeURIComponent(fileId) + '&k=' + encodeURIComponent(W.WC_CSRF || '');
    fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (j) {
        if (!j || !j.ok) { W.wcSnack((j && j.msg) || '获取链接失败'); return; }
        renderCopyDialog(j);
      })
      .catch(function () { W.wcSnack('网络错误，获取链接失败'); });
  };

  function renderCopyDialog(j) {
    var v = j.variants, f = j.file;
    var rows = [
      ['分享页', v.page], ['直链', v.direct],
      ['HTML 图片', v.img], ['HTML 视频', v.video], ['HTML 音频', v.audio],
      ['Markdown', v.md], ['BBCode', v.bbcode], ['CSS 背景', v.css]
    ];
    if (v.poster) { rows.push(['视频封面', v.poster]); }
    if (v.thumb) { rows.push(['缩略直链', v.thumb]); }
    var html = '<div class="wcCopyTabs"><span class="mini">' + esc(f.name) + ' · ' + esc(f.size) + '</span>'
      + '<select id="wcCopyTtl" style="margin-left:auto"><option value="0">永久直链</option>'
      + '<option value="3600">签名 1 小时</option><option value="86400">签名 1 天</option>'
      + '<option value="604800">签名 7 天</option><option value="2592000">签名 30 天</option></select></div>'
      + '<div class="wcCopyGrid">';
    rows.forEach(function (r, i) {
      html += '<div class="wcCopyRow"><span class="lb">' + esc(r[0]) + '</span>'
        + '<input readonly value="' + esc(r[1]) + '" data-i="' + i + '">'
        + '<button class="btn" data-copy="' + i + '"><i class="ic ic-copy"></i> 复制</button></div>';
    });
    html += '</div><div class="mini" style="margin-top:10px">签名直链带有效期，过期自动失效；普通直链长期有效。</div>';

    if (isMdui() && customElements.get('mdui-dialog')) {
      var host = $('#wcDialogHost') || D.body;
      var dlg = D.createElement('mdui-dialog');
      dlg.setAttribute('headline', '复制直链 · ' + f.name);
      dlg.setAttribute('close-on-esc', '');
      dlg.innerHTML = html + '<mdui-button slot="action" variant="text" data-close="1">关闭</mdui-button>';
      host.appendChild(dlg);
      bindCopy(dlg, rows, j);
      dlg.addEventListener('click', function (ev) { if (ev.target.closest('[data-close]')) { dlg.open = false; setTimeout(function () { dlg.remove(); }, 250); } });
      dlg.open = true;
    } else {
      var box = D.createElement('div');
      box.className = 'wcSlide';
      box.innerHTML = '<div class="box" style="max-width:640px;width:92vw;max-height:86vh;overflow:auto"><div class="boxHd">复制直链</div><div class="boxBd">' + html + '</div></div>';
      D.body.appendChild(box);
      box.addEventListener('click', function (ev) { if (ev.target === box) { box.remove(); } });
      bindCopy(box, rows, j);
    }
  }

  function bindCopy(root, rows, j) {
    root.addEventListener('click', function (ev) {
      var b = ev.target.closest('[data-copy]');
      if (!b) { return; }
      var i = parseInt(b.dataset.copy, 10);
      var ttlSel = $('#wcCopyTtl', root);
      var ttl = ttlSel ? parseInt(ttlSel.value, 10) : 0;
      var val = rows[i][1];
      if (ttl > 0 && (i === 1 || i >= 2)) {
        // 重新请求带签名的直链
        fetch((W.WC_BASE || '') + 'api.php?do=links&id=' + j.file.id + '&ttl=' + ttl + '&k=' + encodeURIComponent(W.WC_CSRF || ''), { headers: { 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' })
          .then(function (r) { return r.json(); })
          .then(function (jj) {
            if (jj && jj.ok) {
              var nv = jj.variants;
              var map = [nv.page, nv.direct, nv.img, nv.video, nv.audio, nv.md, nv.bbcode, nv.css, nv.poster, nv.thumb];
              W.wcCopyText(map[i] || val, '已复制（签名 ' + Math.round(ttl / 3600) + ' 小时）');
            } else { W.wcCopyText(val); }
          });
        return;
      }
      W.wcCopyText(val);
    });
  }

  /* ---------- 分享页直链复制（无需登录） ---------- */
  function copyShare(code, kind) {
    var base = W.WC_BASE || (location.origin + '/');
    var direct = base + 'd/' + code;
    var page = base + 's/' + code;
    var rows = [
      ['分享页', page], ['直链', direct],
      ['HTML 图片', '<img src="' + direct + '" alt="">'],
      ['HTML 视频', '<video src="' + direct + '" controls preload="metadata"></video>'],
      ['Markdown', kind === 'image' ? '![](' + direct + ')' : '[下载](' + page + ')'],
      ['BBCode', kind === 'image' ? '[img]' + direct + '[/img]' : '[url=' + page + ']下载[/url]'],
      ['CSS 背景', 'background-image: url("' + direct + '");'],
      ['缩略直链', direct + '?w=300&h=300&crop=1&q=80'],
      ['视频封面', direct + '?poster=1']
    ];
    var html = '<div class="wcCopyGrid">';
    rows.forEach(function (r, i) {
      html += '<div class="wcCopyRow"><span class="lb">' + esc(r[0]) + '</span><input readonly value="' + esc(r[1]) + '">'
        + '<button class="btn" data-copy="' + i + '"><i class="ic ic-copy"></i> 复制</button></div>';
    });
    html += '</div>';
    if (isMdui() && customElements.get('mdui-dialog')) {
      var host = $('#wcDialogHost') || D.body;
      var dlg = D.createElement('mdui-dialog');
      dlg.setAttribute('headline', '复制直链');
      dlg.innerHTML = html + '<mdui-button slot="action" variant="text" data-close="1">关闭</mdui-button>';
      host.appendChild(dlg);
      dlg.addEventListener('click', function (ev) {
        var b = ev.target.closest('[data-copy]');
        if (b) { W.wcCopyText(rows[parseInt(b.dataset.copy, 10)][1]); return; }
        if (ev.target.closest('[data-close]')) { dlg.open = false; setTimeout(function () { dlg.remove(); }, 250); }
      });
      dlg.open = true;
    } else {
      var box = D.createElement('div');
      box.className = 'wcSlide';
      box.innerHTML = '<div class="box" style="max-width:620px;width:92vw;max-height:86vh;overflow:auto"><div class="boxHd">复制直链</div><div class="boxBd">' + html + '</div></div>';
      D.body.appendChild(box);
      box.addEventListener('click', function (ev) {
        var b = ev.target.closest('[data-copy]');
        if (b) { W.wcCopyText(rows[parseInt(b.dataset.copy, 10)][1]); return; }
        if (ev.target === box) { box.remove(); }
      });
    }
  }

  /* ---------- 骨架屏 ---------- */
  W.wcSkeletonHide = function (root) {
    $$('[data-skeleton]', root || D).forEach(function (sk) {
      sk.classList.add('wcOut');
      setTimeout(function () { sk.remove(); }, 340);
    });
  };
  W.wcSkeletonShow = function (container, rows, cols) {
    if (!container) { return null; }
    var sk = D.createElement('div');
    sk.className = 'wcSkeleton'; sk.dataset.skeleton = '1';
    var h = '';
    for (var i = 0; i < (rows || 5); i++) {
      h += '<div class="skRow">';
      for (var c = 0; c < (cols || 4); c++) { h += '<i class="skCell' + (c === 0 ? ' skWide' : '') + '"></i>'; }
      h += '</div>';
    }
    sk.innerHTML = h;
    container.appendChild(sk);
    return sk;
  };

  /* ---------- 懒加载图片占位 ---------- */
  function lazyImages() {
    if (!('IntersectionObserver' in W)) { return; }
    var io = new IntersectionObserver(function (entries) {
      entries.forEach(function (en) {
        if (!en.isIntersecting) { return; }
        var img = en.target;
        io.unobserve(img);
        img.addEventListener('load', function () { img.classList.remove('wcLoading'); img.classList.add('wcFadeIn'); });
        img.addEventListener('error', function () { img.classList.remove('wcLoading'); });
        if (img.dataset.src) { img.src = img.dataset.src; }
      });
    }, { rootMargin: '120px' });
    $$('img[loading="lazy"]').forEach(function (img) { img.classList.add('wcLoading'); io.observe(img); });
  }

  /* ---------- 拖拽移动文件到文件夹 ---------- */
  function dragMove() {
    var rows = $$('table.tb tr[data-file-id]');
    if (!rows.length) { return; }
    rows.forEach(function (tr) {
      tr.setAttribute('draggable', 'true');
      tr.addEventListener('dragstart', function (ev) {
        ev.dataTransfer.setData('text/wc-file', tr.dataset.fileId);
        ev.dataTransfer.effectAllowed = 'move';
      });
    });
    $$('a[data-folder-id]').forEach(function (a) {
      a.addEventListener('dragover', function (ev) { ev.preventDefault(); a.classList.add('wcDropOver'); });
      a.addEventListener('dragleave', function () { a.classList.remove('wcDropOver'); });
      a.addEventListener('drop', function (ev) {
        ev.preventDefault(); a.classList.remove('wcDropOver');
        var id = ev.dataTransfer.getData('text/wc-file');
        if (!id) { return; }
        var fd = new FormData();
        fd.append('do', 'move'); fd.append('id', id); fd.append('fid', a.dataset.folderId);
        fd.append('_token', W.WC_CSRF || ''); fd.append('back', 'files.php');
        fetch((W.WC_BASE || '') + 'api.php', { method: 'POST', body: fd, headers: { 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF': W.WC_CSRF || '' }, credentials: 'same-origin' })
          .then(function (r) { return r.json(); })
          .then(function (j) { W.wcSnack(j && j.msg ? j.msg : '已移动'); if (j && j.ok) { setTimeout(function () { location.reload(); }, 700); } });
      });
    });
  }

  /* ---------- 相册幻灯片 ---------- */
  W.wcSlideshow = function (urls, start) {
    if (!urls || !urls.length) { return; }
    var i = start || 0;
    var box = D.createElement('div');
    box.className = 'wcSlide';
    box.innerHTML = '<img alt="幻灯片"><div class="wcSlideBar">'
      + '<button class="btn" data-s="prev">上一张</button><span class="mini" data-s="idx"></span>'
      + '<button class="btn" data-s="next">下一张</button><button class="btn" data-s="close">关闭</button></div>';
    D.body.appendChild(box);
    var img = $('img', box);
    function show() { img.src = urls[i]; $('[data-s="idx"]', box).textContent = (i + 1) + ' / ' + urls.length; }
    box.addEventListener('click', function (ev) {
      var b = ev.target.closest('[data-s]');
      if (!b) { if (ev.target === box) { box.remove(); } return; }
      var act = b.dataset.s;
      if (act === 'close') { box.remove(); return; }
      i = act === 'next' ? (i + 1) % urls.length : (i - 1 + urls.length) % urls.length;
      show();
    });
    D.addEventListener('keydown', function h(ev) {
      if (!D.body.contains(box)) { D.removeEventListener('keydown', h); return; }
      if (ev.key === 'Escape') { box.remove(); }
      if (ev.key === 'ArrowRight') { i = (i + 1) % urls.length; show(); }
      if (ev.key === 'ArrowLeft') { i = (i - 1 + urls.length) % urls.length; show(); }
    });
    show();
  };

  /* ---------- 快捷键 ---------- */
  function hotkeys() {
    var seq = '';
    D.addEventListener('keydown', function (ev) {
      var tag = (ev.target.tagName || '').toLowerCase();
      if (tag === 'input' || tag === 'textarea' || tag === 'select' || ev.target.isContentEditable) { return; }
      if (ev.key === '/') { ev.preventDefault(); var s = $('.wcSearch input') || $('.navSearch input'); if (s) { s.focus(); } return; }
      if (ev.key === 'u') { location.href = (W.WC_BASE || '') + 'upload.php'; return; }
      if (ev.key === '?') { W.wcSnack('快捷键：/ 搜索 · u 上传 · g f 文件 · g s 分享 · c 复制首个直链'); return; }
      seq += ev.key;
      setTimeout(function () { seq = ''; }, 700);
      if (seq === 'gf') { location.href = (W.WC_BASE || '') + 'files.php'; seq = ''; }
      if (seq === 'gs') { location.href = (W.WC_BASE || '') + 'shares.php'; seq = ''; }
      if (ev.key === 'c') {
        var first = $('[data-copy-links]');
        if (first) { W.wcCopyLinks(first.dataset.copyLinks); }
      }
    });
  }

  /* ---------- 初始化 ---------- */
  function init() {
    if (!isMdui()) { return; }
    // 抽屉
    var drawer = $('#wcDrawer'), menuBtn = $('#wcMenuBtn');
    if (menuBtn && drawer) {
      menuBtn.addEventListener('click', function () { drawer.open = !drawer.open; });
    }
    // 主题切换
    var themeBtn = $('#wcThemeBtn');
    if (themeBtn) {
      themeBtn.addEventListener('click', function () {
        var cur = cookie('wc_theme') || (D.documentElement.classList.contains('mdui-theme-light') ? 'light' : 'dark');
        var next = cur === 'dark' ? 'light' : (cur === 'light' ? 'auto' : 'dark');
        cookie('wc_theme', next);
        D.documentElement.classList.remove('mdui-theme-dark', 'mdui-theme-light', 'mdui-theme-auto');
        D.documentElement.classList.add(next === 'light' ? 'mdui-theme-light' : (next === 'auto' ? 'mdui-theme-auto' : 'mdui-theme-dark'));
        var ic = $('.ic', themeBtn);
        if (ic) { ic.className = 'ic ' + (next === 'light' ? 'ic-sun' : 'ic-moon'); }
        W.wcSnack('主题：' + (next === 'dark' ? '深色' : next === 'light' ? '浅色' : '跟随系统'));
      });
    }
    // 皮肤切换
    var skinBtn = $('#wcSkinBtn');
    if (skinBtn) {
      skinBtn.addEventListener('click', function (ev) {
        ev.preventDefault();
        cookie('wc_skin', D.body.dataset.skin === 'mdui' ? 'classic' : 'mdui');
        location.reload();
      });
    }
    // 滚动模糊加深
    var bar = $('#wcBar');
    if (bar) {
      W.addEventListener('scroll', function () { bar.classList.toggle('wcScrolled', W.scrollY > 8); }, { passive: true });
    }
    // 骨架屏淡出
    setTimeout(function () { W.wcSkeletonHide(); }, 260);
    W.addEventListener('load', function () { W.wcSkeletonHide(); });
    lazyImages();
    dragMove();
    hotkeys();
    // 复制直链按钮
    D.addEventListener('click', function (ev) {
      var b = ev.target.closest('[data-copy-links]');
      if (b) { ev.preventDefault(); W.wcCopyLinks(b.dataset.copyLinks); return; }
      var s = ev.target.closest('[data-copy-share]');
      if (s) { ev.preventDefault(); copyShare(s.dataset.copyShare, s.dataset.kind || ''); }
    });
    // 幻灯片入口
    D.addEventListener('click', function (ev) {
      var b = ev.target.closest('[data-slideshow]');
      if (!b) { return; }
      ev.preventDefault();
      try { W.wcSlideshow(JSON.parse(b.dataset.slideshow), 0); } catch (e) { }
    });
    // 批量直链导出
    D.addEventListener('click', function (ev) {
      var b = ev.target.closest('[data-export]');
      if (!b) { return; }
      ev.preventDefault();
      var ids = $$('#batchForm input[name="file[]"]:checked').map(function (i) { return i.value; });
      var q = 'fmt=' + encodeURIComponent(b.dataset.export);
      if (ids.length) { q += ids.map(function (i) { return '&file[]=' + encodeURIComponent(i); }).join(''); }
      else {
        var m = location.search.match(/[?&]fid=(\d+)/);
        q += '&fid=' + (m ? m[1] : '0');
      }
      location.href = (W.WC_BASE || '') + 'export.php?' + q;
    });
  }

  if (D.readyState === 'loading') { D.addEventListener('DOMContentLoaded', init); } else { init(); }
})();
