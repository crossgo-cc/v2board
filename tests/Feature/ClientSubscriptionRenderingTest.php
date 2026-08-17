<?php

namespace Tests\Feature;

use App\Http\Controllers\V1\Client\ClientController;
use App\Protocols\Mihomo;
use App\Protocols\ClientProtocols;
use App\Protocols\Surge;
use App\Services\ServerService;
use App\Services\UserService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

class ClientSubscriptionRenderingTest extends TestCase
{
    /**
     * @dataProvider rendererProvider
     */
    public function testEverySupportedRendererProducesUnifiedResponse($userAgent)
    {
        $_SERVER['HTTP_HOST'] = 'example.com';
        $user = [
            'uuid' => '00000000-0000-4000-8000-000000000000',
            'token' => 'test-token',
            'u' => 0,
            'd' => 0,
            'transfer_enable' => 1073741824,
            'expired_at' => time() + 86400,
        ];

        $response = $this->render($userAgent, $user, []);

        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertIsString($response->getContent());
    }

    public function rendererProvider()
    {
        return [
            ['unknown-client'],
            ['clash.meta/1.19'],
            ['SFA/1.13'],
            ['Shadowrocket/2.2'],
            ['Surge/5.0'],
        ];
    }

    public function testProfileUpdateIntervalUsesConfiguredHours()
    {
        $_SERVER['HTTP_HOST'] = 'example.com';

        foreach (['unknown-client', 'clash.meta/1.19', 'SFA/1.13', 'Shadowrocket/2.2', 'Surge/5.0'] as $userAgent) {
            $response = $this->render($userAgent, $this->user(), []);

            $this->assertSame('2', $response->headers->get('profile-update-interval'));
        }

        config(['v2board.subscribe_update_interval' => 6]);

        foreach (['unknown-client', 'clash.meta/1.19', 'SFA/1.13', 'Shadowrocket/2.2', 'Surge/5.0'] as $userAgent) {
            $response = $this->render($userAgent, $this->user(), []);

            $this->assertSame('6', $response->headers->get('profile-update-interval'));
        }
    }

    public function testShadowrocketUsesGeneralVlessUriRendering()
    {
        $response = $this->render('Shadowrocket/2.2', $this->user(), [
            $this->server('vless', 'shadowrocket-vless'),
        ]);
        $content = base64_decode($response->getContent());

        $this->assertStringContainsString('vless://', $content);
        $this->assertStringContainsString('#shadowrocket-vless', $content);
    }

    public function testClashVergeUserAgentProducesMihomoYaml()
    {
        $response = $this->render('clash-verge/v2.4.5', $this->user(), [
            $this->server('vmess', 'verge-node'),
        ]);
        $config = Yaml::parse($response->getContent());

        $this->assertIsArray($config);
        $this->assertSame('verge-node', $config['proxies'][0]['name']);
    }

    public function testMaintainedMihomoClientUserAgentsProduceMihomoYaml()
    {
        foreach ([
            'Clash Plus/v1.2.7 ClashMeta Platform/android',
            'ClashMeta/1.19.15; mihomo/1.19.15',
            'ClashMetaForAndroid/2.11.32.Meta',
            'FlClash X/v0.8.92 core/v1.19.11 Platform/android',
            'GUI.for.Clash/v1.26.1',
            'koala-clash/1.3.1',
        ] as $userAgent) {
            $response = $this->render($userAgent, $this->user(), [
                $this->server('vmess', 'mihomo-node'),
            ]);
            $config = Yaml::parse($response->getContent());

            $this->assertIsArray($config);
            $this->assertSame('mihomo-node', $config['proxies'][0]['name']);
        }
    }

    public function testOpenClashUserAgentsProduceMihomoYaml()
    {
        foreach (['OpenClash/0.47.0', 'clash.meta', 'mihomo/1.19.20'] as $userAgent) {
            $response = $this->render($userAgent, $this->user(), [
                $this->server('anytls', 'openclash-anytls'),
            ]);
            $config = Yaml::parse($response->getContent());

            $this->assertIsArray($config);
            $this->assertSame('anytls', $config['proxies'][0]['type']);
        }
    }

    public function testClashMetaForAndroidRequestWithoutFlagProducesMihomoYaml()
    {
        $_SERVER['HTTP_HOST'] = 'example.com';
        $request = Request::create('/subscribe', 'GET', [], [], [], [
            'HTTP_USER_AGENT' => 'ClashMetaForAndroid/2.11.32.Meta',
        ]);
        $request->user = array_merge($this->user(), ['banned' => 1]);

        $response = (new ClientController(new ServerService(), new UserService()))->subscribe($request);
        $config = Yaml::parse($response->getContent());

        $this->assertIsArray($config);
        $this->assertSame('账号已被封禁', $config['proxies'][0]['name']);
        $this->assertSame('2', $response->headers->get('profile-update-interval'));
    }

    public function testSurgeDoesNotReceiveUnsupportedVlessNodes()
    {
        $_SERVER['HTTP_HOST'] = 'example.com';
        $response = $this->render(
            'Surge/5.0',
            $this->user(),
            [
                $this->server('vless', 'unsupported-vless'),
                $this->server('tuic', 'unsupported-tuic'),
                array_merge($this->server('hysteria2', 'supported-hysteria2'), [
                    'tls' => 1,
                    'up_mbps' => 100,
                    'down_mbps' => 100,
                ]),
            ]
        );

        $this->assertStringNotContainsString('unsupported-vless', $response->getContent());
        $this->assertStringNotContainsString('unsupported-tuic', $response->getContent());
        $this->assertStringContainsString('supported-hysteria2=hysteria2', $response->getContent());
    }

    public function testSingboxUsesStableRuleSetDownloadFields()
    {
        $response = $this->render(
            'SFA/1.13.19',
            $this->user(),
            []
        );
        $config = json_decode($response->getContent(), true);

        $this->assertArrayNotHasKey('http_clients', $config);
        $this->assertArrayNotHasKey('default_http_client', $config['route']);
        foreach ($config['route']['rule_set'] as $ruleSet) {
            $this->assertSame('节点选择', $ruleSet['download_detour']);
        }
    }

    public function testSingboxDoesNotEmbedOutboundDomainResolvers()
    {
        $response = $this->render(
            'SFA/1.13.19',
            $this->user(),
            [$this->server('vless', 'singbox-node')]
        );
        $config = json_decode($response->getContent(), true);
        $outbounds = array_column($config['outbounds'], null, 'tag');

        foreach ($outbounds as $outbound) {
            $this->assertArrayNotHasKey('domain_resolver', $outbound);
        }
    }

    public function testSingboxAndMihomoTemplatesShareRoutingDefaults()
    {
        $singbox = json_decode(file_get_contents(base_path('resources/rules/default.sing-box.json')), true);
        $mihomo = Yaml::parseFile(base_path('resources/rules/default.clash.yaml'));
        $fakeIpServers = array_values(array_filter($singbox['dns']['servers'], function ($server) {
            return ($server['tag'] ?? null) === 'fakeip';
        }));
        $urlTestGroups = array_values(array_filter($mihomo['proxy-groups'], function ($group) {
            return ($group['type'] ?? null) === 'url-test';
        }));

        $this->assertSame('198.18.0.0/16', $fakeIpServers[0]['inet4_range']);
        $this->assertArrayNotHasKey('inet6_range', $fakeIpServers[0]);
        $this->assertSame('198.18.0.1/16', $mihomo['dns']['fake-ip-range']);
        $this->assertSame('cn', $singbox['dns']['final']);
        $this->assertSame('cn', $singbox['route']['default_domain_resolver']['server']);
        $this->assertSame('Rule', $singbox['experimental']['clash_api']['default_mode']);
        $this->assertSame('rule', $mihomo['mode']);
        $this->assertSame('5m', $singbox['outbounds'][2]['interval']);
        $this->assertSame(300, $urlTestGroups[0]['interval']);
    }

    public function testMihomoUsesCurrentHttpUpgradeFields()
    {
        $proxy = Mihomo::buildVless($this->user()['uuid'], array_merge(
            $this->server('vless', 'http-upgrade'),
            [
                'network' => 'httpupgrade',
                'network_settings' => [
                    'host' => 'cdn.example.com',
                    'path' => '/upgrade',
                ],
            ]
        ));

        $this->assertSame('ws', $proxy['network']);
        $this->assertTrue($proxy['ws-opts']['v2ray-http-upgrade']);
        $this->assertSame('/upgrade', $proxy['ws-opts']['path']);
        $this->assertSame('cdn.example.com', $proxy['ws-opts']['headers']['Host']);
    }

    public function testGeneralUrisUseCurrentV2rayNFields()
    {
        $vmessUri = \App\Utils\Helper::buildUri($this->user()['uuid'], array_merge(
            $this->server('vmess', 'vmess-xhttp'),
            [
                'network' => 'xhttp',
                'network_settings' => [
                    'host' => 'cdn.example.com',
                    'path' => '/xhttp',
                    'mode' => 'stream-up',
                ],
            ]
        ));
        $vmess = json_decode(base64_decode(substr(trim($vmessUri), strlen('vmess://'))), true);

        $this->assertSame('xhttp', $vmess['net']);
        $this->assertSame('stream-up', $vmess['type']);
        $this->assertSame('cdn.example.com', $vmess['host']);
        $this->assertSame('/xhttp', $vmess['path']);

        $vlessUri = \App\Utils\Helper::buildUri($this->user()['uuid'], array_merge(
            $this->server('vless', 'vless-encryption'),
            [
                'encryption' => 'mlkem768x25519plus',
                'encryption_settings' => [
                    'mode' => 'native',
                    'rtt' => '1rtt',
                    'password' => 'secret',
                ],
            ]
        ));

        $this->assertStringContainsString(
            'encryption=mlkem768x25519plus.native.1rtt.secret',
            $vlessUri
        );
    }

    public function testMihomoOnlyRendersValidTlsAndTransportCombinations()
    {
        $response = $this->render(
            'clash.meta/1.19',
            $this->user(),
            [
                array_merge($this->server('vmess', 'valid-vmess-reality'), [
                    'tls' => 2,
                    'tls_settings' => [
                        'public_key' => 'public-key',
                        'short_id' => '01234567',
                        'fingerprint' => 'chrome',
                        'ech' => 'cloudflare',
                    ],
                ]),
                array_merge($this->server('anytls', 'invalid-anytls-reality'), [
                    'tls' => 2,
                    'tls_settings' => ['public_key' => 'public-key'],
                ]),
                array_merge($this->server('vless', 'invalid-vision-ws'), [
                    'network' => 'ws',
                    'flow' => 'xtls-rprx-vision',
                    'tls' => 1,
                ]),
            ]
        );

        $this->assertStringContainsString('valid-vmess-reality', $response->getContent());
        $this->assertStringContainsString('reality-opts', $response->getContent());
        $this->assertStringNotContainsString('ech-opts', $response->getContent());
        $this->assertStringNotContainsString('invalid-anytls-reality', $response->getContent());
        $this->assertStringNotContainsString('invalid-vision-ws', $response->getContent());
    }

    public function testSingboxIgnoresEchFieldsOnRealityNodes()
    {
        $response = $this->render(
            'SFA/1.13.19',
            $this->user(),
            [
                array_merge($this->server('anytls', 'anytls-reality'), [
                    'tls' => 2,
                    'tls_settings' => [
                        'public_key' => 'public-key',
                        'short_id' => '01234567',
                        'ech' => 'cloudflare',
                    ],
                ]),
            ]
        );
        $config = json_decode($response->getContent(), true);
        $outbounds = array_column($config['outbounds'], null, 'tag');

        $this->assertTrue($outbounds['anytls-reality']['tls']['reality']['enabled']);
        $this->assertArrayNotHasKey('ech', $outbounds['anytls-reality']['tls']);
    }

    public function testMihomoIgnoresVlessOnlyFieldsOnAnyTlsNodes()
    {
        $response = $this->render(
            'clash.meta/1.19',
            $this->user(),
            [
                array_merge($this->server('anytls', 'anytls-with-stale-fields'), [
                    'tls' => 1,
                    'flow' => 'xtls-rprx-vision',
                    'encryption_settings' => [
                        'mode' => 'native',
                        'rtt' => '0rtt',
                        'password' => 'unused',
                    ],
                ]),
            ]
        );
        $config = Yaml::parse($response->getContent());

        $this->assertSame('anytls-with-stale-fields', $config['proxies'][0]['name']);
        $this->assertSame('anytls', $config['proxies'][0]['type']);
        $this->assertArrayNotHasKey('flow', $config['proxies'][0]);
        $this->assertArrayNotHasKey('encryption', $config['proxies'][0]);
    }

    public function testEmptyHysteriaObfsDoesNotRequirePassword()
    {
        $server = array_merge($this->server('hysteria2', 'hysteria-no-obfs'), [
            'tls' => 1,
            'obfs' => '',
            'up_mbps' => 100,
            'down_mbps' => 100,
        ]);

        foreach (['clash.meta/1.19', 'SFA/1.13.19'] as $userAgent) {
            $response = $this->render(
                $userAgent,
                $this->user(),
                [$server]
            );

            $this->assertSame(200, $response->getStatusCode());
            $this->assertStringContainsString('hysteria-no-obfs', $response->getContent());
        }
    }

    public function testSingboxRejectsGeckoAndUsesValidServerPortRanges()
    {
        $response = $this->render(
            'SFA/1.13.19',
            $this->user(),
            [
                array_merge($this->server('hysteria2', 'unsupported-gecko'), [
                    'tls' => 1,
                    'obfs' => 'gecko',
                    'obfs_password' => 'secret',
                ]),
                array_merge($this->server('hysteria2', 'valid-port-hopping'), [
                    'port' => '443,8443-8450,9443,70000,9000-8000',
                    'tls' => 1,
                    'up_mbps' => 100,
                    'down_mbps' => 100,
                ]),
            ]
        );
        $config = json_decode($response->getContent(), true);
        $outbounds = array_column($config['outbounds'], null, 'tag');

        $this->assertArrayNotHasKey('unsupported-gecko', $outbounds);
        $this->assertSame(443, $outbounds['valid-port-hopping']['server_port']);
        $this->assertSame(['8443:8450', '9443:9443'], $outbounds['valid-port-hopping']['server_ports']);
    }

    public function testSurgeUsesCurrentProtocolFields()
    {
        $hysteria2 = Surge::buildHysteria($this->user()['uuid'], array_merge(
            $this->server('hysteria2', 'hysteria-current'),
            [
                'port' => '443,8443-8450',
                'down_mbps' => 100,
                'obfs' => 'gecko',
                'obfs_password' => 'obfs-password',
            ]
        ));
        $ss = Surge::buildShadowsocks($this->user()['uuid'], array_merge(
            $this->server('shadowsocks', 'ss-current'),
            ['cipher' => 'aes-128-gcm', 'created_at' => time(), 'obfs' => null]
        ));

        $this->assertStringContainsString('download-bandwidth=100', $hysteria2);
        $this->assertStringContainsString('port-hopping=443;8443-8450', $hysteria2);
        $this->assertStringContainsString('gecko-password=obfs-password', $hysteria2);
        $this->assertStringContainsString('udp-relay=true', $ss);
        $this->assertStringNotContainsString('udp=true', $ss);
    }

    public function testShadowsocksHttpObfsAnd2022PasswordAreGeneratedCorrectly()
    {
        $httpObfs = array_merge($this->server('shadowsocks', 'ss-http-obfs'), [
            'cipher' => 'aes-128-gcm',
            'network' => 'http',
            'network_settings' => [
                'Host' => 'cdn.example.com',
                'path' => '/obfs',
            ],
            'created_at' => time(),
        ]);
        $surge = Surge::buildShadowsocks($this->user()['uuid'], $httpObfs);
        $uri = \App\Utils\Helper::buildShadowsocksUri($this->user()['uuid'], $httpObfs);
        $mihomo = Mihomo::buildShadowsocks($this->user()['uuid'], array_merge($httpObfs, [
            'cipher' => '2022-blake3-chacha20-poly1305',
        ]));

        $this->assertStringContainsString('obfs=http', $surge);
        $this->assertStringContainsString('obfs-host=cdn.example.com', $surge);
        $this->assertStringContainsString('obfs=http', $uri);
        $this->assertStringContainsString(':', $mihomo['password']);
        $this->assertNotSame($this->user()['uuid'], $mihomo['password']);
    }

    private function user()
    {
        return [
            'uuid' => '00000000-0000-4000-8000-000000000000',
            'token' => 'test-token',
            'u' => 0,
            'd' => 0,
            'transfer_enable' => 1073741824,
            'expired_at' => time() + 86400,
        ];
    }

    private function render($userAgent, array $user, array $servers): Response
    {
        $profile = ClientProtocols::match($userAgent);
        $servers = ClientProtocols::filter($profile['name'], $servers);
        $renderer = $profile['renderer'];

        return (new $renderer($user, $servers))->handle();
    }

    private function server($protocol, $name)
    {
        return [
            'type' => 'v2node',
            'protocol' => $protocol,
            'name' => $name,
            'host' => 'example.com',
            'port' => 443,
            'network' => 'tcp',
            'network_settings' => [],
            'tls' => 0,
            'tls_settings' => [],
            'flow' => '',
            'encryption' => '',
            'obfs' => null,
        ];
    }
}
