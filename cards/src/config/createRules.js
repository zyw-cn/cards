/** 创房选项：斗地主等本地常量 + flower/niu 从 logic 再导出 */

export const ASK_MODE = Object.freeze({
  ROTATE: 1,
  GRAB: 2,
  WINNER: 3,
})

/** 斗地主 / 赖子：大厅玩法里补充解散说明块 */
const DISMISS_PLAY_NOTE = Object.freeze({
  title: '解散说明',
  lines: [
    '未开局：仅房主可解散房间。',
    '等人开局或局间等待：超过 3 分钟未开始，将自动解散房间并退费（未开局退整张房卡；已打过局按剩余局数折算金币返还房主）。',
    '进入牌局后：返回大厅不解散，将自动托管（约 2 秒后代打）并保留座位。',
    '进入牌局后：不允许单方面解散，须全员同意方可解散。',
  ],
})

export const landlordRules = Object.freeze({
  playNotes: [DISMISS_PLAY_NOTE],
  payModes: [
    { value: 1, label: 'AA支付', desc: '本局费用按底分由三人平分' },
    {
      value: 2,
      label: '赢家支付',
      desc: '本局费用按底分由胜利方承担（地主赢付全款；农民赢则两农民各付一半）',
    },
    { value: 3, label: '我支付', desc: '本局费用按底分由房主一人支付' },
  ],
  dfOptions: [
    { value: 0.5, label: '0.5金币' },
    { value: 1, label: '1金币' },
    { value: 2, label: '2金币' },
    { value: 5, label: '5金币' },
    { value: 10, label: '10金币' },
  ],
  askModes: [
    { value: ASK_MODE.ROTATE, label: '轮流地主' },
    { value: ASK_MODE.GRAB, label: '抢庄地主' },
    { value: ASK_MODE.WINNER, label: '胜者连庄' },
  ],
  seats: 3,
  idleTip:
    '创建只扣房卡；未开局仅房主可解散；等人/局间超 3 分钟未开始将自动解散并退费；进入牌局后返回大厅将自动托管（约 2 秒后代打），不允许单方面解散，须全员同意方可解散；长时间无人叫地主将自动解散',
})

export const laiziRules = Object.freeze({
  ...landlordRules,
  idleTip:
    '创建只扣房卡；未开局仅房主可解散；等人/局间超 3 分钟未开始将自动解散并退费；进入牌局后返回大厅将自动托管（约 2 秒后代打），不允许单方面解散，须全员同意方可解散；长时间无人叫地主将自动解散',
})

export const pdkRules = Object.freeze({
  payModes: [
    { value: 1, label: 'AA支付', desc: '本局费用按底分由三人平分' },
    { value: 2, label: '赢家支付', desc: '本局费用按底分由胜利方承担' },
    { value: 3, label: '我支付', desc: '本局费用按底分由房主一人支付' },
  ],
  dfOptions: [
    { value: 0.5, label: '0.5金币' },
    { value: 1, label: '1金币' },
    { value: 2, label: '2金币' },
    { value: 5, label: '5金币' },
    { value: 10, label: '10金币' },
  ],
  bombMultipleOptions: [
    { value: 1, label: '1分' },
    { value: 2, label: '2分' },
    { value: 5, label: '5分' },
    { value: 10, label: '10分' },
    { value: 20, label: '20分' },
  ],
  seats: 3,
  idleTip:
    '光山跑得快：飞机必须带牌，N 组三同张带 N 张单或 N 对；不带牌只能最后一手甩尾。飞机不是炸弹。',
  playNotes: [
    {
      title: '基本',
      lines: [
        '三人 16 张。持红桃 3 先出，不必打出红桃 3。能管必须管。',
        '顺子至少 5 张，连对至少 2 对。',
        '下家剩 1 张时，出非最大单算放走：下家若赢，其他人的剩牌分由你付。下家剩 2 张不限制。',
        '炸弹和三个 A 当场结算。',
      ],
    },
    {
      title: '解散说明',
      lines: [
        '未开局：仅房主可解散房间。',
        '等人开局或局间等待：超过 3 分钟未开始，将自动解散房间并退费（未开局退整张房卡；已打过局按剩余局数折算金币返还房主）。',
        '进入牌局后：返回大厅不解散，将自动托管（约 2 秒后代打）并保留座位。',
        '进入牌局后：不允许单方面解散，须全员同意方可解散。',
      ],
    },
    {
      title: '三带',
      lines: [
        '三带一随时可出。三带二必须带一对，不能带两张零牌。',
        '三不带只能最后一手。比大小只看三张。',
      ],
    },
    {
      title: '四带',
      lines: [
        '四带二单、四带两对都不是炸弹，压不过炸弹。没有四带一、四带三。',
        '比大小只看四张。',
      ],
    },
    {
      title: '飞机',
      lines: [
        '两组及以上相连三同张。必须带同样张数的单或对，带法要跟上家一致。',
        '不带牌只能最后一手。不是炸弹，比大小只看主体。',
      ],
    },
  ],
})

export const hzmjRules = Object.freeze({
  seats: 4,
  payModes: [
    { value: 0, label: '房主支付' },
    { value: 1, label: 'AA支付' },
  ],
  roundOptions: [
    { value: 8, label: '8局' },
    { value: 16, label: '16局' },
    { value: 24, label: '24局' },
  ],
  luckyOptions: [1, 2, 3, 4, 6].map((n) => ({ value: n, label: String(n) })),
  playNotes: [
    {
      title: '玩法',
      lines: [
        '红中是癞子。不能吃，不能点炮，只能自摸胡。可以碰、杠，也可以抢杠胡。',
      ],
    },
    {
      title: '杠',
      lines: [
        '明杠：杠的人加 3，放杠的人扣 3。',
        '续杠：杠的人向其余每家各收 1。',
        '暗杠：杠的人向其余每家各收 2。',
        '以上是底，入账时再乘底分和庄家倍数。杠完立刻记进本局总分。',
      ],
    },
    {
      title: '奖码',
      lines: [
        '选 1 为一码全中，只翻 1 张。红中按 10 算，其他按牌面点数。分数底是点数加 2。',
        '选 2、3、4、6 时翻对应张数。一万、五万、九万，一条、五条、九条，一筒、五筒、九筒和红中算中码。胡牌手里没有红中，多翻 1 张。分数底是 2 乘（中码张数加 1），一张没中也有底分。',
        '自摸时赢家向其余在座的每家各收一份。两人局只收另外 1 家，三人局收 2 家，四人局收 3 家。抢杠胡按同样的家数计，全部由被抢杠的人出。最后再乘底分和庄家倍数。',
      ],
    },
    {
      title: '解散说明',
      lines: [
        '未开局：仅房主可解散房间。',
        '等人开局或局间等待：超过 3 分钟未开始，将自动解散房间并退费（未开局退整张房卡；已打过局按剩余局数折算金币返还房主）。',
        '进入牌局后：返回大厅不解散，将自动托管（约 2 秒后代打）并保留座位。',
        '进入牌局后：不允许单方面解散，须全员同意方可解散。',
      ],
    },
  ],
  dfOptions: [1, 2, 3, 5, 10].map((n) => ({ value: n, label: `${n}金币` })),
  playerNumOptions: [
    { value: 2, label: '2人' },
    { value: 3, label: '3人' },
    { value: 4, label: '4人' },
  ],
  idleTip:
    '未开局仅房主可解散；等人/局间超 3 分钟未开始将自动解散并退费；进入牌局后返回大厅将自动托管（约 2 秒后代打），不允许单方面解散，须全员同意方可解散',
})

/** 斗牛 / 炸金花：与 cards logic 同源 */
export {
  createRoomModes,
  niuCreateRoomRules,
  niuMaxPlayers,
  niuMinPlayers,
} from '@/logic/niu/rules/room'

export {
  flowerCreateRoomRules,
  flowerMaxPlayers,
  flowerMinPlayers,
  flowerRules,
  MAX_FOLLOW_OPTIONS,
  menMaxFromFollow,
  SELECT_POINTS,
} from '@/logic/flower/rules/room'
