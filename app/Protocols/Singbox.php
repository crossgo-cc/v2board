<?php
namespace App\Protocols;

use Illuminate\Http\Response;
use App\Utils\Helper;

class Singbox extends AbstractProtocol
{
    private $config;

    public function handle(): Response
    {
        $appName = config('v2board.app_name', 'V2Board');
        $this->config = $this->loadConfig();
        $proxies = $this->buildProxies();
        $outbounds = $this->addProxies($proxies);
        $this->config['outbounds'] = $outbounds;
        return $this->response(json_encode($this->config, JSON_UNESCAPED_SLASHES), [
            'Content-Type' => 'application/json',
            'subscription-userinfo' => $this->userInfoHeader(),
            'profile-update-interval' => '2',
            'Profile-Title' => 'base64:' . base64_encode($appName),
            'Content-Disposition' => 'attachment; filename="' . $appName . '"',
        ]);
    }

    protected function loadConfig()
    {
        $defaultConfig = base_path('resources/rules/default.sing-box.json');
        $customConfig = base_path('resources/rules/custom.sing-box.json');
        $jsonData = file_exists($customConfig) ? file_get_contents($customConfig) : file_get_contents($defaultConfig);

        return json_decode($jsonData, true);
    }

    protected function buildProxies()
    {
        $proxies = [];
    
        foreach ($this->servers as $item) {
            $item = Helper::normalizeServerProtocol($item);
            switch ($item['type']) {
                case 'shadowsocks':
                    $ssConfig = $this->buildShadowsocks($this->user['uuid'], $item);
                    $proxies[] = $ssConfig;
                    break;
                case 'trojan':
                    $trojanConfig = $this->buildTrojan($this->user['uuid'], $item);
                    $proxies[] = $trojanConfig;
                    break;
                case 'vmess':
                    $vmessConfig = $this->buildVmess($this->user['uuid'], $item);
                    $proxies[] = $vmessConfig;
                    break;
                case 'vless':
                    $vlessConfig = $this->buildVless($this->user['uuid'], $item);
                    $proxies[] = $vlessConfig;
                    break;
                case 'tuic':
                    $tuicConfig = $this->buildTuic($this->user['uuid'], $item);
                    $proxies[] = $tuicConfig;
                    break;
                case 'anytls':
                    $anytlsConfig = $this->buildAnyTLS($this->user['uuid'], $item);
                    $proxies[] = $anytlsConfig;
                    break;
                case 'hysteria2':
                    $hysteria2Config = $this->buildHysteria2($this->user['uuid'], $item);
                    $proxies[] = $hysteria2Config;
                    break;
            }
        }
    
        return $proxies;
    }

    protected function addProxies($proxies)
    {
        foreach ($this->config['outbounds'] as &$outbound) {
            if (($outbound['type'] === 'selector' && $outbound['tag'] === '节点选择') || ($outbound['type'] === 'urltest' && $outbound['tag'] === '自动选择') || ($outbound['type'] === 'selector' && strpos($outbound['tag'], '#') === 0 )) {
                array_push($outbound['outbounds'], ...array_column($proxies, 'tag'));
            }
        }
        unset($outbound);
        $outbounds = array_merge($this->config['outbounds'], $proxies);
        return $outbounds;
    }

    protected function buildShadowsocks($password, $server)
    {
        if (strpos($server['cipher'], '2022-blake3') !== false) {
            $length = $server['cipher'] === '2022-blake3-aes-128-gcm' ? 16 : 32;
            $serverKey = Helper::getServerKey($server['created_at'], $length);
            $userKey = Helper::uuidToBase64($password, $length);
            $password = "{$serverKey}:{$userKey}";
        }
        $array = [];
        $array['tag'] = $server['name'];
        $array['type'] = 'shadowsocks';
        $array['server'] = $server['host'];
        $array['server_port'] = $server['port'];
        $array['method'] = $server['cipher'];
        $array['password'] = $password;
        $array['domain_resolver'] = 'local';
        if (isset($server['obfs']) && $server['obfs'] === 'http') {
            $array['plugin'] = 'obfs-local';
            $plugin_opts_parts = [];
            $plugin_opts_parts[] = "obfs=" . $server['obfs'];
            if (isset($server['obfs-host'])) {
                $plugin_opts_parts[] = "obfs-host=" . $server['obfs-host'];
            }
            if (isset($server['obfs-path'])) {
                $plugin_opts_parts[] = "path=" . $server['obfs-path'];
            }
            $array['plugin_opts'] = implode(';', $plugin_opts_parts);
        } else if ((($server['network'] ?? null) == 'http') && isset($server['network_settings']['Host'])) {
            $array['plugin'] = 'obfs-local';
            $plugin_opts_parts = [];
            $plugin_opts_parts[] = "obfs=http";
            $networkSettings = $server['network_settings'];
            $plugin_opts_parts[] = "obfs-host=" . $networkSettings['Host'];
            $plugin_opts_parts[] = "path=" . ($networkSettings['path'] ?? '/');

            $array['plugin_opts'] = implode(';', $plugin_opts_parts);
        }
        return $array;
    }


    protected function buildVmess($uuid, $server)
    {
        $array = [];
        $array['tag'] = $server['name'];
        $array['type'] = 'vmess';
        $array['server'] = $server['host'];
        $array['server_port'] = $server['port'];
        $array['uuid'] = $uuid;
        $array['security'] = 'auto';
        $array['alter_id'] = 0;
        $array['transport']= [];
        $array['domain_resolver'] = 'local';

        if ($server['tls']) {
            $tlsConfig = [];
            $tlsConfig['enabled'] = true;
            $tlsSettings = $server['tls_settings'] ?? [];
            $tlsConfig['insecure'] = ($tlsSettings['allow_insecure'] ?? 0) == 1 ? true : false;
            $tlsConfig['server_name'] = $tlsSettings['server_name'] ?? '';
            if (!empty($tlsSettings['ech'])) {
                if ($tlsSettings['ech'] === 'cloudflare') {
                    $tlsConfig['ech'] = [
                        'enabled' => true,
                        'query_server_name' => 'cloudflare-ech.com'
                    ];
                } elseif ($tlsSettings['ech'] === 'custom' && !empty($tlsSettings['ech_config'])) {
                    $tlsConfig['ech'] = [
                        'enabled' => true,
                        'config' => is_array($tlsSettings['ech_config']) ? $tlsSettings['ech_config'] : [$tlsSettings['ech_config']]
                    ];
                }
            }
            $array['tls'] = $tlsConfig;
        }
        if ($server['network'] === 'tcp') {
            $tcpSettings = $server['network_settings'] ?? [];
            if (isset($tcpSettings['header']['type']) && $tcpSettings['header']['type'] == 'http') $array['transport']['type'] = $tcpSettings['header']['type'];
            if (isset($tcpSettings['header']['request']['headers']['Host'])) $array['transport']['host'] = $tcpSettings['header']['request']['headers']['Host'];
            if (isset($tcpSettings['header']['request']['path'][0])) $array['transport']['path'] = $tcpSettings['header']['request']['path'][0];
        }
        if ($server['network'] === 'ws') {
            $array['transport']['type'] ='ws';
            $wsSettings = $server['network_settings'] ?? [];
            $array['transport']['path'] = $wsSettings['path'] ?? '/';
            if (isset($wsSettings['headers']['Host']) && !empty($wsSettings['headers']['Host'])) $array['transport']['headers'] = ['Host' => array($wsSettings['headers']['Host'])];
            $array['transport']['max_early_data'] = 2048;
            $array['transport']['early_data_header_name'] = 'Sec-WebSocket-Protocol';
        }
        if ($server['network'] === 'grpc') {
            $array['transport']['type'] ='grpc';
            $grpcSettings = $server['network_settings'] ?? [];
            if (isset($grpcSettings['serviceName'])) $array['transport']['service_name'] = $grpcSettings['serviceName'];
        }
        if ($server['network'] === 'httpupgrade') {
            $array['transport'] = Helper::buildSingboxHttpupgradeTransport($server);
        }

        return $array;
    }

    protected function buildVless($password, $server)
    {
        $array = [
            "type" => "vless",
            "tag" => $server['name'],
            "server" => $server['host'],
            "server_port" => $server['port'],
            "uuid" => $password,
            "domain_resolver" => "local",
            "packet_encoding" => "xudp"
        ];

        $tlsSettings = $server['tls_settings'] ?? [];

        if ($server['tls']) {
            $tlsConfig = [];
            $tlsConfig['enabled'] = true;
            $array['flow'] = !empty($server['flow']) ? $server['flow'] : "";
            $tlsSettings = $server['tls_settings'] ?? [];
            if ($server['tls_settings']) {
                $tlsConfig['insecure'] = ($tlsSettings['allow_insecure'] ?? 0) == 1 ? true : false;
                $tlsConfig['server_name'] = $tlsSettings['server_name'] ?? null;
                if ($server['tls'] == 2) {
                    $tlsConfig['reality'] = [
                        'enabled' => true,
                        'public_key' => $tlsSettings['public_key'] ?? '',
                        'short_id' => $tlsSettings['short_id'] ?? ''
                    ];
                }
                $fingerprints = $tlsSettings['fingerprint'] ?? 'chrome';
                $tlsConfig['utls'] = [
                    "enabled" => true,
                    "fingerprint" => $fingerprints
                ];
                if (!empty($tlsSettings['ech'])) {
                    if ($tlsSettings['ech'] === 'cloudflare') {
                        $tlsConfig['ech'] = [
                            'enabled' => true,
                            'query_server_name' => 'cloudflare-ech.com'
                        ];
                    } elseif ($tlsSettings['ech'] === 'custom' && !empty($tlsSettings['ech_config'])) {
                        $tlsConfig['ech'] = [
                            'enabled' => true,
                            'config' => is_array($tlsSettings['ech_config']) ? $tlsSettings['ech_config'] : [$tlsSettings['ech_config']]
                        ];
                    }
                }
            }
            $array['tls'] = $tlsConfig;
        }

        if ($server['network'] === 'tcp') {
            $tcpSettings = $server['network_settings'];
            if (isset($tcpSettings['header']['type']) && $tcpSettings['header']['type'] == 'http') $array['transport']['type'] = $tcpSettings['header']['type'];
            if (isset($tcpSettings['header']['request']['headers']['Host'])) $array['transport']['host'] = $tcpSettings['header']['request']['headers']['Host'];
            if (isset($tcpSettings['header']['request']['path'][0])) $array['transport']['path'] = $tcpSettings['header']['request']['path'][0];
        }
        if ($server['network'] === 'ws') {
            $array['transport']['type'] ='ws';
            if ($server['network_settings']) {
                $wsSettings = $server['network_settings'];
                if (isset($wsSettings['path']) && !empty($wsSettings['path'])) $array['transport']['path'] = $wsSettings['path'];
                if (isset($wsSettings['headers']['Host']) && !empty($wsSettings['headers']['Host'])) $array['transport']['headers'] = ['Host' => array($wsSettings['headers']['Host'])];
                $array['transport']['max_early_data'] = 2048;
                $array['transport']['early_data_header_name'] = 'Sec-WebSocket-Protocol';
            }
        }
        if ($server['network'] === 'grpc') {
            $array['transport']['type'] ='grpc';
            if ($server['network_settings']) {
                $grpcSettings = $server['network_settings'];
                if (isset($grpcSettings['serviceName'])) $array['transport']['service_name'] = $grpcSettings['serviceName'];
            }
        }
        if ($server['network'] === 'httpupgrade') {
            $array['transport'] = Helper::buildSingboxHttpupgradeTransport($server);
        }

        return $array;
    }

    protected function buildTrojan($password, $server) 
    {
        $array = [];
        $array['tag'] = $server['name'];
        $array['type'] = 'trojan';
        $array['server'] = $server['host'];
        $array['server_port'] = $server['port'];
        $array['password'] = $password;
        $array['domain_resolver'] = 'local';

        $tlsSettings = $server['tls_settings'] ?? [];
        $tlsConfig = [
            'enabled' => true,
            'insecure' => ($tlsSettings['allow_insecure'] ?? 0) == 1 ? true : false,
            'server_name' => $tlsSettings['server_name'] ?? ''
        ];
        if (!empty($tlsSettings['ech'])) {
            if ($tlsSettings['ech'] === 'cloudflare') {
                $tlsConfig['ech'] = [
                    'enabled' => true,
                    'query_server_name' => 'cloudflare-ech.com'
                ];
            } elseif ($tlsSettings['ech'] === 'custom' && !empty($tlsSettings['ech_config'])) {
                $tlsConfig['ech'] = [
                    'enabled' => true,
                    'config' => is_array($tlsSettings['ech_config']) ? $tlsSettings['ech_config'] : [$tlsSettings['ech_config']]
                ];
            }
        }
        $array['tls'] = $tlsConfig;

        if(isset($server['network']) && in_array($server['network'], ["grpc", "ws"])){
            $array['transport']['type'] = $server['network'];
            // grpc配置
            if($server['network'] === "grpc" && isset($server['network_settings']['serviceName'])) {
                $array['transport']['service_name'] = $server['network_settings']['serviceName'];
            }
            // ws配置
            if($server['network'] === "ws") {
                if(isset($server['network_settings']['path'])) {
                    $array['transport']['path'] = $server['network_settings']['path'] ?? '/';
                }
                if(isset($server['network_settings']['headers']['Host'])){
                    $array['transport']['headers'] = ['Host' => array($server['network_settings']['headers']['Host'])];
                }
                $array['transport']['max_early_data'] = 2048;
                $array['transport']['early_data_header_name'] = 'Sec-WebSocket-Protocol';
            }
        };
        if (($server['network'] ?? null) === 'httpupgrade') {
            $array['transport'] = Helper::buildSingboxHttpupgradeTransport($server);
        }

        return $array;
    }

    protected function buildTuic($password, $server)
    {
        $array = [];
        $array['tag'] = $server['name'];
        $array['type'] = 'tuic';
        $array['server'] = $server['host'];
        $array['server_port'] = $server['port'];
        $array['uuid'] = $password;
        $array['password'] = $password;
        $array['congestion_control'] = $server['congestion_control'] ?? 'cubic';
        $array['udp_relay_mode'] = $server['udp_relay_mode'] ?? 'native';
        $array['zero_rtt_handshake'] = !empty($server['zero_rtt_handshake']);
        $array['domain_resolver'] = 'local';

        $tlsSettings = $server['tls_settings'] ?? [];
        $array['tls'] = [
            'enabled' => true,
            'insecure' => ($tlsSettings['allow_insecure'] ?? 0) == 1 ? true : false,
            'alpn' => ['h3'],
            'disable_sni' => !empty($server['disable_sni']),
        ];
        $array['tls']['server_name'] = $tlsSettings['server_name'] ?? '';

        return $array;
    }

    protected function buildAnyTLS($password, $server)
    {
        $array = [];
        $array['tag'] = $server['name'];
        $array['type'] = 'anytls';
        $array['server'] = $server['host'];
        $array['server_port'] = $server['port'];
        $array['password'] = $password;
        $array['domain_resolver'] = 'local';

        $tlsSettings = $server['tls_settings'] ?? [];
        $tlsConfig = [
            'enabled' => true,
            'insecure' => ($tlsSettings['allow_insecure'] ?? 0) == 1 ? true : false,
            'alpn' => [
                'h2',
                'http/1.1',
            ],
            'server_name' => $tlsSettings['server_name'] ?? ''
        ];
        if ($tlsSettings) {
            if ($server['tls'] == 2) {
                $tlsConfig['reality'] = [
                    'enabled' => true,
                    'public_key' => $tlsSettings['public_key'] ?? '',
                    'short_id' => $tlsSettings['short_id'] ?? ''
                ];
            }
            $tlsConfig['utls'] = [
                "enabled" => true,
                "fingerprint" => $tlsSettings['fingerprint'] ?? 'chrome'
            ];
            if (!empty($tlsSettings['ech'])) {
                if ($tlsSettings['ech'] === 'cloudflare') {
                    $tlsConfig['ech'] = [
                        'enabled' => true,
                        'query_server_name' => 'cloudflare-ech.com',
                    ];
                } elseif ($tlsSettings['ech'] === 'custom' && !empty($tlsSettings['ech_config'])) {
                    $tlsConfig['ech'] = [
                        'enabled' => true,
                        'config' => is_array($tlsSettings['ech_config']) ? $tlsSettings['ech_config'] : [$tlsSettings['ech_config']],
                    ];
                }
            }
        }
        $array['tls'] = $tlsConfig;

        return $array;
    }

    protected function buildHysteria2($password, $server)
    {
        $parts = explode(",",$server['port']);
        $firstPart = $parts[0];
        if (strpos($firstPart, '-') !== false) {
            $range = explode('-', $firstPart);
            $firstPort = $range[0];
        } else {
            $firstPort = $firstPart;
        }
        $tlsSettings = $server['tls_settings'] ?? [];
        $array = [
            'server' => $server['host'],
            'server_port' => (int)$firstPort,
            'tls' => [
                'enabled' => true,
                'insecure' => ($tlsSettings['allow_insecure'] ?? 0) == 1 ? true : false,
                'server_name' => $tlsSettings['server_name'] ?? ''
            ],
            'domain_resolver' => 'local',
            'password' => $password,
            'tag' => $server['name'],
            'type' => 'hysteria2'
        ];
        $serverPorts = [];
        foreach ($parts as $index => $port) {
            $port = trim($port);
            if (preg_match('/^(\d+)-(\d+)$/', $port, $matches)) {
                $start = (int)$matches[1];
                $end = (int)$matches[2];
                if ($start >= 1 && $end <= 65535 && $start <= $end) {
                    $serverPorts[] = "{$start}:{$end}";
                }
            } elseif ($index !== 0 && ctype_digit($port)) {
                $singlePort = (int)$port;
                if ($singlePort >= 1 && $singlePort <= 65535) {
                    $serverPorts[] = "{$singlePort}:{$singlePort}";
                }
            }
        }
        if (!empty($serverPorts)) {
            $array['server_ports'] = $serverPorts;
        }
        if (!empty($server['up_mbps'])) {
            $array['up_mbps'] = (int)$server['up_mbps'];
        }
        if (!empty($server['down_mbps'])) {
            $array['down_mbps'] = (int)$server['down_mbps'];
        }
        if (!empty($server['obfs'])) {
            $array['obfs']['type'] = $server['obfs'];
            $array['obfs']['password'] = $server['obfs_password'];
        }
        return $array;
    }
}
