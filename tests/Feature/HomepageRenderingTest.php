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

        $this->assertArrayHasKey('homepage', $fields);
        $this->assertSame('textarea', $fields['homepage']['field_type']);
        $this->assertSame('', $fields['homepage']['default_value']);
        $this->assertArrayHasKey('homepage_js', $fields);
        $this->assertSame('textarea', $fields['homepage_js']['field_type']);
        $this->assertSame('', $fields['homepage_js']['default_value']);
    }

    public function testLegacyThemeConfigGetsNewHomepageDefaults(): void
    {
        config([
            'theme.default' => [
                'theme_color' => 'default',
                'background_url' => '',
                'theme_sidebar' => 'light',
                'theme_header' => 'dark',
                'custom_html' => 'footer'
            ]
        ]);
        Route::post('/_test/theme/get-config', [ThemeController::class, 'getThemeConfig']);

        $this->postJson('/_test/theme/get-config', [
            'name' => 'default'
        ])
            ->assertOk()
            ->assertJsonPath('data.homepage', '')
            ->assertJsonPath('data.homepage_js', '')
            ->assertJsonPath('data.custom_html', 'footer');
    }

    public function testHomepageIsEmbeddedWithSafeJsonEncoding(): void
    {
        $html = '<main data-title="首页 50%"></script><a href="/#/login">立即登录</a></main>';
        $javascript = 'window.__homepageTitle = "中文 50%";';
        $response = $this->getHomepage([
            'homepage' => $html,
            'homepage_js' => $javascript
        ]);
        $jsonOptions = JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT;

        $response->assertOk()
            ->assertSee('homepage: ' . json_encode($html, $jsonOptions), false)
            ->assertSee('homepage_js: ' . json_encode($javascript, $jsonOptions), false)
            ->assertDontSee($html, false)
            ->assertDontSee('homepage_url', false);
    }

    public function testDisabledHomepageKeepsLoginRedirectBehavior(): void
    {
        $response = $this->getHomepage();
        $frontend = file_get_contents(public_path('theme/default/assets/umi.js'));

        $response->assertOk()
            ->assertSee('homepage: ""', false)
            ->assertSee('homepage_js: ""', false);
        $this->assertStringContainsString('!window.settings.homepage', $frontend);
        $this->assertStringContainsString('a.a.push("/login")', $frontend);
    }

    public function testHomepageRendersDirectlyAndRunsWithCleanup(): void
    {
        $frontend = file_get_contents(public_path('theme/default/assets/umi.js'));
        $blade = file_get_contents(public_path('theme/default/dashboard.blade.php'));

        $this->assertStringContainsString('__html: window.settings.homepage', $frontend);
        $this->assertStringContainsString('new Function(window.settings.homepage_js).call(window)', $frontend);
        $this->assertStringContainsString('componentWillUnmount()', $frontend);
        $this->assertStringContainsString('this.cleanupHomepage()', $frontend);
        $this->assertStringNotContainsString('fetch(window.settings.homepage', $frontend);
        $this->assertStringContainsString("homepage: @json(\$theme_config['homepage'] ?? '')", $blade);
        $this->assertStringContainsString("homepage_js: @json(\$theme_config['homepage_js'] ?? '')", $blade);
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
                'custom_html' => ''
            ], $homepageConfig)
        ]);

        return $this->get('/');
    }
}
