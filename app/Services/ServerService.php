<?php

namespace App\Services;

use App\Models\ServerLog;
use App\Models\ServerRoute;
use App\Models\ServerV2node;
use App\Models\Plan;
use App\Models\User;
use App\Utils\CacheKey;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;

class ServerService
{
    private const GLOBAL_ONLINE_USERS_KEY = 'SERVER_GLOBAL_ONLINE_USERS';

    public static function getOnlineStatusTtl(): int
    {
        return max(300, (int)config('v2board.server_push_interval', 60) * 3);
    }

    public static function recordOnlineUsers(array $userIds, ?int $reportedAt = null): void
    {
        $reportedAt = $reportedAt ?? time();
        $expiredAt = $reportedAt - self::getOnlineStatusTtl();
        Redis::command('zremrangebyscore', [self::GLOBAL_ONLINE_USERS_KEY, '-inf', $expiredAt]);

        $userIds = array_values(array_unique(array_filter(array_map(function ($userId) {
            return is_numeric($userId) && (int)$userId > 0 ? (string)(int)$userId : null;
        }, $userIds))));
        if (empty($userIds)) {
            return;
        }

        $arguments = [self::GLOBAL_ONLINE_USERS_KEY];
        foreach ($userIds as $userId) {
            $arguments[] = $reportedAt;
            $arguments[] = $userId;
        }
        Redis::command('zadd', $arguments);
    }

    public static function getOnlineUserCount(?int $now = null): int
    {
        $now = $now ?? time();
        $expiredAt = $now - self::getOnlineStatusTtl();
        $onlineUserCount = (int)Redis::command('zcount', [
            self::GLOBAL_ONLINE_USERS_KEY,
            '(' . $expiredAt,
            '+inf'
        ]);
        if ($onlineUserCount > 0) {
            return $onlineUserCount;
        }

        return User::where('t', '>', $expiredAt)->count();
    }

    public function getAvailableV2node(User $user)
    {
        $servers = [];
        $model = ServerV2node::orderBy('sort', 'ASC');
        $v2node = $model->get()->keyBy('id');
        foreach ($v2node as $key => $v) {
            if (!$v['show']) continue;
            $v2node[$key]['type'] = 'v2node';
            $v2node[$key]['last_check_at'] = Cache::get(CacheKey::get('SERVER_V2NODE_LAST_CHECK_AT', $v['id']));
            if (!in_array($user->group_id, $v['group_id'])) continue;
            if (isset($v2node[$v['parent_id']])) {
                $v2node[$key]['last_check_at'] = Cache::get(CacheKey::get('SERVER_V2NODE_LAST_CHECK_AT', $v['parent_id']));
                $v2node[$key]['created_at'] = $v2node[$v['parent_id']]['created_at'];
            }
            if (isset($v2node[$key]['tls_settings'])) {
                $v2node[$key]['tls_settings'] = array_diff_key(
                    $v2node[$key]['tls_settings'],
                    array_flip(array_filter(['private_key', 'ech_key'], function($k) use ($v2node, $key) {
                        return isset($v2node[$key]['tls_settings'][$k]);
                    }))
                );
            }
            if (isset($v2node[$key]['encryption_settings'])) {
                if (isset($v2node[$key]['encryption_settings']['private_key'])) {
                    $v2node[$key]['encryption_settings'] = array_diff_key($v2node[$key]['encryption_settings'], array('private_key' => ''));
                }
            }
            $servers[] = $v2node[$key]->toArray();
        }
        return $servers;
    }

    public function getAvailableServers(User $user, bool $includePlanNames = false)
    {
        $servers = $this->getAvailableV2node($user);
        $tmp = array_column($servers, 'sort');
        array_multisort($tmp, SORT_ASC, $servers);
        $servers = array_map(function ($server) {
            if (strpos($server['port'], '-')) {
                $server['mport'] = (string)$server['port'];
            } else {
                $server['port'] = (int)$server['port'];
            }
            $server['is_online'] = (time() - 300 > $server['last_check_at']) ? 0 : 1;
            $server['cache_key'] = "{$server['type']}-{$server['id']}-{$server['updated_at']}-{$server['is_online']}";
            return $server;
        }, $servers);

        if ($includePlanNames) {
            $planNamesByGroup = $this->getPlanNamesByGroup();
            $servers = array_map(function ($server) use ($planNamesByGroup) {
                $server['plans'] = $this->getPlanNamesForGroups($server['group_id'], $planNamesByGroup);
                return $server;
            }, $servers);
        }

        return $servers;
    }

    public function getPublicServers()
    {
        $servers = ServerV2node::where('show', 1)
            ->orderBy('sort', 'ASC')
            ->get([
                'id',
                'name',
                'parent_id',
                'group_id',
                'rate',
                'tags',
                'updated_at'
            ]);
        $planNamesByGroup = $this->getPlanNamesByGroup();

        return $servers->map(function ($server) use ($planNamesByGroup) {
            $statusServerId = $server->parent_id ?: $server->id;
            $lastCheckAt = Cache::get(CacheKey::get('SERVER_V2NODE_LAST_CHECK_AT', $statusServerId));
            $isOnline = $lastCheckAt && time() - 300 <= $lastCheckAt ? 1 : 0;

            return [
                'id' => $server->id,
                'name' => $server->name,
                'rate' => $server->rate,
                'tags' => $server->tags,
                'is_online' => $isOnline,
                'plans' => $this->getPlanNamesForGroups($server->group_id, $planNamesByGroup),
                'cache_key' => "public-v2node-{$server->id}-{$server->updated_at}-{$isOnline}"
            ];
        })->values()->all();
    }

    private function getPlanNamesByGroup(): array
    {
        $planNamesByGroup = [];
        $plans = Plan::where('show', 1)
            ->orderBy('sort', 'ASC')
            ->get(['name', 'group_id']);

        foreach ($plans as $plan) {
            $planNamesByGroup[(int)$plan->group_id][] = $plan->name;
        }

        return $planNamesByGroup;
    }

    private function getPlanNamesForGroups($groupIds, array $planNamesByGroup): array
    {
        $planNames = [];
        foreach ((array)$groupIds as $groupId) {
            $planNames = array_merge($planNames, $planNamesByGroup[(int)$groupId] ?? []);
        }

        return array_values(array_unique($planNames));
    }

    public function getAvailableUsers($groupId)
    {
        return User::whereIn('group_id', $groupId)
            ->whereRaw('u + d < transfer_enable')
            ->where(function ($query) {
                $query->where('expired_at', '>=', time())
                    ->orWhere('expired_at', NULL);
            })
            ->where('banned', 0)
            ->select([
                'id',
                'uuid',
                'speed_limit',
                'device_limit'
            ])
            ->get();
    }

    public function log(int $userId, int $serverId, int $u, int $d, float $rate, string $method)
    {
        if (($u + $d) < 10240) return true;
        $timestamp = strtotime(date('Y-m-d'));
        $serverLog = ServerLog::where('log_at', '>=', $timestamp)
            ->where('log_at', '<', $timestamp + 3600)
            ->where('server_id', $serverId)
            ->where('user_id', $userId)
            ->where('rate', $rate)
            ->where('method', $method)
            ->first();
        if ($serverLog) {
            try {
                $serverLog->increment('u', $u);
                $serverLog->increment('d', $d);
                return true;
            } catch (\Exception $e) {
                return false;
            }
        } else {
            $serverLog = new ServerLog();
            $serverLog->user_id = $userId;
            $serverLog->server_id = $serverId;
            $serverLog->u = $u;
            $serverLog->d = $d;
            $serverLog->rate = $rate;
            $serverLog->log_at = $timestamp;
            $serverLog->method = $method;
            return $serverLog->save();
        }
    }

    public function getAllV2node()
    {
        $servers = ServerV2node::orderBy('sort', 'ASC')
            ->get()
            ->toArray();
        foreach ($servers as $k => $v) {
            $servers[$k]['type'] = 'v2node';
            if (isset($v['padding_scheme'])) {
                $servers[$k]['padding_scheme'] = json_encode($v['padding_scheme']);
            }

            $apiHost = config('v2board.server_api_url', config('v2board.app_url'));
            $apiKey = config('v2board.server_token', '');
            $nodeId = (int) $v['id'];
            $apiHostArg = escapeshellarg((string) $apiHost);
            $apiKeyArg = escapeshellarg((string) $apiKey);
            $servers[$k]['install_command'] = sprintf(
                'wget -N https://raw.githubusercontent.com/wyx2685/v2node/master/script/install.sh && bash install.sh --api-host %s --node-id %d --api-key %s',
                $apiHostArg,
                $nodeId,
                $apiKeyArg
            );
        }
        return $servers;
    }

    private function mergeData(&$servers)
    {
        $onlineStatusTtl = self::getOnlineStatusTtl();
        foreach ($servers as $k => $v) {
            $serverType = strtoupper($v['type']);
            $servers[$k]['online'] = Cache::get(CacheKey::get("SERVER_{$serverType}_ONLINE_USER", $v['parent_id'] ?? $v['id']));
            $servers[$k]['last_check_at'] = Cache::get(CacheKey::get("SERVER_{$serverType}_LAST_CHECK_AT", $v['parent_id'] ?? $v['id']));
            $servers[$k]['last_push_at'] = Cache::get(CacheKey::get("SERVER_{$serverType}_LAST_PUSH_AT", $v['parent_id'] ?? $v['id']));
            if (!$servers[$k]['last_push_at'] || (time() - $onlineStatusTtl) >= $servers[$k]['last_push_at']) {
                $servers[$k]['online'] = 0;
            }
            if ((time() - 300) >= $servers[$k]['last_check_at']) {
                $servers[$k]['available_status'] = 0;
            } else if ((time() - $onlineStatusTtl) >= $servers[$k]['last_push_at']) {
                $servers[$k]['available_status'] = 1;
            } else {
                $servers[$k]['available_status'] = 2;
            }
        }
    }

    public function getAllServers()
    {
        $servers = $this->getAllV2node();
        $this->mergeData($servers);
        $tmp = array_column($servers, 'sort');
        array_multisort($tmp, SORT_ASC, $servers);
        return $servers;
    }

    public function getRoutes(array $routeIds)
    {
        $routeIds = array_map('intval', $routeIds);
        $order = implode(',', $routeIds);
        $routes = ServerRoute::select(['id', 'match', 'action', 'action_value'])
            ->whereIn('id', $routeIds)
            ->orderByRaw("FIELD(id, $order)")
            ->get();
        foreach ($routes as $k => $route) {
            $array = json_decode($route->match, true);
            if (is_array($array)) $routes[$k]['match'] = $array;
        }
        return $routes;
    }

    public function getServer($serverId)
    {
        return ServerV2node::find($serverId);
    }
}
