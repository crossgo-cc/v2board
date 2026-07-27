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
            ['Shadowrocket/2.2', 'shadowrocket', Shadowrocket::class],
            ['sing-box/1.13.0', 'sing-box', Singbox::class],
            ['singbox/1.12.0', 'sing-box', Singbox::class],
            ['Surge/5.0', 'surge', Surge::class],
            ['Mihomo/1.19', 'mihomo', Mihomo::class],
            ['Clash.Meta/1.19', 'mihomo', Mihomo::class],
            ['Clash-Verge-Rev/2.5', 'mihomo', Mihomo::class],
            ['Clash Nyanpasu/2.4', 'mihomo', Mihomo::class],
            ['FlClash/0.8', 'mihomo', Mihomo::class],
            ['Mihomo Party/2.0', 'mihomo', Mihomo::class],
            ['Sparkle/1.0', 'mihomo', Mihomo::class],
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
            ['Clash Verge/1.3'],
            ['unknown-client'],
        ];
    }

    public function testSingboxUsesCurrentRendererWithoutSubscriptionInfoNodes()
    {
        $profile = ClientProtocols::match('sing-box/1.11.9');

        $this->assertSame('sing-box', $profile['name']);
        $this->assertSame(Singbox::class, $profile['renderer']);
        $this->assertFalse($profile['subscription_info']);
    }

    public function testFrontendFlagsSelectExplicitProfiles()
    {
        $this->assertSame('sing-box', ClientProtocols::match('sing-box')['name']);
        $this->assertSame('mihomo', ClientProtocols::match('mihomo')['name']);
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
