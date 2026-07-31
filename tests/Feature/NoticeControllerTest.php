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
            $table->string('img_url')->nullable();
            $table->text('tags')->nullable();
            $table->unsignedInteger('created_at');
            $table->unsignedInteger('updated_at');
        });
        $this->databaseReady = true;

        Route::get('/_test/notice/fetch', [UserNoticeController::class, 'fetch']);
        Route::post('/_test/notice/save', [AdminNoticeController::class, 'save']);
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

    public function testStaffCannotEditPublishedNotice(): void
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
            'tags' => [],
            'user' => [
                'is_staff' => true,
                'is_admin' => false
            ]
        ])->assertStatus(403);

        $this->assertTrue($notice->fresh()->show);
        $this->assertSame('旧内容', $notice->fresh()->content);
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
            'tags' => [],
            'user' => [
                'is_staff' => true,
                'is_admin' => true
            ]
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

    public function testStaffCannotDeletePublishedNotice(): void
    {
        $notice = Notice::create([
            'title' => '线上公告',
            'content' => '内容',
            'show' => true,
            'tags' => []
        ]);

        $this->postJson('/_test/notice/drop', [
            'id' => $notice->id,
            'user' => [
                'is_staff' => true,
                'is_admin' => false
            ]
        ])->assertStatus(403);

        $this->assertDatabaseHas('v2_notice', [
            'id' => $notice->id
        ]);
    }
}
