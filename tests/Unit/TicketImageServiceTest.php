<?php

namespace Tests\Unit;

use App\Services\TicketImageService;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Promise\Create;
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
            new Response(200, [], '{"id":"example","url":"https://cdn.nodeimage.com/i/example.webp","filename":"example.png"}'),
        ], $history);

        $batch = $service->uploadBatch([
            UploadedFile::fake()->create('example.png', 1, 'image/png'),
        ]);

        $this->assertSame('configured-test-token', $batch['token']);
        $this->assertSame('example', $batch['items'][0]['id']);
        $this->assertSame('https://cdn.nodeimage.com/i/example.webp', $batch['items'][0]['url']);
        $this->assertSame(
            "问题描述\n\n## 附加图片\n\n![附件 1](https://cdn.nodeimage.com/i/example.webp)",
            $service->appendToMessage('问题描述', $batch)
        );
        $this->assertSame('configured-test-token', $history[0]['request']->getHeaderLine('X-API-Key'));
    }

    public function testItRequiresAnApiKey(): void
    {
        Config::set('v2board.ticket_image_auth_token', '');
        $history = [];
        $service = $this->makeService([], $history);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('请先在后台配置 NodeImage API Key');

        $service->uploadBatch([
            UploadedFile::fake()->create('random.png', 1, 'image/png'),
        ]);
    }

    public function testItDeletesUploadedImageById(): void
    {
        Config::set('v2board.ticket_image_auth_token', 'delete-test-token');
        $history = [];
        $service = $this->makeService([
            new Response(200, [], '{"id":"delete-id","url":"https://cdn.nodeimage.com/i/delete-id.webp"}'),
            new Response(200, [], '{"success":true}'),
        ], $history);

        $batch = $service->uploadBatch([
            UploadedFile::fake()->create('delete.png', 1, 'image/png'),
        ]);
        $service->cleanup($batch);

        $this->assertSame('delete-test-token', $history[1]['request']->getHeaderLine('X-API-Key'));
        $this->assertSame('DELETE', $history[1]['request']->getMethod());
        $this->assertSame(
            'https://api.nodeimage.com/api/image/delete-id',
            (string)$history[1]['request']->getUri()
        );
    }

    public function testItCleansUpUploadedImagesWhenTheBatchFails(): void
    {
        Config::set('v2board.ticket_image_auth_token', 'cleanup-test-token');
        $history = [];
        $service = $this->makeService([
            new Response(200, [], '{"id":"first","url":"https://cdn.nodeimage.com/i/first.webp"}'),
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
            $this->assertSame('NodeImage 暂时不可用，请稍后重试', $e->getMessage());
        }

        $this->assertCount(3, $history);
        $this->assertSame('DELETE', $history[2]['request']->getMethod());
        $this->assertSame(
            'https://api.nodeimage.com/api/image/first',
            (string)$history[2]['request']->getUri()
        );
    }

    public function testItRejectsUnexpectedImageUrls(): void
    {
        Config::set('v2board.ticket_image_auth_token', 'url-test-token');
        $history = [];
        $service = $this->makeService([
            new Response(200, [], '{"id":"file","url":"https://example.com/image/file.png"}'),
        ], $history);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('NodeImage 返回了不受支持的图片地址');

        $service->uploadBatch([
            UploadedFile::fake()->create('file.png', 1, 'image/png'),
        ]);
    }

    public function testItHandlesHttpClientClosingUploadStream(): void
    {
        Config::set('v2board.ticket_image_auth_token', 'stream-test-token');
        $client = new Client([
            'handler' => function ($request) {
                $request->getBody()->close();

                return Create::promiseFor(
                    new Response(200, [], '{"id":"stream","url":"https://cdn.nodeimage.com/i/stream.webp"}')
                );
            },
        ]);
        $service = new TicketImageService($client);

        $batch = $service->uploadBatch([
            UploadedFile::fake()->create('stream.png', 1, 'image/png'),
        ]);

        $this->assertSame(
            'https://cdn.nodeimage.com/i/stream.webp',
            $batch['items'][0]['url']
        );
    }

    private function makeService(array $responses, array &$history): TicketImageService
    {
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($history));

        return new TicketImageService(new Client(['handler' => $stack]));
    }
}
