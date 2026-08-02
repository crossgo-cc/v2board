<?php

namespace Tests\Feature;

use App\Http\Controllers\V1\Admin\NoticeController as AdminNoticeController;
use App\Http\Controllers\V1\User\NoticeController as UserNoticeController;
use App\Models\Notice;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class NoticeControllerTest extends TestCase
{
    private bool $databaseReady = false;

    protected function setUp(): void
    {
        parent::setUp();

        if (!extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('公告接口测试需要 pdo_sqlite 扩展');
        }

        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:'
        ]);
        DB::purge('sqlite');

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
        $this->databaseReady = true;

        Route::get('/_test/notice/fetch', [UserNoticeController::class, 'fetch']);
        Route::post('/_test/notice/save', [AdminNoticeController::class, 'save']);
        Route::post('/_test/notice/pin', [AdminNoticeController::class, 'pin']);
        Route::post('/_test/notice/show', [AdminNoticeController::class, 'show']);
        Route::post('/_test/notice/drop', [AdminNoticeController::class, 'drop']);
    }

    protected function tearDown(): void
    {
        if ($this->databaseReady) {
            Schema::dropIfExists('v2_notice');
            DB::purge('sqlite');
        }

        parent::tearDown();
    }

    public function testUserListReturnsOnlyVisibleNoticesInStableOrder(): void
    {
        $time = time();
        DB::table('v2_notice')->insert([
            [
                'title' => '第一条',
                'content' => '# 第一条',
                'show' => 1,
                'tags' => null,
                'created_at' => $time,
                'updated_at' => $time
            ],
            [
                'title' => '第二条',
                'content' => '# 第二条',
                'show' => 1,
                'tags' => '["弹窗"]',
                'created_at' => $time,
                'updated_at' => $time
            ],
            [
                'title' => '隐藏公告',
                'content' => '不可见',
                'show' => 0,
                'tags' => '[]',
                'created_at' => $time + 1,
                'updated_at' => $time + 1
            ]
        ]);

        $response = $this->getJson('/_test/notice/fetch?current=1&pageSize=10');

        $response->assertOk()
            ->assertJsonPath('total', 2)
            ->assertJsonPath('data.0.title', '第二条')
            ->assertJsonPath('data.1.title', '第一条')
            ->assertJsonPath('data.1.tags', []);
    }

    public function testUserListRejectsInvalidPagination(): void
    {
        $this->getJson('/_test/notice/fetch?current=0&pageSize=101')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['current', 'pageSize']);
    }

    public function testPinnedNoticesAppearBeforeNewerRegularNotices(): void
    {
        $time = time();
        DB::table('v2_notice')->insert([
            [
                'title' => '较新的普通公告',
                'content' => '普通内容',
                'show' => 1,
                'is_pinned' => 0,
                'created_at' => $time,
                'updated_at' => $time
            ],
            [
                'title' => '较早的置顶公告',
                'content' => '置顶内容',
                'show' => 1,
                'is_pinned' => 1,
                'created_at' => $time - 100,
                'updated_at' => $time - 100
            ]
        ]);

        $this->getJson('/_test/notice/fetch?current=1&pageSize=10')
            ->assertOk()
            ->assertJsonPath('data.0.title', '较早的置顶公告')
            ->assertJsonPath('data.0.is_pinned', true)
            ->assertJsonPath('data.0.is_latest', false)
            ->assertJsonPath('data.1.title', '较新的普通公告');
    }

    public function testLatestPinnedNoticeReceivesBothStates(): void
    {
        $time = time();
        DB::table('v2_notice')->insert([
            [
                'title' => '较早的普通公告',
                'content' => '普通内容',
                'show' => 1,
                'is_pinned' => 0,
                'created_at' => $time - 100,
                'updated_at' => $time - 100
            ],
            [
                'title' => '最新的置顶公告',
                'content' => '置顶内容',
                'show' => 1,
                'is_pinned' => 1,
                'created_at' => $time,
                'updated_at' => $time
            ]
        ]);

        $this->getJson('/_test/notice/fetch?current=1&pageSize=10')
            ->assertOk()
            ->assertJsonPath('data.0.title', '最新的置顶公告')
            ->assertJsonPath('data.0.is_pinned', true)
            ->assertJsonPath('data.0.is_latest', true)
            ->assertJsonPath('data.1.is_pinned', false)
            ->assertJsonPath('data.1.is_latest', false);
    }

    public function testAdminCanCreateAndTogglePinnedNotice(): void
    {
        $this->postJson('/_test/notice/save', [
            'title' => '置顶公告',
            'content' => '内容',
            'is_pinned' => true,
            'tags' => []
        ])->assertOk();

        $notice = Notice::where('title', '置顶公告')->firstOrFail();
        $this->assertTrue($notice->is_pinned);

        $this->postJson('/_test/notice/pin', [
            'id' => $notice->id,
            'is_pinned' => false
        ])->assertOk();

        $this->assertFalse($notice->fresh()->is_pinned);
    }

    public function testAdminEditingPublishedNoticeKeepsItPublished(): void
    {
        $notice = Notice::create([
            'title' => '线上公告',
            'content' => '旧内容',
            'show' => true,
            'tags' => []
        ]);

        $this->postJson('/_test/notice/save', [
            'id' => $notice->id,
            'title' => '线上公告',
            'content' => '**新内容**',
            'tags' => []
        ])->assertOk();

        $this->assertTrue($notice->fresh()->show);
    }

    public function testPublishingRequiresExplicitTargetState(): void
    {
        $notice = Notice::create([
            'title' => '草稿',
            'content' => '内容',
            'tags' => []
        ]);

        $this->postJson('/_test/notice/show', [
            'id' => $notice->id
        ])->assertStatus(422)
            ->assertJsonValidationErrors('show');

        $this->postJson('/_test/notice/show', [
            'id' => $notice->id,
            'show' => true
        ])->assertOk();

        $this->assertTrue($notice->fresh()->show);
    }

}
