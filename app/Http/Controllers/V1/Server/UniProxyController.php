<?php

namespace App\Http\Controllers\V1\Server;

use App\Http\Controllers\Controller;
use App\Services\ServerService;
use App\Services\UserService;
use App\Utils\CacheKey;
use App\Utils\Helper;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use MessagePack\Packer;

class UniProxyController extends Controller
{
    private $nodeType;
    private $nodeInfo;
    private $nodeId;
    private $serverService;

    public function __construct(Request $request)
    {
        $token = $request->input('token');
        if (empty($token)) {
            abort(500, 'token is null');
        }
        if ($token !== config('v2board.server_token')) {
            abort(500, 'token is error');
        }
        $this->nodeType = $request->input('node_type');
        if ($this->nodeType === 'v2ray') $this->nodeType = 'vmess';
        if ($this->nodeType === 'hysteria2') $this->nodeType = 'hysteria';
        $this->nodeId = $request->input('node_id');
        $this->serverService = new ServerService();
        $this->nodeInfo = $this->serverService->getServer($this->nodeId, $this->nodeType);
        if (!$this->nodeInfo) abort(500, 'server is not exist');
    }

    // 后端获取用户
    public function user(Request $request)
    {
        ini_set('memory_limit', -1);
        Cache::put(CacheKey::get('SERVER_' . strtoupper($this->nodeType) . '_LAST_CHECK_AT', $this->nodeInfo->id), time(), 3600);
        $users = $this->serverService->getAvailableUsers($this->nodeInfo->group_id)
            ->map(function ($user) {
                return array_filter($user->toArray(), function ($v) {
                    return !is_null($v);
                });
            })->toArray();

        $response['users'] = $users;
        if (strpos($request->header('X-Response-Format'), 'msgpack') !== false) {
            $packer = new Packer();
            $response = $packer->pack($response);
            $eTag = sha1($response);
            if (strpos($request->header('If-None-Match'), $eTag) !== false) {
                abort(304);
            }

            return response($response, 200, ['Content-Type' => 'application/x-msgpack'])->header('ETag', "\"{$eTag}\"");
        } else {
            $eTag = sha1(json_encode($response));
            if (strpos($request->header('If-None-Match'), $eTag) !== false) {
                abort(304);
            }

            return response($response)->header('ETag', "\"{$eTag}\"");
        }
    }

    // 后端提交数据
    public function push(Request $request)
    {
        $data = $request->json()->all();
        if (empty($data)) {
            $data = $_POST;
        }
        if ($data === null && json_last_error() !== JSON_ERROR_NONE) {
            // JSON decoding error
            return response([
                'error' => 'Invalid traffic data'
            ], 400);
        }
        $this->updateOnlineUsers($request, $data);
        $userService = new UserService();
        $userService->trafficFetch($this->nodeInfo->toArray(), $this->nodeType, $data);

        return response([
            'data' => true
        ]);
    }

    // 后端获取在线数据
    public function alivelist(Request $request)
    {
        $alive = Cache::remember('ALIVE_LIST', 60, function () {
            $userService = new UserService();
            $users = $userService->getDeviceLimitedUsers();

            if ($users->isEmpty()) {
                return [];
            }

            $keys = [];
            $idMap = [];
            foreach ($users as $user) {
                $key = 'ALIVE_IP_USER_' . $user->id;
                $keys[] = $key;
                $idMap[$key] = $user->id;
            }

            $results = Cache::many($keys);
            $alive = [];
            foreach ($results as $key => $data) {
                if (is_array($data) && isset($data['alive_ip'])) {
                    $alive[$idMap[$key]] = $data['alive_ip'];
                }
            }
            return $alive;
        });
        return response()->json(['alive' => (object)$alive]);
    }

    // 后端提交在线数据
    public function alive(Request $request)
    {
        $data = $request->json()->all();
        if (empty($data)) {
            $data = $_POST;
        }
        if (!is_array($data)) {
            return response([
                'error' => 'Invalid online data format'
            ], 400);
        }
        $updateAt = time();
        $sourceId = $this->getReportSourceId($request);
        $sourceKey = $this->getAliveSourceKey($sourceId);
        $legacyNodeKey = $this->nodeType . $this->nodeId;
        Cache::lock($this->getAliveLockKey(), 10)->block(3, function () use ($data, $updateAt, $sourceId, $sourceKey, $legacyNodeKey) {
            $cacheKeys = array_map(function ($uid) {
                return 'ALIVE_IP_USER_' . $uid;
            }, array_keys($data));

            $cachedData = Cache::many($cacheKeys);
            $updates = [];

            foreach ($data as $uid => $ips) {
                if (!is_numeric($uid) || !is_array($ips)) {
                    continue; // 跳过无效数据
                }
                $key = 'ALIVE_IP_USER_' . $uid;
                $ips_array = $cachedData[$key] ?? [];

                // 更新节点数据
                $ips_array[$sourceKey] = ['aliveips' => $this->formatAliveIps($ips, $sourceId), 'lastupdateAt' => $updateAt];
                unset($ips_array[$legacyNodeKey]);
                // 清理过期数据
                foreach ($ips_array as $nodetypeid => $oldips) {
                    if ($nodetypeid !== 'alive_ip' && is_array($oldips) && ($updateAt - ($oldips['lastupdateAt'] ?? 0) > 100)) {
                        unset($ips_array[$nodetypeid]);
                    }
                }

                // 计算活跃IP数量
                $count = 0;
                if (config('v2board.device_limit_mode', 0) == 1) {
                    $ipmap = [];
                    foreach ($ips_array as $nodetypeid => $newdata) {
                        if ($nodetypeid !== 'alive_ip' && is_array($newdata) && isset($newdata['aliveips'])) {
                            foreach ($newdata['aliveips'] as $ip_NodeId) {
                                $ip = $this->getAliveClientIp($ip_NodeId);
                                if ($ip !== null) {
                                    $ipmap[$ip] = 1;
                                }
                            }
                        }
                    }
                    $count = count($ipmap);
                } else {
                    $deviceMap = [];
                    foreach ($ips_array as $nodetypeid => $newdata) {
                        if ($nodetypeid !== 'alive_ip' && is_array($newdata) && isset($newdata['aliveips'])) {
                            foreach ($newdata['aliveips'] as $ip_NodeId) {
                                $ip = $this->getAliveClientIp($ip_NodeId);
                                if ($ip !== null) {
                                    $deviceMap[$ip . '_' . $this->getAliveLogicalNodeKey($nodetypeid)] = 1;
                                }
                            }
                        }
                    }
                    $count = count($deviceMap);
                }
                $ips_array['alive_ip'] = $count;

                $updates[$key] = $ips_array;
            }

            // 批量更新缓存
            foreach ($updates as $key => $value) {
                Cache::put($key, $value, 120);
            }
            if (!empty($updates)) {
                Cache::forget('ALIVE_LIST');
            }
        });

        return response([
            'data' => true
        ]);
    }

    // 后端获取配置
    public function config(Request $request)
    {
        switch ($this->nodeType) {
            case 'shadowsocks':
                $response = [
                    'server_port' => $this->nodeInfo->server_port,
                    'cipher' => $this->nodeInfo->cipher,
                    'obfs' => $this->nodeInfo->obfs,
                    'obfs_settings' => $this->nodeInfo->obfs_settings
                ];

                if ($this->nodeInfo->cipher === '2022-blake3-aes-128-gcm') {
                    $response['server_key'] = Helper::getServerKey($this->nodeInfo->created_at, 16);
                }
                if ($this->nodeInfo->cipher === '2022-blake3-aes-256-gcm') {
                    $response['server_key'] = Helper::getServerKey($this->nodeInfo->created_at, 32);
                }
                break;
            case 'vmess':
                $response = [
                    'server_port' => $this->nodeInfo->server_port,
                    'network' => $this->nodeInfo->network,
                    'networkSettings' => $this->nodeInfo->networkSettings,
                    'tls' => $this->nodeInfo->tls
                ];
                break;
            case 'vless':
                $response = [
                    'server_port' => $this->nodeInfo->server_port,
                    'network' => $this->nodeInfo->network,
                    'networkSettings' => $this->nodeInfo->network_settings,
                    'tls' => $this->nodeInfo->tls,
                    'flow' => $this->nodeInfo->flow,
                    'tls_settings' => $this->nodeInfo->tls_settings,
                    'encryption' => $this->nodeInfo->encryption,
                    'encryption_settings' => $this->nodeInfo->encryption_settings
                ];
                break;
            case 'trojan':
                $response = [
                    'host' => $this->nodeInfo->host,
                    'network' => $this->nodeInfo->network,
                    'networkSettings' => $this->nodeInfo->network_settings,
                    'server_port' => $this->nodeInfo->server_port,
                    'server_name' => $this->nodeInfo->server_name,
                ];
                break;
            case 'tuic':
                $response = [
                    'server_port' => $this->nodeInfo->server_port,
                    'server_name' => $this->nodeInfo->server_name,
                    'congestion_control' => $this->nodeInfo->congestion_control,
                    'zero_rtt_handshake' => $this->nodeInfo->zero_rtt_handshake ? true : false,
                ];
                break;
            case 'hysteria':
                $response = [
                    'version' => $this->nodeInfo->version,
                    'host' => $this->nodeInfo->host,
                    'server_port' => $this->nodeInfo->server_port,
                    'server_name' => $this->nodeInfo->server_name,
                    'up_mbps' => $this->nodeInfo->up_mbps,
                    'down_mbps' => $this->nodeInfo->down_mbps
                ];
                if ($this->nodeInfo->version == 1) {
                    $response['obfs'] = $this->nodeInfo->obfs_password ?? null;
                } elseif ($this->nodeInfo->version == 2) {
                    if ($this->nodeInfo->up_mbps == 0 && $this->nodeInfo->down_mbps == 0) {
                        $response['ignore_client_bandwidth'] = true;
                    } else {
                        $response['ignore_client_bandwidth'] = false;
                    }
                    $response['obfs'] = $this->nodeInfo->obfs ?? null;
                    $response['obfs-password'] = $this->nodeInfo->obfs_password ?? null;
                }
                break;
            case 'anytls':
                $response = [
                    'server_port' => $this->nodeInfo->server_port,
                    'server_name' => $this->nodeInfo->server_name,
                    'padding_scheme' => $this->nodeInfo->padding_scheme
                ];
                break;
        }
        $response['base_config'] = [
            'push_interval' => (int)config('v2board.server_push_interval', 60),
            'pull_interval' => (int)config('v2board.server_pull_interval', 60)
        ];
        if ($this->nodeInfo['route_id']) {
            $response['routes'] = $this->serverService->getRoutes($this->nodeInfo['route_id']);
        }
        $eTag = sha1(json_encode($response));
        if (strpos($request->header('If-None-Match'), $eTag) !== false) {
            abort(304);
        }

        return response($response)->header('ETag', "\"{$eTag}\"");
    }

    private function updateOnlineUsers(Request $request, array $data)
    {
        $lockKey = $this->getOnlineSourcesKey() . '_LOCK';
        Cache::lock($lockKey, 10)->block(3, function () use ($request, $data) {
            $now = time();
            $sourcesKey = $this->getOnlineSourcesKey();
            $sources = Cache::get($sourcesKey, []);
            if (!is_array($sources)) {
                $sources = [];
            }

            $sources[$this->getReportSourceId($request)] = [
                'users' => array_values(array_map('strval', array_keys($data))),
                'last_push_at' => $now
            ];

            $this->pruneOnlineSources($sources, $now);
            $this->writeAggregatedOnlineUsers($sources, $now);
            Cache::put($sourcesKey, $sources, 3600);
        });
    }

    private function pruneOnlineSources(array &$sources, int $now)
    {
        $expiredAt = $now - max(300, (int)config('v2board.server_push_interval', 60) * 3);
        foreach ($sources as $sourceId => $source) {
            if (!is_array($source) || !isset($source['last_push_at']) || $source['last_push_at'] < $expiredAt) {
                unset($sources[$sourceId]);
            }
        }
    }

    private function writeAggregatedOnlineUsers(array $sources, int $now)
    {
        $onlineUsers = [];
        $lastPushAt = 0;
        foreach ($sources as $source) {
            $lastPushAt = max($lastPushAt, (int)$source['last_push_at']);
            foreach (($source['users'] ?? []) as $userId) {
                $onlineUsers[$userId] = 1;
            }
        }

        $serverType = strtoupper($this->nodeType);
        Cache::put(CacheKey::get("SERVER_{$serverType}_ONLINE_USER", $this->nodeInfo->id), count($onlineUsers), 3600);
        Cache::put(CacheKey::get("SERVER_{$serverType}_LAST_PUSH_AT", $this->nodeInfo->id), $lastPushAt ?: $now, 3600);
    }

    private function getOnlineSourcesKey(): string
    {
        return 'SERVER_' . strtoupper($this->nodeType) . '_ONLINE_SOURCES_' . $this->nodeInfo->id;
    }

    private function getAliveSourceKey(string $sourceId): string
    {
        return $this->nodeType . $this->nodeId . ':' . $sourceId;
    }

    private function getAliveLockKey(): string
    {
        return 'ALIVE_IP_USER_LOCK_' . strtoupper($this->nodeType) . '_' . $this->nodeId;
    }

    private function getReportSourceId(Request $request): string
    {
        $sourceId = $request->header('CF-Connecting-IP')
            ?: $request->header('X-Real-IP')
            ?: $this->getFirstForwardedFor($request)
            ?: $request->ip()
            ?: $request->server('REMOTE_ADDR')
            ?: 'unknown';

        $sourceId = trim((string)$sourceId);
        if ($sourceId === '') {
            $sourceId = 'unknown';
        }

        return preg_replace('/[^A-Za-z0-9_.:-]/', '_', $sourceId);
    }

    private function getFirstForwardedFor(Request $request)
    {
        $forwardedFor = $request->header('X-Forwarded-For');
        if (!$forwardedFor) {
            return null;
        }
        $ips = explode(',', $forwardedFor);
        return trim($ips[0]);
    }

    private function formatAliveIps(array $ips, string $sourceId): array
    {
        $formatted = [];
        $instanceId = $this->getAliveInstanceId($sourceId);
        foreach ($ips as $ipNodeId) {
            $ip = $this->getAliveClientIp($ipNodeId);
            if ($ip === null) {
                continue;
            }
            $formatted[] = $ip . '_' . $instanceId;
        }
        return $formatted;
    }

    private function getAliveInstanceId(string $sourceId): string
    {
        $hash = substr(sha1($this->nodeType . ':' . $this->nodeId . ':' . $sourceId), 0, 8);
        return $this->nodeType . $this->nodeId . '-' . $hash;
    }

    private function getAliveLogicalNodeKey($sourceKey): string
    {
        $parts = explode(':', (string)$sourceKey, 2);
        $nodeKey = trim($parts[0] ?? '');
        return $nodeKey === '' ? 'unknown' : $nodeKey;
    }

    private function getAliveClientIp($ipNodeId)
    {
        if (!is_string($ipNodeId) && !is_numeric($ipNodeId)) {
            return null;
        }
        $parts = explode('_', trim((string)$ipNodeId), 2);
        $ip = trim($parts[0] ?? '');
        return $ip === '' ? null : $ip;
    }
}
