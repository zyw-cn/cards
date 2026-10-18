<?php

declare(strict_types=1);

namespace App\Game;

use App\Exception\ApiException;
use App\Facades\Log;
use App\Game\Contract\GameContext;
use App\Game\Support\GameRegistry;
use function Hyperf\Support\make;

/**
 * 根据 message 中的 route 转发到对应 Module Action
 *
 * 推荐格式：
 *   Room.Join              → 平台入座
 *   Dou.Bid / Dou.Play     → 斗地主
 *   Niu.Grab / Niu.Bet / Niu.Show → 斗牛
 * 兼容旧格式：
 *   Dou.Landlord.Bid       → 等同 Dou.Bid（中间段忽略，仅作别名）
 *   Niu.Banker.Grab        → 等同 Niu.Grab
 *
 * Module 由 GameRegistry 解析；未注册 Module 的三方路由仍可回退到
 * App\Game\{Module}\{Service}::{action}()（供尚未接入 Registry 的目录使用）
 */
class Dispatcher
{
    private const GAME_NAMESPACE = 'App\\Game';

    /**
     * @param array{route: string, body: array} $message
     * @return array{code: int, message: string, data: mixed, _broadcast?: array}
     */
    public function dispatch(array $message): array
    {
        $route = (string) ($message['route'] ?? '');
        $body = is_array($message['body'] ?? null) ? $message['body'] : [];

        $parts = array_values(array_filter(explode('.', $route), static fn ($p) => $p !== ''));
        if (count($parts) < 2) {
            return [
                'code'    => 400,
                'message' => 'Invalid route, expected Module.Action 或 Module.Service.Action',
                'data'    => null,
            ];
        }

        [$moduleCode, $actionName] = $this->parseRouteParts($parts);

        $module = make(GameRegistry::class)->getByCode($moduleCode);
        if ($module !== null) {
            $action = $module->action($actionName);
            if ($action !== null) {
                try {
                    return $action->handle(new GameContext($body, $route, $module->code(), $actionName));
                } catch (ApiException $e) {
                    return [
                        'code'    => 400,
                        'message' => $e->getMessage() !== '' ? $e->getMessage() : '请求失败',
                        'data'    => null,
                    ];
                } catch (\Throwable $e) {
                    Log::error('Game dispatch failed', [
                        'route' => $route,
                        'error' => $e->getMessage(),
                        'file'  => $e->getFile() . ':' . $e->getLine(),
                    ]);

                    $hint = (string) (\Hyperf\Support\env('APP_ENV', 'prod'));
                    $msg = in_array($hint, ['local', 'dev', 'test'], true)
                        ? ('服务异常: ' . $e->getMessage())
                        : '服务异常，请稍后重试';

                    return ['code' => 500, 'message' => $msg, 'data' => null];
                }
            }
        }

        // 兼容：三方路由且 Module 未声明该 Action 时，走旧类方法解析
        if (count($parts) >= 3) {
            return $this->dispatchLegacyClass($parts[0], $parts[1], $parts[2], $body);
        }

        return [
            'code'    => 404,
            'message' => 'Unknown action: ' . $moduleCode . '.' . $actionName,
            'data'    => null,
        ];
    }

    /**
     * @param list<string> $parts
     * @return array{0: string, 1: string} [moduleCode, actionName]
     */
    private function parseRouteParts(array $parts): array
    {
        if (count($parts) === 2) {
            return [$parts[0], $parts[1]];
        }

        // Dou.Landlord.Bid → module=Dou, action=Bid（中间服务名忽略）
        return [$parts[0], $parts[count($parts) - 1]];
    }

    /**
     * @return array{code: int, message: string, data: mixed, _broadcast?: array}
     */
    private function dispatchLegacyClass(string $game, string $service, string $action, array $body): array
    {
        $class = $this->resolveClass($game, $service);
        if ($class === null) {
            return ['code' => 404, 'message' => 'Unknown service: ' . $game . '.' . $service, 'data' => null];
        }

        $method = $this->actionToMethod($action);
        if (! method_exists($class, $method)) {
            return ['code' => 404, 'message' => 'Unknown action: ' . $action, 'data' => null];
        }

        try {
            $instance = make($class);
            $result = $instance->$method($body);

            return is_array($result) && array_key_exists('code', $result) ? $result : [
                'code'    => 0,
                'message' => 'ok',
                'data'    => $result,
            ];
        } catch (ApiException $e) {
            return [
                'code'    => 400,
                'message' => $e->getMessage() !== '' ? $e->getMessage() : '请求失败',
                'data'    => null,
            ];
        } catch (\Throwable $e) {
            Log::error('Game legacy dispatch failed', [
                'game'    => $game,
                'service' => $service,
                'action'  => $action,
                'error'   => $e->getMessage(),
            ]);

            return ['code' => 500, 'message' => '服务异常，请稍后重试', 'data' => null];
        }
    }

    /**
     * @return class-string|null
     */
    private function resolveClass(string $game, string $service): ?string
    {
        $game = $this->normalizeSegment($game);
        $service = $this->normalizeSegment($service);
        if ($game === '' || $service === '') {
            return null;
        }

        $class = self::GAME_NAMESPACE . '\\' . $game . '\\' . $service;

        return class_exists($class) ? $class : null;
    }

    private function normalizeSegment(string $segment): string
    {
        $segment = preg_replace('/[^A-Za-z0-9]/', '', $segment) ?? '';

        return ucfirst($segment);
    }

    private function actionToMethod(string $action): string
    {
        return lcfirst(str_replace(' ', '', ucwords(str_replace(['-', '_'], ' ', $action))));
    }
}
