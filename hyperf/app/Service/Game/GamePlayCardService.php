<?php

declare(strict_types=1);

namespace App\Service\Game;

use App\Dao\Game\GamePlayCardDao;
use App\Game\Card\CardCode;
use App\Service\BaseService;

class GamePlayCardService extends BaseService
{
    public function __construct(GamePlayCardDao $gamePlayCardDao)
    {
        $this->dao = $gamePlayCardDao;
    }

    /**
     * 发牌落库：每人一条 seq=0 记录
     * - now_cards = 当前手牌（逗号分隔编码，如 A3,B14,E17）
     * - cards = 空（发牌尚未出牌）
     *
     * @param int $roundId
     * @param array<int, array{player_id:int, cards:list<string>}> $players
     */
    public function recordDeal(int $roundId, array $players): bool
    {
        if ($roundId <= 0 || $players === []) {
            return false;
        }
        $now = date('Y-m-d H:i:s');
        $rows = [];
        foreach ($players as $p) {
            $memberId = (int) ($p['player_id'] ?? 0);
            if ($memberId <= 0) {
                continue;
            }
            $hand = $p['cards'] ?? [];
            $rows[] = [
                'game_round_id' => $roundId,
                'seq'           => 0,
                'member_id'     => $memberId,
                'now_cards'     => self::encodeCards(is_array($hand) ? $hand : []),
                'cards'         => '',
                'create_time'   => $now,
            ];
        }
        return $rows === [] ? false : (bool) $this->saveAll($rows);
    }

    /**
     * 出牌落库：seq 自增；now_cards=出完后剩余；cards=本次打出
     *
     * @param list<string> $played
     * @param list<string> $remain
     */
    public function recordPlay(int $roundId, int $memberId, array $played, array $remain): int
    {
        $maxSeq = (int) $this->getMax(['game_round_id' => $roundId], 'seq');
        $seq = max($maxSeq, 0) + 1;
        return $this->save([
            'game_round_id' => $roundId,
            'seq'           => $seq,
            'member_id'     => $memberId,
            'now_cards'     => self::encodeCards($remain),
            'cards'         => self::encodeCards($played),
            'create_time'   => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * 取某玩家本局最新剩余手牌（最大 seq）
     * @return list<string>
     */
    public function getLatestHand(int $roundId, int $memberId): array
    {
        $list = $this->selectList(
            ['game_round_id' => $roundId, 'member_id' => $memberId],
            '*',
            0,
            1,
            ['seq' => 'desc']
        );
        if ($list === null || count($list) === 0) {
            return [];
        }
        $first = $list[0] ?? $list->first();
        $nowCards = is_array($first)
            ? (string) ($first['now_cards'] ?? '')
            : (string) ($first->now_cards ?? '');
        return self::decodeCards($nowCards);
    }

    /**
     * 局末亮牌：按座位取出所有人当前剩余手牌（牌面，不是张数）
     *
     * @param array<int,int> $seatByPid member_id => seat_no
     * @return array{hands: list<array{seatNo:int, playerId:int, cards:list<string>, handCount:int}>, remainCards: array<string, list<string>>}
     */
    public function getRemainHandsBySeat(int $roundId, array $seatByPid): array
    {
        $hands = [];
        $remainCards = [];
        foreach ($seatByPid as $pid => $seat) {
            $pid = (int) $pid;
            $seat = (int) $seat;
            if ($pid <= 0) {
                continue;
            }
            $cards = $this->getLatestHand($roundId, $pid);
            $hands[] = [
                'seatNo'    => $seat,
                'playerId'  => $pid,
                'cards'     => $cards,
                'handCount' => count($cards),
            ];
            $remainCards[(string) $seat] = $cards;
        }

        return ['hands' => $hands, 'remainCards' => $remainCards];
    }

    /**
     * 叫地主结束：把底牌并入地主手牌后，更新地主的发牌记录(seq=0)的 now_cards
     * 更新后地主手牌为 20 张；原始 17 张可由 now_cards 去掉本局底牌(round.extra)回溯
     *
     * @param list<string> $fullHand 含底牌的完整手牌
     */
    public function setLandlordHand(int $roundId, int $memberId, array $fullHand): bool
    {
        if ($roundId <= 0 || $memberId <= 0) {
            return false;
        }
        return (bool) $this->update(
            ['game_round_id' => $roundId, 'member_id' => $memberId, 'seq' => 0],
            ['now_cards' => self::encodeCards($fullHand)]
        );
    }

    /**
     * 解析出牌阶段轮次：当前该出牌的座位、桌面最近一手、是否必须领出（不能 pass）
     *
     * @param array<int,int> $seatByPid member_id => seat_no
     * @return array{
     *   currentIndex: int,
     *   lastPlay: array{index:int, cards:list<string>}|null,
     *   mustLead: bool
     * }
     */
    public function resolvePlayState(int $roundId, array $seatByPid, int $landlordSeat, int $maxPlayers = 3): array
    {
        $rows = $this->getAll(
            ['game_round_id' => $roundId],
            'seq,member_id,cards',
            ['seq' => 'asc']
        );
        $plays = [];
        foreach ($rows as $r) {
            if ((int) ($r['seq'] ?? 0) <= 0) {
                continue;
            }
            $plays[] = [
                'seat'  => $seatByPid[(int) $r['member_id']] ?? 0,
                'cards' => self::decodeCards((string) ($r['cards'] ?? '')),
            ];
        }

        if ($plays === []) {
            $current = $landlordSeat < 0 ? 0 : $landlordSeat;
            return ['currentIndex' => $current, 'lastPlay' => null, 'mustLead' => true];
        }

        $n = count($plays);
        $currentIndex = ((int) $plays[$n - 1]['seat'] + 1) % $maxPlayers;

        $passStreak = 0;
        $lastPlay = null;
        for ($i = $n - 1; $i >= 0; $i--) {
            if ($plays[$i]['cards'] === []) {
                $passStreak++;
                continue;
            }
            // 最近一手真实出牌后其余玩家均 pass → 桌面清空，由该玩家领出
            if ($passStreak < $maxPlayers - 1) {
                $lastPlay = ['index' => (int) $plays[$i]['seat'], 'cards' => $plays[$i]['cards']];
            }
            break;
        }

        return [
            'currentIndex' => $currentIndex,
            'lastPlay'     => $lastPlay,
            'mustLead'     => $lastPlay === null,
        ];
    }

    /** @param array<int, string|int> $cards */
    public static function encodeCards(array $cards): string
    {
        $parts = [];
        foreach ($cards as $c) {
            $s = strtoupper(trim((string) $c));
            if ($s !== '') {
                $parts[] = $s;
            }
        }
        return implode(',', $parts);
    }

    /** @return list<string> */
    public static function decodeCards(string $raw): array
    {
        if ($raw === '') {
            return [];
        }
        $parts = array_map('trim', explode(',', $raw));
        $out = [];
        foreach ($parts as $p) {
            if ($p !== '') {
                $out[] = strtoupper($p);
            }
        }
        return $out;
    }

    /**
     * 从手牌中扣除打出的牌，返回剩余手牌；若手牌不含打出牌则返回 null
     *
     * @param list<string> $hand
     * @param list<string> $played
     * @return list<string>|null
     */
    public static function subtractCards(array $hand, array $played): ?array
    {
        $remain = array_map(static fn ($c) => CardCode::normalize((string) $c), $hand);
        foreach ($played as $card) {
            $code = CardCode::normalize((string) $card);
            $idx = array_search($code, $remain, true);
            if ($idx === false) {
                return null;
            }
            array_splice($remain, (int) $idx, 1);
        }
        return array_values($remain);
    }
}
