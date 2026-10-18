<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Controller\AbstractController;
use App\Middleware\JwtAuthMiddleware;
use App\Request\MemberRequest;
use App\Service\Member\MemberService;
use App\Service\Member\RoomCardService;
use App\Utils\WebSocketKick;
use Hyperf\Context\Context;
use Hyperf\Di\Annotation\Inject;
use Hyperf\HttpServer\Annotation\AutoController;
use Hyperf\HttpServer\Annotation\Middleware;
use Psr\Http\Message\ResponseInterface;

#[AutoController]
#[Middleware(JwtAuthMiddleware::class)]
class MemberController extends AbstractController
{
    #[Inject]
    protected MemberService $memberService;

    #[Inject]
    protected RoomCardService $roomCardService;

    public function index(): ResponseInterface
    {
        $memberId = Context::get('member_id');
        $member = $this->memberService->get(
            $memberId,
            ['id', 'email', 'nickname', 'realname', 'avatar', 'points', 'level', 'invite_code']
        );

        if (!$member) {
            return app('Json')->fail('用户不存在');
        }

        $data = $member->toArray();
        $data['avatar_url'] = $this->memberService->buildAvatarUrl((string) ($data['avatar'] ?? ''));
        // 前端 HUD / 进房统一读 nickname
        $nick = trim((string) ($data['nickname'] ?? ''));
        if ($nick === '') {
            $nick = trim((string) ($data['realname'] ?? ''));
        }
        if ($nick === '' && ! empty($data['email'])) {
            $nick = explode('@', (string) $data['email'])[0] ?: ('玩家' . $memberId);
        }
        $data['nickname'] = $nick !== '' ? $nick : ('玩家' . $memberId);
        $data['gold'] = (float) ($data['points'] ?? 0);
        $data['cards'] = $this->roomCardService->totalQuantity((int) $memberId);
        $data['room_cards'] = $data['cards'];

        return app('Json')->data($data);
    }

    /** 点头像：昵称、玩家 ID、金币、房卡 */
    public function brief(): ResponseInterface
    {
        $id = (int) $this->request->input('id', 0);
        if ($id <= 0) {
            $id = (int) Context::get('member_id');
        }
        if ($id <= 0) {
            return app('Json')->fail('用户不存在');
        }

        $member = $this->memberService->get(
            $id,
            ['id', 'nickname', 'realname', 'avatar', 'points']
        );
        if (! $member) {
            return app('Json')->fail('用户不存在');
        }

        $data = $member->toArray();
        $nick = trim((string) ($data['nickname'] ?? ''));
        if ($nick === '') {
            $nick = trim((string) ($data['realname'] ?? ''));
        }
        $data['nickname'] = $nick !== '' ? $nick : ('玩家' . $id);
        $data['avatar_url'] = $this->memberService->buildAvatarUrl((string) ($data['avatar'] ?? ''));
        $data['gold'] = (float) ($data['points'] ?? 0);
        $data['cards'] = $this->roomCardService->totalQuantity($id);
        $data['room_cards'] = $data['cards'];

        return app('Json')->data($data);
    }

    public function logout(): ResponseInterface
    {
        $memberId = (int) Context::get('member_id');

        try {
            $jwt = $this->container->get(\HyperfExtension\Jwt\Contracts\JwtFactoryInterface::class)->make();
            $jwt->parseToken()->invalidate();
        } catch (\Throwable $e) {
            // Token 已失效或解析失败，视为已登出
        }

        // 登出时主动断开该账号的长连接，避免 uid→fd 残留导致同账号双端在线
        if ($memberId > 0) {
            WebSocketKick::disconnectUid($memberId);
        }

        return app('Json')->success('登出成功');
    }

    public function avatar(MemberRequest $request): ResponseInterface
    {
        $memberId = (int) Context::get('member_id');
        $file = $request->file('avatar');

        if (! $file) {
            return app('Json')->fail('请上传头像文件');
        }

        $data = $this->memberService->uploadAvatar($memberId, $file);

        return app('Json')->success('头像上传成功');
    }

}