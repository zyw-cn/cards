# 迁移示例片段

## 1. 牌型规则：JS → TS

```ts
// ✅ logic/landlord/cards.ts
import { cardWeight, sortCards } from '@/logic/shared/card'

export type CardTypeResult = {
  type: string
  weight: number
  length: number
} | null

export function getCardType(cards: CardLike[]): CardTypeResult {
  if (!cards?.length) return null
  // …保留旧语义，去掉对 Vue/store 的引用
}
```

## 2. WS → 状态 → 事件 → 队列

```ts
// ✅ 伪结构（实现时写成完整模块）
wsService.on('game_push', (data) => {
  applyRoomSnapshot(data)           // 唯一写状态
  eventBus.emit('room:update', data)
  actionQueue.enqueue({ type: 'deal', payload: data })
})

// Pixi 侧
actionQueue.start((action) => {
  // 只读 gameState，跑 GSAP，不改 handCards
})
```

## 3. 切屏

```ts
// ❌ router.push({ name: 'landlord' })
// ✅
gameState.currentScreen = 'GAME_TABLE'
gameState.room.gameType = GameType.LANDLORD
```

## 4. HTTP

```ts
// ❌ axios.post('/room/create', body)
// ✅
import { http } from '@/services/http'
await http.post('/room/create', body)
```
