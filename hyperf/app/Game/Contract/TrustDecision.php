<?php

declare(strict_types=1);

namespace App\Game\Contract;

/**
 * 托管 AI 统一决策结果
 *
 * action 为玩法语义字符串；payload 承载 cards / score / tile / op / multiple 等。
 */
final class TrustDecision
{
    public const ACTION_PASS = 'PASS';

    public const ACTION_PASS_CALL = 'PASS_CALL';

    public const ACTION_PLAY = 'PLAY';

    public const ACTION_DISCARD = 'DISCARD';

    public const ACTION_WIN = 'WIN';

    public const ACTION_KONG = 'KONG';

    public const ACTION_PONG = 'PONG';

    public const ACTION_FOLD = 'FOLD';

    public const ACTION_CALL = 'CALL';

    public const ACTION_RAISE = 'RAISE';

    public const ACTION_COMPARE = 'COMPARE';

    public const ACTION_LOOK = 'LOOK';

    public const ACTION_GRAB = 'GRAB';

    public const ACTION_BET = 'BET';

    public const ACTION_SHOW = 'SHOW';

    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(
        public readonly string $action,
        public readonly array $payload = [],
    ) {
    }

    public static function pass(): self
    {
        return new self(self::ACTION_PASS);
    }

    public static function passCall(int $score = 0): self
    {
        return new self(self::ACTION_PASS_CALL, ['score' => $score]);
    }

    /**
     * @param list<string|int> $cards
     */
    public static function play(array $cards): self
    {
        return new self(self::ACTION_PLAY, ['cards' => array_values($cards)]);
    }

    public static function discard(int $tile): self
    {
        return new self(self::ACTION_DISCARD, ['tile' => $tile, 'op' => 'discard']);
    }

    /**
     * @return list<string|int>
     */
    public function cards(): array
    {
        $cards = $this->payload['cards'] ?? [];

        return is_array($cards) ? array_values($cards) : [];
    }

    public function score(): int
    {
        return (int) ($this->payload['score'] ?? 0);
    }

    public function tile(): int
    {
        return (int) ($this->payload['tile'] ?? 0);
    }

    public function op(): string
    {
        return (string) ($this->payload['op'] ?? strtolower($this->action));
    }

    public function multiple(): int
    {
        return (int) ($this->payload['multiple'] ?? 0);
    }

    public function isPass(): bool
    {
        return $this->action === self::ACTION_PASS || $this->action === self::ACTION_PASS_CALL;
    }
}
