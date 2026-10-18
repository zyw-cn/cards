import fs from 'node:fs'

function bump(file, reps) {
  let c = fs.readFileSync(file, 'utf8')
  let n = 0
  for (const [a, b] of reps) {
    const before = c
    c = c.split(a).join(b)
    if (c !== before) n += 1
    else console.warn('miss', file, JSON.stringify(a).slice(0, 60))
  }
  fs.writeFileSync(file, c)
  console.log('ok', file, n, '/', reps.length)
}

bump('src/views/LandlordRoomView.vue', [
  ['class="h-14 w-14 transition active:scale-95"', 'class="h-[68px] w-[68px] transition active:scale-95"'],
  ['h-[72px] w-[240px]', 'h-[86px] w-[280px]'],
  ['h-[72px] w-[210px]', 'h-[86px] w-[240px]'],
  ['h-[72px] w-[168px]', 'h-[86px] w-[196px]'],
  ['flex h-[72px] w-[72px]', 'flex h-[86px] w-[86px]'],
  ['h-[34px] object-contain', 'h-[40px] object-contain'],
  ['clock-sec text-[22px]', 'clock-sec text-[24px]'],
  ['h-[68px] w-[136px]', 'h-[80px] w-[156px]'],
])

bump('src/views/NiuRoomView.vue', [
  ['pointer-events-auto h-14 border-0', 'pointer-events-auto h-16 border-0'],
  ['class="h-14 w-auto"', 'class="h-16 w-auto"'],
  ['height: 72px;', 'height: 86px;'],
  ['height: 64px;', 'height: 76px;'],
  [
    `.niu-ops__coin {
  min-width: 96px;
  height: 56px;`,
    `.niu-ops__coin {
  min-width: 112px;
  height: 68px;`,
  ],
  ['max-height: 34px;', 'max-height: 40px;'],
  ['width: calc(64px * 222 / 93);', 'width: calc(76px * 222 / 93);'],
  ['width: calc(64px * 182 / 93);', 'width: calc(76px * 182 / 93);'],
  ['width: calc(56px * 222 / 93);', 'width: calc(68px * 222 / 93);'],
  [
    `.gf-ops-btn--sm {
  height: 56px;`,
    `.gf-ops-btn--sm {
  height: 68px;`,
  ],
  [
    `height: 52px;
  line-height: 52px;`,
    `height: 60px;
  line-height: 60px;`,
  ],
  ['font-size: 22px;', 'font-size: 24px;'],
])

bump('src/views/FlowerRoomView.vue', [
  ['pointer-events-auto h-14 border-0', 'pointer-events-auto h-16 border-0'],
  ['pointer-events-auto mb-1 h-14 border-0', 'pointer-events-auto mb-1 h-16 border-0'],
  ['class="h-14 w-auto"', 'class="h-16 w-auto"'],
  ['height: 72px;', 'height: 86px;'],
  ['height: 64px;', 'height: 76px;'],
  ['height: 56px;', 'height: 68px;'],
  ['min-width: 96px;', 'min-width: 112px;'],
  ['min-width: 108px;', 'min-width: 124px;'],
  ['font-size: 22px;', 'font-size: 24px;'],
  ['font-size: 18px;', 'font-size: 20px;'],
  [
    `height: 52px;
  line-height: 52px;`,
    `height: 60px;
  line-height: 60px;`,
  ],
])

bump('src/views/LoginView.vue', [
  [
    `.auth-row {
  position: relative;
  display: flex;
  height: 50px;`,
    `.auth-row {
  position: relative;
  display: flex;
  height: 60px;`,
  ],
  ['font-size: 15px;', 'font-size: 17px;'],
  [
    `.auth-input {
  width: 100%;
  height: 46px;`,
    `.auth-input {
  width: 100%;
  height: 56px;`,
  ],
  ['height: 2.75rem;', 'height: 3.25rem;'],
  ['font-size: 0.8rem;', 'font-size: 0.95rem;'],
  [
    `width: 260px;
  height: 58px;`,
    `width: 300px;
  height: 70px;`,
  ],
])

bump('src/components/JoinRoomModal.vue', [
  ['width="700px" min-height="660px"', 'width="760px" min-height="700px"'],
  ['class="mb-3 h-12 object-contain"', 'class="mb-3 h-14 object-contain"'],
  ['class="relative flex h-16 w-16 items-center', 'class="relative flex h-[76px] w-[76px] items-center'],
  ['class="relative z-[1] h-9 object-contain', 'class="relative z-[1] h-11 object-contain'],
  ['class="flex h-[68px] w-[136px]', 'class="flex h-[80px] w-[156px]'],
  ['class="h-12 object-contain drop-shadow"', 'class="h-14 object-contain drop-shadow"'],
])

bump('src/components/hzmj/HzmjOpBar.vue', [
  ['class="block min-h-[64px] w-[128px]', 'class="block min-h-[76px] w-[148px]'],
  ["const BTN_STEP = 138", 'const BTN_STEP = 158'],
  ["minHeight: '72px'", "minHeight: '86px'"],
  ["minWidth: '128px'", "minWidth: '148px'"],
])

bump('src/components/hzmj/HzmjRoomHud.vue', [
  ['w: 84, h: 80, ax: 0.5, ay: 0.5 }, 100),', 'w: 96, h: 92, ax: 0.5, ay: 0.5 }, 100),'],
  ['w: 84, h: 84, ax: 0.5, ay: 0.5 }, 320),', 'w: 96, h: 96, ax: 0.5, ay: 0.5 }, 320),'],
])
