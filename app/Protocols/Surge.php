<?php

namespace App\Protocols;

use Illuminate\Http\Response;
use App\Utils\Helper;

class Surge extends AbstractProtocol
{

    public function handle(): Response
    {
        $servers = $this->servers;
        $user = $this->user;

        $appName = config('v2board.app_name', 'V2Board');
        $proxies = '';
        $proxyGroup = '';

        foreach ($servers as $item) {
            $item = Helper::normalizeServerProtocol($item);
            if ($item['type'] === 'shadowsocks') {
                // [Proxy]
                $proxies .= self::buildShadowsocks($user['uuid'], $item);
                // [Proxy Group]
                $proxyGroup .= $item['name'] . ', ';
            }elseif ($item['type'] === 'vmess') {
                // [Proxy]
                $proxies .= self::buildVmess($user['uuid'], $item);
                // [Proxy Group]
                $proxyGroup .= $item['name'] . ', ';
            }elseif ($item['type'] === 'trojan') {
                // [Proxy]
                $proxies .= self::buildTrojan($user['uuid'], $item);
                // [Proxy Group]
                $proxyGroup .= $item['name'] . ', ';
            }elseif ($item['type'] === 'hysteria2') {
                // [Proxy]
                $proxies .= self::buildHysteria($user['uuid'], $item);
                // [Proxy Group]
                $proxyGroup .= $item['name'] . ', ';
            }elseif ($item['type'] === 'anytls') {
                // [Proxy]
                $proxies .= self::buildAnyTLS($user['uuid'], $item);
                // [Proxy Group]
                $proxyGroup .= $item['name'] . ', ';
            }
        }

        $defaultConfig = base_path() . '/resources/rules/default.surge.conf';
        $customConfig = base_path() . '/resources/rules/custom.surge.conf';
        if (\File::exists($customConfig)) {
            $config = file_get_contents("$customConfig");
        } else {
            $config = file_get_contents("$defaultConfig");
        }

        // Subscription link
        $subsURL = Helper::getSubscribeUrl($user['token']);
        $subsDomain = $_SERVER['HTTP_HOST'];

        $config = str_replace('$subs_link', $subsURL, $config);
        $config = str_replace('$subs_domain', $subsDomain, $config);
        $config = str_replace('$proxies', $proxies, $config);
        $config = str_replace('$proxy_group', rtrim($proxyGroup, ', '), $config);

        $upload = round($user['u'] / (1024*1024*1024), 2);
        $download = round($user['d'] / (1024*1024*1024), 2);
        $useTraffic = $upload + $download;
        $totalTraffic = round($user['transfer_enable'] / (1024*1024*1024), 2);
        $expireDate = $user['expired_at'] === NULL ? '长期有效' : date('Y-m-d H:i:s', $user['expired_at']);
        $subscribeInfo = "title={$appName}订阅信息, content=上传流量：{$upload}GB\\n下载流量：{$download}GB\\n剩余流量：{$useTraffic}GB\\n套餐流量：{$totalTraffic}GB\\n到期时间：{$expireDate}";
        $config = str_replace('$subscribe_info', $subscribeInfo, $config);

        return $this->response($config, [
            'content-disposition' => "attachment;filename*=UTF-8''" . rawurlencode($appName) . '.conf',
        ]);
    }

    public static function buildShadowsocks($password, $server)
    {
        if ($server['cipher'] === '2022-blake3-aes-128-gcm') {
            $serverKey = Helper::getServerKey($server['created_at'], 16);
            $userKey = Helper::uuidToBase64($password, 16);
            $password = "{$serverKey}:{$userKey}";
        } elseif ($server['cipher'] === '2022-blake3-aes-256-gcm') {
            $serverKey = Helper::getServerKey($server['created_at'], 32);
            $userKey = Helper::uuidToBase64($password, 32);
            $password = "{$serverKey}:{$userKey}";
        }
        $config = [
            "{$server['name']}=ss",
        ];
        $config[] = $server['host'];
        $config[] = $server['port'];
        $config[] = "encrypt-method={$server['cipher']}";
        $config[] = "password={$password}";

        if (isset($server['obfs']) && in_array($server['obfs'], ['http', 'tls'], true)) {
            $config[] = "obfs={$server['obfs']}";
            if (isset($server['obfs-host']) && !empty($server['obfs-host'])) {
                $config[] = "obfs-host={$server['obfs-host']}";
            }
            if (isset($server['obfs-path'])) {
                $config[] = "obfs-uri={$server['obfs-path']}";
            }
        } elseif (($server['network'] ?? null) === 'http') {
            $networkSettings = $server['network_settings'] ?? [];
            $config[] = 'obfs=http';
            if (!empty($networkSettings['Host'])) {
                $config[] = "obfs-host={$networkSettings['Host']}";
            }
            if (isset($networkSettings['path'])) {
                $config[] = "obfs-uri={$networkSettings['path']}";
            }
        }
        $config[] = 'fast-open=false';
        $config[] = 'udp-relay=true';
        $uri = implode(',', $config);
        $uri .= "\r\n";

        return $uri;
    }

    public static function buildVmess($uuid, $server)
    {
        $networkSettings = $server['network_settings'] ?? [];
        $tlsSettings = $server['tls_settings'] ?? [];
        $config = [
            "{$server['name']}=vmess",
            "{$server['host']}",
            "{$server['port']}",
            "username={$uuid}",
            "vmess-aead=true",
            'tfo=true',
            'udp-relay=true'
        ];

        if ($server['tls']) {
            array_push($config, 'tls=true');
            if ($tlsSettings) {
                $allowInsecure = $tlsSettings['allow_insecure'] ?? null;
                $serverName = $tlsSettings['server_name'] ?? null;
                if (!empty($allowInsecure))
                    array_push($config, 'skip-cert-verify=' . ($allowInsecure ? 'true' : 'false'));
                if (!empty($serverName))
                    array_push($config, "sni={$serverName}");
            }
        }
        if ($server['network'] === 'ws') {
            array_push($config, 'ws=true');
            if ($networkSettings) {
                $wsSettings = $networkSettings;
                if (isset($wsSettings['path']) && !empty($wsSettings['path']))
                    array_push($config, "ws-path={$wsSettings['path']}");
                if (isset($wsSettings['headers']['Host']) && !empty($wsSettings['headers']['Host']))
                    array_push($config, "ws-headers=Host:{$wsSettings['headers']['Host']}");
                if (isset($wsSettings['security'])) 
                    array_push($config, "encrypt-method={$wsSettings['security']}");
            }
        }

        $uri = implode(',', $config);
        $uri .= "\r\n";
        return $uri;
    }

    public static function buildTrojan($password, $server)
    {
        $tlsSettings = $server['tls_settings'] ?? [];
        $sni = $tlsSettings['server_name'] ?? '';
        $allowInsecure = $tlsSettings['allow_insecure'] ?? 0;
        $config = [
            "{$server['name']}=trojan",
            "{$server['host']}",
            "{$server['port']}",
            "password={$password}",
            $sni ? "sni={$sni}" : "",
            'tfo=true',
            'udp-relay=true'
        ];
        if ($allowInsecure !== null) {
            array_push($config, $allowInsecure ? 'skip-cert-verify=true' : 'skip-cert-verify=false');
        }
        if (isset($server['network']) && (string)$server['network'] === 'ws') {
            array_push($config, 'ws=true');
            if ($server['network_settings']) {
                $wsSettings = $server['network_settings'];
                if (isset($wsSettings['path']) && !empty($wsSettings['path']))
                    array_push($config, "ws-path={$wsSettings['path']}");
                if (isset($wsSettings['headers']['Host']) && !empty($wsSettings['headers']['Host']))
                    array_push($config, "ws-headers=Host:{$wsSettings['headers']['Host']}");
            }
        }
        $config = array_filter($config);
        $uri = implode(',', $config);
        $uri .= "\r\n";
        return $uri;
    }

    //参考文档: https://manual.nssurge.com/policy/proxy.html
    public static function buildHysteria($password, $server)
    {
        $tlsSettings = $server['tls_settings'] ?? [];
        $sni = $tlsSettings['server_name'] ?? '';
        $insecure = $tlsSettings['allow_insecure'] ?? 0;

        $parts = explode(",",$server['port']);
        $firstPart = $parts[0];
        if (strpos($firstPart, '-') !== false) {
            $range = explode('-', $firstPart);
            $firstPort = $range[0];
        } else {
            $firstPort = $firstPart;
        }

        $config = [
            "{$server['name']}=hysteria2",
            "{$server['host']}",
            "{$firstPort}",
            "password={$password}",
            $sni ? "sni={$sni}" : "",
            'udp-relay=true'
        ];
        if (!empty($server['down_mbps'])) {
            $config[] = "download-bandwidth={$server['down_mbps']}";
        }
        if (count($parts) !== 1 || strpos($parts[0], '-') !== false) {
            $config[] = 'port-hopping=' . str_replace(',', ';', $server['port']);
        }
        if (!empty($server['obfs_password'])) {
            $obfs = ($server['obfs'] ?? 'salamander') === 'gecko' ? 'gecko' : 'salamander';
            $config[] = "{$obfs}-password={$server['obfs_password']}";
        }
        if ($insecure !== null) {
            array_push($config, $insecure ? 'skip-cert-verify=true' : 'skip-cert-verify=false');
        }
        $config = array_filter($config);
        $uri = implode(',', $config);
        $uri .= "\r\n";
        return $uri;
    }

    public static function buildAnyTLS($password, $server)
    {
        $tlsSettings = $server['tls_settings'] ?? [];
        $allowInsecure = ($tlsSettings['allow_insecure'] ?? 0) == 1 ? 'true' : 'false';
        $sni = $tlsSettings['server_name'] ?? '';

        $config = [
            "{$server['name']}=anytls",
            "{$server['host']}",
            "{$server['port']}",
            "password={$password}",
            "skip-cert-verify={$allowInsecure}",
            'tfo=true',
        ];

        if ($sni) {
            $config[] = "sni={$sni}";
        }

        $uri = implode(', ', $config);
        $uri .= "\r\n";
        return $uri;
    }
}
