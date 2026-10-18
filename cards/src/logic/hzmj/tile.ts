// @ts-nocheck — 从 game 迁移的红中规则，保持语义
import {
  CHARACTER,
  BAMBOO,
  DOT,
  DRAGONS,
  KING_TILE,
  SHORT_TILE_LABEL
} from "@/logic/hzmj/rules/constants";
function buildTileWall() {
  const wall = [];
  for (const suit of [CHARACTER, BAMBOO, DOT]) {
    for (const t of suit) {
      for (let i = 0; i < 4; i++) wall.push(t);
    }
  }
  for (let i = 0; i < 4; i++) wall.push(KING_TILE);
  return wall;
}
function shuffle(arr) {
  const a = [...arr];
  for (let i = a.length - 1; i > 0; i--) {
    const j = Math.floor(Math.random() * (i + 1));
    [a[i], a[j]] = [a[j], a[i]];
  }
  return a;
}
function sortTiles(tiles) {
  return [...tiles].sort((a, b) => {
    const ak = a === KING_TILE ? -1 : a;
    const bk = b === KING_TILE ? -1 : b;
    return ak - bk;
  });
}
function tileLabel(tile) {
  return SHORT_TILE_LABEL[tile] ?? String(tile);
}
function tileColor(tile) {
  if (tile === KING_TILE || DRAGONS.includes(tile)) return "#c62828";
  if (CHARACTER.includes(tile)) return "#1b5e20";
  if (BAMBOO.includes(tile)) return "#0d47a1";
  if (DOT.includes(tile)) return "#e65100";
  return "#333";
}
function buildMockDeal(playerNum, dealerIdx = 0) {
  const wall = shuffle(buildTileWall());
  const hands = [];
  let idx = 0;
  for (let p = 0; p < playerNum; p++) {
    const count = p === dealerIdx ? 14 : 13;
    hands.push(wall.slice(idx, idx + count));
    idx += count;
  }
  const remain = wall.slice(idx);
  return { hands, wall: remain, left: remain.length };
}
export {
  buildMockDeal,
  buildTileWall,
  shuffle,
  sortTiles,
  tileColor,
  tileLabel
};
