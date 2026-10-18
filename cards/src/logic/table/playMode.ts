import { GameType } from '@/config/gameTypes'
import { gameState } from '@/services/gameState'
import {
  GAME_TYPE as DOU_TYPE,
  MODULE_CODE as DOU_CODE,
  WS_ACTION as DOU_WS,
  TIMEOUT as DOU_TIMEOUT,
} from '@/logic/landlord/rules/room'
import {
  GAME_TYPE as LZ_TYPE,
  MODULE_CODE as LZ_CODE,
  TIMEOUT as LZ_TIMEOUT,
} from '@/logic/laizi/rules/room'
import {
  GAME_TYPE as PDK_TYPE,
  MODULE_CODE as PDK_CODE,
  TIMEOUT as PDK_TIMEOUT,
  HAND_SIZE as PDK_HAND,
} from '@/logic/pdk/rules/room'

export type TableKind = 'landlord' | 'laizi' | 'pdk'

export interface TableWsActions {
  JOIN: string
  START: string
  BID: string
  DOUBLE: string
  PLAY: string
  TRUST: string
  UNTRUST: string
  LEAVE: string
  DISMISS: string
  APPLY_DISMISS: string
  VOTE_DISMISS: string
}

export interface TableFeatures {
  kind: TableKind
  gameType: number
  moduleCode: string
  /** 叫抢地主 */
  hasBid: boolean
  /** 农民加倍 */
  hasDouble: boolean
  /** 显示底牌 */
  hasBottom: boolean
  /** 地主/农民身份与结算角色 */
  hasLandlordRole: boolean
  /** 能管必须管：可管时不显示「不出」 */
  forceBeat: boolean
  /** 红桃 3 首出提示 */
  spade3First: boolean
  /** 局中炸弹即时分 */
  bombSettle: boolean
  handSize: number
  dealMinLock: number
  playName: string
  ws: TableWsActions
  timeout: {
    WAIT_START: number
    BID: number
    DOUBLE: number
    PLAY: number
    PLAY_CANNOT_BEAT: number
  }
}

const ROOM_SHARED = Object.freeze({
  JOIN: 'Room.Join',
  LEAVE: 'Room.Leave',
  DISMISS: 'Room.Dismiss',
  APPLY_DISMISS: 'Room.ApplyDismiss',
  VOTE_DISMISS: 'Room.VoteDismiss',
})

function withRoomActions(mod: Partial<TableWsActions> & Pick<TableWsActions, 'START' | 'PLAY' | 'TRUST' | 'UNTRUST'>): TableWsActions {
  return {
    ...ROOM_SHARED,
    BID: mod.BID || mod.PLAY,
    DOUBLE: mod.DOUBLE || mod.PLAY,
    ...mod,
  }
}

export function resolveGameType(explicit?: number): number {
  const n = Number(explicit ?? gameState.room.gameType ?? DOU_TYPE)
  return Number.isFinite(n) && n > 0 ? n : DOU_TYPE
}

export function resolveTableKind(gameType?: number): TableKind {
  const t = resolveGameType(gameType)
  if (t === LZ_TYPE || t === GameType.LAIZI_LANDLORD) return 'laizi'
  if (t === PDK_TYPE || t === GameType.PAODEKUAI) return 'pdk'
  return 'landlord'
}

/** 按房间 gameType 解析桌面能力（规则分家、UI 共用） */
export function resolveTableFeatures(gameType?: number): TableFeatures {
  const kind = resolveTableKind(gameType)
  if (kind === 'laizi') {
    return {
      kind,
      gameType: LZ_TYPE,
      moduleCode: LZ_CODE,
      hasBid: true,
      hasDouble: true,
      hasBottom: true,
      hasLandlordRole: true,
      forceBeat: false,
      spade3First: false,
      bombSettle: false,
      handSize: 20,
      dealMinLock: 17,
      playName: '癞子斗地主',
      ws: withRoomActions({
        START: 'Lz.StartGame',
        BID: 'Lz.Bid',
        DOUBLE: 'Lz.Double',
        PLAY: 'Lz.Play',
        TRUST: 'Lz.Trust',
        UNTRUST: 'Lz.Untrust',
      }),
      timeout: {
        WAIT_START: LZ_TIMEOUT.WAIT_START,
        BID: LZ_TIMEOUT.BID ?? 30,
        DOUBLE: LZ_TIMEOUT.DOUBLE ?? 10,
        PLAY: LZ_TIMEOUT.PLAY,
        PLAY_CANNOT_BEAT: LZ_TIMEOUT.PLAY_CANNOT_BEAT ?? 5,
      },
    }
  }
  if (kind === 'pdk') {
    return {
      kind,
      gameType: PDK_TYPE,
      moduleCode: PDK_CODE,
      hasBid: false,
      hasDouble: false,
      hasBottom: false,
      hasLandlordRole: false,
      forceBeat: true,
      spade3First: true,
      bombSettle: true,
      handSize: PDK_HAND,
      dealMinLock: PDK_HAND,
      playName: '跑得快',
      ws: withRoomActions({
        START: 'Pdk.StartGame',
        PLAY: 'Pdk.Play',
        TRUST: 'Pdk.Trust',
        UNTRUST: 'Pdk.Untrust',
        BID: 'Pdk.Play',
        DOUBLE: 'Pdk.Play',
      }),
      timeout: {
        WAIT_START: PDK_TIMEOUT.WAIT_START,
        BID: 10,
        DOUBLE: 10,
        PLAY: PDK_TIMEOUT.PLAY,
        PLAY_CANNOT_BEAT: 5,
      },
    }
  }
  return {
    kind: 'landlord',
    gameType: DOU_TYPE,
    moduleCode: DOU_CODE,
    hasBid: true,
    hasDouble: true,
    hasBottom: true,
    hasLandlordRole: true,
    forceBeat: false,
    spade3First: false,
    bombSettle: false,
    handSize: 17,
    dealMinLock: 17,
    playName: '斗地主',
    ws: { ...DOU_WS },
    timeout: {
      WAIT_START: DOU_TIMEOUT.WAIT_START,
      BID: DOU_TIMEOUT.BID,
      DOUBLE: DOU_TIMEOUT.DOUBLE,
      PLAY: DOU_TIMEOUT.PLAY,
      PLAY_CANNOT_BEAT: DOU_TIMEOUT.PLAY_CANNOT_BEAT,
    },
  }
}

export function currentTableFeatures(): TableFeatures {
  return resolveTableFeatures()
}
