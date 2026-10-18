import { SHOW_OP } from '@/logic/hzmj/rules/typed'
import { assetUrl } from '@/services/assetUrl'
import { art } from '@/utils/art'

/** 结算等仍走 public/assets/hzmj/ui；桌面/座位/罗盘走 artres（Cocos majiang.fire） */
export const HZMJ_ASSET_ROOT = 'hzmj'
const UI = `${HZMJ_ASSET_ROOT}/ui`

export function hzmjAsset(rel: string): string {
  return assetUrl(rel)
}

const artMj = (...parts: string[]) => art('ui', 'mahjong', ...parts)
const artOp = (...parts: string[]) => art('ui', 'chi_peng_gang', ...parts)

/** 桌布：majiang.fire「背景可随意拉伸铺满」——禁止 cards mj.png / GameRoom2D bg0 */
export const hzmjTableCloth = art('bg', 'bg_stretch_fill.jpg')
/** 桌心龙纹 */
export const hzmjDragonCenter = art('hero', 'room', 'bg', 'dragon_center.png')

/** client majiang.fire 桌角 */
export function hzmjTableCorner(key: 1 | 2 | 3 | 4): string {
  return art('room', 'bg', `table_corner${key}.png`)
}

export const hzmjGameTitle = art('room', 'bg', 'game_title6.png')
export const hzmjRoomNumbers = art('room', 'room_numbers1.png')
export const hzmjSettingBtn = art('room', 'setting1.png')
/** readybtn → ready1.png */
export const hzmjReadyBtn = art('room', 'ready1.png')
/** 菜单 leave / trust / setting（从 beimi-image-other 切出） */
export const hzmjMenuLeave = art('room', 'menu', 'leave.png')
export const hzmjMenuTrust = art('room', 'menu', 'trust.png')
export const hzmjMenuSetting = art('room', 'menu', 'setting.png')

/**
 * 罗盘：切边 time11 + searchlight 光柱（majiang.fire）
 * ui0 自己=current/time3；ui1 右=time5；ui2 对家=time2；ui3 左=time4
 */
export const hzmjCompassIdle = art('ui', 'compass_edge', 'time11.png')
export const hzmjCompassBeamByUiSeat = [
  art('ui', 'compass_edge', 'time3.png'), // 0 自己（下）current
  art('ui', 'compass_edge', 'time5.png'), // 1 右
  art('ui', 'compass_edge', 'time2.png'), // 2 对家（上）
  art('ui', 'compass_edge', 'time4.png'), // 3 左
] as const

/**
 * majiang.fire searchlight 子节点相对 searchlight 容器的偏移（Cocos Y↑）
 * 容器本身相对 timer：(25, -126)
 */
export const HZMJ_SEARCHLIGHT = {
  /** 相对罗盘中心（CSS Y↓） */
  root: { x: 25, y: 126 },
  /** 相对 searchlight 根：current / right / top / left */
  children: [
    { x: 0, y: 1, w: 426, h: 203 }, // current → time3
    { x: 104, y: -153, w: 204, h: 425 }, // right → time5
    { x: 0, y: -255, w: 422, h: 201 }, // top → time2
    { x: -153, y: -152, w: 204, h: 425 }, // left → time4
  ],
} as const

/** mjplayer 头像框 / 默认头 */
export const hzmjAvatarFrame = art(
  'room',
  'player_avatar',
  'edge_slices',
  'player_avatar_glass_edge.png',
)
export const hzmjAvatarDefault = art(
  'room',
  'player_avatar',
  'edge_slices',
  'player_avatar_default_edge.png',
)
export const hzmjBankerImg = art('room', 'player_avatar', 'banker.png')
export const hzmjRoomOwner = art('room', 'player_avatar', 'room_creater.png')
export const hzmjNameBase = art('room', 'player_info', 'base_name.png')
export const hzmjCoinBase = art('room', 'player_info', 'base_coin.png')
export const hzmjCoinIcon = art('room', 'player_info', 'coin.png')

/** @deprecated 旧 GameRoom2D / cards 壳，勿当桌布 */
export const hzmjPrepareBtn = hzmjReadyBtn
export const hzmjDealerImg = hzmjBankerImg
export const hzmjHeadFrame = hzmjAvatarFrame
export const hzmjNameBg = hzmjNameBase

export const hzmjSettleWinBg = hzmjAsset(`${UI}/BackGround/settlement_win.png`)
export const hzmjSettleFailBg = hzmjAsset(`${UI}/BackGround/settlement_fail.png`)
export const hzmjRoomInfoBg = hzmjAsset(`${UI}/GameRoom2DUI/roominfo.png`)
export const hzmjOpBg = hzmjAsset(`${UI}/GameRoomUI/operation_bg.png`)
export const hzmjKingBg = hzmjAsset(`${UI}/GameRoomUI/king_tile_bg.png`)
export const hzmjKingTitle = hzmjAsset(`${UI}/GameRoomUI/king_tile_title.png`)
export const hzmjKingWhite = hzmjAsset(`${UI}/GameRoomUI/king_tile_white.png`)
export const hzmjKingMark = hzmjAsset(`${UI}/GameRoomUI/kingtilemark.png`)
export const hzmjPointer = hzmjAsset(`${UI}/GameRoomUI/gameroom_tile_pointer.png`)
export const hzmjNeedDiscard = hzmjAsset(`${UI}/GameRoomUI/need_discard.png`)
export const hzmjCanWinTitle = hzmjAsset(`${UI}/GameRoomUI/canwin_tile.png`)
export const hzmjCanWinFrame = hzmjAsset(`${UI}/GameRoomUI/canwin_tiles_small_frame.png`)
export const hzmjCanWinAny = hzmjAsset(`${UI}/GameRoomUI/canwin_anytile.png`)
export const hzmjTingBtn = hzmjAsset(`${UI}/GameRoomUI/wintips_btn.png`)

export const hzmjWinTitle = hzmjAsset(`${UI}/SettlementUI/win_title.png`)
export const hzmjFailTitle = hzmjAsset(`${UI}/SettlementUI/fail_title.png`)
export const hzmjDrawTitle = hzmjAsset(`${UI}/SettlementUI/dogfull_title.png`)
export const hzmjSettleConfirm = hzmjAsset(`${UI}/SettlementUI/confirm_btn.png`)
export const hzmjResultTitle = hzmjAsset(`${UI}/SettlementUI/total.png`)
export const hzmjResultBtn = hzmjAsset(`${UI}/SettlementUI/result_btn.png`)
export const hzmjMobao = hzmjAsset(`${UI}/SettlementUI/mobao.png`)
export const hzmjDrawWin = hzmjAsset(`${UI}/SettlementUI/draw_win.png`)
export const hzmjGiveWin = hzmjAsset(`${UI}/SettlementUI/give_win.png`)

export const hzmjAgreeBtn = hzmjAsset(`${UI}/ApplyCloseUI/agree_btn.png`)
export const hzmjRefuseBtn = hzmjAsset(`${UI}/ApplyCloseUI/refuse_btn.png`)
export const hzmjCloseTitle = hzmjAsset(`${UI}/ApplyCloseUI/title.png`)
export const hzmjCloseFrame = hzmjAsset(`${UI}/ApplyCloseUI/frame.png`)
export const hzmjCloseAgree = hzmjAsset(`${UI}/ApplyCloseUI/agree.png`)
export const hzmjCloseRefuse = hzmjAsset(`${UI}/ApplyCloseUI/refuse.png`)
export const hzmjCloseUnselect = hzmjAsset(`${UI}/ApplyCloseUI/unselect.png`)

/** @deprecated */
export const hzmjCompassIdleLegacy = art('ui', 'compass_center', 'time1.png')
export const hzmjCompassByUiSeat = [
  art('ui', 'compass_center', 'time3.png'),
  art('ui', 'compass_center', 'time5.png'),
  art('ui', 'compass_center', 'time2.png'),
  art('ui', 'compass_center', 'time4.png'),
] as const
/** cards 东南西北罗盘，走 artres，避免 CDN 路径把盘丢掉 */
export const hzmjCompassBg = art('ui', 'compass', 'curplayer_bg.png')
export const hzmjCompassDirs = [
  art('ui', 'compass', 'east.png'),
  art('ui', 'compass', 'south.png'),
  art('ui', 'compass', 'west.png'),
  art('ui', 'compass', 'north.png'),
] as const

export function hzmjFaceKey(tile: number): string {
  const t = Number(tile)
  if (t >= 1 && t <= 9) return `wan${t}`
  if (t >= 31 && t <= 39) return `suo${t - 30}`
  if (t >= 51 && t <= 59) return `tong${t - 50}`
  if (t >= 71 && t <= 77) return `wind${t - 70}`
  if (t >= 91 && t <= 94) return `flower${t - 90}`
  if (t >= 95 && t <= 98) return `season${t - 94}`
  return ''
}

export function hzmjFace(tile: number, _size: 'big' | 'small' = 'small'): string {
  const key = hzmjFaceKey(tile)
  if (key) return artMj('faces', `${key}.png`)
  return hzmjAsset(`${HZMJ_ASSET_ROOT}/tiles/small/mahjong_small_${tile}.png`)
}

export function hzmjFrame(kind: 'hand' | 'desk' | 'back' = 'hand'): string {
  if (kind === 'hand') return `${artMj('self_hand.png')}?v=green`
  if (kind === 'back') return `${artMj('self_kong_back.png')}?v=green`
  return artMj('self_discard_tile.png')
}

export function hzmjOpBtn(op: number, ops: number[]): string {
  if (op === SHOW_OP.PASS && ops.includes(SHOW_OP.WIN)) {
    return artOp('tip_skip.png')
  }
  const file: Record<number, string> = {
    [SHOW_OP.CHOW]: 'tip_chi.png',
    [SHOW_OP.PONG]: 'tip_peng.png',
    [SHOW_OP.KONG]: 'tip_gang.png',
    [SHOW_OP.WIN]: 'tip_hu.png',
    [SHOW_OP.PASS]: 'tip_skip.png',
  }
  return artOp(file[op] || 'tip_skip.png')
}

export function hzmjDice(value: number): string {
  const n = Math.min(6, Math.max(1, Number(value) || 1))
  return hzmjAsset(`${HZMJ_ASSET_ROOT}/effect/sezi_right_${n}.png`)
}

export const HZMJ_ART_FRAMES = Object.freeze({
  hand: `${artMj('self_hand.png')}?v=green`,
  up: artMj('self_discard_tile.png'),
  desk: artMj('self_discard_tile.png'),
  sideHand: `${artMj('side_hand.png')}?v=cards`,
  /** 左右出牌/碰杠：与 cards 相同的 side desk / side down */
  sideDesk: `${artMj('side_discard.png')}?v=cards`,
  sideDown: `${artMj('side_kong.png')}?v=cards`,
  topHand: `${artMj('top_hand.png')}?v=green`,
  back: `${artMj('self_kong_back.png')}?v=green`,
})
