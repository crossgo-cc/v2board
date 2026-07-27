<?php

namespace Tests\Unit;

use App\Protocols\AbstractProtocol;
use App\Protocols\ClientProtocols;
use App\Protocols\General;
use App\Protocols\Mihomo;
use App\Protocols\Shadowrocket;
use App\Protocols\Singbox;
use App\Protocols\Surge;
use Illuminate\Http\Response;
use PHPUnit\Framework\TestCase;

class ClientProtocolsTest extends TestCase
{
    /**
     * @dataProvider maintainedClientProvider
     */
    public function testItClassifiesMaintainedClientsByRenderer($userAgent, $name, $renderer)
    {
        $profile = ClientProtocols::match($userAgent);

        $this->assertSame($name, $profile['name']);
        $this->assertSame($renderer, $profile['renderer']);
        $this->assertTrue(is_subclass_of($renderer, AbstractProtocol::class));
    }

    public function maintainedClientProvider()
    {
        return [
            ['Shadowrocket/2.2 CFNetwork/1492.0.1 Darwin/23.3.0', 'shadowrocket', Shadowrocket::class],
            ['SFA/1.13.0 (100; sing-box 1.13.0; language en_US)', 'sing-box', Singbox::class],
            ['SFI/1.13.0 (Build 100; sing-box 1.13.0; language en_US)', 'sing-box', Singbox::class],
            ['SFM/1.13.0 (Build 100; sing-box 1.13.0; language en_US)', 'sing-box', Singbox::class],
            ['SFT/1.13.0 (Build 100; sing-box 1.13.0; language en_US)', 'sing-box', Singbox::class],
            ['Surge/5.0', 'surge', Surge::class],
            ['Surge Mac/5.10.0', 'surge', Surge::class],
            ['Surge iOS/5.10.0', 'surge', Surge::class],
            ['clash.meta/1.19', 'mihomo', Mihomo::class],
            ['clash-verge/v2.4.5', 'mihomo', Mihomo::class],
            ['clash-nyanpasu/v2.4', 'mihomo', Mihomo::class],
            ['FlClash/v0.8 clash-verge Platform/android', 'mihomo', Mihomo::class],
            ['mihomo.party/v1.8.9 (clash.meta)', 'mihomo', Mihomo::class],
        ];
    }

    /**
     * @dataProvider generalClientProvider
     */
    public function testUnsupportedOrUriClientsUseGeneralRenderer($userAgent)
    {
        $profile = ClientProtocols::match($userAgent);

        $this->assertSame('general', $profile['name']);
        $this->assertSame(General::class, $profile['renderer']);
    }

    public function generalClientProvider()
    {
        return [
            [''],
            ['v2rayN/7.0'],
            ['v2rayNG/1.9'],
            ['Passwall/4.0'],
            ['Quantumult X'],
            ['SagerNet/0.1'],
            ['SSRPlus/1.0'],
            ['Surfboard/2.4'],
            ['Stash/2.7'],
            ['Clash/1.0'],
            ['Mihomo/1.19'],
            ['Clash-Verge-Rev/2.5'],
            ['Clash Nyanpasu/2.4'],
            ['Mihomo Party/2.0'],
            ['Sparkle/1.0'],
            ['singbox/1.12.0'],
            ['wrapper clash.meta/1.19'],
            ['not-surge/5.0'],
            ['mihomo-preview'],
            ['unknown-client'],
        ];
    }

    public function testSingboxUsesCurrentRendererWithoutSubscriptionInfoNodes()
    {
        $profile = ClientProtocols::match('SFA/1.11.9');

        $this->assertSame('sing-box', $profile['name']);
        $this->assertSame(Singbox::class, $profile['renderer']);
        $this->assertFalse($profile['subscription_info']);
    }

    public function testExplicitFlagsSelectProfiles()
    {
        $this->assertSame('shadowrocket', ClientProtocols::match('shadowrocket')['name']);
        $this->assertSame('sing-box', ClientProtocols::match('sing-box')['name']);
        $this->assertSame('surge', ClientProtocols::match('surge')['name']);
        $this->assertSame('mihomo', ClientProtocols::match('mihomo')['name']);
    }

    public function testFrontendImportLinksSetExplicitProfileFlags()
    {
        $frontend = file_get_contents(dirname(__DIR__, 2) . '/public/theme/default/assets/umi.js');

        foreach (['flag=shadowrocket', 'flag=sing-box', 'flag=surge', 'flag=mihomo'] as $flag) {
            $this->assertStringContainsString($flag, $frontend);
        }
    }

    public function testFrontendImportLinksFollowSupportedPlatforms()
    {
        $frontend = file_get_contents(dirname(__DIR__, 2) . '/public/theme/default/assets/umi.js');
        $subscribeBox = substr($frontend, strpos($frontend, 'renderSubscribeBox()'), 5000);

        $this->assertMatchesRegularExpression('/Object\(u\["k"\]\)\(\) && !Object\(u\["j"\]\)\(\)/', $frontend);
        $this->assertMatchesRegularExpression('/indexOf\("linux"\) && !p\(\)/', $frontend);
        $this->assertMatchesRegularExpression('/\(isAppleMobile \|\| isMacOS \|\| isAndroid \|\| isWindows \|\| isLinux\) && t\.push\(\{\s+title: "Hiddify"/', $subscribeBox);
        $this->assertStringNotContainsString('title: "Sing-box"', $subscribeBox);
        $this->assertStringNotContainsString('sing-box://import-remote-profile', $subscribeBox);
        $this->assertMatchesRegularExpression('/\(isAppleMobile \|\| isMacOS\) && \(t\.push\(\{\s+title: "Shadowrocket".*title: "Surge"/s', $subscribeBox);
        $this->assertMatchesRegularExpression('/\(isMacOS \|\| isWindows \|\| isLinux\) && t\.push\(\{\s+title: "Clash Verge Rev"/', $subscribeBox);
        $this->assertMatchesRegularExpression('/\(isMacOS \|\| isWindows \|\| isLinux \|\| isAndroid\) && t\.push\(\{\s+title: "FlClash"/', $subscribeBox);
        $this->assertMatchesRegularExpression('/isAndroid && t\.push\(\{\s+title: "ClashMeta For Android"/', $subscribeBox);
        $this->assertDoesNotMatchRegularExpression('/,\s*[ai]\s*=/', $subscribeBox);
    }

    /**
     * @dataProvider capabilityProvider
     */
    public function testItRejectsUnsupportedProtocolOrTransport($client, $server, $supported)
    {
        $server = array_merge([
            'type' => 'v2node',
            'protocol' => 'vmess',
            'network' => 'tcp',
            'tls' => 0,
        ], $server);

        $this->assertSame($supported, ClientProtocols::supports($client, $server));
    }

    public function capabilityProvider()
    {
        return [
            ['surge', ['protocol' => 'vless'], false],
            ['surge', ['protocol' => 'tuic'], false],
            ['surge', ['protocol' => 'hysteria2', 'tls' => 1], true],
            ['surge', ['protocol' => 'vmess', 'network' => 'grpc'], false],
            ['surge', ['protocol' => 'vmess', 'tls' => 2, 'tls_settings' => ['public_key' => 'key']], false],
            ['surge', ['protocol' => 'shadowsocks', 'cipher' => 'aes-256-gcm'], true],
            ['surge', ['protocol' => 'shadowsocks', 'cipher' => 'aes-192-gcm'], false],
            ['surge', ['protocol' => 'shadowsocks', 'cipher' => '2022-blake3-aes-128-gcm'], true],
            ['surge', ['protocol' => 'shadowsocks', 'cipher' => '2022-blake3-aes-256-gcm'], true],
            ['sing-box', ['protocol' => 'vless', 'network' => 'xhttp'], false],
            ['sing-box', ['protocol' => 'anytls', 'tls' => 1], true],
            ['sing-box', ['protocol' => 'shadowsocks', 'cipher' => 'unsupported-cipher'], false],
            ['sing-box', ['protocol' => 'vmess', 'tls' => 2, 'tls_settings' => ['public_key' => 'key']], false],
            ['sing-box', ['protocol' => 'hysteria2', 'tls' => 1, 'obfs' => 'gecko', 'obfs_password' => 'secret'], false],
            ['sing-box', ['protocol' => 'hysteria2', 'tls' => 1, 'obfs' => 'salamander', 'obfs_password' => 'secret'], true],
            ['general', ['protocol' => 'vmess', 'network' => 'kcp'], true],
            ['general', ['protocol' => 'vmess', 'network' => 'xhttp'], true],
            ['general', ['protocol' => 'vmess', 'tls' => 2, 'tls_settings' => ['public_key' => 'key']], false],
            ['general', ['protocol' => 'vless', 'encryption' => 'mlkem768x25519plus', 'encryption_settings' => ['password' => 'key']], true],
            ['mihomo', ['protocol' => 'vmess', 'network' => 'xhttp'], false],
            ['mihomo', ['protocol' => 'vless', 'network' => 'xhttp'], true],
            ['mihomo', ['protocol' => 'vmess', 'tls' => 2, 'tls_settings' => ['public_key' => 'key']], true],
            ['mihomo', ['protocol' => 'vmess', 'network' => 'ws', 'tls' => 2, 'tls_settings' => ['public_key' => 'key']], false],
            ['mihomo', ['protocol' => 'vless', 'network' => 'xhttp', 'tls' => 2, 'tls_settings' => ['public_key' => 'key']], true],
            ['mihomo', ['protocol' => 'anytls', 'tls' => 2, 'tls_settings' => ['public_key' => 'key']], false],
            ['mihomo', ['protocol' => 'vless', 'flow' => 'xtls-rprx-vision', 'tls' => 1], true],
            ['mihomo', ['protocol' => 'vless', 'network' => 'ws', 'flow' => 'xtls-rprx-vision', 'tls' => 1], false],
            ['mihomo', ['protocol' => 'hysteria2', 'tls' => 1, 'obfs' => 'gecko'], false],
            ['mihomo', ['protocol' => 'hysteria2', 'tls' => 1, 'obfs' => 'gecko', 'obfs_password' => 'secret'], true],
            ['mihomo', ['protocol' => 'vless', 'encryption' => 'mlkem768x25519plus', 'encryption_settings' => ['password' => 'key']], true],
            ['mihomo', ['protocol' => 'vless', 'encryption' => 'mlkem768x25519plus', 'encryption_settings' => ['password' => 'key', 'mode' => 'invalid']], false],
            ['sing-box', ['protocol' => 'vless', 'encryption' => 'mlkem768x25519plus', 'encryption_settings' => ['password' => 'key']], false],
            ['mihomo', ['protocol' => 'vless', 'tls' => 1, 'tls_settings' => ['ech' => 'custom']], false],
            ['mihomo', ['protocol' => 'vless', 'tls' => 1, 'tls_settings' => ['ech' => 'custom', 'ech_config' => 'config']], true],
            ['shadowrocket', ['protocol' => 'anytls', 'tls' => 2], false],
            ['shadowrocket', ['protocol' => 'vless', 'network' => 'kcp'], true],
            ['mihomo', ['protocol' => 'wireguard'], false],
        ];
    }

    public function testProtocolResponseCarriesContentHeadersAndStatus()
    {
        $protocol = new class([], []) extends AbstractProtocol {
            public function handle(): Response
            {
                return $this->response('content', ['X-Test' => 'value'], 201);
            }
        };
        $response = $protocol->handle();

        $this->assertSame('content', $response->getContent());
        $this->assertSame('value', $response->headers->get('X-Test'));
        $this->assertSame(201, $response->getStatusCode());
    }

    public function testUnknownClientNameFailsInsteadOfFallingBackDuringFiltering()
    {
        $this->expectException(\InvalidArgumentException::class);

        ClientProtocols::supports('misspelled-client', []);
    }

    public function testItFiltersServersForTheSelectedClient()
    {
        $servers = ClientProtocols::filter('surge', [
            ['type' => 'v2node', 'protocol' => 'vless', 'network' => 'tcp', 'tls' => 1],
            ['type' => 'v2node', 'protocol' => 'hysteria2', 'network' => 'tcp', 'tls' => 1],
        ]);

        $this->assertCount(1, $servers);
        $this->assertSame('hysteria2', $servers[0]['protocol']);
    }
}
