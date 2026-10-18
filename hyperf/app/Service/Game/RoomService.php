<?php

declare(strict_types=1);

namespace App\Service\Game;

use App\Constants\GameType;
use App\Dao\Game\GameRoomDao;
use App\Exception\ApiException;
use App\Game\Hzmj\HzmjConsts;
use App\Game\Niu\NiuConsts;
use App\Game\Pdk\PdkConsts;
use App\Game\Platform\Timeout\RoomTimeoutService;
use App\Game\Zjh\ZjhConsts;
use App\Model\Game\GameRoom;
use App\Model\Game\GameRound;
use App\Service\BaseService;
use App\Service\Member\MemberService;
use App\Service\Member\RoomCardService;
use function Hyperf\Support\make;

class RoomService extends BaseService
{
    public function __construct(GameRoomDao $roomDao)
    {
        $this->dao = $roomDao;
    }

    /**
     * 根据房间号获取一条房间记录
     */
    public function getByRoomNum(string $roomNum): ?GameRoom
    {
        /** @var GameRoom|null */
        return $this->dao->getOne(['room_num' => $roomNum]);
    }

    /**
     * 创建房间
     *
     * 按当前会员 + 游戏类型：
     * - 不存在记录 → 新建并返回房间号
     * - 最近一条已结束 → 新建并返回房间号
     * - 最近一条未结束（已创建/游戏中）→ 直接返回该房间号
     *
     * @param string $rule 建房规则 JSON 字符串
     */
    public function create(
        int $memberId,
        int $gameType = GameType::LANDLORD,
        int $num = 3,
        string $rule = '',
    ): array {
        $num = $this->normalizeSeatCount($gameType, $num);
        $ruleData = $this->decodeRule($rule);

        $cardTypeId = (int) ($ruleData['room_card_type_id'] ?? $ruleData['card_type_id'] ?? 0);
        if ($cardTypeId <= 0) {
            throw new ApiException('请选择房卡');
        }
        $cardSvc = make(RoomCardService::class);
        $cardType = $cardSvc->requireEnabledType($cardTypeId);
        $totalPlayCount = (int) $cardType['round_count'];
        if ($totalPlayCount <= 0) {
            throw new ApiException('房卡局数配置错误');
        }

        // 规则写入局数，并去掉平台费支付（改由房卡建房）
        $ruleData['room_card_type_id'] = $cardTypeId;
        $ruleData['room_card_name'] = $cardType['name'];
        $ruleData['total_play_count'] = $totalPlayCount;
        $ruleData['zjs'] = $totalPlayCount;
        $ruleData['pay_amount'] = 0;
        $ruleData['pay_mode_text'] = '房卡';

        // 入场门槛固定：底分 × 5（覆盖前端传入的 entry_points）
        $bottom = $this->normalizeRulePoints(
            $ruleData['bottom_points'] ?? $ruleData['df'] ?? $ruleData['ante'] ?? '1',
            '1'
        );
        $entryPoints = bcmul($bottom, '5', 2);
        $ruleData['df'] = is_numeric($ruleData['df'] ?? null)
            ? $ruleData['df']
            : (float) $bottom;
        $ruleData['entry_points'] = (float) $entryPoints;
        $rule = json_encode($ruleData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';

        // 仍挂在旧房：无对局则解散；有对局则直接返回该房（让前端回桌），勿抛错卡死
        $resumeRoom = $this->releaseIdleMembership($memberId);
        if ($resumeRoom !== null) {
            return $resumeRoom;
        }

        return $this->transaction(function () use (
            $memberId,
            $gameType,
            $num,
            $rule,
            $totalPlayCount,
            $cardTypeId,
            $cardSvc,
            $bottom,
            $entryPoints
        ) {
            $existing = $this->findLatestRoom($memberId, $gameType);
            if ($existing !== null) {
                $locked = $this->dao->lockForUpdate(
                    (int) $existing->id,
                    ['id', 'room_num', 'status', 'rule']
                );
                if ($locked !== null && ! GameRoom::isEndedStatus((int) $locked->status)) {
                    $roomNum = (string) $locked->room_num;
                    // 历史 5 位房号：复用时升级为 6 位，与加入房间 UI 对齐
                    if (strlen($roomNum) !== 6 || ! ctype_digit($roomNum)) {
                        $roomNum = $this->generateRoomNum();
                        $this->dao->update((int) $locked->id, ['room_num' => $roomNum]);
                    }
                    // 复用旧房也校验金币门槛
                    $existRule = (string) ($locked->rule ?? '');
                    $this->assertMemberGoldEnough(
                        $memberId,
                        $this->resolveBottomPoints($existRule),
                        $this->resolveEntryPoints($existRule)
                    );
                    return [
                        'room_num' => $roomNum,
                        'rule' => $existRule,
                    ];
                }
            }

            // 新建：金币须 ≥ 底分 × 5
            $this->assertMemberGoldEnough($memberId, $bottom, $entryPoints);

            if ($cardSvc->quantityOf($memberId, $cardTypeId) < 1) {
                throw new ApiException('房卡不足，无法创建房间');
            }

            $roomNum = $this->generateRoomNum();

            $roomId = $this->dao->create([
                'total_play_count' => $totalPlayCount,
                'played_count' => 0,
                'status' => GameRoom::STATUS_CREATED,
                'room_num' => $roomNum,
                'game_type' => $gameType,
                'member_id' => $memberId,
                'num' => $num,
                'rule' => $rule !== '' ? $rule : null,
            ]);

            $cardSvc->decrease(
                $memberId,
                $cardTypeId,
                1,
                RoomCardService::FLOW_CREATE_ROOM,
                (int) $roomId,
                sprintf('创建房间%s', $roomNum)
            );

            return [
                'room_num' => $roomNum,
                'rule' => $rule,
            ];
        });
    }

    /**
     * 处理「仍挂在未结束成员行」：
     * - 无进行中牌局：解散旧房，返回 null（可继续创建）
     * - 有进行中牌局：返回该房信息，供前端重新进入（不再抛错）
     *
     * @return null|array{room_num:string,rule:string,game_type:int,already_in:bool}
     */
    private function releaseIdleMembership(int $memberId): ?array
    {
        if ($memberId <= 0) {
            return null;
        }
        $memberSvc = make(RoomMemberService::class);
        $member = $memberSvc->getActiveByPlayer($memberId);
        if ($member === null) {
            return null;
        }
        $roomId = (int) ($member->room_id ?? 0);
        if ($roomId <= 0) {
            return null;
        }
        $playing = make(GameRoundService::class)->getOne(
            [
                'room_id' => $roomId,
                'status'  => GameRound::STATUS_PLAYING,
            ],
            'id'
        );
        $room = $this->dao->getOne(['id' => $roomId], 'id,room_num,game_type,rule,status');
        if ($playing !== null) {
            if ($room === null || ! GameRoom::isActiveStatus((int) ($room->status ?? 0))) {
                return null;
            }

            return [
                'room_num'   => (string) ($room->room_num ?? ''),
                'rule'       => (string) ($room->rule ?? ''),
                'game_type'  => (int) ($room->game_type ?? 0),
                'already_in' => true,
            ];
        }
        make(RoomTimeoutService::class)->dissolveRoom(
            $roomId,
            '无人继续对局，原房间已关闭'
        );

        return null;
    }

    /**
     * 该会员该游戏类型最近一条房间记录
     */
    private function findLatestRoom(int $memberId, int $gameType): ?GameRoom
    {
        $rows = $this->dao->selectList(
            [
                'member_id' => $memberId,
                'game_type' => $gameType,
            ],
            ['id', 'room_num', 'status', 'rule'],
            0,
            1,
            ['id' => 'desc']
        );

        $room = $rows->first();

        return $room instanceof GameRoom ? $room : null;
    }

    /**
     * 从房间 rule 解析底分（兼容 bottom_points / df / ante，缺省 1）
     */
    public function resolveBottomPoints(?string $rule): string
    {
        $data = $this->decodeRule((string) $rule);
        $raw = $data['bottom_points'] ?? $data['df'] ?? $data['ante'] ?? '1';

        return $this->normalizeRulePoints($raw, '1');
    }

    /**
     * 支付方式：1 AA / 2 赢家支付 / 3 我（房主）支付
     */
    public function resolvePayMode(?string $rule): int
    {
        $data = $this->decodeRule((string) $rule);
        $mode = (int) ($data['pay_mode'] ?? $data['pay_model'] ?? $data['payMode'] ?? 1);

        return in_array($mode, [1, 2, 3], true) ? $mode : 1;
    }

    /**
     * 每局平台费用总额（非每人单价；AA 时在局末按人数均分）
     */
    public function resolvePayAmount(?string $rule): string
    {
        $data = $this->decodeRule((string) $rule);
        $raw = $data['pay_amount'] ?? $data['payAmount'] ?? null;
        if ($raw === null) {
            $fee = $data['fee'] ?? null;
            if (is_array($fee)) {
                $bottom = (string) ($data['bottom_points'] ?? $data['df'] ?? '');
                $raw = $fee[$bottom] ?? null;
            } else {
                $raw = $fee;
            }
        }

        return $this->normalizeRulePoints($raw, '0');
    }

    /**
     * 斗牛牌型倍数。优先读取结构化 win_multipliers，兼容 createRoomRules.win 文案。
     *
     * @return array<string,int>
     */
    public function resolveNiuMultipliers(?string $rule): array
    {
        $defaults = [
            'niu_niu' => 4,
            'niu_9' => 3,
            'niu_8' => 2,
            'five_flower' => 5,
            'bomb' => 6,
            'five_small' => 8,
        ];
        $data = $this->decodeRule((string) $rule);
        $configured = $data['win_multipliers'] ?? null;
        if (is_array($configured)) {
            foreach ($defaults as $key => $fallback) {
                $value = (int) ($configured[$key] ?? 0);
                if ($value > 0) {
                    $defaults[$key] = $value;
                }
            }
        }

        $win = (string) ($data['win'] ?? '');
        $patterns = [
            'niu_niu' => '/牛牛\s*[×xX*]\s*(\d+)/u',
            'niu_9' => '/牛九\s*[×xX*]\s*(\d+)/u',
            'niu_8' => '/牛八\s*[×xX*]\s*(\d+)/u',
            'five_flower' => '/五花牛\s*[×xX*]\s*(\d+)/u',
            'bomb' => '/炸弹牛\s*[×xX*]\s*(\d+)/u',
            'five_small' => '/五小牛\s*[×xX*]\s*(\d+)/u',
        ];
        foreach ($patterns as $key => $pattern) {
            if (preg_match($pattern, $win, $match) === 1 && (int) $match[1] > 0) {
                $defaults[$key] = (int) $match[1];
            }
        }

        return $defaults;
    }

    /** 斗牛定庄模式：rotate=轮流、winner=赢家、free=自由叫分。 */
    public function resolveNiuBankerMode(?string $rule): string
    {
        $data = $this->decodeRule((string) $rule);
        $mode = strtolower((string) ($data['mode_key'] ?? $data['mode'] ?? 'free'));

        return match ($mode) {
            'ming', 'rotate', 'rotation' => 'rotate',
            'tongbi', 'winner', 'winner_banker' => 'winner',
            default => 'free',
        };
    }

    /**
     * 从房间 rule 解析入场金币门槛。
     * 固定为底分 × 5（创建/加入统一门槛，忽略客户端 entry_points）。
     */
    public function resolveEntryPoints(?string $rule): string
    {
        $bottom = $this->resolveBottomPoints($rule);

        return bcmul($bottom, '5', 2);
    }

    /**
     * 进房最低金币：底分 × 5
     */
    public function resolveMinJoinGold(?string $rule): string
    {
        return $this->resolveEntryPoints($rule);
    }

    /** 创建/加入：金币须 ≥ 底分 × 5 */
    public function assertMemberGoldEnough(int $memberId, string $bottom, ?string $entryPoints = null): void
    {
        if ($memberId <= 0) {
            throw new ApiException('参数错误');
        }
        $need = $entryPoints ?? bcmul($bottom, '5', 2);
        if (bccomp($need, '0', 2) <= 0) {
            return;
        }
        $member = make(MemberService::class)->getOne(['id' => $memberId], 'id,points');
        $points = bcadd((string) ($member?->points ?? '0'), '0', 2);
        if (bccomp($points, $need, 2) < 0) {
            throw new ApiException(sprintf(
                '金币数量至少是底分的5倍（底分 %s，需至少 %s）',
                bcadd($bottom, '0', 2),
                $need
            ));
        }
    }

    private function decodeRule(string $rule): array
    {
        if ($rule === '') {
            return [];
        }
        $decoded = json_decode($rule, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function normalizeRulePoints(mixed $raw, string $default): string
    {
        if (is_int($raw) || is_float($raw)) {
            return bcadd((string) $raw, '0', 2);
        }

        $s = preg_replace('/[^\d.]/', '', (string) $raw);
        if ($s === null || $s === '' || ! is_numeric($s)) {
            return bcadd($default, '0', 2);
        }

        return bcadd($s, '0', 2);
    }

    private function resolveTotalPlayCount(array $ruleData): int
    {
        foreach (['total_play_count', 'zjs', 'rounds'] as $key) {
            if (isset($ruleData[$key]) && (int) $ruleData[$key] > 0) {
                return (int) $ruleData[$key];
            }
        }
        return 6;
    }

    private function normalizeSeatCount(int $gameType, int $num): int
    {
        if ($gameType === GameType::NIU) {
            if ($num < NiuConsts::MIN_PLAYERS || $num > NiuConsts::MAX_PLAYERS) {
                throw new ApiException(sprintf(
                    '斗牛人数须为 %d~%d',
                    NiuConsts::MIN_PLAYERS,
                    NiuConsts::MAX_PLAYERS
                ));
            }
            return $num;
        }

        if ($gameType === GameType::ZJH) {
            if ($num < ZjhConsts::MIN_PLAYERS || $num > ZjhConsts::MAX_PLAYERS) {
                throw new ApiException(sprintf(
                    '炸金花人数须为 %d~%d',
                    ZjhConsts::MIN_PLAYERS,
                    ZjhConsts::MAX_PLAYERS
                ));
            }
            return $num;
        }

        if ($gameType === GameType::LANDLORD) {
            return 3;
        }

        if ($gameType === GameType::LAIZI_LANDLORD) {
            return 3;
        }

        if ($gameType === GameType::PAODEKUAI) {
            return PdkConsts::MAX_PLAYERS;
        }

        if ($gameType === GameType::HZMJ) {
            if ($num < HzmjConsts::MIN_PLAYERS || $num > HzmjConsts::MAX_PLAYERS) {
                throw new ApiException(sprintf(
                    '红中麻将人数须为 %d~%d',
                    HzmjConsts::MIN_PLAYERS,
                    HzmjConsts::MAX_PLAYERS
                ));
            }

            return $num;
        }

        return max(2, $num);
    }

    /** 生成 6 位数字房间号，冲突重试 */
    private function generateRoomNum(): string
    {
        for ($i = 0; $i < 50; $i++) {
            $roomNum = (string) random_int(100000, 999999);
            if (! $this->dao->be(['room_num' => $roomNum])) {
                return $roomNum;
            }
        }

        throw new ApiException('生成房间号失败，请重试');
    }
}
