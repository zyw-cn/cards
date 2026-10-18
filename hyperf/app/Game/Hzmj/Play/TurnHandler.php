<?php

declare(strict_types=1);

namespace App\Game\Hzmj\Play;

use App\Exception\ApiException;
use App\Game\Hzmj\CallBack;
use App\Game\Hzmj\HzmjConsts;
use App\Game\Hzmj\RoundState;
use App\Game\Hzmj\Rule\HzmjRules;
use App\Game\Hzmj\Rule\LuckyTiles;
use App\Game\Support\GameRecordHooks;
use App\Model\Game\GameRoom;
use App\Model\Game\GameRound;
use App\Service\Game\GamePlayCardService;
use App\Service\Game\GameRoundService;
use App\Service\Game\RoomMemberService;
use App\Service\Game\RoomService;
use function Hyperf\Support\make;

/**
 * 红中麻将操作：出牌 / 过 / 碰 / 杠 / 自摸胡
 */
final class TurnHandler
{
    /**
     * @param array<string, mixed> $body
     * @return array{data: array<string, mixed>, roomOver: bool}
     */
    public function execute(
        int $roomId,
        int $playerId,
        int $seatNo,
        string $op,
        ?int $tile,
        array $body,
        RoomMemberService $roomMemberService,
        GameRoundService $gameRoundService,
        GamePlayCardService $gamePlayCardService,
    ): array {
        $round = $gameRoundService->lockForUpdate(
            ['room_id' => $roomId, 'status' => GameRound::STATUS_PLAYING],
            ['id', 'master_id', 'status', 'extra']
        );
        if ($round === null) {
            throw new ApiException('本局尚未开始');
        }
        $roundId = (int) $round->id;
        if ((int) ($round->master_id ?? 0) <= 0) {
            throw new ApiException('本局尚未发牌');
        }

        $state = RoundState::decode($round->extra ?? null);
        $phase = (string) ($state['phase'] ?? '');
        $allowedPhases = [HzmjConsts::PHASE_PLAYING, HzmjConsts::PHASE_WAIT];
        if (! in_array($phase, $allowedPhases, true)) {
            throw new ApiException('当前不在出牌阶段');
        }

        $members = $roomMemberService->getActiveInRoom($roomId, 'player_id,seat_no,fd');
        $seatByPid = [];
        $pidBySeat = [];
        $seats = [];
        foreach ($members as $m) {
            $pid = (int) ($m['player_id'] ?? 0);
            $s = (int) ($m['seat_no'] ?? 0);
            $seatByPid[$pid] = $s;
            $pidBySeat[$s] = $pid;
            $seats[] = $s;
        }
        sort($seats);
        $maxPlayers = max(count($seats), (int) ($state['maxPlayers'] ?? count($seats)));
        $kingTiles = array_map('intval', is_array($state['kingTiles'] ?? null) ? $state['kingTiles'] : [HzmjConsts::KING_TILE]);

        $hand = HzmjRules::toIntTiles($gamePlayCardService->getLatestHand($roundId, $playerId));
        $ctx = [
            'roomId'       => $roomId,
            'roundId'      => $roundId,
            'playerId'     => $playerId,
            'seatNo'       => $seatNo,
            'state'        => $state,
            'seatByPid'    => $seatByPid,
            'pidBySeat'    => $pidBySeat,
            'seats'        => $seats,
            'maxPlayers'   => $maxPlayers,
            'kingTiles'    => $kingTiles,
            'hand'         => $hand,
            'gamePlayCard' => $gamePlayCardService,
            'gameRound'    => $gameRoundService,
            'body'         => $body,
        ];

        $data = match ($op) {
            'discard' => $this->applyDiscard($ctx, $tile),
            'pass'    => $this->applyPass($ctx),
            'pong'    => $this->applyPong($ctx),
            'kong'    => $this->applyKong($ctx),
            'win'     => $this->applyWin($ctx),
            default   => throw new ApiException('未知操作'),
        };

        $this->syncGameRecord($ctx, $data);

        $roomOver = (bool) ($data['settle']['roomOver'] ?? false);

        if (empty($body['_auto'])) {
            $roomMemberService->clearTrust($roomId, $playerId);
        }

        return ['data' => $data, 'roomOver' => $roomOver];
    }

    /**
     * @param array<string, mixed> $ctx
     * @return array<string, mixed>
     */
    private function applyDiscard(array $ctx, ?int $tile): array
    {
        if ($tile === null || $tile <= 0) {
            throw new ApiException('请选择要打出的牌');
        }
        $state = $ctx['state'];
        $seatNo = (int) $ctx['seatNo'];
        if (! empty($state['pendingDiscard'])) {
            throw new ApiException('有人响应中，不能出牌');
        }
        if ((int) ($state['curSeat'] ?? -1) !== $seatNo) {
            throw new ApiException('还没轮到你出牌');
        }
        $waitOps = is_array($state['waitOps'] ?? null) ? $state['waitOps'] : [];
        // 摸牌提示未过完也可直接出牌（等同过掉杠/胡提示）
        unset($waitOps[(string) $seatNo]);

        $hand = $ctx['hand'];
        if (! in_array($tile, $hand, true)) {
            throw new ApiException('手牌中没有该牌');
        }

        $remain = HzmjRules::sortTiles(HzmjRules::removeTiles($hand, $tile, 1), HzmjConsts::KING_TILE);
        /** @var GamePlayCardService $gpc */
        $gpc = $ctx['gamePlayCard'];
        $gpc->recordPlay(
            (int) $ctx['roundId'],
            (int) $ctx['playerId'],
            [(string) $tile],
            HzmjRules::toStringTiles($remain)
        );

        $discards = is_array($state['discards'] ?? null) ? $state['discards'] : [];
        $key = (string) $seatNo;
        if (! isset($discards[$key]) || ! is_array($discards[$key])) {
            $discards[$key] = [];
        }
        $discards[$key][] = $tile;
        $state['discards'] = $discards;

        $claimOps = $this->buildDiscardWaitOps($ctx, $seatNo, $tile, $remain);
        if ($claimOps !== []) {
            $state['pendingDiscard'] = ['tile' => $tile, 'fromSeat' => $seatNo];
            $state['waitOps'] = $claimOps;
            $state['phase'] = HzmjConsts::PHASE_WAIT;
            $this->saveState($ctx, $state);

            return $this->basePayload($ctx, $state, [
                'action'   => 'discard',
                'seat'     => $seatNo,
                'tile'     => $tile,
                'myTiles'  => $remain,
                'handCount'=> count($remain),
                'myWaitOps'=> [],
                // 各座位问询操作，由 OpAction 按 fd 分发 myWaitOps
                '_waitOpsBySeat' => $claimOps,
            ]);
        }

        return $this->advanceAfterDiscard($ctx, $state, $seatNo, [
            'action'   => 'discard',
            'seat'     => $seatNo,
            'tile'     => $tile,
            'myTiles'  => $remain,
            'handCount'=> count($remain),
        ]);
    }

    /**
     * @param array<string, mixed> $ctx
     * @param list<int> $actorRemain 仅用于本家手牌提示，不影响其他人 wait 计算
     * @return array<string, list<int>>
     */
    private function buildDiscardWaitOps(array $ctx, int $fromSeat, int $tile, array $actorRemain): array
    {
        unset($actorRemain);
        $kingTiles = $ctx['kingTiles'];
        $gpc = $ctx['gamePlayCard'];
        $roundId = (int) $ctx['roundId'];
        $wait = [];
        foreach ($ctx['pidBySeat'] as $seat => $pid) {
            $seat = (int) $seat;
            if ($seat === $fromSeat || $pid <= 0) {
                continue;
            }
            $hand = HzmjRules::toIntTiles($gpc->getLatestHand($roundId, (int) $pid));
            $ops = HzmjRules::getDiscardNotifyOps($hand, $tile, $kingTiles);
            if ($ops !== []) {
                $wait[(string) $seat] = array_values(array_merge($ops, [HzmjConsts::SHOW_OP_PASS]));
            }
        }

        return $wait;
    }

    /**
     * @param array<string, mixed> $ctx
     * @param array<string, mixed> $state
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    private function advanceAfterDiscard(array $ctx, array $state, int $fromSeat, array $extra = []): array
    {
        $state['pendingDiscard'] = null;
        $state['waitOps'] = [];
        $wall = array_values(array_map('intval', is_array($state['tileWall'] ?? null) ? $state['tileWall'] : []));
        if ($wall === []) {
            return $this->endDraw($ctx, $state, $extra);
        }

        $next = $this->nextSeat($fromSeat, $ctx['seats']);
        $drawn = array_shift($wall);
        $state['tileWall'] = $wall;
        $state['curSeat'] = $next;
        $state['lastDrawnTile'] = (int) $drawn;
        $state['phase'] = HzmjConsts::PHASE_PLAYING;

        $nextPid = (int) ($ctx['pidBySeat'][$next] ?? 0);
        if ($nextPid <= 0) {
            throw new ApiException('下家座位无效');
        }
        $nextHand = HzmjRules::toIntTiles(
            make(GamePlayCardService::class)->getLatestHand((int) $ctx['roundId'], $nextPid)
        );
        $nextHand = HzmjRules::sortTiles(array_merge($nextHand, [$drawn]), HzmjConsts::KING_TILE);
        make(GamePlayCardService::class)->recordPlay(
            (int) $ctx['roundId'],
            $nextPid,
            [(string) $drawn],
            HzmjRules::toStringTiles($nextHand)
        );

        $ups = $this->upsOfSeat($state, $next);
        $drawOps = HzmjRules::getDrawNotifyOps($nextHand, $ups, $ctx['kingTiles']);
        if ($drawOps !== []) {
            $state['waitOps'] = [
                (string) $next => array_values(array_merge($drawOps, [HzmjConsts::SHOW_OP_PASS])),
            ];
        }

        $this->saveState($ctx, $state);

        $payload = array_merge($extra, [
            'drawSeat'   => $next,
            'drawnTile'  => null, // 仅摸牌者私有
            'curSeat'    => $next,
            'leftTileNum'=> count($wall),
        ]);
        // 给摸牌者补私有字段（OpAction 广播时按 actor 保留）
        if ((int) $ctx['seatNo'] === $next) {
            $payload['myTiles'] = $nextHand;
            $payload['drawnTile'] = $drawn;
            $payload['myWaitOps'] = $state['waitOps'][(string) $next] ?? [];
            $payload['handCount'] = count($nextHand);
        } else {
            $payload['_drawPrivate'] = [
                'seat'      => $next,
                'playerId'  => $nextPid,
                'myTiles'   => $nextHand,
                'drawnTile' => $drawn,
                'myWaitOps' => $state['waitOps'][(string) $next] ?? [],
            ];
            if (! isset($payload['myTiles'])) {
                $payload['myTiles'] = $ctx['hand'];
            }
            $payload['myWaitOps'] = [];
        }

        return $this->basePayload($ctx, $state, $payload);
    }

    /**
     * @param array<string, mixed> $ctx
     * @return array<string, mixed>
     */
    private function applyPass(array $ctx): array
    {
        $state = $ctx['state'];
        $seatNo = (int) $ctx['seatNo'];
        $waitOps = is_array($state['waitOps'] ?? null) ? $state['waitOps'] : [];
        $key = (string) $seatNo;

        if (! empty($state['pendingDiscard'])) {
            if (! isset($waitOps[$key])) {
                throw new ApiException('当前无需操作');
            }
            unset($waitOps[$key]);
            $state['waitOps'] = $waitOps;
            if ($waitOps === []) {
                $fromSeat = (int) ($state['pendingDiscard']['fromSeat'] ?? $seatNo);

                return $this->advanceAfterDiscard($ctx, $state, $fromSeat, [
                    'action'    => 'pass',
                    'seat'      => $seatNo,
                    'myTiles'   => $ctx['hand'],
                    'handCount' => count($ctx['hand']),
                ]);
            }
            $state['phase'] = HzmjConsts::PHASE_WAIT;
            $this->saveState($ctx, $state);

            return $this->basePayload($ctx, $state, [
                'action'    => 'pass',
                'seat'      => $seatNo,
                'myTiles'   => $ctx['hand'],
                'handCount' => count($ctx['hand']),
                'myWaitOps' => [],
            ]);
        }

        // 抢杠胡问询：全部过 → 续杠算分并补牌
        if (! empty($state['pendingKongWin'])) {
            if (! isset($waitOps[$key])) {
                throw new ApiException('当前无需操作');
            }
            unset($waitOps[$key]);
            $state['waitOps'] = $waitOps;
            if ($waitOps === []) {
                return $this->resolveContinueKongAfterPass($ctx, $state, $seatNo);
            }
            $state['phase'] = HzmjConsts::PHASE_WAIT;
            $this->saveState($ctx, $state);

            return $this->basePayload($ctx, $state, [
                'action'    => 'pass',
                'seat'      => $seatNo,
                'myTiles'   => $ctx['hand'],
                'handCount' => count($ctx['hand']),
                'myWaitOps' => [],
            ]);
        }

        // 摸牌回合过掉自摸/杠提示
        if ((int) ($state['curSeat'] ?? -1) !== $seatNo) {
            throw new ApiException('还没轮到你');
        }
        unset($waitOps[$key]);
        $state['waitOps'] = $waitOps;
        $state['phase'] = HzmjConsts::PHASE_PLAYING;
        $this->saveState($ctx, $state);

        return $this->basePayload($ctx, $state, [
            'action'    => 'pass',
            'seat'      => $seatNo,
            'myTiles'   => $ctx['hand'],
            'handCount' => count($ctx['hand']),
            'myWaitOps' => [],
        ]);
    }

    /**
     * @param array<string, mixed> $ctx
     * @return array<string, mixed>
     */
    private function applyPong(array $ctx): array
    {
        $state = $ctx['state'];
        $pending = $state['pendingDiscard'] ?? null;
        if (! is_array($pending)) {
            throw new ApiException('没有可碰的牌');
        }
        $tile = (int) ($pending['tile'] ?? 0);
        $fromSeat = (int) ($pending['fromSeat'] ?? -1);
        $seatNo = (int) $ctx['seatNo'];
        $waitOps = is_array($state['waitOps'] ?? null) ? $state['waitOps'] : [];
        if (! isset($waitOps[(string) $seatNo])) {
            throw new ApiException('当前不能碰');
        }
        $hand = $ctx['hand'];
        if (! HzmjRules::canPong($hand, $tile, $ctx['kingTiles'])) {
            throw new ApiException('不能碰');
        }

        $remain = HzmjRules::sortTiles(HzmjRules::removeTiles($hand, $tile, 2), HzmjConsts::KING_TILE);
        make(GamePlayCardService::class)->recordPlay(
            (int) $ctx['roundId'],
            (int) $ctx['playerId'],
            [(string) $tile, (string) $tile],
            HzmjRules::toStringTiles($remain)
        );

        $this->popLastDiscard($state, $fromSeat, $tile);
        $ups = is_array($state['ups'] ?? null) ? $state['ups'] : [];
        $uk = (string) $seatNo;
        if (! isset($ups[$uk]) || ! is_array($ups[$uk])) {
            $ups[$uk] = [];
        }
        $ups[$uk][] = [$tile, $tile, $tile];
        $state['ups'] = $ups;
        $state['pendingDiscard'] = null;
        $state['waitOps'] = [];
        $state['curSeat'] = $seatNo;
        $state['phase'] = HzmjConsts::PHASE_PLAYING;
        $this->saveState($ctx, $state);

        return $this->basePayload($ctx, $state, [
            'action'    => 'pong',
            'seat'      => $seatNo,
            'fromSeat'  => $fromSeat,
            'tile'      => $tile,
            'meld'      => [$tile, $tile, $tile],
            'myTiles'   => $remain,
            'handCount' => count($remain),
            'myWaitOps' => [],
        ]);
    }

    /**
     * @param array<string, mixed> $ctx
     * @return array<string, mixed>
     */
    private function applyKong(array $ctx): array
    {
        $state = $ctx['state'];
        $seatNo = (int) $ctx['seatNo'];
        $hand = $ctx['hand'];
        $pending = $state['pendingDiscard'] ?? null;

        if (is_array($pending)) {
            return $this->applyExposedKong($ctx, $state, $pending);
        }

        if ((int) ($state['curSeat'] ?? -1) !== $seatNo) {
            throw new ApiException('还没轮到你杠');
        }

        $ups = $this->upsOfSeat($state, $seatNo);
        $concealed = HzmjRules::getOneConcealedKongTile($hand, $ctx['kingTiles']);
        if ($concealed > 0) {
            $remain = HzmjRules::sortTiles(HzmjRules::removeTiles($hand, $concealed, 4), HzmjConsts::KING_TILE);
            make(GamePlayCardService::class)->recordPlay(
                (int) $ctx['roundId'],
                (int) $ctx['playerId'],
                array_fill(0, 4, (string) $concealed),
                HzmjRules::toStringTiles($remain)
            );
            $allUps = is_array($state['ups'] ?? null) ? $state['ups'] : [];
            $uk = (string) $seatNo;
            if (! isset($allUps[$uk]) || ! is_array($allUps[$uk])) {
                $allUps[$uk] = [];
            }
            $allUps[$uk][] = [0, 0, 0, $concealed];
            $state['ups'] = $allUps;
            $scores = $this->applyKongScores($ctx, $state, 'concealed', $seatNo, -1);

            return $this->afterKongDraw($ctx, $state, $remain, [
                'action'   => 'kong',
                'kongType' => 'concealed',
                'seat'     => $seatNo,
                'tile'     => $concealed,
                'meld'     => [0, 0, 0, $concealed],
                'scores'   => $scores,
            ]);
        }

        $cont = HzmjRules::getContinueKongTile($hand, $ups, $ctx['kingTiles']);
        if ($cont > 0) {
            $remain = HzmjRules::sortTiles(HzmjRules::removeTiles($hand, $cont, 1), HzmjConsts::KING_TILE);
            make(GamePlayCardService::class)->recordPlay(
                (int) $ctx['roundId'],
                (int) $ctx['playerId'],
                [(string) $cont],
                HzmjRules::toStringTiles($remain)
            );
            $allUps = is_array($state['ups'] ?? null) ? $state['ups'] : [];
            $uk = (string) $seatNo;
            $list = is_array($allUps[$uk] ?? null) ? $allUps[$uk] : [];
            foreach ($list as $i => $m) {
                if (is_array($m) && count($m) === 3 && (int) $m[0] === $cont) {
                    $list[$i] = [$cont, $cont, $cont, $cont];
                    break;
                }
            }
            $allUps[$uk] = $list;
            $state['ups'] = $allUps;

            // 抢杠胡：其他家可胡则先问询
            $claimOps = $this->buildContinueKongWinOps($ctx, $seatNo, $cont);
            if ($claimOps !== []) {
                $state['pendingKongWin'] = ['tile' => $cont, 'fromSeat' => $seatNo];
                $state['pendingDiscard'] = null;
                $state['waitOps'] = $claimOps;
                $state['phase'] = HzmjConsts::PHASE_WAIT;
                $state['curSeat'] = $seatNo;
                $this->saveState($ctx, $state);

                return $this->basePayload($ctx, $state, [
                    'action'         => 'kong',
                    'kongType'       => 'continue',
                    'seat'           => $seatNo,
                    'tile'           => $cont,
                    'meld'           => [$cont, $cont, $cont, $cont],
                    'myTiles'        => $remain,
                    'handCount'      => count($remain),
                    'myWaitOps'      => [],
                    '_waitOpsBySeat' => $claimOps,
                    'pendingKongWin' => $state['pendingKongWin'],
                ]);
            }

            $scores = $this->applyKongScores($ctx, $state, 'continue', $seatNo, -1);

            return $this->afterKongDraw($ctx, $state, $remain, [
                'action'   => 'kong',
                'kongType' => 'continue',
                'seat'     => $seatNo,
                'tile'     => $cont,
                'meld'     => [$cont, $cont, $cont, $cont],
                'scores'   => $scores,
            ]);
        }

        throw new ApiException('不能杠');
    }

    /**
     * @param array<string, mixed> $ctx
     * @param array<string, mixed> $state
     * @param array{tile?:int,fromSeat?:int} $pending
     * @return array<string, mixed>
     */
    private function applyExposedKong(array $ctx, array $state, array $pending): array
    {
        $tile = (int) ($pending['tile'] ?? 0);
        $fromSeat = (int) ($pending['fromSeat'] ?? -1);
        $seatNo = (int) $ctx['seatNo'];
        $waitOps = is_array($state['waitOps'] ?? null) ? $state['waitOps'] : [];
        if (! isset($waitOps[(string) $seatNo])) {
            throw new ApiException('当前不能杠');
        }
        $hand = $ctx['hand'];
        if (! HzmjRules::canExposedKong($hand, $tile, $ctx['kingTiles'])) {
            throw new ApiException('不能明杠');
        }

        $remain = HzmjRules::sortTiles(HzmjRules::removeTiles($hand, $tile, 3), HzmjConsts::KING_TILE);
        make(GamePlayCardService::class)->recordPlay(
            (int) $ctx['roundId'],
            (int) $ctx['playerId'],
            array_fill(0, 3, (string) $tile),
            HzmjRules::toStringTiles($remain)
        );
        $this->popLastDiscard($state, $fromSeat, $tile);
        $ups = is_array($state['ups'] ?? null) ? $state['ups'] : [];
        $uk = (string) $seatNo;
        if (! isset($ups[$uk]) || ! is_array($ups[$uk])) {
            $ups[$uk] = [];
        }
        $ups[$uk][] = [$tile, $tile, $tile, $tile];
        $state['ups'] = $ups;
        $state['pendingDiscard'] = null;
        $state['waitOps'] = [];
        $state['curSeat'] = $seatNo;
        $scores = $this->applyKongScores($ctx, $state, 'exposed', $seatNo, $fromSeat);

        return $this->afterKongDraw($ctx, $state, $remain, [
            'action'   => 'kong',
            'kongType' => 'exposed',
            'seat'     => $seatNo,
            'fromSeat' => $fromSeat,
            'tile'     => $tile,
            'meld'     => [$tile, $tile, $tile, $tile],
            'scores'   => $scores,
        ]);
    }

    /**
     * @param array<string, mixed> $ctx
     * @param array<string, mixed> $state
     * @param list<int> $handBeforeDraw
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    private function afterKongDraw(array $ctx, array $state, array $handBeforeDraw, array $extra): array
    {
        $wall = array_values(array_map('intval', is_array($state['tileWall'] ?? null) ? $state['tileWall'] : []));
        if ($wall === []) {
            return $this->endDraw($ctx, $state, $extra);
        }
        $drawn = array_shift($wall);
        $state['tileWall'] = $wall;
        $seatNo = (int) $ctx['seatNo'];
        $state['curSeat'] = $seatNo;
        $state['lastDrawnTile'] = (int) $drawn;
        $state['phase'] = HzmjConsts::PHASE_PLAYING;
        $hand = HzmjRules::sortTiles(array_merge($handBeforeDraw, [$drawn]), HzmjConsts::KING_TILE);
        make(GamePlayCardService::class)->recordPlay(
            (int) $ctx['roundId'],
            (int) $ctx['playerId'],
            [(string) $drawn],
            HzmjRules::toStringTiles($hand)
        );
        $ups = $this->upsOfSeat($state, $seatNo);
        $drawOps = HzmjRules::getDrawNotifyOps($hand, $ups, $ctx['kingTiles']);
        $state['waitOps'] = $drawOps === []
            ? []
            : [(string) $seatNo => array_values(array_merge($drawOps, [HzmjConsts::SHOW_OP_PASS]))];
        $this->saveState($ctx, $state);

        return $this->basePayload($ctx, $state, array_merge($extra, [
            'drawnTile'  => $drawn,
            'myTiles'    => $hand,
            'handCount'  => count($hand),
            'myWaitOps'  => $state['waitOps'][(string) $seatNo] ?? [],
            'leftTileNum'=> count($wall),
            'curSeat'    => $seatNo,
        ]));
    }

    /**
     * @param array<string, mixed> $ctx
     * @return array<string, mixed>
     */
    private function applyWin(array $ctx): array
    {
        $state = $ctx['state'];
        $seatNo = (int) $ctx['seatNo'];

        // 抢杠胡
        if (! empty($state['pendingKongWin'])) {
            return $this->applyKongWin($ctx, $state);
        }

        if (! empty($state['pendingDiscard'])) {
            throw new ApiException('红中麻将不可点炮');
        }
        if ((int) ($state['curSeat'] ?? -1) !== $seatNo) {
            throw new ApiException('还没轮到你');
        }
        $hand = $ctx['hand'];
        if (! HzmjRules::canWin($hand, $ctx['kingTiles'])) {
            throw new ApiException('不能胡牌');
        }

        $finalTile = (int) ($state['lastDrawnTile'] ?? 0);
        if ($finalTile <= 0 && count($hand) > 0) {
            $finalTile = (int) $hand[count($hand) - 1];
        }
        $cfgLuckyNum = (int) ($state['luckyNum'] ?? 2);
        $wall = array_values(array_map('intval', is_array($state['tileWall'] ?? null) ? $state['tileWall'] : []));
        [$luckyTiles, $luckyHit, $wall] = LuckyTiles::draw($wall, $hand, $cfgLuckyNum);
        $state['tileWall'] = $wall;

        $activeSeats = $this->activeSeats($ctx);
        $scores = RoundState::scaleScoreDeltas(
            LuckyTiles::scoreDeltas($seatNo, $luckyHit, $cfgLuckyNum, $activeSeats),
            (float) ($state['df'] ?? 1),
            (int) ($state['bankBeishu'] ?? 1)
        );
        $this->mergeTotalScores($state, $scores);

        $state['pendingDiscard'] = null;
        $state['pendingKongWin'] = null;
        $state['waitOps'] = [];
        $state['phase'] = HzmjConsts::PHASE_WAIT;
        $state['dealerSeat'] = $seatNo;
        $this->saveState($ctx, $state);

        $settle = $this->settleRound(
            (int) $ctx['roomId'],
            (int) $ctx['roundId'],
            (int) $ctx['playerId'],
            $seatNo,
            'win',
            $state
        );
        $settle['luckyTiles'] = $luckyTiles;
        $settle['luckyHit'] = $luckyHit;
        $settle['luckyNum'] = $cfgLuckyNum;
        $settle['scores'] = $scores;
        $settle['finalTile'] = $finalTile;
        $settle['winOp'] = 'draw_win';
        $settle['df'] = (float) ($state['df'] ?? 1);
        $settle['bankBeishu'] = max(1, (int) ($state['bankBeishu'] ?? 1));

        $showHands = $this->collectShowHands($ctx);

        return $this->basePayload($ctx, $state, [
            'action'     => 'win',
            'seat'       => $seatNo,
            'result'     => 'win',
            'winOp'      => 'draw_win',
            'finalTile'  => $finalTile,
            'myTiles'    => $hand,
            'handCount'  => count($hand),
            'myWaitOps'  => [],
            'settle'     => $settle,
            'dealerSeat' => $seatNo,
            'showHands'  => $showHands,
            'winSeat'    => $seatNo,
            'luckyTiles' => $luckyTiles,
            'luckyHit'   => $luckyHit,
            'leftTileNum'=> count($wall),
            'totalScores'=> $state['totalScores'] ?? [],
        ]);
    }

    /**
     * 抢杠胡
     *
     * @param array<string, mixed> $ctx
     * @param array<string, mixed> $state
     * @return array<string, mixed>
     */
    private function applyKongWin(array $ctx, array $state): array
    {
        $seatNo = (int) $ctx['seatNo'];
        $pending = $state['pendingKongWin'] ?? null;
        if (! is_array($pending)) {
            throw new ApiException('没有可抢的杠');
        }
        $waitOps = is_array($state['waitOps'] ?? null) ? $state['waitOps'] : [];
        if (! isset($waitOps[(string) $seatNo])) {
            throw new ApiException('当前不能抢杠胡');
        }
        $tile = (int) ($pending['tile'] ?? 0);
        $fromSeat = (int) ($pending['fromSeat'] ?? -1);
        $hand = array_values(array_map('intval', $ctx['hand']));
        $try = $hand;
        $try[] = $tile;
        if (! HzmjRules::canWin($try, $ctx['kingTiles'])) {
            throw new ApiException('不能抢杠胡');
        }

        // 写入胡牌（补上抢到的牌）
        $winHand = HzmjRules::sortTiles($try, HzmjConsts::KING_TILE);
        make(GamePlayCardService::class)->recordPlay(
            (int) $ctx['roundId'],
            (int) $ctx['playerId'],
            [(string) $tile],
            HzmjRules::toStringTiles($winHand)
        );

        // 杠者副露改回碰（被抢杠）
        $allUps = is_array($state['ups'] ?? null) ? $state['ups'] : [];
        $fk = (string) $fromSeat;
        $list = is_array($allUps[$fk] ?? null) ? $allUps[$fk] : [];
        foreach ($list as $i => $m) {
            if (is_array($m) && count($m) === 4 && ((int) ($m[0] ?? 0) === $tile || (int) ($m[3] ?? 0) === $tile)) {
                $list[$i] = [$tile, $tile, $tile];
                break;
            }
        }
        $allUps[$fk] = $list;
        $state['ups'] = $allUps;

        $cfgLuckyNum = (int) ($state['luckyNum'] ?? 2);
        $wall = array_values(array_map('intval', is_array($state['tileWall'] ?? null) ? $state['tileWall'] : []));
        [$luckyTiles, $luckyHit, $wall] = LuckyTiles::draw($wall, $winHand, $cfgLuckyNum);
        $state['tileWall'] = $wall;

        $activeSeats = $this->activeSeats($ctx);
        $scores = RoundState::scaleScoreDeltas(
            LuckyTiles::kongWinScoreDeltas($seatNo, $fromSeat, $luckyHit, $cfgLuckyNum, $activeSeats),
            (float) ($state['df'] ?? 1),
            (int) ($state['bankBeishu'] ?? 1)
        );
        $this->mergeTotalScores($state, $scores);

        $state['pendingKongWin'] = null;
        $state['pendingDiscard'] = null;
        $state['waitOps'] = [];
        $state['phase'] = HzmjConsts::PHASE_WAIT;
        $state['dealerSeat'] = $seatNo;
        $this->saveState($ctx, $state);

        $settle = $this->settleRound(
            (int) $ctx['roomId'],
            (int) $ctx['roundId'],
            (int) $ctx['playerId'],
            $seatNo,
            'win',
            $state
        );
        $settle['luckyTiles'] = $luckyTiles;
        $settle['luckyHit'] = $luckyHit;
        $settle['luckyNum'] = $cfgLuckyNum;
        $settle['scores'] = $scores;
        $settle['finalTile'] = $tile;
        $settle['winOp'] = 'kong_win';
        $settle['fromSeat'] = $fromSeat;
        $settle['df'] = (float) ($state['df'] ?? 1);
        $settle['bankBeishu'] = max(1, (int) ($state['bankBeishu'] ?? 1));

        $showHands = $this->collectShowHands($ctx);

        return $this->basePayload($ctx, $state, [
            'action'     => 'win',
            'seat'       => $seatNo,
            'result'     => 'win',
            'winOp'      => 'kong_win',
            'fromSeat'   => $fromSeat,
            'finalTile'  => $tile,
            'myTiles'    => $winHand,
            'handCount'  => count($winHand),
            'myWaitOps'  => [],
            'settle'     => $settle,
            'dealerSeat' => $seatNo,
            'showHands'  => $showHands,
            'winSeat'    => $seatNo,
            'luckyTiles' => $luckyTiles,
            'luckyHit'   => $luckyHit,
            'leftTileNum'=> count($wall),
            'totalScores'=> $state['totalScores'] ?? [],
        ]);
    }

    /**
     * @param array<string, mixed> $ctx
     * @param array<string, mixed> $state
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    private function endDraw(array $ctx, array $state, array $extra = []): array
    {
        $state['pendingDiscard'] = null;
        $state['waitOps'] = [];
        $state['tileWall'] = [];
        $state['phase'] = HzmjConsts::PHASE_WAIT;
        // 流局庄家不变
        $this->saveState($ctx, $state);

        $dealerSeat = (int) ($state['dealerSeat'] ?? 0);
        $dealerPid = (int) ($ctx['pidBySeat'][$dealerSeat] ?? 0);
        $settle = $this->settleRound(
            (int) $ctx['roomId'],
            (int) $ctx['roundId'],
            $dealerPid,
            $dealerSeat,
            'draw_end',
            $state
        );
        $settle['luckyTiles'] = [];
        $settle['luckyHit'] = 0;
        $settle['luckyNum'] = (int) ($state['luckyNum'] ?? 2);
        $settle['scores'] = [];
        $settle['winOp'] = '';
        $settle['finalTile'] = 0;
        $showHands = $this->collectShowHands($ctx);

        return $this->basePayload($ctx, $state, array_merge($extra, [
            'result'     => 'draw_end',
            'leftTileNum'=> 0,
            'settle'     => $settle,
            'dealerSeat' => $dealerSeat,
            'myWaitOps'  => [],
            'showHands'  => $showHands,
            'winSeat'    => -1,
            'luckyTiles' => [],
            'luckyHit'   => 0,
            'totalScores'=> $state['totalScores'] ?? [],
        ]));
    }

    /**
     * 局末摊牌：各座位最终手牌（所有人可见）
     *
     * @param array<string, mixed> $ctx
     * @return array<string, list<int>>
     */
    private function collectShowHands(array $ctx): array
    {
        $gpc = make(GamePlayCardService::class);
        $roundId = (int) $ctx['roundId'];
        $out = [];
        foreach ($ctx['pidBySeat'] as $seat => $pid) {
            $out[(string) (int) $seat] = HzmjRules::sortTiles(
                HzmjRules::toIntTiles($gpc->getLatestHand($roundId, (int) $pid)),
                HzmjConsts::KING_TILE
            );
        }

        return $out;
    }

    /**
     * 将本步动作写入录像流水；有 settle 则 finish
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

        $action = (string) ($data['action'] ?? '');
        $seat = (int) ($data['seat'] ?? -1);
        $fromSeat = (int) ($data['fromSeat'] ?? -1);

        if ($action !== '') {
            $tiles = [];
            if ($action === 'pong' || $action === 'kong') {
                $meld = $data['meld'] ?? null;
                $tiles = is_array($meld)
                    ? array_map('intval', $meld)
                    : [(int) ($data['tile'] ?? 0)];
            } elseif ($action === 'win') {
                $ft = (int) ($data['finalTile'] ?? 0);
                if ($ft > 0) {
                    $tiles = [$ft];
                }
            } elseif (isset($data['tile'])) {
                $tiles = [(int) $data['tile']];
            }

            $ext = [];
            if ($action === 'kong' && isset($data['kongType'])) {
                $ext['kind'] = (string) $data['kongType'];
            }
            if ($action === 'win') {
                $ext['winOp'] = (string) ($data['winOp'] ?? '');
            }
            if (isset($data['scores']) && is_array($data['scores'])) {
                $ext['scores'] = $data['scores'];
            }

            GameRecordHooks::append($roundId, $action, $seat, $fromSeat, $tiles, $ext);
        }

        $drawn = $data['drawnTile'] ?? null;
        $drawSeat = isset($data['drawSeat']) ? (int) $data['drawSeat'] : null;
        if (($drawn === null || $drawn === '') && isset($data['_drawPrivate']) && is_array($data['_drawPrivate'])) {
            $drawn = $data['_drawPrivate']['drawnTile'] ?? null;
            $drawSeat = (int) ($data['_drawPrivate']['seat'] ?? ($drawSeat ?? -1));
        }
        if ($drawn !== null && $drawn !== '' && (int) $drawn > 0) {
            $ds = $drawSeat ?? $seat;
            GameRecordHooks::append($roundId, 'draw', (int) $ds, (int) $ds, [(int) $drawn], []);
        }

        if (! empty($data['luckyTiles']) && is_array($data['luckyTiles'])) {
            GameRecordHooks::append(
                $roundId,
                'lucky',
                $seat >= 0 ? $seat : -1,
                -1,
                array_map('intval', $data['luckyTiles']),
                ['hits' => (int) ($data['luckyHit'] ?? 0)]
            );
        }

        if (($data['result'] ?? '') === 'draw_end' && $action === '') {
            GameRecordHooks::append($roundId, 'draw_end', -1, -1, [], []);
        }

        $settle = $data['settle'] ?? null;
        if (! is_array($settle)) {
            return;
        }

        $scoresBySeat = is_array($settle['scores'] ?? null) ? $settle['scores'] : [];
        $winSeat = (int) ($settle['winnerSeat'] ?? $data['winSeat'] ?? -1);
        $dealerSeat = (int) ($data['dealerSeat'] ?? $ctx['state']['dealerSeat'] ?? -1);
        $playerScores = [];
        foreach ($ctx['pidBySeat'] as $s => $pid) {
            $s = (int) $s;
            $pid = (int) $pid;
            if ($pid <= 0) {
                continue;
            }
            $playerScores[] = [
                'memberId' => $pid,
                'score'    => $scoresBySeat[(string) $s] ?? $scoresBySeat[$s] ?? 0,
                'isWinner' => $winSeat >= 0 && $s === $winSeat,
                'isBanker' => $dealerSeat >= 0 && $s === $dealerSeat,
            ];
        }

        GameRecordHooks::finish($roundId, [
            'scores'  => $scoresBySeat,
            'winners' => $winSeat >= 0 ? [$winSeat] : [],
            'ext'     => [
                'winOp'       => (string) ($settle['winOp'] ?? ''),
                'finalTile'   => (int) ($settle['finalTile'] ?? 0),
                'luckyTiles'  => $settle['luckyTiles'] ?? [],
                'luckyHit'    => (int) ($settle['luckyHit'] ?? 0),
                'luckyNum'    => (int) ($settle['luckyNum'] ?? 0),
                'totalScores' => $settle['totalScores'] ?? ($data['totalScores'] ?? []),
                'roomOver'    => (bool) ($settle['roomOver'] ?? false),
                'reason'      => (string) ($settle['reason'] ?? ''),
                'showHands'   => $data['showHands'] ?? [],
                'fromSeat'    => $settle['fromSeat'] ?? null,
            ],
        ], $playerScores);
    }

    /**
     * @return array<string, mixed>
     */
    private function settleRound(
        int $roomId,
        int $roundId,
        int $winnerPid,
        int $winnerSeat,
        string $reason,
        array $state = [],
    ): array {
        $roomService = make(RoomService::class);
        $room = $roomService->lockForUpdate(
            ['id' => $roomId],
            ['id', 'played_count', 'total_play_count', 'status']
        );
        if ($room === null) {
            throw new ApiException('房间不存在');
        }
        $playedCount = (int) ($room->played_count ?? 0) + 1;
        $total = (int) ($room->total_play_count ?? 0);
        $roomOver = $total > 0 && $playedCount >= $total;

        $roomUpdate = ['played_count' => $playedCount];
        if ($roomOver) {
            $roomUpdate['status'] = GameRoom::STATUS_ENDED;
            $roomUpdate['end_time'] = date('Y-m-d H:i:s');
        }
        $roomService->update($roomId, $roomUpdate);

        $winnerIds = $reason === 'draw_end' ? '' : (string) $winnerPid;
        make(GameRoundService::class)->update($roundId, [
            'status'          => GameRound::STATUS_ENDED,
            'winner_user_ids' => $winnerIds,
            'end_time'        => date('Y-m-d H:i:s'),
        ]);

        return [
            'reason'      => $reason,
            'winnerIndex' => $reason === 'draw_end' ? -1 : $winnerSeat,
            'winnerSeat'  => $reason === 'draw_end' ? -1 : $winnerSeat,
            'winnerPid'   => $reason === 'draw_end' ? 0 : $winnerPid,
            'rewards'     => [],
            'playedCount' => $playedCount,
            'totalCount'  => $total,
            'roomOver'    => $roomOver,
            'totalScores' => is_array($state['totalScores'] ?? null) ? $state['totalScores'] : [],
        ];
    }

    /**
     * @param array<string, mixed> $ctx
     * @param array<string, mixed> $state
     */
    private function saveState(array $ctx, array $state): void
    {
        /** @var GameRoundService $svc */
        $svc = $ctx['gameRound'];
        $svc->update((int) $ctx['roundId'], [
            'extra' => RoundState::encode($state),
        ]);
        $ctx['state'] = $state;
    }

    /**
     * @param array<string, mixed> $state
     */
    private function popLastDiscard(array &$state, int $fromSeat, int $tile): void
    {
        $discards = is_array($state['discards'] ?? null) ? $state['discards'] : [];
        $key = (string) $fromSeat;
        $pile = is_array($discards[$key] ?? null) ? $discards[$key] : [];
        $n = count($pile);
        if ($n > 0 && (int) $pile[$n - 1] === $tile) {
            array_pop($pile);
            $discards[$key] = $pile;
            $state['discards'] = $discards;
        }
    }

    /**
     * @param array<string, mixed> $state
     * @return list<list<int>>
     */
    private function upsOfSeat(array $state, int $seat): array
    {
        $ups = is_array($state['ups'] ?? null) ? $state['ups'] : [];
        $list = $ups[(string) $seat] ?? [];
        if (! is_array($list)) {
            return [];
        }
        $out = [];
        foreach ($list as $m) {
            if (is_array($m)) {
                $out[] = array_map('intval', $m);
            }
        }

        return $out;
    }

    /**
     * @param list<int> $seats
     */
    private function nextSeat(int $fromSeat, array $seats): int
    {
        $seats = array_values($seats);
        $n = count($seats);
        if ($n === 0) {
            return $fromSeat;
        }
        $idx = array_search($fromSeat, $seats, true);
        if ($idx === false) {
            return (int) $seats[0];
        }

        return (int) $seats[(($idx + 1) % $n)];
    }

    /**
     * @param array<string, mixed> $ctx
     * @param array<string, mixed> $state
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    private function basePayload(array $ctx, array $state, array $extra): array
    {
        $seatCounts = [];
        $gpc = make(GamePlayCardService::class);
        $roundId = (int) $ctx['roundId'];
        foreach ($ctx['pidBySeat'] as $seat => $pid) {
            $cnt = count($gpc->getLatestHand($roundId, (int) $pid));
            $seatCounts[] = [
                'seatNo'    => (int) $seat,
                'tileCount' => $cnt,
                'cardsCount'=> $cnt,
            ];
        }

        $data = array_merge([
            'callback'       => CallBack::OP,
            'roundId'        => $roundId,
            'curSeat'        => (int) ($state['curSeat'] ?? 0),
            'dealerSeat'     => (int) ($state['dealerSeat'] ?? 0),
            'leftTileNum'    => count(is_array($state['tileWall'] ?? null) ? $state['tileWall'] : []),
            'kingTiles'      => $ctx['kingTiles'],
            'discards'       => $state['discards'] ?? [],
            'ups'            => $state['ups'] ?? [],
            'pendingDiscard' => $state['pendingDiscard'] ?? null,
            'pendingKongWin' => $state['pendingKongWin'] ?? null,
            'phase'          => (string) ($state['phase'] ?? HzmjConsts::PHASE_PLAYING),
            'seats'          => $seatCounts,
            'totalScores'    => $state['totalScores'] ?? [],
        ], $extra);

        return $data;
    }

    /**
     * @param array<string, mixed> $ctx
     * @return list<int>
     */
    private function activeSeats(array $ctx): array
    {
        $out = [];
        foreach ($ctx['pidBySeat'] as $s => $pid) {
            if ((int) $pid > 0) {
                $out[] = (int) $s;
            }
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $state
     * @param array<string, int> $deltas
     */
    private function mergeTotalScores(array &$state, array $deltas): void
    {
        $totals = is_array($state['totalScores'] ?? null) ? $state['totalScores'] : [];
        foreach ($deltas as $k => $v) {
            $key = (string) $k;
            $totals[$key] = (int) ($totals[$key] ?? 0) + (int) $v;
        }
        $state['totalScores'] = $totals;
    }

    /**
     * @param array<string, mixed> $ctx
     * @param array<string, mixed> $state
     * @param 'concealed'|'continue'|'exposed' $kongType
     * @return array<string, int>
     */
    private function applyKongScores(array $ctx, array &$state, string $kongType, int $actorSeat, int $fromSeat): array
    {
        $deltas = RoundState::scaleScoreDeltas(
            LuckyTiles::kongScoreDeltas($kongType, $actorSeat, $fromSeat, $this->activeSeats($ctx)),
            (float) ($state['df'] ?? 1),
            (int) ($state['bankBeishu'] ?? 1)
        );
        $this->mergeTotalScores($state, $deltas);

        return $deltas;
    }

    /**
     * @param array<string, mixed> $ctx
     * @return array<string, list<int>>
     */
    private function buildContinueKongWinOps(array $ctx, int $fromSeat, int $tile): array
    {
        $gpc = make(GamePlayCardService::class);
        $roundId = (int) $ctx['roundId'];
        $kingTiles = $ctx['kingTiles'];
        $wait = [];
        foreach ($ctx['pidBySeat'] as $seat => $pid) {
            $seat = (int) $seat;
            if ($seat === $fromSeat || (int) $pid <= 0) {
                continue;
            }
            $hand = HzmjRules::toIntTiles($gpc->getLatestHand($roundId, (int) $pid));
            $try = $hand;
            $try[] = $tile;
            if (HzmjRules::canWin($try, $kingTiles)) {
                $wait[(string) $seat] = [HzmjConsts::SHOW_OP_WIN, HzmjConsts::SHOW_OP_PASS];
            }
        }

        return $wait;
    }

    /**
     * 无人抢杠：续杠算分并摸牌
     *
     * @param array<string, mixed> $ctx
     * @param array<string, mixed> $state
     * @return array<string, mixed>
     */
    private function resolveContinueKongAfterPass(array $ctx, array $state, int $passSeat): array
    {
        $pending = $state['pendingKongWin'] ?? null;
        $fromSeat = (int) (is_array($pending) ? ($pending['fromSeat'] ?? $passSeat) : $passSeat);
        $tile = (int) (is_array($pending) ? ($pending['tile'] ?? 0) : 0);
        $state['pendingKongWin'] = null;
        $state['waitOps'] = [];
        $scores = $this->applyKongScores($ctx, $state, 'continue', $fromSeat, -1);

        $gpc = make(GamePlayCardService::class);
        $fromPid = (int) ($ctx['pidBySeat'][$fromSeat] ?? 0);
        $hand = HzmjRules::toIntTiles($gpc->getLatestHand((int) $ctx['roundId'], $fromPid));

        // 临时切换 ctx 为杠者，便于 afterKongDraw 记牌
        $actorCtx = $ctx;
        $actorCtx['seatNo'] = $fromSeat;
        $actorCtx['playerId'] = $fromPid;
        $actorCtx['hand'] = $hand;

        return $this->afterKongDraw($actorCtx, $state, $hand, [
            'action'   => 'kong',
            'kongType' => 'continue',
            'seat'     => $fromSeat,
            'tile'     => $tile,
            'scores'   => $scores,
            'kongResume' => true,
            'passSeat' => $passSeat,
        ]);
    }
}
