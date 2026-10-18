<?php

declare(strict_types=1);

namespace App\Service\Game;

use App\Dao\Member\FinancialLogDao;
use App\Dao\Member\MemberDao;
use App\Exception\ApiException;
use App\Service\BaseService;
use App\Service\Member\FinanceService;

/**
 * 炸金花入池扣款 / 奖池派彩（须在外层事务内调用）
 */
class ZjhChipService extends BaseService
{
    private const SCALE = 2;

    public function __construct(
        MemberDao $memberDao,
        protected FinancialLogDao $logDao,
    ) {
        $this->dao = $memberDao;
    }

    /**
     * 从玩家扣入奖池金额（不足则抛错）
     */
    public function deduct(int $playerId, string $amount, string $remark): string
    {
        $amount = $this->normalize($amount);
        if ($playerId <= 0 || bccomp($amount, '0', self::SCALE) <= 0) {
            return '0.00';
        }
        $row = $this->dao->lockForUpdate($playerId, ['id', 'points']);
        if ($row === null) {
            throw new ApiException('玩家不存在');
        }
        $before = $this->normalize((string) ($row->points ?? '0'));
        if (bccomp($before, $amount, self::SCALE) < 0) {
            throw new ApiException('金币不足');
        }
        $after = bcsub($before, $amount, self::SCALE);
        $this->dao->update($playerId, ['points' => $after]);
        $this->logDao->create([
            'member_id'            => $playerId,
            'change_amount'        => '-' . $amount,
            'before_amount'        => $before,
            'after_amount'         => $after,
            'flow_type'            => FinanceService::FLOW_GAME_LOSE,
            'order_sn'             => build_order_sn('ZJH'),
            'remark'               => $remark,
            'before_frozen_amount' => '0.000',
            'after_froze_amount'   => '0.000',
            'create_at'            => date('Y-m-d H:i:s'),
        ]);

        return $after;
    }

    /**
     * 派发奖池给赢家
     */
    public function credit(int $playerId, string $amount, string $remark): string
    {
        $amount = $this->normalize($amount);
        if ($playerId <= 0 || bccomp($amount, '0', self::SCALE) <= 0) {
            $row = $this->dao->lockForUpdate($playerId, ['id', 'points']);

            return $this->normalize((string) ($row->points ?? '0'));
        }
        $row = $this->dao->lockForUpdate($playerId, ['id', 'points']);
        if ($row === null) {
            throw new ApiException('玩家不存在');
        }
        $before = $this->normalize((string) ($row->points ?? '0'));
        $after = bcadd($before, $amount, self::SCALE);
        $this->dao->update($playerId, ['points' => $after]);
        $this->logDao->create([
            'member_id'            => $playerId,
            'change_amount'        => $amount,
            'before_amount'        => $before,
            'after_amount'         => $after,
            'flow_type'            => FinanceService::FLOW_GAME_WIN,
            'order_sn'             => build_order_sn('ZJH'),
            'remark'               => $remark,
            'before_frozen_amount' => '0.000',
            'after_froze_amount'   => '0.000',
            'create_at'            => date('Y-m-d H:i:s'),
        ]);

        return $after;
    }

    /** 当前金币余额（只读） */
    public function balance(int $playerId): string
    {
        if ($playerId <= 0) {
            return '0.00';
        }
        $row = $this->dao->getOne(['id' => $playerId], 'id,points');

        return $this->normalize((string) ($row->points ?? '0'));
    }

    private function normalize(string $amount): string
    {
        return bcadd($amount, '0', self::SCALE);
    }
}
