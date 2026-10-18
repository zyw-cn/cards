<?php

declare(strict_types=1);

namespace App\Service\Member;

use App\Dao\Member\MemberRoomCardDao;
use App\Dao\Member\MemberRoomCardFlowDao;
use App\Dao\Member\RoomCardTypeDao;
use App\Exception\ApiException;
use App\Model\Member\MemberRoomCard;
use App\Service\BaseService;
use Hyperf\DbConnection\Db;
use function Hyperf\Support\make;

/**
 * 房卡：类型 / 我的房卡 / 流水。
 * 金币购卡、创房扣卡、未开局返还。
 */
class RoomCardService extends BaseService
{
    /** 购买（后台金币兑换） */
    public const FLOW_BUY = 1;

    /** 系统/后台赠送 */
    public const FLOW_GIFT = 2;

    /** 建房消耗 */
    public const FLOW_CREATE_ROOM = 3;

    /** 未开局解散返还 */
    public const FLOW_REFUND = 4;

    /** 续局消耗 */
    public const FLOW_CONTINUE = 5;

    /** 后台调整 */
    public const FLOW_ADJUST = 6;

    public const FLOW_TYPE_LABELS = [
        self::FLOW_BUY => '购买',
        self::FLOW_GIFT => '赠送',
        self::FLOW_CREATE_ROOM => '建房消耗',
        self::FLOW_REFUND => '未开局返还',
        self::FLOW_CONTINUE => '续局消耗',
        self::FLOW_ADJUST => '后台调整',
    ];

    public function __construct(
        protected RoomCardTypeDao $typeDao,
        protected MemberRoomCardDao $cardDao,
        protected MemberRoomCardFlowDao $flowDao,
    ) {
        $this->dao = $this->cardDao;
    }

    /** 启用中的房卡类型列表（创房选项用） */
    public function listEnabledTypes(): array
    {
        return $this->typeDao
            ->selectModel(['status' => 1], '*', 0, 0, ['sort' => 'desc', 'id' => 'asc'])
            ->get()
            ->map(static fn ($row) => [
                'id' => (int) $row->id,
                'name' => (string) $row->name,
                'price' => (string) $row->price,
                'round_count' => (int) $row->round_count,
            ])
            ->all();
    }

    /** 某会员各类型持有（含类型信息） */
    public function listMine(int $memberId): array
    {
        $types = $this->typeDao
            ->selectModel(['status' => 1], '*', 0, 0, ['sort' => 'desc', 'id' => 'asc'])
            ->get()
            ->keyBy('id');

        $owned = MemberRoomCard::query()
            ->where('member_id', $memberId)
            ->get()
            ->keyBy('type_id');

        $list = [];
        foreach ($types as $id => $type) {
            $qty = (int) ($owned->get($id)?->quantity ?? 0);
            $list[] = [
                'type_id' => (int) $id,
                'name' => (string) $type->name,
                'price' => (string) $type->price,
                'round_count' => (int) $type->round_count,
                'quantity' => $qty,
            ];
        }

        return $list;
    }

    /** 某类型当前张数 */
    public function quantityOf(int $memberId, int $typeId): int
    {
        $row = MemberRoomCard::query()
            ->where('member_id', $memberId)
            ->where('type_id', $typeId)
            ->first();

        return (int) ($row?->quantity ?? 0);
    }

    /** 会员持有房卡总张数（大厅展示） */
    public function totalQuantity(int $memberId): int
    {
        if ($memberId <= 0) {
            return 0;
        }

        return (int) MemberRoomCard::query()
            ->where('member_id', $memberId)
            ->sum('quantity');
    }

    /**
     * 启用中的类型；不存在或下架则抛错
     *
     * @return array{id:int,name:string,price:string,round_count:int}
     */
    public function requireEnabledType(int $typeId): array
    {
        $type = $this->typeDao->get($typeId);
        if (! $type || (int) $type->status !== 1) {
            throw new ApiException('房卡类型不可用');
        }

        return [
            'id' => (int) $type->id,
            'name' => (string) $type->name,
            'price' => (string) $type->price,
            'round_count' => (int) $type->round_count,
        ];
    }

    /**
     * 未开局解散时返还建房所扣房卡（幂等：同一房间只退一次）
     */
    public function refundCreateIfNeeded(int $memberId, int $roomId, int $typeId): bool
    {
        if ($memberId <= 0 || $roomId <= 0 || $typeId <= 0) {
            return false;
        }
        if ($this->flowDao->be([
            'member_id' => $memberId,
            'rel_room_id' => $roomId,
            'flow_type' => self::FLOW_REFUND,
        ])) {
            return false;
        }
        if (! $this->flowDao->be([
            'member_id' => $memberId,
            'rel_room_id' => $roomId,
            'flow_type' => self::FLOW_CREATE_ROOM,
        ])) {
            return false;
        }

        $this->change(
            $memberId,
            $typeId,
            1,
            self::FLOW_REFUND,
            $roomId,
            sprintf('房间%d未开局返还', $roomId)
        );

        return true;
    }

    /**
     * 中途解散：按「房卡单价 ÷ 总局数 × 剩余局数」折算金币退给房主，并写资金流水（幂等）。
     *
     * 例：5 局房卡价 10，已打 4 局 → 退 10/5*1 = 2 金币。
     *
     * @return array{refunded:bool, amount:string, remain:int, total:int}|null
     */
    public function refundUnusedRoundsAsGoldIfNeeded(
        int $memberId,
        int $roomId,
        int $typeId,
        int $playedCount,
        int $totalRounds,
    ): ?array {
        if ($memberId <= 0 || $roomId <= 0 || $typeId <= 0) {
            return null;
        }
        if ($playedCount <= 0 || $totalRounds <= 0 || $playedCount >= $totalRounds) {
            return null;
        }
        // 须确有建房扣卡记录
        if (! $this->flowDao->be([
            'member_id' => $memberId,
            'rel_room_id' => $roomId,
            'flow_type' => self::FLOW_CREATE_ROOM,
        ])) {
            return null;
        }

        $finance = make(FinanceService::class);
        $orderSn = 'RCR' . $roomId;
        if (make(\App\Dao\Member\FinancialLogDao::class)->be([
            'member_id' => $memberId,
            'flow_type' => FinanceService::FLOW_ROOM_CARD_REMAIN_REFUND,
            'order_sn'  => $orderSn,
        ])) {
            return null;
        }

        $type = $this->typeDao->get($typeId);
        if ($type === null) {
            return null;
        }
        $price = bcadd((string) ($type->price ?? '0'), '0', 2);
        if (bccomp($price, '0', 2) <= 0) {
            return null;
        }

        $remain = $totalRounds - $playedCount;
        // 单价/总局 × 剩余局；金额向下截断到 2 位（1.527→1.52，不四舍五入）
        $raw = bcmul(bcdiv($price, (string) $totalRounds, 8), (string) $remain, 8);
        $amount = $this->floorMoney2($raw);
        if (bccomp($amount, '0', 2) <= 0) {
            return null;
        }

        $finance->income(
            $memberId,
            $amount,
            FinanceService::FLOW_ROOM_CARD_REMAIN_REFUND,
            $orderSn,
            sprintf(
                '房间%d中途解散返还：房卡价%s÷%d局×剩%d局',
                $roomId,
                $price,
                $totalRounds,
                $remain
            )
        );

        return [
            'refunded' => true,
            'amount'   => $amount,
            'remain'   => $remain,
            'total'    => $totalRounds,
        ];
    }

    /**
     * 变动某类型房卡并写流水（事务 + 行锁）。
     *
     * @param int $change 正数增加、负数扣减
     * @return array{before:int, after:int, change:int, flow_id:int}
     */
    public function change(
        int $memberId,
        int $typeId,
        int $change,
        int $flowType,
        ?int $relRoomId = null,
        string $remark = '',
    ): array {
        if ($change === 0) {
            throw new ApiException('变动张数不能为 0');
        }
        if ($memberId <= 0 || $typeId <= 0) {
            throw new ApiException('参数错误');
        }

        return Db::transaction(function () use ($memberId, $typeId, $change, $flowType, $relRoomId, $remark) {
            $type = $this->typeDao->get($typeId);
            if (! $type || (int) $type->status !== 1) {
                throw new ApiException('房卡类型不可用');
            }

            /** @var MemberRoomCard|null $row */
            $row = MemberRoomCard::query()
                ->where('member_id', $memberId)
                ->where('type_id', $typeId)
                ->lockForUpdate()
                ->first();

            if (! $row) {
                $row = new MemberRoomCard([
                    'member_id' => $memberId,
                    'type_id' => $typeId,
                    'quantity' => 0,
                ]);
                $row->save();
                $row = MemberRoomCard::query()
                    ->where('id', $row->id)
                    ->lockForUpdate()
                    ->first();
            }

            $before = (int) $row->quantity;
            $after = $before + $change;
            if ($after < 0) {
                throw new ApiException('房卡不足');
            }

            $row->quantity = $after;
            $row->save();

            $flowId = (int) $this->flowDao->create([
                'member_id' => $memberId,
                'type_id' => $typeId,
                'change_number' => $change,
                'after_number' => $after,
                'flow_type' => $flowType,
                'rel_room_id' => $relRoomId,
                'remark' => $remark,
                'create_time' => date('Y-m-d H:i:s'),
            ]);

            return [
                'before' => $before,
                'after' => $after,
                'change' => $change,
                'flow_id' => $flowId,
            ];
        });
    }

    /** 增加（张数传正数） */
    public function increase(int $memberId, int $typeId, int $qty, int $flowType, string $remark = ''): array
    {
        if ($qty <= 0) {
            throw new ApiException('增加张数必须大于 0');
        }

        return $this->change($memberId, $typeId, $qty, $flowType, null, $remark);
    }

    /** 扣减（张数传正数） */
    public function decrease(
        int $memberId,
        int $typeId,
        int $qty,
        int $flowType,
        ?int $relRoomId = null,
        string $remark = '',
    ): array {
        if ($qty <= 0) {
            throw new ApiException('扣减张数必须大于 0');
        }

        return $this->change($memberId, $typeId, -$qty, $flowType, $relRoomId, $remark);
    }

    /**
     * 金币购买房卡（按类型单价 × 张数）。
     *
     * @return array{
     *   type_id:int, name:string, round_count:int, quantity:int,
     *   gold:string, cards_total:int, cost:string, buy_qty:int
     * }
     */
    public function buyWithGold(int $memberId, int $typeId, int $qty = 1): array
    {
        if ($memberId <= 0 || $typeId <= 0) {
            throw new ApiException('参数错误');
        }
        if ($qty < 1 || $qty > 99) {
            throw new ApiException('购买数量须为 1~99');
        }

        $type = $this->requireEnabledType($typeId);
        $unit = bcadd((string) $type['price'], '0', 2);
        if (bccomp($unit, '0', 2) <= 0) {
            throw new ApiException('该房卡暂未定价，请联系管理员');
        }
        $cost = bcmul($unit, (string) $qty, 2);

        return Db::transaction(function () use ($memberId, $typeId, $qty, $type, $unit, $cost) {
            $orderSn = function_exists('build_order_sn')
                ? build_order_sn('RC')
                : ('RC' . date('YmdHis') . random_int(1000, 9999));

            $finance = make(FinanceService::class);
            $goldRes = $finance->expense(
                $memberId,
                $cost,
                FinanceService::FLOW_BUY_CARD,
                $orderSn,
                sprintf('购买%s×%d（单价%s）', $type['name'], $qty, $unit)
            );

            $cardRes = $this->increase(
                $memberId,
                $typeId,
                $qty,
                self::FLOW_BUY,
                sprintf('金币购买%s×%d', $type['name'], $qty)
            );

            return [
                'type_id' => $typeId,
                'name' => $type['name'],
                'round_count' => $type['round_count'],
                'quantity' => (int) $cardRes['after'],
                'gold' => (string) $goldRes['after'],
                'cards_total' => $this->totalQuantity($memberId),
                'cost' => $cost,
                'buy_qty' => $qty,
            ];
        });
    }

    /** 金额向下截断到 2 位小数（不四舍五入） */
    private function floorMoney2(string $amount): string
    {
        $normalized = bcadd($amount, '0', 8);
        // 先 ×100 取整再 ÷100，正数即截断小数第 3 位及以后
        $cents = bcmul($normalized, '100', 0);

        return bcdiv($cents, '100', 2);
    }
}
