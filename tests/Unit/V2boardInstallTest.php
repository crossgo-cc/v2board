<?php

namespace Tests\Unit;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class V2boardInstallTest extends TestCase
{
    public function testItReturnsFailureWhenApplicationIsAlreadyInstalled(): void
    {
        File::shouldReceive('exists')->once()->andReturn(true);

        $exitCode = Artisan::call('v2board:install');

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('如需重新安装', Artisan::output());
    }
}
