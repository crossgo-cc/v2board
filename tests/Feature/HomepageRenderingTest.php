<?php

namespace Tests\Feature;

use App\Http\Controllers\V1\Admin\ThemeController;
use App\Http\Controllers\V1\User\CommController;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class HomepageRenderingTest extends TestCase
{
    public function testThemeDeclaresHomepageConfiguration(): void
    {
        $config = json_decode(file_get_contents(public_path('theme/default/config.json')), true);
        $fields = array_column($config['configs'], null, 'field_name');

        $this->assertSame([
            'theme_color',
            'background_url',
            'theme_sidebar',
            'theme_header',
            'custom_homepage_js',
            'custom_footer_html'
        ], array_keys($fields));
        $this->assertArrayHasKey('custom_homepage_js', $fields);
        $this->assertArrayHasKey('custom_footer_html', $fields);
        $this->assertSame('textarea', $fields['custom_homepage_js']['field_type']);
        $this->assertSame('', $fields['custom_homepage_js']['default_value']);
        $this->assertSame('自定义主页 JS 源码', $fields['custom_homepage_js']['label']);
        foreach (['Vite', 'ESM', 'mount', 'unmount', 'Blob'] as $token) {
            $this->assertStringContainsString($token, $fields['custom_homepage_js']['placeholder']);
        }
        $this->assertSame('textarea', $fields['custom_footer_html']['field_type']);
        $this->assertSame('自定义页脚 HTML', $fields['custom_footer_html']['label']);
        $this->assertArrayNotHasKey('homepage_js', $fields);
    }

    public function testThemeConfigGetsHomepageDefaults(): void
    {
        config([
            'theme.default' => [
                'theme_color' => 'default',
                'background_url' => '',
                'theme_sidebar' => 'light',
                'theme_header' => 'dark',
                'custom_html' => 'legacy footer'
            ]
        ]);
        Route::post('/_test/theme/get-config', [ThemeController::class, 'getThemeConfig']);

        $response = $this->postJson('/_test/theme/get-config', [
            'name' => 'default'
        ])
            ->assertOk()
            ->assertJsonPath('data.custom_homepage_js', '')
            ->assertJsonPath('data.custom_footer_html', '');

        $this->assertArrayNotHasKey('custom_html', $response->json('data'));
    }

    public function testHomepageSettingsAreEmbeddedWithSafeJsonEncoding(): void
    {
        $script = 'export function mount(container) { return { unmount() {} }; } // 中文 50%';
        $footer = '<script>window.__footerTest = "中文 50%";</script>';
        $response = $this->getHomepage([
            'custom_homepage_js' => $script,
            'custom_footer_html' => $footer
        ]);
        $jsonOptions = JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT;

        $response->assertOk()
            ->assertSee('custom_homepage_js: ' . json_encode($script, $jsonOptions), false)
            ->assertSee($footer, false)
            ->assertDontSee('homepage_url', false);
    }

    public function testDisabledHomepageKeepsLoginRedirectBehavior(): void
    {
        $response = $this->getHomepage();
        $frontend = file_get_contents(public_path('theme/default/assets/umi.js'));

        $response->assertOk()
            ->assertSee('custom_homepage_js: ""', false);
        $this->assertStringContainsString('!window.settings.custom_homepage_js.trim()', $frontend);
        $this->assertStringContainsString('a.a.push("/login")', $frontend);
    }

    public function testHomepageRendersDirectlyAndRunsWithCleanup(): void
    {
        $frontend = file_get_contents(public_path('theme/default/assets/umi.js'));
        $blade = file_get_contents(public_path('theme/default/dashboard.blade.php'));

        $this->assertStringContainsString('this.runHomepageScript()', $frontend);
        $this->assertStringContainsString('import(t).then', $frontend);
        $this->assertStringContainsString('this.registerHomepageUnmount', $frontend);
        $this->assertStringContainsString('this.homepageUnmount', $frontend);
        $this->assertStringContainsString('this.setHomepageHost', $frontend);
        $this->assertStringContainsString('e.attachShadow', $frontend);
        $this->assertStringContainsString('mode: "open"', $frontend);
        $this->assertStringContainsString('this.homepageShadowRoot.appendChild', $frontend);
        $this->assertStringContainsString('mount(container)', $frontend);
        $this->assertStringContainsString('new window.Blob', $frontend);
        $this->assertStringContainsString('window.URL.createObjectURL', $frontend);
        $this->assertStringContainsString('window.URL.revokeObjectURL', $frontend);
        $this->assertStringContainsString('this.homepageBlobUrl', $frontend);
        $this->assertStringContainsString('id: "v2board-homepage-root"', $frontend);
        $this->assertStringContainsString('this.homepageRoot.innerHTML = ""', $frontend);
        $this->assertStringContainsString('componentWillUnmount()', $frontend);
        $this->assertStringContainsString('this.cleanupHomepage()', $frontend);
        $this->assertStringContainsString("custom_homepage_js: @json(\$theme_config['custom_homepage_js'] ?? '')", $blade);
        $this->assertStringContainsString("{!! \$theme_config['custom_footer_html'] ?? '' !!}", $blade);
        $this->assertStringNotContainsString('getHomepageScriptUrl', $frontend);
        $this->assertStringNotContainsString('window.settings.homepage', $frontend);
        $this->assertStringNotContainsString('window.settings.homepage_js', $frontend);
        $this->assertStringNotContainsString("homepage_js: @json(\$theme_config['homepage_js'] ?? '')", $blade);
    }

    public function testHomepageRuntimeSupportsViteMountContract(): void
    {
        $frontend = file_get_contents(public_path('theme/default/assets/umi.js'));

        foreach (['custom_homepage_js', 'setHomepageHost', 'attachShadow', 'homepageShadowRoot', 'new window.Blob', 'window.URL.createObjectURL', 'window.URL.revokeObjectURL', 'import(t)', 'e.mount', 'e.default', 'this.registerHomepageUnmount', 'this.disposeHomepageUnmount', 'must export mount(container)', 'must return { unmount() }', 'homepageUnmount', 'homepageBlobUrl', 'homepageCleanupStarted'] as $token) {
            $this->assertStringContainsString($token, $frontend);
        }

        $this->assertStringNotContainsString('new window.DOMParser', $frontend);
        $this->assertStringNotContainsString('getHomepageScriptUrl', $frontend);
        $this->assertStringNotContainsString('createHomepageRuntime', $frontend);
        $this->assertStringNotContainsString('this.homepageScriptObserver', $frontend);
        $this->assertStringNotContainsString('externalScripts', $frontend);
        $this->assertStringNotContainsString('externalAssets', $frontend);
    }

    public function testHomepageRuntimeCleansHomepageOwnedNodesAndCallbacks(): void
    {
        $frontend = file_get_contents(public_path('theme/default/assets/umi.js'));

        $this->assertStringContainsString('this.disposeHomepageUnmount(this.homepageUnmount)', $frontend);
        $this->assertStringContainsString('this.homepageUnmount = null', $frontend);
        $this->assertStringContainsString('this.releaseHomepageBlobUrl(this.homepageBlobUrl)', $frontend);
        $this->assertStringContainsString('this.homepageBlobUrl = null', $frontend);
        $this->assertStringContainsString('this.homepageCleanupStarted', $frontend);
        $this->assertStringContainsString('this.homepageShadowRoot.innerHTML = ""', $frontend);
        $this->assertStringContainsString('this.homepageRoot = null', $frontend);
        $this->assertStringContainsString('this.homepageShadowRoot = null', $frontend);
        $this->assertStringContainsString('this.homepageUnmount = e', $frontend);
        $this->assertStringContainsString('e.unmount.call(e)', $frontend);
        $this->assertStringContainsString('this.homepageRunId += 1', $frontend);
        $this->assertStringContainsString('this.homepageRoot.innerHTML = ""', $frontend);
    }

    public function testApplicationsDoNotExposeLanguageSwitching(): void
    {
        $frontend = file_get_contents(public_path('theme/default/assets/umi.js'));
        $adminFrontend = file_get_contents(public_path('assets/admin/umi.js'));
        $blade = file_get_contents(public_path('theme/default/dashboard.blade.php'));

        foreach ([$frontend, $adminFrontend, $blade] as $source) {
            $this->assertStringNotContainsString('navigator.language', $source);
            $this->assertStringNotContainsString('umi_locale', $source);
            $this->assertStringNotContainsString('Content-Language', $source);
            $this->assertStringNotContainsString('i18nText', $source);
        }

        $this->assertStringNotContainsString('window.settings.i18n', $blade);
        $this->assertStringNotContainsString('/assets/i18n/', $blade);
        $this->assertStringNotContainsString('fa-language', $frontend);
        $this->assertStringNotContainsString('v2board-login-i18n-btn', $frontend);
    }

    public function testBundlesDoNotIncludeI18nRuntime(): void
    {
        $bundles = [
            public_path('theme/default/assets/umi.js'),
            public_path('assets/admin/umi.js'),
            public_path('theme/default/assets/components.async.js'),
            public_path('assets/admin/components.async.js'),
            public_path('theme/default/assets/vendors.async.js'),
            public_path('assets/admin/vendors.async.js'),
        ];

        foreach ($bundles as $path) {
            $source = file_get_contents($path);

            foreach ([
                'JRPe',
                'LLXN',
                'uct0',
                'xmVa',
                'IntlProvider',
                'IntlMessageFormat',
                '__addLocaleData',
                'umi-plugin-locale',
                'pluralRuleFunction',
                'navigator.language',
                'navigator.browserLanguage',
            ] as $token) {
                $this->assertStringNotContainsString($token, $source, $path);
            }
        }

        $frontend = file_get_contents(public_path('theme/default/assets/umi.js'));
        $this->assertStringContainsString('formatMessage: r', $frontend);
        $this->assertStringContainsString('replace(/\{([\w]+)\}/g', $frontend);
        $this->assertStringNotContainsString('formatHTMLMessage', $frontend);
        $this->assertStringContainsString('WEpk: function', $frontend);
        $this->assertStringContainsString('WFJy: function', $frontend);
        $this->assertStringContainsString('aRTE: function', $frontend);

        $adminFrontend = file_get_contents(public_path('assets/admin/umi.js'));
        $this->assertStringContainsString('a0xu: function', $adminFrontend);
    }

    public function testTicketImageSettingComesFromUserConfigInsteadOfBlade(): void
    {
        config(['v2board.ticket_image_enable' => 1]);

        $response = (new CommController())->config();
        $data = json_decode($response->getContent(), true);
        $frontend = file_get_contents(public_path('theme/default/assets/umi.js'));
        $blade = file_get_contents(public_path('theme/default/dashboard.blade.php'));

        $this->assertSame(1, $data['data']['ticket_image_enable']);
        $this->assertStringContainsString('this.props.comm.config.ticket_image_enable', $frontend);
        $this->assertStringContainsString('type: "comm/config"', $frontend);
        $this->assertStringNotContainsString('window.settings.ticket_image_enable', $frontend);
        $this->assertStringNotContainsString('ticket_image_enable', $blade);
    }

    private function getHomepage(array $homepageConfig = [])
    {
        config([
            'v2board.frontend_theme' => 'default',
            'v2board.safe_mode_enable' => 0,
            'theme.default' => array_merge([
                'theme_color' => 'default',
                'background_url' => '',
                'theme_sidebar' => 'light',
                'theme_header' => 'dark',
                'custom_homepage_js' => '',
                'custom_footer_html' => ''
            ], $homepageConfig)
        ]);

        return $this->get('/');
    }
}
