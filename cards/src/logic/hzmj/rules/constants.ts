// @ts-nocheck — 从 game 迁移的红中规则，保持语义
const GAME_NAME = "\u7EA2\u4E2D\u9EBB\u5C06";
const OP = {
  PASS: 8,
  DRAW: 16,
  DISCARD: 24,
  CHOW: 32,
  PONG: 40,
  KONG_WREATH: 48,
  EXPOSED_KONG: 56,
  CONTINUE_KONG: 57,
  CONCEALED_KONG: 58,
  CUT: 80,
  READY: 88,
  DRAW_END: 96,
  DRAW_WIN: 104,
  KONG_WIN: 105,
  WREATH_WIN: 106,
  GIVE_WIN: 107
};
const SHOW_OP = {
  PASS: 1,
  CHOW: 4,
  PONG: 5,
  KONG: 7,
  WIN: 13
};
const ROOM_WAITING = 0;
const ROOM_PLAYING = 1;
/** 对齐桌心出牌倒计时（秒）；与后端 HzmjTimeoutHandler::PLAY_TIMEOUT 一致 */
const FAKE_COUNTDOWN = 15;
/** 对齐 Cocos：开局发牌动画额外延长 */
const FAKE_BEGIN_ANIMATION_TIME = 5;
const CHARACTER = [1, 2, 3, 4, 5, 6, 7, 8, 9];
const BAMBOO = [31, 32, 33, 34, 35, 36, 37, 38, 39];
const DOT = [51, 52, 53, 54, 55, 56, 57, 58, 59];
const DRAGON_RED = 75;
const DRAGON_GREEN = 76;
const DRAGON_WHITE = 77;
const DRAGONS = [DRAGON_RED, DRAGON_GREEN, DRAGON_WHITE];
const WIND_EAST = 71;
const WIND_SOUTH = 72;
const WIND_WEST = 73;
const WIND_NORTH = 74;
const WINDS = [WIND_EAST, WIND_SOUTH, WIND_WEST, WIND_NORTH];
const KING_TILE = DRAGON_RED;
const NORMAL_ROOM = 0;
const AGENT_ROOM = 1;
const CLUB_ROOM = 2;
const NORMAL_PAY_MODE = 0;
const AA_PAY_MODE = 1;
const AGENT_PAY_MODE = 2;
const CLUB_PAY_MODE = 3;
const TILE_LABEL = {
  1: "\u4E00\u4E07",
  2: "\u4E8C\u4E07",
  3: "\u4E09\u4E07",
  4: "\u56DB\u4E07",
  5: "\u4E94\u4E07",
  6: "\u516D\u4E07",
  7: "\u4E03\u4E07",
  8: "\u516B\u4E07",
  9: "\u4E5D\u4E07",
  31: "\u4E00\u6761",
  32: "\u4E8C\u6761",
  33: "\u4E09\u6761",
  34: "\u56DB\u6761",
  35: "\u4E94\u6761",
  36: "\u516D\u6761",
  37: "\u4E03\u6761",
  38: "\u516B\u6761",
  39: "\u4E5D\u6761",
  51: "\u4E00\u7B52",
  52: "\u4E8C\u7B52",
  53: "\u4E09\u7B52",
  54: "\u56DB\u7B52",
  55: "\u4E94\u7B52",
  56: "\u516D\u7B52",
  57: "\u4E03\u7B52",
  58: "\u516B\u7B52",
  59: "\u4E5D\u7B52",
  71: "\u4E1C",
  72: "\u5357",
  73: "\u897F",
  74: "\u5317",
  75: "\u4E2D",
  76: "\u53D1",
  77: "\u767D"
};
const SHORT_TILE_LABEL = {
  1: "1\u4E07",
  2: "2\u4E07",
  3: "3\u4E07",
  4: "4\u4E07",
  5: "5\u4E07",
  6: "6\u4E07",
  7: "7\u4E07",
  8: "8\u4E07",
  9: "9\u4E07",
  31: "1\u6761",
  32: "2\u6761",
  33: "3\u6761",
  34: "4\u6761",
  35: "5\u6761",
  36: "6\u6761",
  37: "7\u6761",
  38: "8\u6761",
  39: "9\u6761",
  51: "1\u7B52",
  52: "2\u7B52",
  53: "3\u7B52",
  54: "4\u7B52",
  55: "5\u7B52",
  56: "6\u7B52",
  57: "7\u7B52",
  58: "8\u7B52",
  59: "9\u7B52",
  71: "\u4E1C",
  72: "\u5357",
  73: "\u897F",
  74: "\u5317",
  75: "\u4E2D",
  76: "\u53D1",
  77: "\u767D"
};
export {
  AA_PAY_MODE,
  AGENT_PAY_MODE,
  AGENT_ROOM,
  BAMBOO,
  CHARACTER,
  CLUB_PAY_MODE,
  CLUB_ROOM,
  DOT,
  DRAGONS,
  DRAGON_GREEN,
  DRAGON_RED,
  DRAGON_WHITE,
  GAME_NAME,
  KING_TILE,
  NORMAL_PAY_MODE,
  NORMAL_ROOM,
  OP,
  FAKE_BEGIN_ANIMATION_TIME,
  FAKE_COUNTDOWN,
  ROOM_PLAYING,
  ROOM_WAITING,
  SHORT_TILE_LABEL,
  SHOW_OP,
  TILE_LABEL,
  WINDS,
  WIND_EAST,
  WIND_NORTH,
  WIND_SOUTH,
  WIND_WEST
};
