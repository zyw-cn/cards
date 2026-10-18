/** 斗牛房间切图（对齐 game assets.ui.niu → public/assets/images/niu） */
import { assetUrl } from '@/services/assetUrl'

export const niuUi = {
  footer: assetUrl('images/niu/footer_bull.png'),
  home: assetUrl('images/niu/home.png'),
  note: assetUrl('images/niu/note4.png'),
  rule: assetUrl('images/niu/icon_game_rule.png'),
  sound: assetUrl('images/niu/icon_sound61.png'),
  message: assetUrl('images/niu/icon_game_message.png'),
  clock: assetUrl('images/niu/bull_clock.png'),
  btnOrange: assetUrl('images/niu/bull_button_orange.png'),
  btnBlue: assetUrl('images/niu/bull_button_blue.png'),
  rob: assetUrl('images/niu/bull_text_rob.png'),
  notRob: assetUrl('images/niu/bull_text_notrob.png'),
  readyBtn: assetUrl('images/niu/readyButton.png'),
  playerBg: assetUrl('images/niu/player.png'),
  zhuang: assetUrl('images/niu/zhuang.png'),
  playerWin: assetUrl('images/niu/playerWin.png'),
  look: assetUrl('images/flower/lookcard.png'),
  btnLook: assetUrl('images/niu/button1.png'),
  btnShow: assetUrl('images/niu/button2.png'),
} as const
