<?php

declare(strict_types=1);

namespace App\Service\Game;

use App\Dao\Game\NiuBetDao;
use App\Service\BaseService;

/**
 * 斗牛闲家下注服务（表 niu_bet，前缀 h_）
 *
 * 职责：
 * - 读写闲家下注记录（庄家无行）
 * - calcAmount：生成「底分 × 倍数」快照，供落库与结算复用
 */
class NiuBetService extends BaseService
{
    public function __construct(NiuBetDao $niuBetDao)
    {
        $this->dao = $niuBetDao;
    }

    /**
     * 加载本局全部下注记录（按座位升序）
     *
     * @return list<array{seat:int, player_id:int, multiple:int, amount:string, is_auto:int}>
     */
    public function loadByRound(int $roundId): array
    {
        $rows = $this->getAll(
            ['game_round_id' => $roundId],
            'seat_no,member_id,multiple,amount,is_auto',
            ['seat_no' => 'asc']
        );
        $list = [];
        foreach ($rows as $r) {
            $list[] = [
                'seat'      => (int) $r['seat_no'],
                'player_id' => (int) $r['member_id'],
                'multiple'  => (int) $r['multiple'],
                // 统一成 2 位小数字符串，便于 bc* 运算
                'amount'    => bcadd((string) ($r['amount'] ?? '0'), '0', 2),
                'is_auto'   => (int) ($r['is_auto'] ?? 0),
            ];
        }

        return $list;
    }

    /**
     * 该玩家本局是否已下注
     */
    public function hasBet(int $roundId, int $memberId): bool
    {
        return $this->be([
            'game_round_id' => $roundId,
            'member_id'     => $memberId,
        ]);
    }

    /**
     * 计算下注额快照：底分 × 倍数（精度 2 位）
     *
     * @param string $bottomPoints 房间配置 bottom_points
     * @param int    $multiple     闲家选择的下注倍数
     */
    public function calcAmount(string $bottomPoints, int $multiple): string
    {
        $bottom = bcadd($bottomPoints, '0', 2);
        $mul = max(0, $multiple);

        return bcmul($bottom, (string) $mul, 2);
    }
}
