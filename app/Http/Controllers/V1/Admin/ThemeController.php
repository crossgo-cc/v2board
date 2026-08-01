<?php

namespace App\Http\Controllers\V1\Admin;

use App\Http\Controllers\Controller;
use App\Services\ThemeService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Workerman\Timer;

class ThemeController extends Controller
{
    private $themes;
    private $path;

    public function __construct()
    {
        $this->path = $path = public_path('theme/');
        $this->themes = array_map(function ($item) use ($path) {
            return str_replace($path, '', $item);
        }, glob($path . '*'));
    }

    public function getThemes()
    {
        $themeConfigs = [];
        foreach ($this->themes as $theme) {
            $themeConfigFile = $this->path . "{$theme}/config.json";
            if (!File::exists($themeConfigFile)) continue;
            $themeConfig = json_decode(File::get($themeConfigFile), true);
            if (!isset($themeConfig['configs']) || !is_array($themeConfig)) continue;
            $themeConfigs[$theme] = $themeConfig;
            if (config("theme.{$theme}")) continue;
            $themeService = new ThemeService($theme);
            $themeService->init();
        }
        return response([
            'data' => [
                'themes' => $themeConfigs,
                'active' => config('v2board.frontend_theme', 'v2board')
            ]
        ]);
    }

    public function getThemeConfig(Request $request)
    {
        $payload = $request->validate([
            'name' => 'required|in:' . join(',', $this->themes)
        ]);
        $themeConfigFile = $this->path . "{$payload['name']}/config.json";
        if (!File::exists($themeConfigFile)) abort(500, '主题不存在');
        $themeConfig = json_decode(File::get($themeConfigFile), true);
        if (!is_array($themeConfig) || !isset($themeConfig['configs']) || !is_array($themeConfig['configs'])) {
            abort(500, '主题配置文件有误');
        }

        $defaults = [];
        foreach ($themeConfig['configs'] as $themeField) {
            $defaults[$themeField['field_name']] = $themeField['default_value'] ?? '';
        }
        $config = config("theme.{$payload['name']}", []);
        if (!is_array($config)) $config = [];

        return response([
            'data' => array_merge($defaults, $config)
        ]);
    }

    public function saveThemeConfig(Request $request)
    {
        $payload = $request->validate([
            'name' => 'required|in:' . join(',', $this->themes),
            'config' => 'required'
        ]);
        $payload['config'] = json_decode(base64_decode($payload['config']), true);
        if (!$payload['config'] || !is_array($payload['config'])) abort(500, '参数有误');
        $themeConfigFile = public_path("theme/{$payload['name']}/config.json");
        if (!File::exists($themeConfigFile)) abort(500, '主题不存在');
        $themeConfig = json_decode(File::get($themeConfigFile), true);
        if (!is_array($themeConfig) || !isset($themeConfig['configs']) || !is_array($themeConfig['configs'])) {
            abort(500, '主题配置文件有误');
        }
        $defaults = [];
        foreach ($themeConfig['configs'] as $themeField) {
            $defaults[$themeField['field_name']] = $themeField['default_value'] ?? '';
        }
        $validateFields = array_column($themeConfig['configs'], 'field_name');
        $config = [];
        foreach ($validateFields as $validateField) {
            $config[$validateField] = isset($payload['config'][$validateField])
                ? $payload['config'][$validateField]
                : ($defaults[$validateField] ?? '');
        }

        File::ensureDirectoryExists(base_path() . '/config/theme/');

        $data = var_export($config, 1);
        if (!File::put(base_path() . "/config/theme/{$payload['name']}.php", "<?php\n return $data ;")) {
            abort(500, '修改失败');
        }

        try {
            Artisan::call('config:cache');
//            sleep(2);
        } catch (\Exception $e) {
            abort(500, '保存失败');
        }
        $this->reloadWebman();

        return response([
            'data' => $config
        ]);
    }

    private function reloadWebman(): void
    {
        if (!defined('isWEBMAN') || !isWEBMAN || !Cache::has('WEBMANPID')) return;

        $pid = (int)Cache::get('WEBMANPID');
        Cache::forget('WEBMANPID');
        if ($pid > 1) {
            Timer::add(0.1, 'posix_kill', [$pid, \SIGUSR1], false);
        }
    }
}
