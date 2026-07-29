<?php

namespace Tests\Unit;

use App\Services\TicketImageService;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class TicketImageServiceTest extends TestCase
{
    public function testItUploadsAndAppendsImageMarkdown(): void
    {
        Config::set('v2board.ticket_image_auth_token', 'configured-test-token');
        $history = [];
        $service = $this->makeService([
            new Response(200, [], '{"ok":true,"src":"/image/example.png"}'),
        ], $history);

        $batch = $service->uploadBatch([
            UploadedFile::fake()->create('example.png', 1, 'image/png'),
        ]);

        $this->assertSame('configured-test-token', $batch['token']);
        $this->assertSame('https://i.111666.best/image/example.png', $batch['items'][0]['url']);
        $this->assertSame(
            "问题描述\n\n## 附加图片\n\n![附件 1](https://i.111666.best/image/example.png)",
            $service->appendToMessage('问题描述', $batch)
        );
        $this->assertSame('configured-test-token', $history[0]['request']->getHeaderLine('Auth-Token'));
    }

    public function testItUsesOneRandomTokenForUploadAndCleanup(): void
    {
        Config::set('v2board.ticket_image_auth_token', '');
        $history = [];
        $service = $this->makeService([
            new Response(200, [], '{"ok":true,"src":"/image/random.png"}'),
            new Response(200, [], '{"ok":true}'),
        ], $history);

        $batch = $service->uploadBatch([
            UploadedFile::fake()->create('random.png', 1, 'image/png'),
        ]);
        $service->cleanup($batch);

        $this->assertSame(32, strlen($batch['token']));
        $this->assertSame($batch['token'], $history[0]['request']->getHeaderLine('Auth-Token'));
        $this->assertSame($batch['token'], $history[1]['request']->getHeaderLine('Auth-Token'));
        $this->assertSame('DELETE', $history[1]['request']->getMethod());
    }

    public function testItCleansUpUploadedImagesWhenTheBatchFails(): void
    {
        Config::set('v2board.ticket_image_auth_token', 'cleanup-test-token');
        $history = [];
        $service = $this->makeService([
            new Response(200, [], '{"ok":true,"src":"/image/first.png"}'),
            new Response(503),
            new Response(200, [], '{"ok":true}'),
        ], $history);

        try {
            $service->uploadBatch([
                UploadedFile::fake()->create('first.png', 1, 'image/png'),
                UploadedFile::fake()->create('second.png', 1, 'image/png'),
            ]);
            $this->fail('批量上传失败时应抛出异常');
        } catch (\RuntimeException $e) {
            $this->assertSame('图床暂时不可用，请稍后重试', $e->getMessage());
        }

        $this->assertCount(3, $history);
        $this->assertSame('DELETE', $history[2]['request']->getMethod());
        $this->assertSame(
            'https://i.111666.best/image/first.png',
            (string)$history[2]['request']->getUri()
        );
    }

    public function testItRejectsUnexpectedImageUrls(): void
    {
        Config::set('v2board.ticket_image_auth_token', 'url-test-token');
        $history = [];
        $service = $this->makeService([
            new Response(200, [], '{"ok":true,"src":"https://example.com/image/file.png"}'),
        ], $history);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('图床返回了不受支持的图片地址');

        $service->uploadBatch([
            UploadedFile::fake()->create('file.png', 1, 'image/png'),
        ]);
    }

    private function makeService(array $responses, array &$history): TicketImageService
    {
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($history));

        return new TicketImageService(new Client(['handler' => $stack]));
    }
}
