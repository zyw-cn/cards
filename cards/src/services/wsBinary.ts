/**
 * WebSocket 二进制协议（对齐 hyperf BinaryPacket）
 *
 * 帧: type(1) + length(3 big-endian) + body(UTF-8 JSON)
 */

export const MessageType = Object.freeze({
  PING: 0x01,
  CHAT: 0x02,
  MESSAGE: 0x03,
  NOTICE: 0x04,
})

const HEADER_SIZE = 4
const MAX_BODY = 0xffffff
const textEncoder = new TextEncoder()
const textDecoder = new TextDecoder('utf-8')

function toBytes(body: unknown): Uint8Array {
  if (body == null) return new Uint8Array(0)
  if (body instanceof Uint8Array) return body
  if (body instanceof ArrayBuffer) return new Uint8Array(body)
  if (typeof body === 'string') return textEncoder.encode(body)
  return textEncoder.encode(JSON.stringify(body))
}

export function encodeFrame(type: number, body?: unknown): ArrayBuffer {
  const bytes = toBytes(body)
  if (bytes.length > MAX_BODY) throw new RangeError(`ws body too large: ${bytes.length}`)
  const buffer = new ArrayBuffer(HEADER_SIZE + bytes.length)
  const view = new DataView(buffer)
  view.setUint8(0, type)
  view.setUint8(1, (bytes.length >>> 16) & 0xff)
  view.setUint8(2, (bytes.length >>> 8) & 0xff)
  view.setUint8(3, bytes.length & 0xff)
  new Uint8Array(buffer).set(bytes, HEADER_SIZE)
  return buffer
}

export function decodeFrame(data: ArrayBuffer | Uint8Array): {
  type: number
  length: number
  body: Uint8Array
} {
  const bytes = data instanceof Uint8Array ? data : new Uint8Array(data)
  if (bytes.length < HEADER_SIZE) throw new Error('ws frame too short')
  const type = bytes[0]
  const length = (bytes[1] << 16) | (bytes[2] << 8) | bytes[3]
  return { type, length, body: bytes.subarray(HEADER_SIZE, HEADER_SIZE + length) }
}

export function bodyToJson(body: Uint8Array): unknown {
  const text = textDecoder.decode(body)
  if (!text) return null
  try {
    return JSON.parse(text)
  } catch {
    return text
  }
}
