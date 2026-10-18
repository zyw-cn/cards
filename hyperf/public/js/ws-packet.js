/**
 * WebSocket 二进制包体协议（与后端 App\Protocol\BinaryPacket 对应）
 *
 * 包结构（大端序 big-endian，共 4 字节头 + N 字节包体）：
 * +--------+--------------------+-----------------------+
 * | type   | length (3 bytes)   | body (length bytes)   |
 * | 1 字节 | uint24 包体字节长度 | UTF-8 字符串(通常 JSON) |
 * +--------+--------------------+-----------------------+
 *
 * type 取值：
 *   0x01 PING    心跳
 *   0x02 CHAT    聊天（房间内广播）
 *   0x03 MESSAGE 业务消息（body: { route, body }，由 Dispatcher 转发）
 *   0x04 NOTIFY  发送通知（系统通知）
 *
 * 使用（浏览器，需 ws.binaryType = 'arraybuffer'）：
 *   const ws = new WebSocket('ws://host/ws');
 *   ws.binaryType = 'arraybuffer';
 *   ws.onopen = () => ws.send(WsPacket.ping());
 *   ws.onmessage = (e) => {
 *     const { type, body } = WsPacket.decode(e.data);
 *     console.log(type, body);
 *   };
 *   ws.send(WsPacket.chat({ roomId: 'R123', from: 1001, content: 'hi' }));
 */
(function (root, factory) {
  if (typeof module === 'object' && module.exports) {
    module.exports = factory();
  } else {
    root.WsPacket = factory();
  }
})(typeof self !== 'undefined' ? self : this, function () {
  'use strict';

  var TYPE = {
    PING: 0x01,
    CHAT: 0x02,
    MESSAGE: 0x03,
    NOTIFY: 0x04,
  };

  var HEADER_SIZE = 4;
  var MAX_BODY_SIZE = 0xffffff; // 3 字节最大值

  var encoder = typeof TextEncoder !== 'undefined' ? new TextEncoder() : null;
  var decoder = typeof TextDecoder !== 'undefined' ? new TextDecoder('utf-8') : null;

  function utf8Encode(str) {
    if (encoder) return encoder.encode(str);
    // Node 兜底
    return new Uint8Array(Buffer.from(str, 'utf-8'));
  }

  function utf8Decode(bytes) {
    if (decoder) return decoder.decode(bytes);
    return Buffer.from(bytes).toString('utf-8');
  }

  /**
   * 编码为二进制帧
   * @param {number} type 包类型（TYPE.*）
   * @param {string|object|undefined} body 包体；对象将被 JSON.stringify
   * @returns {ArrayBuffer}
   */
  function encode(type, body) {
    var bodyStr = '';
    if (body !== undefined && body !== null) {
      bodyStr = typeof body === 'string' ? body : JSON.stringify(body);
    }
    var bodyBytes = utf8Encode(bodyStr);
    var len = bodyBytes.length;
    if (len > MAX_BODY_SIZE) {
      throw new Error('Body too large, max ' + MAX_BODY_SIZE + ' bytes');
    }
    var buf = new Uint8Array(HEADER_SIZE + len);
    buf[0] = type & 0xff;
    buf[1] = (len >>> 16) & 0xff;
    buf[2] = (len >>> 8) & 0xff;
    buf[3] = len & 0xff;
    buf.set(bodyBytes, HEADER_SIZE);
    return buf.buffer;
  }

  /**
   * 解码二进制帧
   * @param {ArrayBuffer|Uint8Array} data
   * @returns {{type:number, length:number, body:any}} body 为 JSON 时自动解析为对象
   */
  function decode(data) {
    var bytes;
    if (data instanceof ArrayBuffer) {
      bytes = new Uint8Array(data);
    } else if (data instanceof Uint8Array) {
      bytes = data;
    } else if (data && data.buffer) {
      bytes = new Uint8Array(data.buffer);
    } else {
      throw new Error('Unsupported data type, expected ArrayBuffer');
    }
    if (bytes.length < HEADER_SIZE) {
      throw new Error('Packet too short, need at least ' + HEADER_SIZE + ' bytes');
    }
    var type = bytes[0];
    var length = (bytes[1] << 16) | (bytes[2] << 8) | bytes[3];
    var bodyBytes = bytes.subarray(HEADER_SIZE, HEADER_SIZE + length);
    var bodyStr = utf8Decode(bodyBytes);
    var body = bodyStr;
    if (bodyStr) {
      try {
        body = JSON.parse(bodyStr);
      } catch (e) {
        /* 非 JSON 则保留原始字符串 */
      }
    }
    return { type: type, length: length, body: body };
  }

  // 各类型便捷构造方法，返回可直接 ws.send 的 ArrayBuffer
  function ping() {
    return encode(TYPE.PING, '');
  }

  /** @param {{roomId:string, from?:any, content:string}} payload */
  function chat(payload) {
    return encode(TYPE.CHAT, payload);
  }

  /** @param {{route:string, body?:object}} payload */
  function message(payload) {
    return encode(TYPE.MESSAGE, payload);
  }

  /** @param {{target?:'room'|'fd'|'all', roomId?:string, fd?:number, title?:string, content:string}} payload */
  function notify(payload) {
    return encode(TYPE.NOTIFY, payload);
  }

  return {
    TYPE: TYPE,
    HEADER_SIZE: HEADER_SIZE,
    MAX_BODY_SIZE: MAX_BODY_SIZE,
    encode: encode,
    decode: decode,
    ping: ping,
    chat: chat,
    message: message,
    notify: notify,
  };
});
