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
        Config::set('v2board.ticket_image_enable', 1);
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

    public function testItRejectsImagesWhenUploadsAreDisabled(): void
    {
        Config::set('v2board.ticket_image_enable', 0);
        $history = [];
        $service = $this->makeService([], $history);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('工单图片上传功能未启用');

        $service->uploadBatch([
            UploadedFile::fake()->create('disabled.png', 1, 'image/png'),
        ]);
    }

    public function testItAllowsMessagesWithoutImagesWhenUploadsAreDisabled(): void
    {
        Config::set('v2board.ticket_image_enable', 0);
        $history = [];
        $service = $this->makeService([], $history);

        $this->assertSame(['items' => []], $service->uploadBatch([]));
        $this->assertSame([], $history);
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

    public function testItDeletesOnlyExpiredTicketImages(): void
    {
        $history = [];
        $service = $this->makeService([
            new Response(200, [], $this->listResponse([
                ['ticket-images/2026/06/01/old.png', '2026-06-01T00:00:00.000Z'],
                ['ticket-images/2026/07/25/new.png', '2026-07-25T00:00:00.000Z'],
            ])),
            new Response(200, [], '<DeleteResult xmlns="http://s3.amazonaws.com/doc/2006-03-01/"/>'),
        ], $history);

        $this->assertSame(1, $service->deleteExpired(30));
        $this->assertCount(2, $history);

        $listRequest = $history[0]['request'];
        $this->assertSame('GET', $listRequest->getMethod());
        $this->assertSame(
            'list-type=2&max-keys=100&prefix=ticket-images%2F',
            $listRequest->getUri()->getQuery()
        );

        $deleteRequest = $history[1]['request'];
        $deleteBody = (string)$deleteRequest->getBody();
        $this->assertSame('POST', $deleteRequest->getMethod());
        $this->assertSame('delete=', $deleteRequest->getUri()->getQuery());
        $this->assertStringContainsString('ticket-images/2026/06/01/old.png', $deleteBody);
        $this->assertStringNotContainsString('ticket-images/2026/07/25/new.png', $deleteBody);
        $this->assertSame(base64_encode(md5($deleteBody, true)), $deleteRequest->getHeaderLine('Content-MD5'));
    }

    public function testItPaginatesTicketImages(): void
    {
        $history = [];
        $service = $this->makeService([
            new Response(200, [], $this->listResponse([
                ['ticket-images/2026/07/25/first.png', '2026-07-25T00:00:00.000Z'],
            ], true)),
            new Response(200, [], $this->listResponse([
                ['ticket-images/2026/07/26/second.png', '2026-07-26T00:00:00.000Z'],
            ])),
        ], $history);

        $this->assertSame(0, $service->deleteExpired(7));
        $this->assertCount(2, $history);
        $this->assertSame(
            'list-type=2&max-keys=100&prefix=ticket-images%2F&start-after=ticket-images%2F2026%2F07%2F25%2Ffirst.png',
            $history[1]['request']->getUri()->getQuery()
        );
    }

    public function testItDoesNotRequestR2WhenRetentionIsDisabled(): void
    {
        $history = [];
        $service = $this->makeService([], $history);

        $this->assertSame(0, $service->deleteExpired(0));
        $this->assertSame([], $history);
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

    private function listResponse(array $objects, bool $truncated = false): string
    {
        $contents = '';
        foreach ($objects as [$key, $lastModified]) {
            $contents .= '<Contents><Key>' . $key . '</Key><LastModified>'
                . $lastModified . '</LastModified></Contents>';
        }

        return '<ListBucketResult xmlns="http://s3.amazonaws.com/doc/2006-03-01/">'
            . '<IsTruncated>' . ($truncated ? 'true' : 'false') . '</IsTruncated>'
            . $contents
            . '</ListBucketResult>';
    }
}
