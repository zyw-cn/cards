// @ts-nocheck — 从 game 迁移的红中规则，保持语义
import { CHARACTER, BAMBOO, DOT, DRAGON_RED } from "@/logic/hzmj/rules/constants";
const BOUNDARY = 60;
function histKey(tiles) {
  return [...tiles].sort((a, b) => a - b).join(",");
}
function countOf(tiles, t) {
  let n = 0;
  for (const x of tiles) if (x === t) n++;
  return n;
}
function removeOne(tiles, t) {
  const i = tiles.indexOf(t);
  if (i < 0) return tiles;
  const next = tiles.slice();
  next.splice(i, 1);
  return next;
}
function meldWithPairNeedNum(tiles, history = {}) {
  let case1 = 999;
  let case2 = 999;
  if (meldOnlyNeedNum(tiles, history) === 0) case1 = 2;
  for (let i = 0; i < tiles.length; i++) {
    const t = tiles[i];
    let tmp = tiles.slice();
    if (countOf(tiles, t) === 1) {
      tmp = removeOne(tmp, t);
      case2 = Math.min(case2, 1 + meldOnlyNeedNum(tmp, history));
    } else {
      tmp = removeOne(tmp, t);
      tmp = removeOne(tmp, t);
      case2 = Math.min(case2, meldOnlyNeedNum(tmp, history));
    }
  }
  return Math.min(case1, case2);
}
function meldOnlyNeedNum(tilesIn, history = {}, used = 0) {
  if (used > 4) return 999;
  const tiles = [...tilesIn].sort((a, b) => a - b);
  const key = histKey(tiles);
  if (key in history) return history[key];
  const size = tiles.length;
  if (size === 0) return 0;
  if (size === 1) return 2;
  if (size === 2) {
    const [p1, p2] = tiles;
    let case12 = 999;
    if (p1 < BOUNDARY && p2 - p1 <= 2) case12 = 1;
    const case22 = p1 === p2 ? 1 : 4;
    return Math.min(case12, case22);
  }
  const first = tiles[0];
  let case1 = 0;
  let left1 = tiles.slice(1);
  if (first >= BOUNDARY) {
    case1 = 999;
  } else {
    if (left1.includes(first + 1)) left1 = removeOne(left1, first + 1);
    else case1 += 1;
    if (left1.includes(first + 2)) left1 = removeOne(left1, first + 2);
    else case1 += 1;
    const res1 = meldOnlyNeedNum(left1, history, used);
    history[histKey(left1)] = res1;
    case1 += res1;
  }
  let case2 = 0;
  let left2 = tiles.slice(1);
  const cnt = countOf(left2, first);
  if (cnt >= 2) {
    left2 = removeOne(left2, first);
    left2 = removeOne(left2, first);
  } else if (cnt === 1) {
    left2 = removeOne(left2, first);
    case2 += 1;
  } else {
    case2 += 2;
  }
  const res2 = meldOnlyNeedNum(left2, history, used);
  history[histKey(left2)] = res2;
  case2 += res2;
  const result = Math.min(case1, case2);
  history[key] = result;
  return result;
}
function classifySuitTiles(tiles) {
  const chars = [];
  const bambs = [];
  const dots = [];
  let dragonRed = 0;
  for (const t of tiles) {
    if (CHARACTER.includes(t)) chars.push(t);
    else if (BAMBOO.includes(t)) bambs.push(t);
    else if (DOT.includes(t)) dots.push(t);
    else if (t === DRAGON_RED) dragonRed += 1;
  }
  return { chars, bambs, dots, dragonRed };
}
export {
  BOUNDARY,
  classifySuitTiles,
  meldOnlyNeedNum,
  meldWithPairNeedNum
};
