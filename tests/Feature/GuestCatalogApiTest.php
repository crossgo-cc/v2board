<?php

namespace Tests\Feature;

use App\Http\Controllers\V1\Admin\NoticeController as AdminNoticeController;
use App\Http\Controllers\V1\Admin\PlanController as AdminPlanController;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class GuestCatalogApiTest extends TestCase
{
    private bool $databaseReady = false;

    protected function setUp(): void
    {
        parent::setUp();

        if (!extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('公开主页接口测试需要 pdo_sqlite 扩展');
        }

        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:'
        ]);
        DB::purge('sqlite');

        $this->createTables();
        $this->databaseReady = true;

        Route::post('/_test/guest-cache/notice/show', [AdminNoticeController::class, 'show']);
        Route::post('/_test/guest-cache/plan/update', [AdminPlanController::class, 'update']);
    }

    protected function tearDown(): void
    {
        if ($this->databaseReady) {
            Schema::dropIfExists('v2_notice');
            Schema::dropIfExists('v2_user');
            Schema::dropIfExists('v2_plan');
            DB::purge('sqlite');
        }

        parent::tearDown();
    }

    public function testGuestPlanEndpointReturnsOnlyPublicFields(): void
    {
        $time = time();
        DB::table('v2_plan')->insert([
            $this->plan(1, '公开套餐', true, 5, $time),
            $this->plan(2, '隐藏套餐', false, null, $time)
        ]);
        DB::table('v2_user')->insert([
            ['plan_id' => 1, 'expired_at' => $time + 3600],
            ['plan_id' => 1, 'expired_at' => $time - 3600]
        ]);

        $response = $this->getJson('/api/v1/guest/plan/fetch')->assertOk();
        $plans = $response->json('data');

        $this->assertCount(1, $plans);
        $this->assertSame('公开套餐', $plans[0]['name']);
        $this->assertSame(4, $plans[0]['capacity_limit']);
        $this->assertArrayNotHasKey('group_id', $plans[0]);
        $this->assertArrayNotHasKey('show', $plans[0]);
        $this->assertArrayNotHasKey('reset_price', $plans[0]);
    }

    public function testGuestNoticeEndpointReturnsOnlyHomepageNotices(): void
    {
        $time = time();
        DB::table('v2_notice')->insert([
            $this->notice(1, '首页公告', true, ['首页'], $time),
            $this->notice(2, '用户公告', true, ['弹窗'], $time + 1),
            $this->notice(3, '未发布首页公告', false, ['首页'], $time + 2)
        ]);

        $response = $this->getJson('/api/v1/guest/notice/fetch?pageSize=3')->assertOk();
        $notices = $response->json('data');

        $this->assertCount(1, $notices);
        $this->assertSame('首页公告', $notices[0]['title']);
        $this->assertArrayNotHasKey('show', $notices[0]);
        $this->assertArrayNotHasKey('tags', $notices[0]);
    }

    public function testHidingPlanInvalidatesGuestCache(): void
    {
        $time = time();
        DB::table('v2_plan')->insert($this->plan(1, '公开套餐', true, null, $time));

        $this->getJson('/api/v1/guest/plan/fetch')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->postJson('/_test/guest-cache/plan/update', [
            'id' => 1,
            'show' => 0
        ])->assertOk();

        $this->getJson('/api/v1/guest/plan/fetch')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function testHidingNoticeInvalidatesGuestCache(): void
    {
        $time = time();
        DB::table('v2_notice')->insert($this->notice(1, '首页公告', true, ['首页'], $time));

        $this->getJson('/api/v1/guest/notice/fetch?pageSize=3')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->postJson('/_test/guest-cache/notice/show', [
            'id' => 1,
            'show' => false
        ])->assertOk();

        $this->getJson('/api/v1/guest/notice/fetch?pageSize=3')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    private function createTables(): void
    {
        Schema::create('v2_plan', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('group_id');
            $table->unsignedInteger('transfer_enable');
            $table->unsignedInteger('device_limit')->nullable();
            $table->string('name');
            $table->unsignedInteger('speed_limit')->nullable();
            $table->boolean('show')->default(false);
            $table->unsignedInteger('sort')->nullable();
            $table->boolean('renew')->default(true);
            $table->text('content')->nullable();
            $table->integer('month_price')->nullable();
            $table->integer('quarter_price')->nullable();
            $table->integer('half_year_price')->nullable();
            $table->integer('year_price')->nullable();
            $table->integer('two_year_price')->nullable();
            $table->integer('three_year_price')->nullable();
            $table->integer('onetime_price')->nullable();
            $table->integer('reset_price')->nullable();
            $table->unsignedInteger('capacity_limit')->nullable();
            $table->unsignedInteger('created_at');
            $table->unsignedInteger('updated_at');
        });

        Schema::create('v2_user', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('plan_id')->nullable();
            $table->unsignedInteger('expired_at')->nullable();
        });

        Schema::create('v2_notice', function (Blueprint $table) {
            $table->increments('id');
            $table->string('title');
            $table->text('content');
            $table->boolean('show')->default(false);
            $table->boolean('is_pinned')->default(false);
            $table->string('img_url')->nullable();
            $table->text('tags')->nullable();
            $table->unsignedInteger('created_at');
            $table->unsignedInteger('updated_at');
        });
    }

    private function plan(int $id, string $name, bool $show, ?int $capacity, int $time): array
    {
        return [
            'id' => $id,
            'group_id' => 1,
            'transfer_enable' => 100,
            'device_limit' => 3,
            'name' => $name,
            'speed_limit' => 100,
            'show' => $show,
            'sort' => $id,
            'renew' => true,
            'content' => '套餐内容',
            'month_price' => 1000,
            'quarter_price' => null,
            'half_year_price' => null,
            'year_price' => null,
            'two_year_price' => null,
            'three_year_price' => null,
            'onetime_price' => null,
            'reset_price' => null,
            'capacity_limit' => $capacity,
            'created_at' => $time,
            'updated_at' => $time
        ];
    }

    private function notice(int $id, string $title, bool $show, array $tags, int $time): array
    {
        return [
            'id' => $id,
            'title' => $title,
            'content' => '公告内容',
            'show' => $show,
            'is_pinned' => false,
            'img_url' => null,
            'tags' => json_encode($tags),
            'created_at' => $time,
            'updated_at' => $time
        ];
    }
}
