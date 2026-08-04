<?php

namespace Tests\Feature;

use App\Http\Controllers\V1\Admin\Server\RouteController;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ServerRouteSortingTest extends TestCase
{
    private bool $databaseReady = false;

    protected function setUp(): void
    {
        parent::setUp();

        if (!extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('服务器路由排序测试需要 pdo_sqlite 扩展');
        }

        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:'
        ]);
        DB::purge('sqlite');

        Schema::create('v2_server_route', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('sort')->default(0);
            $table->string('remarks');
            $table->text('match');
            $table->string('action', 11);
            $table->text('action_value')->nullable();
            $table->unsignedInteger('created_at');
            $table->unsignedInteger('updated_at');
        });

        Route::get('/_test/server-route/fetch', [RouteController::class, 'fetch']);
        Route::post('/_test/server-route/sort', [RouteController::class, 'sort']);
        $this->databaseReady = true;
    }

    protected function tearDown(): void
    {
        if ($this->databaseReady) {
            Schema::dropIfExists('v2_server_route');
            DB::purge('sqlite');
        }

        parent::tearDown();
    }

    public function testFetchReturnsRoutesInConfiguredOrder(): void
    {
        DB::table('v2_server_route')->insert([
            $this->route(1, 3),
            $this->route(2, 1),
            $this->route(3, 2)
        ]);

        $routes = $this->getJson('/_test/server-route/fetch')
            ->assertOk()
            ->json('data');

        $this->assertSame([2, 3, 1], array_column($routes, 'id'));
    }

    public function testSortPersistsTheSubmittedOrder(): void
    {
        DB::table('v2_server_route')->insert([
            $this->route(1, 1),
            $this->route(2, 2),
            $this->route(3, 3)
        ]);

        $this->postJson('/_test/server-route/sort', [
            'route_ids' => [3, 1, 2]
        ])->assertOk()->assertJson(['data' => true]);

        $this->assertSame(
            [3, 1, 2],
            DB::table('v2_server_route')->orderBy('sort')->pluck('id')->all()
        );
        $this->assertSame(
            [1, 2, 3],
            DB::table('v2_server_route')->orderBy('sort')->pluck('sort')->all()
        );
    }

    private function route(int $id, int $sort): array
    {
        $time = time();

        return [
            'id' => $id,
            'sort' => $sort,
            'remarks' => "Route {$id}",
            'match' => '[]',
            'action' => 'block',
            'action_value' => null,
            'created_at' => $time,
            'updated_at' => $time
        ];
    }
}
