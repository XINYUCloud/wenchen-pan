/* 增量 SHA-1（用于上传前秒传探测），不依赖 Web Crypto（其不提供 SHA-1） */
(function (w) {
  'use strict';

  function WcSha1() {
    this.h = [0x67452301, 0xefcdab89, 0x98badcfe, 0x10325476, 0xc3d2e1f0];
    this.block = new Uint8Array(64);
    this.blockUsed = 0;
    this.totalBytes = 0;
    this.w = new Int32Array(80);
  }

  WcSha1.prototype._compress = function () {
    var w = this.w, b = this.block, h = this.h, t;
    for (t = 0; t < 16; t++) {
      w[t] = (b[t * 4] << 24) | (b[t * 4 + 1] << 16) | (b[t * 4 + 2] << 8) | b[t * 4 + 3];
    }
    for (t = 16; t < 80; t++) {
      var v = w[t - 3] ^ w[t - 8] ^ w[t - 14] ^ w[t - 16];
      w[t] = (v << 1) | (v >>> 31);
    }
    var a = h[0], bb = h[1], c = h[2], d = h[3], e = h[4];
    for (t = 0; t < 80; t++) {
      var f, k;
      if (t < 20) { f = (bb & c) | ((~bb) & d); k = 0x5a827999; }
      else if (t < 40) { f = bb ^ c ^ d; k = 0x6ed9eba1; }
      else if (t < 60) { f = (bb & c) | (bb & d) | (c & d); k = 0x8f1bbcdc; }
      else { f = bb ^ c ^ d; k = 0xca62c1d6; }
      var temp = (((a << 5) | (a >>> 27)) + f + e + k + w[t]) | 0;
      e = d; d = c;
      c = ((bb << 30) | (bb >>> 2)) | 0;
      bb = a; a = temp;
    }
    h[0] = (h[0] + a) | 0; h[1] = (h[1] + bb) | 0; h[2] = (h[2] + c) | 0; h[3] = (h[3] + d) | 0; h[4] = (h[4] + e) | 0;
  };

  WcSha1.prototype._push = function (v) {
    this.block[this.blockUsed++] = v & 0xff;
    if (this.blockUsed === 64) { this._compress(); this.blockUsed = 0; }
  };

  WcSha1.prototype.update = function (bytes) {
    var i = 0, len = bytes.length;
    this.totalBytes += len;
    while (i < len) {
      var take = Math.min(64 - this.blockUsed, len - i);
      for (var j = 0; j < take; j++) { this.block[this.blockUsed + j] = bytes[i + j]; }
      this.blockUsed += take;
      i += take;
      if (this.blockUsed === 64) { this._compress(); this.blockUsed = 0; }
    }
    return this;
  };

  WcSha1.prototype.digestHex = function () {
    var bitLen = this.totalBytes * 8;
    this._push(0x80);
    while (this.blockUsed !== 56) { this._push(0); }
    var hi = Math.floor(bitLen / 4294967296);
    var lo = bitLen % 4294967296;
    this._push(hi >>> 24); this._push((hi >>> 16) & 0xff); this._push((hi >>> 8) & 0xff); this._push(hi & 0xff);
    this._push(lo >>> 24); this._push((lo >>> 16) & 0xff); this._push((lo >>> 8) & 0xff); this._push(lo & 0xff);
    var out = '';
    for (var i = 0; i < 5; i++) { out += ('00000000' + (this.h[i] >>> 0).toString(16)).slice(-8); }
    return out;
  };

  /**
   * 分块计算文件 SHA1，避免一次性读入内存
   * @param {File} file
   * @param {Function} onProgress 0-1
   * @returns {Promise<string>}
   */
  w.wcSha1File = function (file, onProgress) {
    return new Promise(function (resolve, reject) {
      var slice = 4 * 1024 * 1024;
      var off = 0;
      var sha = new WcSha1();
      function step() {
        if (off >= file.size) {
          if (onProgress) { onProgress(1); }
          resolve(sha.digestHex());
          return;
        }
        var end = Math.min(off + slice, file.size);
        var reader = new FileReader();
        reader.onerror = function () { reject(new Error('读取文件失败')); };
        reader.onload = function () {
          sha.update(new Uint8Array(reader.result));
          off = end;
          if (onProgress) { onProgress(off / file.size); }
          setTimeout(step, 0);
        };
        reader.readAsArrayBuffer(file.slice ? file.slice(off, end) : file.webkitSlice(off, end));
      }
      step();
    });
  };

  w.WcSha1 = WcSha1;
})(window);
