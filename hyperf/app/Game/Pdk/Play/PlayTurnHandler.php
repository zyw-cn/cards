<?php

declare(strict_types=1);

namespace App\Game\Pdk\Play;

use App\Exception\ApiException;
use App\Game\Pdk\CallBack;
use App\Game\Pdk\Card\PdkCard;
use App\Game\Pdk\PdkConsts;
use App\Game\Pdk\RoundState;
use App\Game\Support\GameRecordHooks;
use App\Model\Game\GameRound;
use App\Service\Game\GamePlayCardService;
use App\Service\Game\GameRoundService;
use App\Service\Game\PdkSettleService;
use App\Service\Game\RoomMemberService;
use function Hyperf\Support\make;

/**
 * 跑得快出牌阶段：校验、不出/出牌、局末结算
 */
final class PlayTurnHandler
{
    /**
     * @param array<string, mixed> $body
     * @param list<string> $played
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

        $actualPlayed = is_array($ctx['validation']['played'] ?? null)
            ? $ctx['validation']['played']
            : $played;
        $roomOver = false;
        if ($ctx['isPass']) {
            $data = $this->applyPass($ctx, $body);
        } else {
            [$data, $roomOver] = $this->applyPlay($ctx, $actualPlayed, $body, $gameRoundService);
        }

        if (empty($body['_auto'])) {
            $roomMemberService->clearTrust($roomId, $playerId);
        }

        return ['data' => $data, 'roomOver' => $roomOver];
    }

    /**
     * @param list<string> $played
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
        $firstPid = (int) ($round->master_id ?? 0);
        if ($firstPid === 0) {
            throw new ApiException('本局尚未发牌');
        }

        $roundState = RoundState::decode($round->extra ?? null);
        $members = $roomMemberService->getActiveInRoom($roomId, 'player_id,seat_no');
        $seatByPid = [];
        $firstSeat = (int) ($roundState['firstSeat'] ?? 0);
        foreach ($members as $m) {
            $pid = (int) ($m['player_id'] ?? 0);
            $s = (int) ($m['seat_no'] ?? 0);
            $seatByPid[$pid] = $s;
            if ($pid === $firstPid) {
                $firstSeat = $s;
            }
        }

        $validation = make(PlayValidator::class)->validate(
            $gamePlayCardService,
            $roundId,
            $playerId,
            $seatNo,
            $played,
            $seatByPid,
            $firstSeat,
            (bool) ($roundState['spade3Played'] ?? false),
            is_array($roundState['surrendered'] ?? null) ? $roundState['surrendered'] : []
        );
        if (! ($validation['ok'] ?? false)) {
            throw new ApiException((string) ($validation['message'] ?? '出牌不合法'));
        }

        return [
            'roundId'      => $roundId,
            'firstPid'     => $firstPid,
            'firstSeat'    => $firstSeat,
            'roundState'   => $roundState,
            'seatByPid'    => $seatByPid,
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
        GameRecordHooks::append($roundId, 'pass', $seatNo);
        $surrendered = is_array($ctx['roundState']['surrendered'] ?? null)
            ? $ctx['roundState']['surrendered']
            : [];
        $nextState = make(PdkPlayStateResolver::class)->resolve(
            $gamePlayCardService,
            $roundId,
            $ctx['seatByPid'],
            (int) $ctx['firstSeat'],
            $surrendered
        );

        $data = [
            'callback'     => CallBack::PLAY,
            'action'       => 'pass',
            'passIndex'    => $seatNo,
            'currentIndex' => $nextState['currentIndex'],
            'handCount'    => count($hand),
        ];
        $surrendered = is_array($ctx['roundState']['surrendered'] ?? null)
            ? $ctx['roundState']['surrendered']
            : [];
        if ($surrendered !== []) {
            $data['surrenderedSeats'] = $surrendered;
        }
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

        $remain = $validation['remain'] ?? [];
        $cardType = (string) ($validation['cardType'] ?? '');
        $roundState = is_array($ctx['roundState'] ?? null) ? $ctx['roundState'] : [];
        $gamePlayCardService->recordPlay($roundId, $playerId, $played, $remain);
        GameRecordHooks::append($roundId, 'play', $seatNo, -1, $played, [
            'cardType' => $cardType,
        ]);

        // 炸弹 / 三个 A（最大）：当场结算，其余两人各付底分×bomb_multiple
        $isBombSettle = PdkCard::isBombSettlePlay($played);
        $isBomb = $cardType === PdkCard::CARD_TYPE_BOMB;
        $bombs = is_array($roundState['bombs'] ?? null) ? array_values($roundState['bombs']) : [];
        $bombCountNow = max(0, (int) ($roundState['bombCount'] ?? count($bombs)));
        $changed = false;
        $bombSettle = null;
        if ($isBombSettle) {
            $bombs[] = $playerId;
            $roundState['bombs'] = $bombs;
            $bombCountNow = count($bombs);
            $roundState['bombCount'] = $bombCountNow;
            $changed = true;
            $bombSettle = make(PdkSettleService::class)->settleBombNow($roomId, $roundId, $playerId);
            if ($bombSettle !== null) {
                $roundState = SurrenderHelper::markBrokeAfterBomb(
                    $roundState,
                    is_array($bombSettle['rewards'] ?? null) ? $bombSettle['rewards'] : []
                );
                $scores = is_array($roundState['bombScores'] ?? null) ? $roundState['bombScores'] : [];
                foreach (is_array($bombSettle['rewards'] ?? null) ? $bombSettle['rewards'] : [] as $reward) {
                    if (! is_array($reward)) {
                        continue;
                    }
                    $pidKey = (string) (int) ($reward['playerId'] ?? 0);
                    if ($pidKey === '0') {
                        continue;
                    }
                    $prev = number_format((float) ($scores[$pidKey] ?? 0), 2, '.', '');
                    $add = number_format((float) ($reward['delta'] ?? 0), 2, '.', '');
                    $scores[$pidKey] = bcadd($prev, $add, 2);
                }
                $roundState['bombScores'] = $scores;
                $changed = true;
            }
        }
        if (! empty($validation['fangzou'])) {
            $nextSeat = ($seatNo + 1) % PdkConsts::MAX_PLAYERS;
            $pidBySeat = array_flip(is_array($ctx['seatByPid'] ?? null) ? $ctx['seatByPid'] : []);
            $nextPid = (int) ($pidBySeat[$nextSeat] ?? 0);
            if ($nextPid > 0) {
                $roundState['fangzou'] = [
                    'by'   => $playerId,
                    'next' => $nextPid,
                ];
                $changed = true;
            }
        }
        if (empty($roundState['spade3Played'])) {
            $roundState['spade3Played'] = true;
            $changed = true;
        }
        if ($changed) {
            $gameRoundService->update($roundId, [
                'extra' => RoundState::encode($roundState),
            ]);
        }

        $surrendered = is_array($roundState['surrendered'] ?? null) ? $roundState['surrendered'] : [];
        $nextState = make(PdkPlayStateResolver::class)->resolve(
            $gamePlayCardService,
            $roundId,
            $ctx['seatByPid'],
            (int) $ctx['firstSeat'],
            $surrendered
        );
        $nextState['currentIndex'] = SurrenderHelper::advanceToActive(
            (int) ($nextState['currentIndex'] ?? $seatNo),
            $surrendered,
            PdkConsts::MAX_PLAYERS
        );

        $data = [
            'callback'     => CallBack::PLAY,
            'action'       => 'play',
            'playIndex'    => $seatNo,
            'cards'        => $played,
            'cardType'     => $cardType,
            'currentIndex' => $nextState['currentIndex'],
            'handCount'    => count($remain),
            'isBomb'       => $isBomb || $isBombSettle,
            'bombCount'    => $bombCountNow,
        ];
        if (! empty($validation['forcedPlay'])) {
            $data['forcedPlay'] = true;
        }
        if ($bombSettle !== null) {
            $data['bombSettle'] = $bombSettle;
            $data['bombSettle']['surrenderedSeats'] = $surrendered;
        }
        if ($surrendered !== []) {
            $data['surrenderedSeats'] = $surrendered;
        }
        if (! empty($body['_auto']) || ! empty($validation['forcedPlay'])) {
            $data['myCards'] = $remain;
        }

        $roomOver = false;
        $autoSettle = $this->maybeAutoWinAfterSurrender(
            $ctx,
            $roundState,
            $playerId,
            $seatNo,
            $gameRoundService
        );
        if ($autoSettle !== null) {
            $data['settle'] = $autoSettle;
            $roomOver = (bool) ($autoSettle['roomOver'] ?? false);
            self::finishGameRecord($roundId, $autoSettle);
        } elseif ($remain === []) {
            $data['settle'] = make(RoundSettleApplier::class)->apply(
                $roomId,
                $roundId,
                $playerId,
                $seatNo,
                $gameRoundService
            );
            $roomOver = (bool) ($data['settle']['roomOver'] ?? false);
            self::finishGameRecord($roundId, $data['settle']);
        }

        return [$data, $roomOver];
    }

    /**
     * @param array<string, mixed> $settle
     */
    private static function finishGameRecord(int $roundId, array $settle): void
    {
        $rewards = is_array($settle['rewards'] ?? null) ? $settle['rewards'] : [];
        $scores = [];
        $winners = [];
        $playerScores = [];
        foreach ($rewards as $rw) {
            if (! is_array($rw)) {
                continue;
            }
            $pid = (int) ($rw['playerId'] ?? 0);
            $seat = (int) ($rw['seatNo'] ?? 0);
            $delta = $rw['delta'] ?? 0;
            $scores[(string) $seat] = $delta;
            $isWinner = ! empty($rw['isWinner']);
            if ($isWinner) {
                $winners[] = $seat;
            }
            if ($pid > 0) {
                $playerScores[] = [
                    'memberId' => $pid,
                    'score'    => $delta,
                    'isWinner' => $isWinner,
                ];
            }
        }
        GameRecordHooks::finish($roundId, [
            'scores'  => $scores,
            'winners' => $winners,
            'ext'     => [
                'unit'         => $settle['unit'] ?? 0,
                'bombUnit'     => $settle['bombUnit'] ?? 0,
                'bombMultiple' => $settle['bombMultiple'] ?? 0,
                'bombCount'    => $settle['bombCount'] ?? 0,
                'roomOver'     => (bool) ($settle['roomOver'] ?? false),
            ],
        ], $playerScores);
    }

    /**
     * 仅余一名未认输玩家时自动胜局
     *
     * @param array<string, mixed> $ctx
     * @param array<string, mixed> $roundState
     * @return array<string, mixed>|null
     */
    private function maybeAutoWinAfterSurrender(
        array $ctx,
        array $roundState,
        int $playerId,
        int $seatNo,
        GameRoundService $gameRoundService,
    ): ?array {
        $surrendered = is_array($roundState['surrendered'] ?? null) ? $roundState['surrendered'] : [];
        $allSeats = array_values($ctx['seatByPid']);
        $active = SurrenderHelper::activeSeats($allSeats, $surrendered);
        if (count($active) > 1) {
            return null;
        }

        $winnerSeat = count($active) === 1 ? (int) $active[0] : $seatNo;
        $winnerPid = $playerId;
        foreach ($ctx['seatByPid'] as $pid => $seat) {
            if ((int) $seat === $winnerSeat) {
                $winnerPid = (int) $pid;
                break;
            }
        }

        return make(RoundSettleApplier::class)->apply(
            (int) $ctx['roomId'],
            (int) $ctx['roundId'],
            $winnerPid,
            $winnerSeat,
            $gameRoundService
        );
    }
}
