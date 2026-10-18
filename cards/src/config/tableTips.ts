/**
 * 牌桌 tip 切图（英文文件名）。
 * 目录：`src/assets/images/common/tip/`
 */
export const TABLE_TIP_DIR = 'images/common/tip'

/** key → 相对 assets 的路径（便于 CDN 同步） */
export const TableTipAsset = Object.freeze({
  jiaodizhu: `${TABLE_TIP_DIR}/jiaodizhu_tip.png`,
  qiangdizhu: `${TABLE_TIP_DIR}/qiangdizhu_tip.png`,
  buqiang: `${TABLE_TIP_DIR}/buqiang_tip.png`,
  bujiao: `${TABLE_TIP_DIR}/bujiao_tip.png`,
  jiabei: `${TABLE_TIP_DIR}/jiabei_tip.png`,
  bujiabei: `${TABLE_TIP_DIR}/bujiabei_tip.png`,
  buyao: `${TABLE_TIP_DIR}/buyao_tip.png`,
  baojing: `${TABLE_TIP_DIR}/baojing_tip.png`,
  ready: `${TABLE_TIP_DIR}/pm_ready.png`,
  tongyiJiesan: `${TABLE_TIP_DIR}/tongyijiesan_tip.png`,
  butongyiJiesan: `${TABLE_TIP_DIR}/butongyijiesan_tip.png`,
  applyDismiss: `${TABLE_TIP_DIR}/apply_dismiss_tip.png`,
  tuoguan: `${TABLE_TIP_DIR}/tuoguan_tip.png`,
  daBuGuoShangJia: `${TABLE_TIP_DIR}/dbgsjp_tip.png`,
  sanJiaBuJiao: `${TABLE_TIP_DIR}/sjdbjdz_tip.png`,
  chuPaiBuFuHe: `${TABLE_TIP_DIR}/cpbfhgz_tip.png`,
  chongXinFaPai: `${TABLE_TIP_DIR}/cxfp_tip.png`,
})

export type TableTipKey = keyof typeof TableTipAsset
