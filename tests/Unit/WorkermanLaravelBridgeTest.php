<?php

namespace Tests\Unit;

use App\Utils\WorkermanLaravelBridge;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Response;
use Illuminate\Http\UploadedFile;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Workerman\Protocols\Http\Request as WorkermanRequest;

class WorkermanLaravelBridgeTest extends TestCase
{
    private $server;
    private $get;
    private $post;
    private $cookie;
    private $files;
    private $request;

    protected function setUp(): void
    {
        parent::setUp();
        $this->server = $_SERVER;
        $this->get = $_GET;
        $this->post = $_POST;
        $this->cookie = $_COOKIE;
        $this->files = $_FILES;
        $this->request = $_REQUEST;
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->server;
        $_GET = $this->get;
        $_POST = $this->post;
        $_COOKIE = $this->cookie;
        $_FILES = $this->files;
        $_REQUEST = $this->request;
        parent::tearDown();
    }

    public function testItBuildsLaravelRequestAndRefreshesGlobals(): void
    {
        $_SERVER['HTTP_STALE_HEADER'] = 'stale';
        $body = '{"message":"hello"}';
        $request = new WorkermanRequest(
            "POST /api/test?page=2 HTTP/1.1\r\n"
            . "Host: example.com:8080\r\n"
            . "Authorization: Bearer token\r\n"
            . "Cookie: locale=zh-CN\r\n"
            . "Content-Type: application/json\r\n"
            . 'Content-Length: ' . strlen($body) . "\r\n\r\n"
            . $body
        );

        $laravelRequest = WorkermanLaravelBridge::toLaravelRequest($request);

        $this->assertSame('hello', $laravelRequest->input('message'));
        $this->assertSame('2', $laravelRequest->query('page'));
        $this->assertSame('Bearer token', $laravelRequest->header('authorization'));
        $this->assertSame('zh-CN', $laravelRequest->cookie('locale'));
        $this->assertSame($body, $laravelRequest->getContent());
        $this->assertSame('example.com', $laravelRequest->server('SERVER_NAME'));
        $this->assertSame(8080, $laravelRequest->server('SERVER_PORT'));
        $this->assertSame('Bearer token', $_SERVER['HTTP_AUTHORIZATION']);
        $this->assertSame('Bearer token', getallheaders()['Authorization']);
        $this->assertSame(['page' => '2', 'message' => 'hello'], $_REQUEST);
        $this->assertArrayNotHasKey('HTTP_STALE_HEADER', $_SERVER);

        WorkermanLaravelBridge::toLaravelRequest(
            new WorkermanRequest("GET /next HTTP/1.1\r\nHost: example.com\r\n\r\n")
        );

        $this->assertSame([], $_POST);
        $this->assertArrayNotHasKey('HTTP_AUTHORIZATION', $_SERVER);
    }

    public function testItConvertsMultipleFilesAndWorkermanCleansThemUp(): void
    {
        $boundary = '----BridgeBoundary';
        $body = self::multipart($boundary, [
            ['name' => 'images[]', 'filename' => 'first.png', 'value' => 'first-bytes'],
            ['name' => 'images[]', 'filename' => 'second.png', 'value' => 'second-bytes'],
        ]);
        $request = new WorkermanRequest(
            "POST /upload HTTP/1.1\r\n"
            . "Host: example.com\r\n"
            . "Content-Type: multipart/form-data; boundary={$boundary}\r\n"
            . 'Content-Length: ' . strlen($body) . "\r\n\r\n"
            . $body
        );

        $laravelRequest = WorkermanLaravelBridge::toLaravelRequest($request);
        $images = $laravelRequest->file('images');
        $paths = array_map(function (UploadedFile $file) {
            return $file->getRealPath();
        }, $images);

        $this->assertCount(2, $images);
        $this->assertContainsOnlyInstancesOf(UploadedFile::class, $images);
        $this->assertTrue($images[0]->isValid());
        $this->assertTrue($images[1]->isValid());
        $this->assertSame(['first.png', 'second.png'], array_map(function (UploadedFile $file) {
            return $file->getClientOriginalName();
        }, $images));
        $this->assertSame(['first-bytes', 'second-bytes'], array_map('file_get_contents', $paths));

        unset($request);
        gc_collect_cycles();
        clearstatcache();

        $this->assertSame([false, false], array_map('file_exists', $paths));
    }

    public function testItConvertsScalarAndNestedFileFields(): void
    {
        $boundary = '----NestedBoundary';
        $body = self::multipart($boundary, [
            ['name' => 'avatar', 'filename' => 'avatar.png', 'value' => 'avatar-bytes'],
            ['name' => 'documents[front]', 'filename' => 'front.png', 'value' => 'front-bytes'],
        ]);
        $request = new WorkermanRequest(
            "POST /upload HTTP/1.1\r\n"
            . "Host: example.com\r\n"
            . "Content-Type: multipart/form-data; boundary={$boundary}\r\n"
            . 'Content-Length: ' . strlen($body) . "\r\n\r\n"
            . $body
        );

        $laravelRequest = WorkermanLaravelBridge::toLaravelRequest($request);

        $this->assertInstanceOf(UploadedFile::class, $laravelRequest->file('avatar'));
        $this->assertTrue($laravelRequest->file('avatar')->isValid());
        $this->assertInstanceOf(UploadedFile::class, $laravelRequest->file('documents.front'));
        $this->assertTrue($laravelRequest->file('documents.front')->isValid());
    }

    public function testItKeepsUnknownContentTypeOutOfPostData(): void
    {
        $body = 'raw=binary&payload';
        $request = new WorkermanRequest(
            "POST /binary HTTP/1.1\r\n"
            . "Host: example.com\r\n"
            . "Content-Type: application/octet-stream\r\n"
            . 'Content-Length: ' . strlen($body) . "\r\n\r\n"
            . $body
        );

        $laravelRequest = WorkermanLaravelBridge::toLaravelRequest($request);

        $this->assertSame([], $laravelRequest->request->all());
        $this->assertSame([], $_POST);
        $this->assertSame($body, $laravelRequest->getContent());
    }

    public function testItSupportsJsonCompatibleMediaTypes(): void
    {
        $body = '{"message":"problem"}';
        $request = new WorkermanRequest(
            "POST /problem HTTP/1.1\r\n"
            . "Host: example.com\r\n"
            . "Content-Type: application/problem+json\r\n"
            . 'Content-Length: ' . strlen($body) . "\r\n\r\n"
            . $body
        );

        $laravelRequest = WorkermanLaravelBridge::toLaravelRequest($request);

        $this->assertSame('problem', $laravelRequest->input('message'));
    }

    public function testItPreservesStatusHeadersCookiesAndBody(): void
    {
        $response = new Response('payload', 201, ['X-Test' => 'yes']);
        $response->headers->set('X-Multi', ['one', 'two']);
        $response->headers->setCookie(new Cookie('first', 'a'));
        $response->headers->setCookie(new Cookie('second', 'b'));
        $response->headers->set('Content-Length', '999');

        $workermanResponse = WorkermanLaravelBridge::toWorkermanResponse(
            $response,
            'payload',
            '1.1'
        );

        $this->assertSame(201, $workermanResponse->getStatusCode());
        $this->assertSame('payload', $workermanResponse->rawBody());
        $this->assertSame(['yes'], $workermanResponse->getHeader('X-Test'));
        $this->assertSame(['one', 'two'], $workermanResponse->getHeader('X-Multi'));
        $this->assertCount(2, $workermanResponse->getHeader('Set-Cookie'));
        $this->assertNull($workermanResponse->getHeader('Content-Length'));
    }

    public function testItRunsAndTerminatesLaravelKernel(): void
    {
        $request = new WorkermanRequest("GET /health HTTP/1.1\r\nHost: example.com\r\n\r\n");
        $kernel = $this->createMock(Kernel::class);
        $kernel->expects($this->once())
            ->method('handle')
            ->willReturnCallback(function () {
                echo 'prefix-';
                return new Response('ok', 202, ['Content-Type' => 'text/plain']);
            });
        $kernel->expects($this->once())->method('terminate');

        $response = WorkermanLaravelBridge::handle($kernel, $request);

        $this->assertSame(202, $response->getStatusCode());
        $this->assertSame('prefix-ok', $response->rawBody());
        $this->assertSame(['text/plain'], $response->getHeader('Content-Type'));
    }

    public function testHeadRequestReturnsNoBody(): void
    {
        $request = new WorkermanRequest("HEAD /health HTTP/1.1\r\nHost: example.com\r\n\r\n");
        $kernel = $this->createMock(Kernel::class);
        $kernel->method('handle')->willReturn(new Response('not-sent'));

        $response = WorkermanLaravelBridge::handle($kernel, $request);

        $this->assertSame('', $response->rawBody());
    }

    public function testItCapturesStreamedResponseBody(): void
    {
        $request = new WorkermanRequest("GET /stream HTTP/1.1\r\nHost: example.com\r\n\r\n");
        $kernel = $this->createMock(Kernel::class);
        $kernel->method('handle')->willReturn(new StreamedResponse(function () {
            echo 'streamed-content';
        }, 200, ['Content-Type' => 'text/plain']));

        $response = WorkermanLaravelBridge::handle($kernel, $request);

        $this->assertSame('streamed-content', $response->rawBody());
        $this->assertSame(['text/plain'], $response->getHeader('Content-Type'));
    }

    public function testItRestoresBufferAndCleansUploadWhenKernelThrows(): void
    {
        $boundary = '----FailureBoundary';
        $body = self::multipart($boundary, [
            ['name' => 'images[]', 'filename' => 'failure.png', 'value' => 'failure-bytes'],
        ]);
        $request = new WorkermanRequest(
            "POST /upload HTTP/1.1\r\n"
            . "Host: example.com\r\n"
            . "Content-Type: multipart/form-data; boundary={$boundary}\r\n"
            . 'Content-Length: ' . strlen($body) . "\r\n\r\n"
            . $body
        );
        $path = $request->file('images')[0]['tmp_name'];
        $bufferLevel = ob_get_level();
        $kernel = $this->createMock(Kernel::class);
        $kernel->method('handle')->willReturnCallback(function () {
            ob_start();
            echo 'nested-output';
            throw new \RuntimeException('failure');
        });
        $kernel->expects($this->never())->method('terminate');

        try {
            WorkermanLaravelBridge::handle($kernel, $request);
            $this->fail('Kernel 异常应该继续向外抛出');
        } catch (\RuntimeException $e) {
            $this->assertSame('failure', $e->getMessage());
            unset($e);
        }

        $this->assertSame($bufferLevel, ob_get_level());
        unset($request);
        gc_collect_cycles();
        clearstatcache();
        $this->assertFileDoesNotExist($path);
    }

    private static function multipart(string $boundary, array $parts): string
    {
        $body = '';
        foreach ($parts as $part) {
            $body .= "--{$boundary}\r\n";
            $body .= 'Content-Disposition: form-data; name="' . $part['name']
                . '"; filename="' . $part['filename'] . "\"\r\n";
            $body .= "Content-Type: image/png\r\n\r\n";
            $body .= $part['value'] . "\r\n";
        }
        return $body . "--{$boundary}--\r\n";
    }
}
