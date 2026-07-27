<?php

namespace App\Protocols;

use InvalidArgumentException;

final class ClientProtocols
{
    public const GENERAL = 'general';
    public const MIHOMO = 'mihomo';
    public const SING_BOX = 'sing-box';
    public const SURGE = 'surge';
    public const SHADOWROCKET = 'shadowrocket';

    private const MODERN_SS_CIPHERS = [
        'aes-128-gcm',
        'aes-192-gcm',
        'aes-256-gcm',
        'chacha20-ietf-poly1305',
        'xchacha20-ietf-poly1305',
        '2022-blake3-aes-128-gcm',
        '2022-blake3-aes-256-gcm',
        '2022-blake3-chacha20-poly1305',
    ];

    private const SURGE_SS_CIPHERS = [
        'aes-128-gcm',
        'aes-256-gcm',
        'chacha20-ietf-poly1305',
        '2022-blake3-aes-128-gcm',
        '2022-blake3-aes-256-gcm',
    ];

    private const DEFAULT_PROFILE = [
        'name' => self::GENERAL,
        'renderer' => General::class,
        'subscription_info' => true,
    ];

    private const PROFILES = [
        self::SHADOWROCKET => [
            'renderer' => Shadowrocket::class,
            'flags' => ['shadowrocket'],
            'user_agent_prefixes' => ['shadowrocket/'],
            'subscription_info' => true,
        ],
        self::SING_BOX => [
            'renderer' => Singbox::class,
            'flags' => ['sing-box'],
            'user_agent_prefixes' => ['sfa/', 'sfi/', 'sfm/', 'sft/'],
            'subscription_info' => false,
        ],
        self::SURGE => [
            'renderer' => Surge::class,
            'flags' => ['surge'],
            'user_agent_prefixes' => ['surge/', 'surge mac/', 'surge ios/'],
            'subscription_info' => true,
        ],
        self::MIHOMO => [
            'renderer' => Mihomo::class,
            'flags' => ['mihomo'],
            'user_agent_prefixes' => [
                'clash.meta/',
                'clash-verge/v',
                'clash-nyanpasu/v',
                'flclash/v',
                'mihomo.party/v',
            ],
            'subscription_info' => true,
        ],
    ];

    private const RULES = [
        self::GENERAL => [
            'shadowsocks' => ['networks' => ['tcp', 'http'], 'tls' => [0], 'obfs' => ['', 'http'], 'ciphers' => self::MODERN_SS_CIPHERS],
            'vmess' => ['networks' => ['tcp', 'ws', 'grpc', 'kcp', 'httpupgrade', 'xhttp'], 'tls' => [0, 1]],
            'vless' => ['networks' => ['tcp', 'ws', 'grpc', 'kcp', 'httpupgrade', 'xhttp'], 'tls' => [0, 1, 2], 'reality_networks' => ['tcp', 'grpc', 'xhttp'], 'ech' => true, 'vless_encryption' => true],
            'trojan' => ['networks' => ['tcp', 'ws', 'grpc'], 'tls' => [1, 2], 'reality_networks' => ['tcp', 'grpc'], 'ech' => true],
            'hysteria2' => ['networks' => ['tcp'], 'tls' => [1], 'obfs' => ['', 'salamander', 'gecko']],
            'tuic' => ['networks' => ['tcp'], 'tls' => [1]],
            'anytls' => ['networks' => ['tcp'], 'tls' => [1]],
        ],
        self::MIHOMO => [
            'shadowsocks' => ['networks' => ['tcp', 'http'], 'tls' => [0], 'obfs' => ['', 'http'], 'ciphers' => self::MODERN_SS_CIPHERS],
            'vmess' => ['networks' => ['tcp', 'ws', 'grpc', 'httpupgrade'], 'tls' => [0, 1, 2], 'reality_networks' => ['tcp', 'grpc'], 'ech' => true],
            'vless' => ['networks' => ['tcp', 'ws', 'grpc', 'httpupgrade', 'xhttp'], 'tls' => [0, 1, 2], 'reality_networks' => ['tcp', 'grpc', 'xhttp'], 'ech' => true, 'vless_encryption' => true],
            'trojan' => ['networks' => ['tcp', 'ws', 'grpc', 'httpupgrade'], 'tls' => [1, 2], 'reality_networks' => ['tcp', 'grpc'], 'ech' => true],
            'tuic' => ['networks' => ['tcp'], 'tls' => [1]],
            'hysteria2' => ['networks' => ['tcp'], 'tls' => [1], 'obfs' => ['', 'salamander', 'gecko']],
            'anytls' => ['networks' => ['tcp'], 'tls' => [1], 'ech' => true],
        ],
        self::SING_BOX => [
            'shadowsocks' => ['networks' => ['tcp', 'http'], 'tls' => [0], 'obfs' => ['', 'http'], 'ciphers' => self::MODERN_SS_CIPHERS],
            'vmess' => ['networks' => ['tcp', 'ws', 'grpc', 'httpupgrade'], 'tls' => [0, 1], 'ech' => true],
            'vless' => ['networks' => ['tcp', 'ws', 'grpc', 'httpupgrade'], 'tls' => [0, 1, 2], 'reality_networks' => ['tcp', 'grpc'], 'ech' => true],
            'trojan' => ['networks' => ['tcp', 'ws', 'grpc', 'httpupgrade'], 'tls' => [1], 'ech' => true],
            'tuic' => ['networks' => ['tcp'], 'tls' => [1]],
            'hysteria2' => ['networks' => ['tcp'], 'tls' => [1], 'obfs' => ['', 'salamander']],
            'anytls' => ['networks' => ['tcp'], 'tls' => [1, 2], 'reality_networks' => ['tcp'], 'ech' => true],
        ],
        self::SURGE => [
            'shadowsocks' => ['networks' => ['tcp', 'http'], 'tls' => [0], 'obfs' => ['', 'http', 'tls'], 'ciphers' => self::SURGE_SS_CIPHERS],
            'vmess' => ['networks' => ['tcp', 'ws'], 'tls' => [0, 1]],
            'trojan' => ['networks' => ['tcp', 'ws'], 'tls' => [1]],
            'hysteria2' => ['networks' => ['tcp'], 'tls' => [1], 'obfs' => ['', 'salamander', 'gecko']],
            'anytls' => ['networks' => ['tcp'], 'tls' => [1]],
        ],
        self::SHADOWROCKET => [
            'shadowsocks' => ['networks' => ['tcp', 'http'], 'tls' => [0], 'obfs' => ['', 'http'], 'ciphers' => self::MODERN_SS_CIPHERS],
            'vmess' => ['networks' => ['tcp', 'ws', 'grpc'], 'tls' => [0, 1]],
            'vless' => ['networks' => ['tcp', 'ws', 'grpc', 'kcp', 'httpupgrade', 'xhttp'], 'tls' => [0, 1, 2], 'reality_networks' => ['tcp', 'grpc', 'xhttp'], 'ech' => true],
            'trojan' => ['networks' => ['tcp', 'ws', 'grpc'], 'tls' => [1, 2], 'reality_networks' => ['tcp', 'grpc'], 'ech' => true],
            'hysteria2' => ['networks' => ['tcp'], 'tls' => [1], 'obfs' => ['', 'salamander', 'gecko']],
            'tuic' => ['networks' => ['tcp'], 'tls' => [1]],
            'anytls' => ['networks' => ['tcp'], 'tls' => [1]],
        ],
    ];

    public static function match($userAgent): array
    {
        $userAgent = strtolower((string)$userAgent);

        foreach (self::PROFILES as $name => $profile) {
            if (in_array($userAgent, $profile['flags'], true)) {
                return ['name' => $name] + $profile;
            }
        }

        foreach (self::PROFILES as $name => $profile) {
            foreach ($profile['user_agent_prefixes'] as $prefix) {
                if (strpos($userAgent, $prefix) === 0) {
                    return ['name' => $name] + $profile;
                }
            }
        }

        return self::DEFAULT_PROFILE;
    }

    public static function supports(string $client, array $server): bool
    {
        if (!isset(self::RULES[$client])) {
            throw new InvalidArgumentException("Unknown client capability [{$client}].");
        }
        if (($server['type'] ?? null) !== 'v2node') {
            return false;
        }

        $type = strtolower((string)($server['protocol'] ?? ''));
        $rule = self::RULES[$client][$type] ?? false;

        if (!is_array($rule)) {
            return false;
        }
        if (isset($rule['networks']) && !in_array(self::network($server), $rule['networks'], true)) {
            return false;
        }
        $tls = (int)($server['tls'] ?? 0);
        if (isset($rule['tls']) && !in_array($tls, $rule['tls'], true)) {
            return false;
        }
        $tlsSettings = $server['tls_settings'] ?? [];
        if ($tls === 2 && empty($tlsSettings['public_key'])) {
            return false;
        }
        if ($tls === 2 && isset($rule['reality_networks'])
            && !in_array(self::network($server), $rule['reality_networks'], true)
        ) {
            return false;
        }
        if ($tls === 1 && !empty($tlsSettings['ech'])) {
            if (empty($rule['ech'])) {
                return false;
            }
            if ($tlsSettings['ech'] !== 'cloudflare'
                && ($tlsSettings['ech'] !== 'custom' || empty($tlsSettings['ech_config']))
            ) {
                return false;
            }
        }
        if (isset($rule['obfs']) && !in_array((string)($server['obfs'] ?? ''), $rule['obfs'], true)) {
            return false;
        }
        if ($type === 'shadowsocks' && empty($server['cipher'])) {
            return false;
        }
        if ($type === 'shadowsocks' && !in_array($server['cipher'], $rule['ciphers'], true)) {
            return false;
        }
        if ($type === 'hysteria2' && !empty($server['obfs']) && empty($server['obfs_password'])) {
            return false;
        }
        if ($type === 'vless' && !empty($server['flow']) && (
            $server['flow'] !== 'xtls-rprx-vision'
            || $tls === 0
            || self::network($server) !== 'tcp'
        )) {
            return false;
        }
        if ($type === 'vless' && !empty($server['encryption'])) {
            $encryptionSettings = $server['encryption_settings'] ?? [];
            if (empty($rule['vless_encryption'])
                || $server['encryption'] !== 'mlkem768x25519plus'
                || empty($encryptionSettings['password'])
                || !in_array($encryptionSettings['mode'] ?? 'native', ['native', 'xorpub', 'random'], true)
                || !in_array($encryptionSettings['rtt'] ?? '0rtt', ['0rtt', '1rtt'], true)
                || (isset($encryptionSettings['client_padding']) && !is_string($encryptionSettings['client_padding']))
            ) {
                return false;
            }
        }

        return true;
    }

    public static function filter(string $client, array $servers): array
    {
        return array_values(array_filter($servers, function ($server) use ($client) {
            return is_array($server) && self::supports($client, $server);
        }));
    }

    private static function network(array $server): string
    {
        $network = strtolower((string)($server['network'] ?? 'tcp'));
        return $network === '' ? 'tcp' : $network;
    }
}
