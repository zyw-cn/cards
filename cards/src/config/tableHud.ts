import { assetUrl } from '@/services/assetUrl'

/**
 * 各玩法共用牌桌 Header / Footer 切图。
 * 放到 public/assets/images/common/hud/（CDN 同相对路径）。
 */
export const TABLE_HUD_DIR = 'images/common/hud'

export const TableHudAsset = Object.freeze({
  bar: `${TABLE_HUD_DIR}/hud_bar.png`,
  back: `${TABLE_HUD_DIR}/btn_back.png`,
  dismiss: `${TABLE_HUD_DIR}/btn_dismiss.png`,
  rule: `${TABLE_HUD_DIR}/btn_rule.png`,
  trust: `${TABLE_HUD_DIR}/btn_trust.png`,
  trustOn: `${TABLE_HUD_DIR}/btn_trust_on.png`,
  settings: `${TABLE_HUD_DIR}/btn_settings.png`,
  /** 底栏左侧形象图（约占遮罩一半宽） */
  footerFigure: `${TABLE_HUD_DIR}/footer_figure.png`,
  badgeLandlord: `${TABLE_HUD_DIR}/badge_landlord.png`,
  badgeFarmer: `${TABLE_HUD_DIR}/badge_farmer.png`,
  iconBean: `${TABLE_HUD_DIR}/icon_bean.png`,
  iconMulti: `${TABLE_HUD_DIR}/icon_multi.png`,
  btnChat: `${TABLE_HUD_DIR}/btn_chat.png`,
})

export function tableHudUrl(rel: string): string {
  return assetUrl(rel)
}

/** Header 高度（设计坐标） */
export const TABLE_HUD_BAR_H = 56

/** Footer 遮罩高度（设计坐标），中层手牌需抬高避开 */
export const TABLE_FOOTER_H = 64
