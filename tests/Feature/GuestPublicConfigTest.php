<?php

namespace Tests\Feature;

use Tests\TestCase;

class GuestPublicConfigTest extends TestCase
{
    public function testGuestConfigExposesHomepageSiteInformation(): void
    {
        config([
            'v2board.app_name' => 'Example',
            'v2board.privacy_url' => 'https://example.com/privacy',
            'v2board.tos_url' => 'https://example.com/terms',
            'v2board.stop_register' => 1,
            'v2board.try_out_plan_id' => 3,
            'v2board.try_out_hour' => 12,
            'v2board.currency' => 'USD',
            'v2board.currency_symbol' => '$',
        ]);

        $response = $this->getJson('/api/v1/guest/comm/config');

        $response
            ->assertOk()
            ->assertJsonPath('data.app_name', 'Example')
            ->assertJsonPath('data.privacy_url', 'https://example.com/privacy')
            ->assertJsonPath('data.tos_url', 'https://example.com/terms')
            ->assertJsonPath('data.is_register', 0)
            ->assertJsonPath('data.is_try_out', 1)
            ->assertJsonPath('data.try_out_hour', 12)
            ->assertJsonPath('data.currency', 'USD')
            ->assertJsonPath('data.currency_symbol', '$');

        $data = json_decode($response->getContent(), true);
        $this->assertArrayNotHasKey('ticket_image_enable', $data['data']);
        $this->assertArrayNotHasKey('telegram_discuss_link', $data['data']);
    }

    public function testRemovedAppAndTelegramEndpointsAreUnavailable(): void
    {
        $this->getJson('/api/v1/guest/app/fetch')->assertNotFound();
        $this->postJson('/api/v1/guest/telegram/webhook')->assertNotFound();
    }
}
