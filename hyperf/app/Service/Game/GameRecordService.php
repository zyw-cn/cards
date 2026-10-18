<?php

declare(strict_types=1);

namespace App\Service\Game;

use App\Constants\RedisKey;
use App\Dao\Game\GameRecordDao;
use App\Dao\Game\GameRecordPlayerDao;
use App\Facades\Log;
use App\Model\Game\GameRecord;
use App\Service\BaseService;
use Hyperf\Redis\Redis;
use function Hyperf\Support\make;

/**
 * 全玩法对局录像：begin → appendOp* → finish / abort
 *
 * ops 局中写入 Redis LIST，局末刷入 h_game_record.ops_json。
 */
class GameRecordService extends BaseService
{
    public const SCHEMA_VER = 1;

    public function __construct(
        GameRecordDao $gameRecordDao,
        private readonly GameRecordPlayerDao $playerDao,
    ) {
        $this->dao = $gameRecordDao;
    }

    /**
     * 开局冻结录像头 + 参与者行
     *
     * @param array{
     *   gameType:int,
     *   roomId:int,
     *   roundId:int,
     *   roomNum?:string,
     *   ownerId?:int,
     *   curRound?:int,
     *   maxRound?:int,
     *   playerNum?:int,
     *   unit?:string|float|int,
     *   rule?:array|string|null,
     *   init:array,
     *   players:list<array{memberId:int,seatNo:int,nickname?:string,avatar?:string,isBanker?:bool}>
     * } $params
     */
    public function begin(array $params): int
    {
        $roundId = (int) ($params['roundId'] ?? 0);
        $gameType = (int) ($params['gameType'] ?? 0);
        if ($roundId <= 0 || $gameType <= 0) {
            return 0;
        }

        $existing = $this->getOne(['round_id' => $roundId], 'id,status');
        if ($existing !== null) {
            return (int) ($existing->id ?? 0);
        }

        $now = date('Y-m-d H:i:s');
        $rule = $params['rule'] ?? null;
        if (is_string($rule)) {
            $decoded = json_decode($rule, true);
            $ruleJson = is_array($decoded) ? $this->encode($decoded) : $rule;
        } else {
            $ruleJson = $this->encode(is_array($rule) ? $rule : []);
        }

        $players = is_array($params['players'] ?? null) ? $params['players'] : [];
        $playerNum = (int) ($params['playerNum'] ?? count($players));
        $roomNum = (string) ($params['roomNum'] ?? '');

        try {
            $recordId = (int) $this->save([
                'schema_ver'  => self::SCHEMA_VER,
                'game_type'   => $gameType,
                'room_id'     => (int) ($params['roomId'] ?? 0),
                'round_id'    => $roundId,
                'room_num'    => $roomNum,
                'owner_id'    => (int) ($params['ownerId'] ?? 0),
                'cur_round'   => max(1, (int) ($params['curRound'] ?? 1)),
                'max_round'   => max(1, (int) ($params['maxRound'] ?? 1)),
                'player_num'  => max(0, $playerNum),
                'unit'        => $this->normalizeUnit($params['unit'] ?? 0),
                'rule_json'   => $ruleJson !== '' ? $ruleJson : null,
                'init_json'   => $this->encode($params['init'] ?? []),
                'ops_json'    => '[]',
                'result_json' => null,
                'status'      => GameRecord::STATUS_RECORDING,
                'op_count'    => 0,
                'start_time'  => $now,
                'end_time'    => null,
                'create_at'   => $now,
                'update_at'   => $now,
            ]);
        } catch (\Throwable $e) {
            Log::error('录像 begin 失败', [
                'round_id' => $roundId,
                'error'    => $e->getMessage(),
            ]);

            return 0;
        }

        if ($recordId <= 0) {
            return 0;
        }

        $this->clearOpsBuffer($roundId);

        $rows = [];
        foreach ($players as $p) {
            $mid = (int) ($p['memberId'] ?? $p['member_id'] ?? $p['player_id'] ?? 0);
            if ($mid <= 0) {
                continue;
            }
            $rows[] = [
                'record_id' => $recordId,
                'game_type' => $gameType,
                'room_num'  => $roomNum,
                'member_id' => $mid,
                'seat_no'   => (int) ($p['seatNo'] ?? $p['seat_no'] ?? $p['seat'] ?? 0),
                'nickname'  => (string) ($p['nickname'] ?? ''),
                'avatar'    => (string) ($p['avatar'] ?? ''),
                'score'     => '0.00',
                'is_winner' => 0,
                'is_banker' => ! empty($p['isBanker']) || ! empty($p['is_banker']) ? 1 : 0,
                'create_at' => $now,
            ];
        }
        if ($rows !== []) {
            try {
                $this->playerDao->saveAll($rows);
            } catch (\Throwable $e) {
                Log::error('录像参与者写入失败', [
                    'record_id' => $recordId,
                    'error'     => $e->getMessage(),
                ]);
            }
        }

        return $recordId;
    }

    /**
     * 追加一条操作（局中 Redis；失败不打断牌局）
     *
     * @param list<int|string> $tiles
     * @param array<string, mixed> $ext
     */
    public function appendOp(
        int $roundId,
        string $op,
        int $seat = -1,
        int $fromSeat = -1,
        array $tiles = [],
        array $ext = [],
    ): void {
        if ($roundId <= 0 || $op === '') {
            return;
        }

        try {
            $redis = make(Redis::class);
            $key = $this->opsKey($roundId);
            $seq = (int) $redis->llen($key) + 1;
            $item = [
                'seq'      => $seq,
                'op'       => $op,
                'seat'     => $seat,
                'fromSeat' => $fromSeat,
                'tiles'    => array_values($tiles),
                'ext'      => $ext === [] ? new \stdClass() : $ext,
            ];
            $redis->rpush($key, $this->encode($item));
            $redis->expire($key, 86400);
        } catch (\Throwable $e) {
            Log::warning('录像 appendOp 失败', [
                'round_id' => $roundId,
                'op'       => $op,
                'error'    => $e->getMessage(),
            ]);
        }
    }

    /**
     * 局末落盘
     *
     * @param array<string, mixed> $result
     * @param list<array{memberId:int,score?:float|int|string,isWinner?:bool,isBanker?:bool}> $playerScores
     */
    public function finish(int $roundId, array $result, array $playerScores = []): void
    {
        if ($roundId <= 0) {
            return;
        }

        try {
            $row = $this->getOne(
                ['round_id' => $roundId, 'status' => GameRecord::STATUS_RECORDING],
                'id'
            );
            if ($row === null) {
                $this->clearOpsBuffer($roundId);

                return;
            }
            $recordId = (int) $row->id;
            $ops = $this->drainOps($roundId);
            $now = date('Y-m-d H:i:s');
            $this->update($recordId, [
                'ops_json'    => $this->encode($ops),
                'result_json' => $this->encode($result),
                'op_count'    => count($ops),
                'status'      => GameRecord::STATUS_DONE,
                'end_time'    => $now,
                'update_at'   => $now,
            ]);

            foreach ($playerScores as $ps) {
                $mid = (int) ($ps['memberId'] ?? $ps['member_id'] ?? 0);
                if ($mid <= 0) {
                    continue;
                }
                $patch = [
                    'score' => $this->normalizeUnit($ps['score'] ?? 0),
                ];
                if (array_key_exists('isWinner', $ps) || array_key_exists('is_winner', $ps)) {
                    $patch['is_winner'] = ! empty($ps['isWinner']) || ! empty($ps['is_winner']) ? 1 : 0;
                }
                if (array_key_exists('isBanker', $ps) || array_key_exists('is_banker', $ps)) {
                    $patch['is_banker'] = ! empty($ps['isBanker']) || ! empty($ps['is_banker']) ? 1 : 0;
                }
                $this->playerDao->update(
                    ['record_id' => $recordId, 'member_id' => $mid],
                    $patch
                );
            }
        } catch (\Throwable $e) {
            Log::error('录像 finish 失败', [
                'round_id' => $roundId,
                'error'    => $e->getMessage(),
            ]);
        }
    }

    /** 中途解散等：标记废弃并清缓冲 */
    public function abort(int $roundId): void
    {
        if ($roundId <= 0) {
            return;
        }
        try {
            $row = $this->getOne(
                ['round_id' => $roundId, 'status' => GameRecord::STATUS_RECORDING],
                'id'
            );
            if ($row === null) {
                $this->clearOpsBuffer($roundId);

                return;
            }
            $ops = $this->drainOps($roundId);
            $now = date('Y-m-d H:i:s');
            $this->update((int) $row->id, [
                'ops_json'  => $this->encode($ops),
                'op_count'  => count($ops),
                'status'    => GameRecord::STATUS_ABORTED,
                'end_time'  => $now,
                'update_at' => $now,
            ]);
        } catch (\Throwable $e) {
            Log::warning('录像 abort 失败', [
                'round_id' => $roundId,
                'error'    => $e->getMessage(),
            ]);
            $this->clearOpsBuffer($roundId);
        }
    }

    /** 房间解散时废弃该房所有录制中的局 */
    public function abortByRoom(int $roomId): void
    {
        if ($roomId <= 0) {
            return;
        }
        try {
            $rows = $this->getAll(
                ['room_id' => $roomId, 'status' => GameRecord::STATUS_RECORDING],
                'id,round_id'
            );
            foreach ($rows as $r) {
                $roundId = (int) (is_array($r) ? ($r['round_id'] ?? 0) : ($r->round_id ?? 0));
                if ($roundId > 0) {
                    $this->abort($roundId);
                }
            }
        } catch (\Throwable $e) {
            Log::warning('录像 abortByRoom 失败', [
                'room_id' => $roomId,
                'error'   => $e->getMessage(),
            ]);
        }
    }

    /**
     * 大厅战绩列表
     *
     * @return list<array<string, mixed>>
     */
    public function listByMember(int $memberId, int $gameType = 0, int $limit = 20, int $offset = 0): array
    {
        if ($memberId <= 0) {
            return [];
        }
        $limit = max(1, min(50, $limit));
        $offset = max(0, $offset);
        $where = [
            'member_id' => $memberId,
        ];
        if ($gameType > 0) {
            $where['game_type'] = $gameType;
        }

        $players = $this->playerDao->selectList(
            $where,
            '*',
            $offset,
            $limit,
            ['id' => 'desc']
        );
        if ($players === null || count($players) === 0) {
            return [];
        }

        $out = [];
        foreach ($players as $p) {
            $arr = is_array($p) ? $p : $p->toArray();
            $recordId = (int) ($arr['record_id'] ?? 0);
            $rec = $recordId > 0
                ? $this->getOne(['id' => $recordId, 'status' => GameRecord::STATUS_DONE], 'id,game_type,room_num,cur_round,max_round,unit,start_time,end_time,player_num')
                : null;
            if ($rec === null) {
                continue;
            }
            $out[] = [
                'recordId'  => (int) $rec->id,
                'gameType'  => (int) $rec->game_type,
                'roomNum'   => (string) $rec->room_num,
                'curRound'  => (int) $rec->cur_round,
                'maxRound'  => (int) $rec->max_round,
                'unit'      => (string) $rec->unit,
                'playerNum' => (int) $rec->player_num,
                'startTime' => $rec->start_time,
                'endTime'   => $rec->end_time,
                'seatNo'    => (int) ($arr['seat_no'] ?? 0),
                'score'     => (string) ($arr['score'] ?? '0'),
                'isWinner'  => (int) ($arr['is_winner'] ?? 0) === 1,
                'isBanker'  => (int) ($arr['is_banker'] ?? 0) === 1,
            ];
        }

        return $out;
    }

    /**
     * 回放整包（仅已完成）
     *
     * @return array<string, mixed>|null
     */
    public function getPlayback(int $recordId, int $viewerId = 0): ?array
    {
        if ($recordId <= 0) {
            return null;
        }
        $rec = $this->getOne(['id' => $recordId, 'status' => GameRecord::STATUS_DONE], '*');
        if ($rec === null) {
            return null;
        }
        if ($viewerId > 0) {
            $ok = $this->playerDao->getOne(
                ['record_id' => $recordId, 'member_id' => $viewerId],
                'id'
            );
            if ($ok === null) {
                return null;
            }
        }

        $players = $this->playerDao->getAll(
            ['record_id' => $recordId],
            'member_id,seat_no,nickname,avatar,score,is_winner,is_banker',
            ['seat_no' => 'asc']
        );
        $playerList = [];
        foreach ($players as $p) {
            $a = is_array($p) ? $p : (array) $p;
            $playerList[] = [
                'memberId' => (int) ($a['member_id'] ?? 0),
                'seatNo'   => (int) ($a['seat_no'] ?? 0),
                'nickname' => (string) ($a['nickname'] ?? ''),
                'avatar'   => (string) ($a['avatar'] ?? ''),
                'score'    => (string) ($a['score'] ?? '0'),
                'isWinner' => (int) ($a['is_winner'] ?? 0) === 1,
                'isBanker' => (int) ($a['is_banker'] ?? 0) === 1,
            ];
        }

        return [
            'recordId'  => (int) $rec->id,
            'schemaVer' => (int) $rec->schema_ver,
            'gameType'  => (int) $rec->game_type,
            'roomId'    => (int) $rec->room_id,
            'roundId'   => (int) $rec->round_id,
            'roomNum'   => (string) $rec->room_num,
            'curRound'  => (int) $rec->cur_round,
            'maxRound'  => (int) $rec->max_round,
            'playerNum' => (int) $rec->player_num,
            'unit'      => (string) $rec->unit,
            'rule'      => $this->decode($rec->rule_json),
            'init'      => $this->decode($rec->init_json),
            'ops'       => $this->decode($rec->ops_json) ?: [],
            'result'    => $this->decode($rec->result_json),
            'opCount'   => (int) $rec->op_count,
            'startTime' => $rec->start_time,
            'endTime'   => $rec->end_time,
            'players'   => $playerList,
        ];
    }

    private function opsKey(int $roundId): string
    {
        return sprintf(RedisKey::GAME_RECORD_OPS, $roundId);
    }

    private function clearOpsBuffer(int $roundId): void
    {
        try {
            make(Redis::class)->del($this->opsKey($roundId));
        } catch (\Throwable) {
            // ignore
        }
    }

    /** @return list<array<string, mixed>> */
    private function drainOps(int $roundId): array
    {
        $ops = [];
        try {
            $redis = make(Redis::class);
            $key = $this->opsKey($roundId);
            $rawList = $redis->lrange($key, 0, -1);
            $redis->del($key);
            if (is_array($rawList)) {
                foreach ($rawList as $raw) {
                    $item = json_decode((string) $raw, true);
                    if (is_array($item)) {
                        $ops[] = $item;
                    }
                }
            }
        } catch (\Throwable $e) {
            Log::warning('录像 drainOps 失败', [
                'round_id' => $roundId,
                'error'    => $e->getMessage(),
            ]);
        }

        return $ops;
    }

    /** @param array<string, mixed>|list<mixed> $data */
    private function encode(array $data): string
    {
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return $json === false ? '{}' : $json;
    }

    /** @return array<string, mixed>|list<mixed> */
    private function decode(?string $raw): array
    {
        if ($raw === null || $raw === '') {
            return [];
        }
        $data = json_decode($raw, true);

        return is_array($data) ? $data : [];
    }

    private function normalizeUnit(float|int|string $unit): string
    {
        if (is_string($unit) && is_numeric($unit)) {
            return number_format((float) $unit, 2, '.', '');
        }
        if (is_int($unit) || is_float($unit)) {
            return number_format((float) $unit, 2, '.', '');
        }

        return '0.00';
    }
}
