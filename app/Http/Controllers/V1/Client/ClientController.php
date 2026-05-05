<?php

namespace App\Http\Controllers\V1\Client;

use App\Http\Controllers\Controller;
use App\Protocols\General;
use App\Protocols\Singbox\Singbox;
use App\Protocols\Singbox\SingboxOld;
use App\Protocols\ClashMeta;
use App\Services\ServerService;
use App\Services\UserService;
use App\Utils\Helper;
use Illuminate\Http\Request;

class ClientController extends Controller
{
    public function subscribe(Request $request)
    {
        $flag = $request->input('flag')
            ?? ($_SERVER['HTTP_USER_AGENT'] ?? '');
        $flag = strtolower($flag);
        $user = $request->user;
        $unavailableReason = $this->getUnavailableReason($user);
        if ($unavailableReason === null) {
            $serverService = new ServerService();
            $servers = $serverService->getAvailableServers($user);
            if (empty($servers)) {
                $servers = $this->getUnavailableSubscribeServers(['暂无可用节点', '请联系官网客服处理']);
                return $this->formatSubscribe($flag, $user, $servers);
            }
            if ($flag && strpos($flag, 'sing') === false) {
                $this->setSubscribeInfoToServers($servers, $user);
            }
            return $this->formatSubscribe($flag, $user, $servers);
        }
        $servers = $this->getUnavailableSubscribeServers($unavailableReason);
        return $this->formatSubscribe($flag, $user, $servers);
    }

    private function formatSubscribe($flag, $user, $servers)
    {
        if($flag) {
            if (strpos($flag, 'sing') === false) {
                foreach (array_reverse(glob(app_path('Protocols') . '/*.php')) as $file) {
                    $file = 'App\\Protocols\\' . basename($file, '.php');
                    $class = new $file($user, $servers);
                    if (strpos($flag, $class->flag) !== false) {
                        return $class->handle();
                    }
                }
            }
            if (strpos($flag, 'sing') !== false) {
                $version = null;
                if (preg_match('/sing-box[\/\s]+([0-9.]+)/i', $flag, $matches)) {
                    $version = $matches[1];
                }
                if (!is_null($version) && version_compare($version, '1.12.0', '>=')) {
                    $class = new Singbox($user, $servers);
                } else {
                    $class = new SingboxOld($user, $servers);
                }
                return $class->handle();
            }
        }
        $class = new General($user, $servers);
        return $class->handle();
    }

    private function setSubscribeInfoToServers(&$servers, $user)
    {
        if (!isset($servers[0])) return;
        if (!(int)config('v2board.show_info_to_server_enable', 0)) return;
        $useTraffic = $user['u'] + $user['d'];
        $totalTraffic = $user['transfer_enable'];
        $remainingTraffic = Helper::trafficConvert($totalTraffic - $useTraffic);
        $expiredDate = $user['expired_at'] ? date('Y-m-d', $user['expired_at']) : '长期有效';
        $userService = new UserService();
        $resetDay = $userService->getResetDay($user);
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

    private function getUnavailableSubscribeServers($names)
    {
        $officialUrl = config('v2board.app_url') ?: url('/');
        $names[] = "官网：{$officialUrl}";

        return array_map(function ($name, $index) {
            return [
                'id' => $index + 1,
                'type' => 'shadowsocks',
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
                'last_check_at' => time()
            ];
        }, $names, array_keys($names));
    }
}
