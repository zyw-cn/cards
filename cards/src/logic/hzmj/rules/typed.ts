/**
 * 给界面用的带类型入口。
 * rules/ops.ts、constants.ts 仍是旧语义（@ts-nocheck），直接 import 会被看成 any。
 */
import { SHOW_OP as RAW_SHOW_OP } from '@/logic/hzmj/rules/constants'
import { canWin as rawCanWin, getCanWinTiles as rawGetCanWinTiles } from '@/logic/hzmj/rules/ops'

export const SHOW_OP = {
  PASS: Number(RAW_SHOW_OP.PASS) || 1,
  CHOW: Number(RAW_SHOW_OP.CHOW) || 4,
  PONG: Number(RAW_SHOW_OP.PONG) || 5,
  KONG: Number(RAW_SHOW_OP.KONG) || 7,
  WIN: Number(RAW_SHOW_OP.WIN) || 13,
} as const

export type ShowOp = (typeof SHOW_OP)[keyof typeof SHOW_OP]

export function canWin(hand: number[], kingTiles: number[] = []): boolean {
  return Boolean(rawCanWin(hand, kingTiles))
}

export function getCanWinTiles(hand: number[], selectTile = 0, kingTiles: number[] = []): number[] {
  const raw = rawGetCanWinTiles(hand, selectTile, kingTiles) as unknown
  return Array.isArray(raw) ? raw.map((n) => Number(n)).filter((n) => n > 0) : []
}
