<?php

namespace App\Http\Controllers\V1\Client;

use App\Http\Controllers\Controller;
use App\Protocols\ClientProtocols;
use App\Services\ServerService;
use App\Services\UserService;
use App\Utils\Helper;
use Illuminate\Http\Response;
use Illuminate\Http\Request;

class ClientController extends Controller
{
    private $serverService;
    private $userService;

    public function __construct(ServerService $serverService, UserService $userService)
    {
        $this->serverService = $serverService;
        $this->userService = $userService;
    }

    public function subscribe(Request $request): Response
    {
        $userAgent = $request->input('flag')
            ?? $request->userAgent();
        $profile = ClientProtocols::match($userAgent);
        $user = $request->user;
        $unavailableReason = $this->getUnavailableReason($user);

        if ($unavailableReason !== null) {
            return $this->render(
                $profile,
                $user,
                $this->filterServers($profile, $this->makeNoticeServers($unavailableReason))
            );
        }

        $servers = $this->filterServers(
            $profile,
            $this->serverService->getAvailableServers($user)
        );
        if (empty($servers)) {
            return $this->render(
                $profile,
                $user,
                $this->filterServers($profile, $this->makeNoticeServers([
                    '暂无可用节点',
                    '请联系官网客服处理',
                ]))
            );
        }

        if ((string)$userAgent !== '' && $profile['subscription_info']) {
            $this->prependSubscriptionInfo($servers, $user);
        }

        return $this->render($profile, $user, $servers);
    }

    private function filterServers(array $profile, array $servers): array
    {
        return ClientProtocols::filter($profile['name'], $servers);
    }

    private function render(array $profile, $user, array $servers): Response
    {
        $renderer = $profile['renderer'];

        return (new $renderer($user, $servers))->handle();
    }

    private function prependSubscriptionInfo(array &$servers, $user): void
    {
        if (!isset($servers[0])) return;
        if (!(int)config('v2board.show_info_to_server_enable', 0)) return;

        $usedTraffic = $user['u'] + $user['d'];
        $remainingTraffic = Helper::trafficConvert($user['transfer_enable'] - $usedTraffic);
        $expiredDate = $user['expired_at'] ? date('Y-m-d', $user['expired_at']) : '长期有效';
        $resetDay = $this->userService->getResetDay($user);

        array_unshift($servers, array_merge($servers[0], [
            'name' => "套餐到期：{$expiredDate}",
        ]));
        if ($resetDay) {
            array_unshift($servers, array_merge($servers[0], [
                'name' => "距离下次重置剩余：{$resetDay} 天",
            ]));
        }
        array_unshift($servers, array_merge($servers[0], [
            'name' => "剩余流量：{$remainingTraffic}",
        ]));
    }

    private function getUnavailableReason($user)
    {
        if ((int)$user['banned']) {
            return ['账号已被封禁', '请联系官网客服处理'];
        }
        if ((int)$user['transfer_enable'] <= 0) {
            return ['暂无有效订阅', '请购买订阅后重新更新订阅'];
        }
        if ($user['expired_at'] !== null && $user['expired_at'] <= time()) {
            $expiredDate = $user['expired_at'] ? date('Y-m-d', $user['expired_at']) : '已过期';
            return ['订阅已到期', "到期时间：{$expiredDate}", '请续费后重新更新订阅'];
        }
        if ($user['u'] + $user['d'] >= $user['transfer_enable']) {
            return ['流量已用尽', '请重置流量或续费后重新更新订阅'];
        }

        return null;
    }

    private function makeNoticeServers(array $names): array
    {
        $names[] = '官网：' . (config('v2board.app_url') ?: url('/'));

        return array_map(function ($name, $index) {
            return [
                'id' => $index + 1,
                'type' => 'v2node',
                'protocol' => 'shadowsocks',
                'name' => $name,
                'host' => '127.0.0.1',
                'port' => 1,
                'cipher' => 'aes-128-gcm',
                'network' => 'tcp',
                'network_settings' => [],
                'obfs' => '',
                'obfs-host' => '',
                'obfs-path' => '',
                'created_at' => time(),
                'updated_at' => time(),
                'last_check_at' => time(),
            ];
        }, $names, array_keys($names));
    }
}
