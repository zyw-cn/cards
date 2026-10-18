<?php

declare(strict_types=1);

namespace App\Game\Lz\Play;

use App\Exception\ApiException;
use App\Game\Lz\CallBack;
use App\Game\Lz\Card\LaiziCard;
use App\Game\Lz\LzConsts;
use App\Game\Lz\RoundState;
use App\Game\Lz\Rule\BidRule;
use App\Game\Support\GameRecordHooks;
use App\Model\Game\GameRound;
use App\Service\Game\GamePlayCardService;
use App\Service\Game\GameRoundService;
use App\Service\Game\RoomMemberService;
use function Hyperf\Support\make;

/**
 * 癞子斗地主出牌阶段：校验上下文、不出/出牌、局末结算 payload
 */
final class PlayTurnHandler
{
    /**
     * @param array<string, mixed> $body
     * @return array{data: array<string, mixed>, roomOver: bool}
     */
    public function execute(
        int $roomId,
        int $playerId,
        int $seatNo,
        array $played,
        array $body,
        RoomMemberService $roomMemberService,
        GameRoundService $gameRoundService,
        GamePlayCardService $gamePlayCardService,
    ): array {
        $ctx = $this->loadContext(
            $roomId,
            $playerId,
            $seatNo,
            $played,
            $gameRoundService,
            $gamePlayCardService,
            $roomMemberService
        );

        $roomOver = false;
        if ($ctx['isPass']) {
            $data = $this->applyPass($ctx, $body);
        } else {
            [$data, $roomOver] = $this->applyPlay($ctx, $played, $body, $gameRoundService);
        }

        $this->syncGameRecord($ctx, $data);

        if (empty($body['_auto'])) {
            $roomMemberService->clearTrust($roomId, $playerId);
        }

        return ['data' => $data, 'roomOver' => $roomOver];
    }

    /**
     * @return array<string, mixed>
     */
    private function loadContext(
        int $roomId,
        int $playerId,
        int $seatNo,
        array $played,
        GameRoundService $gameRoundService,
        GamePlayCardService $gamePlayCardService,
        RoomMemberService $roomMemberService,
    ): array {
        $round = $gameRoundService->lockForUpdate(
            ['room_id' => $roomId, 'status' => GameRound::STATUS_PLAYING],
            ['id', 'master_id', 'status', 'extra']
        );
        if ($round === null) {
            throw new ApiException('本局尚未开始');
        }
        $roundId = (int) $round->id;
        $masterPid = (int) ($round->master_id ?? 0);
        if ($masterPid === 0) {
            throw new ApiException('叫抢阶段尚未结束');
        }

        $roundState = RoundState::decode($round->extra ?? null);
        $bidMultiple = max(1, (int) ($roundState['landlordMultiple'] ?? 1));

        $members = $roomMemberService->getActiveInRoom($roomId, 'player_id,seat_no');
        $seatByPid = [];
        $landlordSeat = $seatNo;
        foreach ($members as $m) {
            $pid = (int) ($m['player_id'] ?? 0);
            $s = (int) ($m['seat_no'] ?? 0);
            $seatByPid[$pid] = $s;
            if ($pid === $masterPid) {
                $landlordSeat = $s;
            }
        }

        $validation = make(PlayValidator::class)->validate(
            $gamePlayCardService,
            $roundId,
            $playerId,
            $seatNo,
            $played,
            $seatByPid,
            $landlordSeat
        );
        if (! ($validation['ok'] ?? false)) {
            throw new ApiException((string) ($validation['message'] ?? '出牌不合法'));
        }

        return [
            'roundId'      => $roundId,
            'masterPid'    => $masterPid,
            'bidMultiple'  => $bidMultiple,
            'roundState'   => $roundState,
            'seatByPid'    => $seatByPid,
            'landlordSeat' => $landlordSeat,
            'validation'   => $validation,
            'isPass'       => (bool) ($validation['isPass'] ?? false),
            'hand'         => $validation['hand'] ?? [],
            'roomId'       => $roomId,
            'playerId'     => $playerId,
            'seatNo'       => $seatNo,
            'gamePlayCard' => $gamePlayCardService,
        ];
    }

    /**
     * @param array<string, mixed> $ctx
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    private function applyPass(array $ctx, array $body): array
    {
        /** @var GamePlayCardService $gamePlayCardService */
        $gamePlayCardService = $ctx['gamePlayCard'];
        $roundId = (int) $ctx['roundId'];
        $playerId = (int) $ctx['playerId'];
        $seatNo = (int) $ctx['seatNo'];
        $hand = $ctx['hand'];

        $gamePlayCardService->recordPlay($roundId, $playerId, [], $hand);
        $nextState = $gamePlayCardService->resolvePlayState(
            $roundId,
            $ctx['seatByPid'],
            (int) $ctx['landlordSeat'],
            LzConsts::MAX_PLAYERS
        );

        $data = [
            'callback'     => CallBack::PLAY,
            'action'       => 'pass',
            'passIndex'    => $seatNo,
            'currentIndex' => $nextState['currentIndex'],
            'handCount'    => count($hand),
        ];
        if (! empty($body['_auto'])) {
            $data['myCards'] = $hand;
        }

        return $data;
    }

    /**
     * @param array<string, mixed> $ctx
     * @param list<string> $played
     * @param array<string, mixed> $body
     * @return array{0: array<string, mixed>, 1: bool}
     */
    private function applyPlay(
        array $ctx,
        array $played,
        array $body,
        GameRoundService $gameRoundService,
    ): array {
        /** @var GamePlayCardService $gamePlayCardService */
        $gamePlayCardService = $ctx['gamePlayCard'];
        $validation = $ctx['validation'];
        $roundId = (int) $ctx['roundId'];
        $playerId = (int) $ctx['playerId'];
        $seatNo = (int) $ctx['seatNo'];
        $roomId = (int) $ctx['roomId'];
        $masterPid = (int) $ctx['masterPid'];
        $bidMultiple = (int) $ctx['bidMultiple'];

        $remain = $validation['remain'] ?? [];
        $cardType = (string) ($validation['cardType'] ?? '');
        $gamePlayCardService->recordPlay($roundId, $playerId, $played, $remain);
        $nextState = $gamePlayCardService->resolvePlayState(
            $roundId,
            $ctx['seatByPid'],
            (int) $ctx['landlordSeat'],
            LzConsts::MAX_PLAYERS
        );

        $isBomb = $cardType === LaiziCard::CARD_TYPE_BOMB
            || $cardType === LaiziCard::CARD_TYPE_ROCKET;

        // 桌面倍数：叫抢 × 2^炸弹；炸弹次数写入 round.extra，推送 multiple 给前端
        $roundState = is_array($ctx['roundState'] ?? null) ? $ctx['roundState'] : [];
        $bombCountNow = max(0, (int) ($roundState['bombCount'] ?? 0));
        if ($isBomb) {
            $bombCountNow++;
            $roundState['bombCount'] = $bombCountNow;
            $gameRoundService->update($roundId, [
                'extra' => RoundState::encode($roundState),
            ]);
        }

        $data = [
            'callback'     => CallBack::PLAY,
            'action'       => 'play',
            'playIndex'    => $seatNo,
            'cards'        => $played,
            'currentIndex' => $nextState['currentIndex'],
            'handCount'    => count($remain),
            'isBomb'       => $isBomb,
            'bombCount'    => $bombCountNow,
            'multiple'     => BidRule::tableMultiple($bidMultiple, $bombCountNow),
        ];
        if (! empty($body['_auto'])) {
            $data['myCards'] = $remain;
        }

        $roomOver = false;
        if ($remain === []) {
            $data['settle'] = make(RoundSettleApplier::class)->apply(
                $roomId,
                $roundId,
                $masterPid,
                $playerId,
                $seatNo,
                $gameRoundService
            );
            $pack = $gamePlayCardService->getRemainHandsBySeat($roundId, $ctx['seatByPid']);
            $data['settle']['hands'] = $pack['hands'];
            $data['settle']['remainCards'] = $pack['remainCards'];
            $roomOver = (bool) ($data['settle']['roomOver'] ?? false);
        }

        return [$data, $roomOver];
    }

    /**
     * 将本步出牌/过写入录像；有 settle 则 finish
     *
     * @param array<string, mixed> $ctx
     * @param array<string, mixed> $data
     */
    private function syncGameRecord(array $ctx, array $data): void
    {
        $roundId = (int) ($ctx['roundId'] ?? 0);
        if ($roundId <= 0) {
            return;
        }

        try {
            $action = (string) ($data['action'] ?? '');
            $seat = (int) ($ctx['seatNo'] ?? -1);
            if ($action === 'play') {
                $cards = is_array($data['cards'] ?? null) ? array_values($data['cards']) : [];
                GameRecordHooks::append($roundId, 'play', $seat, -1, $cards, []);
            } elseif ($action === 'pass') {
                GameRecordHooks::append($roundId, 'pass', $seat, -1, [], []);
            }

            $settle = $data['settle'] ?? null;
            if (! is_array($settle)) {
                return;
            }

            $landlordSeat = (int) ($ctx['landlordSeat'] ?? -1);
            $landlordWin = (bool) ($settle['landlordWin'] ?? false);
            $rewards = is_array($settle['rewards'] ?? null) ? $settle['rewards'] : [];
            $scores = [];
            $winners = [];
            $playerScores = [];
            foreach ($rewards as $r) {
                if (! is_array($r)) {
                    continue;
                }
                $pid = (int) ($r['playerId'] ?? 0);
                $s = (int) ($r['seatNo'] ?? -1);
                $delta = (float) ($r['delta'] ?? 0);
                $isLandlord = (bool) ($r['isLandlord'] ?? false);
                $isWinner = $landlordWin ? $isLandlord : ! $isLandlord;
                if ($s >= 0) {
                    $scores[(string) $s] = $delta;
                    if ($isWinner) {
                        $winners[] = $s;
                    }
                }
                if ($pid > 0) {
                    $playerScores[] = [
                        'memberId' => $pid,
                        'score'    => $delta,
                        'isWinner' => $isWinner,
                        'isBanker' => $isLandlord || ($landlordSeat >= 0 && $s === $landlordSeat),
                    ];
                }
            }

            GameRecordHooks::finish($roundId, [
                'scores'  => $scores,
                'winners' => $winners,
                'ext'     => [
                    'landlordSeat'  => $landlordSeat,
                    'landlordWin'   => $landlordWin,
                    'winSide'       => $settle['winSide'] ?? null,
                    'multiple'      => $settle['multiple'] ?? null,
                    'bidMultiple'   => $settle['bidMultiple'] ?? null,
                    'bombCount'     => $settle['bombCount'] ?? null,
                    'spring'        => $settle['spring'] ?? null,
                    'springType'    => $settle['springType'] ?? null,
                    'farmerDoubles' => $settle['farmerDoubles'] ?? [],
                    'unit'          => $settle['unit'] ?? null,
                    'remainCards'   => $settle['remainCards'] ?? [],
                    'roomOver'      => (bool) ($settle['roomOver'] ?? false),
                ],
            ], $playerScores);
        } catch (\Throwable) {
            // 录像失败不影响牌局
        }
    }
}
