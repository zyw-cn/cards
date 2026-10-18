/** 炸金花房间切图（对齐 game assets.ui.flower → public/assets/images/flower） */
import { assetUrl } from '@/services/assetUrl'

export const flowerUi = {
  footer: assetUrl('images/flower/footer.png'),
  home: assetUrl('images/flower/home.png'),
  note: assetUrl('images/flower/note4.png'),
  rule: assetUrl('images/flower/icon_game_rule.png'),
  sound: assetUrl('images/flower/icon_sound61.png'),
  message: assetUrl('images/flower/icon_game_message.png'),
  clock: assetUrl('images/flower/clock.png'),
  look: assetUrl('images/flower/lookcard.png'),
  totalScore: assetUrl('images/flower/totalScore.png'),
  readyBtn: assetUrl('images/flower/readyButton.png'),
  playerBg: assetUrl('images/flower/player.png'),
  btnLeft: assetUrl('images/flower/button1.png'),
  btnRight: assetUrl('images/flower/button2.png'),
  zhuang: assetUrl('images/flower/zhuang.png'),
  playerWin: assetUrl('images/flower/playerWin.png'),
} as const
