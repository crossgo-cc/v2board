<?php

namespace Tests\Feature;

use App\Http\Controllers\V1\Admin\ConfigController;
use Illuminate\Http\Request;
use Tests\TestCase;

class AdminConfigTest extends TestCase
{
    public function testAdminConfigExposesPolicyUrls(): void
    {
        config([
            'v2board.privacy_url' => 'https://example.com/privacy',
            'v2board.tos_url' => 'https://example.com/terms'
        ]);

        $response = (new ConfigController())->fetch(Request::create('/config/fetch', 'GET'));
        $site = json_decode($response->getContent(), true)['data']['site'];

        $this->assertSame('https://example.com/privacy', $site['privacy_url']);
        $this->assertSame('https://example.com/terms', $site['tos_url']);
    }
}
