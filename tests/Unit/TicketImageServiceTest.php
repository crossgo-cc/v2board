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
    private const ACCOUNT_ID = '0123456789abcdef0123456789abcdef';
    private const ACCESS_KEY_ID = 'test-access-key-id';
    private const SECRET_ACCESS_KEY = 'test-secret-access-key';
    private const BUCKET = 'ticket-images';
    private const PUBLIC_URL = 'https://images.example.com';
    private const AMZ_DATE = '20260729T120000Z';

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('v2board.ticket_image_r2_account_id', self::ACCOUNT_ID);
        Config::set('v2board.ticket_image_r2_access_key_id', self::ACCESS_KEY_ID);
        Config::set('v2board.ticket_image_r2_secret_access_key', self::SECRET_ACCESS_KEY);
        Config::set('v2board.ticket_image_r2_bucket', self::BUCKET);
        Config::set('v2board.ticket_image_r2_public_url', self::PUBLIC_URL);
    }

    public function testItUploadsToR2AndAppendsImageMarkdown(): void
    {
        $history = [];
        $service = $this->makeService([new Response(200)], $history);

        $batch = $service->uploadBatch([
            UploadedFile::fake()->create('example.png', 1, 'image/png'),
        ]);

        $this->assertMatchesRegularExpression(
            '#^ticket-images/\d{4}/\d{2}/\d{2}/[a-f0-9]{32}\.png$#',
            $batch['items'][0]['key']
        );
        $this->assertSame(
            self::PUBLIC_URL . '/' . $batch['items'][0]['key'],
            $batch['items'][0]['url']
        );
        $this->assertSame(
            "问题描述\n\n![附件 1](" . $batch['items'][0]['url'] . ')',
            $service->appendToMessage('问题描述', $batch)
        );

        $request = $history[0]['request'];
        $this->assertSame('PUT', $request->getMethod());
        $this->assertSame('image/png', $request->getHeaderLine('Content-Type'));
        $this->assertSame(self::AMZ_DATE, $request->getHeaderLine('X-Amz-Date'));
        $this->assertSame(
            hash('sha256', (string)$request->getBody()),
            $request->getHeaderLine('X-Amz-Content-Sha256')
        );
        $this->assertMatchesRegularExpression(
            '#^https://' . self::ACCOUNT_ID . '\.r2\.cloudflarestorage\.com/'
                . self::BUCKET . '/ticket-images/.+\.png$#',
            (string)$request->getUri()
        );
        $this->assertMatchesRegularExpression(
            '#^AWS4-HMAC-SHA256 Credential=' . self::ACCESS_KEY_ID
                . '/20260729/auto/s3/aws4_request, SignedHeaders=content-type;host;'
                . 'x-amz-content-sha256;x-amz-date, Signature=[a-f0-9]{64}$#',
            $request->getHeaderLine('Authorization')
        );
    }

    public function testItRequiresCompleteR2Configuration(): void
    {
        Config::set('v2board.ticket_image_r2_secret_access_key', '');
        $history = [];
        $service = $this->makeService([], $history);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('请先在后台完整配置 Cloudflare R2');

        $service->uploadBatch([
            UploadedFile::fake()->create('random.png', 1, 'image/png'),
        ]);
    }

    public function testItDeletesUploadedObjectByKey(): void
    {
        $history = [];
        $service = $this->makeService([
            new Response(200),
            new Response(204),
        ], $history);

        $batch = $service->uploadBatch([
            UploadedFile::fake()->create('delete.png', 1, 'image/png'),
        ]);
        $service->cleanup($batch);

        $request = $history[1]['request'];
        $this->assertSame('DELETE', $request->getMethod());
        $this->assertSame(
            'https://' . self::ACCOUNT_ID . '.r2.cloudflarestorage.com/'
                . self::BUCKET . '/' . $batch['items'][0]['key'],
            (string)$request->getUri()
        );
        $this->assertSame(hash('sha256', ''), $request->getHeaderLine('X-Amz-Content-Sha256'));
    }

    public function testItCleansUpUploadedObjectsWhenTheBatchFails(): void
    {
        $history = [];
        $service = $this->makeService([
            new Response(200),
            new Response(503),
            new Response(204),
        ], $history);

        try {
            $service->uploadBatch([
                UploadedFile::fake()->create('first.png', 1, 'image/png'),
                UploadedFile::fake()->create('second.png', 1, 'image/png'),
            ]);
            $this->fail('批量上传失败时应抛出异常');
        } catch (\RuntimeException $e) {
            $this->assertSame('Cloudflare R2 暂时不可用，请稍后重试', $e->getMessage());
        }

        $this->assertCount(3, $history);
        $this->assertSame('DELETE', $history[2]['request']->getMethod());
        $this->assertSame(
            $history[0]['request']->getUri()->getPath(),
            $history[2]['request']->getUri()->getPath()
        );
    }

    public function testItRejectsNonHttpsPublicUrls(): void
    {
        Config::set('v2board.ticket_image_r2_public_url', 'http://images.example.com');
        $history = [];
        $service = $this->makeService([], $history);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Cloudflare R2 公开访问地址必须是 HTTPS URL');

        $service->uploadBatch([
            UploadedFile::fake()->create('file.png', 1, 'image/png'),
        ]);
    }

    public function testItReportsInvalidR2Credentials(): void
    {
        $history = [];
        $service = $this->makeService([new Response(403)], $history);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Cloudflare R2 凭据无效或无权限');

        $service->uploadBatch([
            UploadedFile::fake()->create('forbidden.png', 1, 'image/png'),
        ]);
    }

    private function makeService(array $responses, array &$history): TicketImageService
    {
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($history));

        return new TicketImageService(new Client(['handler' => $stack]), function () {
            return self::AMZ_DATE;
        });
    }
}
