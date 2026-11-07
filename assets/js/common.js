/* 文谶云盘 公共脚本：全选、复制、目标目录联动等 */
(function (w) {
  'use strict';

  /**
   * 头像逐级降级：国内镜像 → 官方源 → 字母占位
   * 用法：<img src="当前源" data-alt="备用源" onerror="wcAvatarFail(this)">
   */
  w.wcAvatarFail = function (img) {
    if (!img) { return; }
    var stage = img.dataset.stage || '0';
    var alt = img.dataset.alt;
    if (stage === '0' && alt && img.getAttribute('src') !== alt) {
      img.dataset.stage = '1';
      img.src = alt;
      return;
    }
    // 备用源也取不到（或与当前源相同）：隐藏图片，显示字母占位
    img.dataset.stage = '2';
    img.style.display = 'none';
    var fb = img.parentNode ? img.parentNode.querySelector('.avFb') : null;
    if (fb) { fb.style.display = 'inline-block'; }
  };

  w.wcCheckAll = function (src, name) {
    var form = src.form || document.forms[0];
    if (!form) { return; }
    var boxes = form.querySelectorAll('input[type=checkbox][name^="' + name + '"]');
    for (var i = 0; i < boxes.length; i++) { boxes[i].checked = src.checked; }
  };

  w.wcSetFolder = function (v) {
    var el = document.getElementById('legacyFolder');
    if (el) { el.value = v; }
    if (w.WC_UPLOAD) { w.WC_UPLOAD.folder = parseInt(v, 10) || 0; }
  };

  w.wcCopy = function (text) {
    var ta = document.createElement('textarea');
    ta.value = text;
    ta.style.position = 'fixed';
    ta.style.left = '-999px';
    document.body.appendChild(ta);
    ta.select();
    try { document.execCommand('copy'); } catch (e) {}
    document.body.removeChild(ta);
    return true;
  };

  w.wcCopyInput = function (id) {
    var el = document.getElementById(id);
    if (!el) { return; }
    el.select();
    try { document.execCommand('copy'); el.style.background = '#eaf7ea'; setTimeout(function () { el.style.background = ''; }, 600); } catch (e) {}
  };

  // 表单重复提交保护：提交后禁用按钮并显示处理中
  document.addEventListener('DOMContentLoaded', function () {
    var forms = document.querySelectorAll('form.frm');
    for (var i = 0; i < forms.length; i++) {
      forms[i].addEventListener('submit', function () {
        var btns = this.querySelectorAll('input[type=submit]');
        setTimeout(function () {
          for (var j = 0; j < btns.length; j++) {
            if (!btns[j].dataset.busy) { btns[j].dataset.busy = '1'; btns[j].value = btns[j].value + '…处理中'; btns[j].disabled = true; }
          }
        }, 10);
      });
    }
    // 外链地址输入框点击即选中并复制
    var urlInputs = document.querySelectorAll('.shareUrlBox input, .pvShare input');
    for (var k = 0; k < urlInputs.length; k++) {
      urlInputs[k].addEventListener('click', function () { this.select(); });
    }
  });
})(window);
