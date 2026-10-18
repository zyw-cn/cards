/** 与 cards / 后端 GameType 对齐 */
export const GameType = Object.freeze({
  LANDLORD: 1,
  NIU: 2,
  ZJH: 3,
  PAODEKUAI: 4,
  LAIZI_LANDLORD: 5,
  HZMJ: 7,
})

export const hallGames = [
  { id: 4, name: '跑得快', seats: 3, gameType: GameType.PAODEKUAI, route: 'pdk' },
  { id: 7, name: '红中麻将', seats: 4, gameType: GameType.HZMJ, route: 'hzmj' },
  { id: 1, name: '斗地主', seats: 3, gameType: GameType.LANDLORD, route: 'landlord' },
  { id: 5, name: '癞子斗地主', seats: 3, gameType: GameType.LAIZI_LANDLORD, route: 'laizi' },
  { id: 3, name: '炸金花', seats: 6, gameType: GameType.ZJH, route: 'flower' },
  { id: 2, name: '斗牛', seats: 6, gameType: GameType.NIU, route: 'niu' },
]

export function findHallGame(gameType) {
  return hallGames.find((g) => g.gameType === gameType)
}
