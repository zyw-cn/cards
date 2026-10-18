// @ts-nocheck — 从 game 迁移的红中规则，保持语义
import { SHOW_OP, KING_TILE, CHARACTER, BAMBOO, DOT } from "@/logic/hzmj/rules/constants";
import { classifySuitTiles, meldOnlyNeedNum, meldWithPairNeedNum } from "./meld";
function canChow() {
  return false;
}
function canPong(tiles, t, kingTiles = [KING_TILE]) {
  if (kingTiles.includes(t)) return false;
  return tiles.filter((x) => x === t).length >= 2;
}
function canExposedKong(tiles, t, kingTiles = [KING_TILE]) {
  if (kingTiles.includes(t)) return false;
  return tiles.filter((x) => x === t).length === 3;
}
function canConcealedKong(tiles, t, kingTiles = [KING_TILE]) {
  if (kingTiles.includes(t)) return false;
  return tiles.filter((x) => x === t).length === 4;
}
function getOneConcealedKongTile(tiles, kingTiles = [KING_TILE]) {
  const map = /* @__PURE__ */ new Map();
  for (const t of tiles) {
    if (kingTiles.includes(t)) continue;
    const n = (map.get(t) ?? 0) + 1;
    map.set(t, n);
    if (n >= 4) return t;
  }
  return 0;
}
function canContinueKongFromUptiles(upTilesList, t, kingTiles = [KING_TILE]) {
  if (kingTiles.includes(t)) return false;
  return upTilesList.some(
    (m) => m.length === 3 && m[0] === m[1] && m[1] === m[2] && m[0] === t
  );
}
function getContinueKongTile(hand, upTilesList, kingTiles = [KING_TILE]) {
  for (const t of hand) {
    if (canContinueKongFromUptiles(upTilesList, t, kingTiles)) return t;
  }
  return 0;
}
function canWin(handTiles, _kingTiles = [KING_TILE]) {
  if (handTiles.length % 3 !== 2) return false;
  const tiles = [...handTiles].sort((a, b) => a - b);
  const { chars, bambs, dots, dragonRed } = classifySuitTiles(tiles);
  const hist = {};
  const c1 = meldOnlyNeedNum(chars, hist);
  const c2 = meldWithPairNeedNum(chars, hist);
  if (c1 > dragonRed && c2 > dragonRed) return false;
  const b1 = meldOnlyNeedNum(bambs, hist);
  const b2 = meldWithPairNeedNum(bambs, hist);
  if (b1 > dragonRed && b2 > dragonRed) return false;
  const d1 = meldOnlyNeedNum(dots, hist);
  const d2 = meldWithPairNeedNum(dots, hist);
  if (d1 > dragonRed && d2 > dragonRed) return false;
  return c2 + b1 + d1 <= dragonRed || c1 + b2 + d1 <= dragonRed || c1 + b1 + d2 <= dragonRed;
}

/** 听牌提示：打出 selectTile（>0）后，哪些牌可胡；selectTile=0 表示不先打牌 */
function getCanWinTiles(handTiles, selectTile = 0, kingTiles = [KING_TILE]) {
  const hand = Array.isArray(handTiles) ? handTiles.slice() : [];
  const sel = Number(selectTile) || 0;
  if (sel > 0) {
    const pos = hand.indexOf(sel);
    if (pos >= 0) hand.splice(pos, 1);
  }
  const candidates = [...CHARACTER, ...BAMBOO, ...DOT, KING_TILE];
  const out = [];
  const seen = new Set();
  for (const t of candidates) {
    if (seen.has(t)) continue;
    if (canWin([...hand, t], kingTiles)) {
      seen.add(t);
      out.push(t);
    }
  }
  return out;
}
function getDiscardNotifyOps(hand, tile, kingTiles = [KING_TILE]) {
  const ops = [];
  if (canExposedKong(hand, tile, kingTiles)) ops.push(SHOW_OP.KONG);
  if (canPong(hand, tile, kingTiles)) ops.push(SHOW_OP.PONG);
  return ops;
}
function getDrawNotifyOps(hand, upTilesList, kingTiles = [KING_TILE]) {
  const ops = [];
  if (getOneConcealedKongTile(hand, kingTiles) || getContinueKongTile(hand, upTilesList, kingTiles)) {
    ops.push(SHOW_OP.KONG);
  }
  if (hand.length % 3 === 2 && canWin(hand, kingTiles)) {
    ops.push(SHOW_OP.WIN);
  }
  return ops;
}
function removeTiles(hand, t, n) {
  const next = hand.slice();
  let left = n;
  for (let i = next.length - 1; i >= 0 && left > 0; i--) {
    if (next[i] === t) {
      next.splice(i, 1);
      left--;
    }
  }
  return next;
}
export {
  canChow,
  canConcealedKong,
  canContinueKongFromUptiles,
  canExposedKong,
  canPong,
  canWin,
  getCanWinTiles,
  getContinueKongTile,
  getDiscardNotifyOps,
  getDrawNotifyOps,
  getOneConcealedKongTile,
  removeTiles
};
