<?php

declare(strict_types=1);

namespace App\Service\Game;

use App\Dao\Game\LandLordBidDao;
use App\Service\BaseService;

/**
 * 叫抢地主记录
 *
 * 表 land_lord_bid（前缀 h_）：
 * - seq=0：首叫座位种子行
 * - seq>=1：真实叫抢记录
 *
 * 托管 / 超时计数在 h_room_member（见 RoomMemberService），不在本表。
 */
class LandLordBidService extends BaseService
{
    public function __construct(LandLordBidDao $landLordBidDao)
    {
        $this->dao = $landLordBidDao;
    }

    /**
     * 加载本局叫抢记录：首叫座位 + 真实叫抢序列
     *
     * @return array{first: int, actions: list<array{seat:int, player_id:int, score:int}>}
     */
    public function loadBidActions(int $roundId): array
    {
        $rows = $this->getAll(
            ['game_round_id' => $roundId],
            'seq,seat_no,member_id,score',
            ['seq' => 'asc']
        );
        $first = 0;
        $actions = [];
        foreach ($rows as $r) {
            if ((int) $r['seq'] === 0) {
                $first = (int) $r['seat_no'];
                continue;
            }
            $actions[] = [
                'seat'      => (int) $r['seat_no'],
                'player_id' => (int) $r['member_id'],
                'score'     => (int) $r['score'],
            ];
        }
        return ['first' => $first, 'actions' => $actions];
    }

    /**
     * 叫抢倍数：
     * - 叫地主：不翻倍（基数 1）
     * - 每抢地主一次：×2
     * 个人「加倍」不在此计算，见 farmerDoubles / 结算
     */
    public function calcMultiple(array $actions): int
    {
        $multiple = 1;
        $hasCall = false;
        foreach ($actions as $a) {
            if ((int) ($a['score'] ?? 0) <= 0) {
                continue;
            }
            if (! $hasCall) {
                // 叫地主：不加倍
                $hasCall = true;
                continue;
            }
            // 抢地主：翻倍
            $multiple *= 2;
        }

        return $multiple;
    }
}
