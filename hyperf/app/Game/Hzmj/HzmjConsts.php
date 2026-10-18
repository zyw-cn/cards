<?php

declare(strict_types=1);

namespace App\Game\Hzmj;

/**
 * 红中麻将 Module 常量
 *
 * 牌副：万 1-9、条 31-39、筒 51-59 各 4 张 + 红中 75×4 = 112。
 * 红中为癞子；不可吃、不可点炮；自摸胡；碰/明杠吃弃牌；暗杠/续杠在摸牌回合。
 */
final class HzmjConsts
{
    public const MIN_PLAYERS = 2;

    public const MAX_PLAYERS = 4;

    /** 红中（癞子） */
    public const KING_TILE = 75;

    /** 前端展示操作码 */
    public const SHOW_OP_PASS = 1;

    public const SHOW_OP_PONG = 5;

    public const SHOW_OP_KONG = 7;

    public const SHOW_OP_WIN = 13;

    /** @var array{PASS:int,PONG:int,KONG:int,WIN:int} */
    public const SHOW_OP = [
        'PASS' => self::SHOW_OP_PASS,
        'PONG' => self::SHOW_OP_PONG,
        'KONG' => self::SHOW_OP_KONG,
        'WIN'  => self::SHOW_OP_WIN,
    ];

    public const PHASE_PLAYING = 'playing';

    public const PHASE_WAIT = 'waiting';

    /** 抢庄定庄（满员开始后、发牌前） */
    public const PHASE_GRAB = 'grab';

    /** 抢庄倍数：0=不抢，1/2/3 */
    public const GRAB_MULTIPLES = [0, 1, 2, 3];

    public const GRAB_TIMEOUT = 15;

    /** 万 */
    public const CHARACTER = [1, 2, 3, 4, 5, 6, 7, 8, 9];

    /** 条 */
    public const BAMBOO = [31, 32, 33, 34, 35, 36, 37, 38, 39];

    /** 筒 */
    public const DOT = [51, 52, 53, 54, 55, 56, 57, 58, 59];

    /** 顺子边界：>=60 不可组顺 */
    public const BOUNDARY = 60;
}
