<?php

namespace App\Utils;

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request as LaravelRequest;
use Illuminate\Http\UploadedFile;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Throwable;
use Workerman\Protocols\Http\Request as WorkermanRequest;
use Workerman\Protocols\Http\Response as WorkermanResponse;

class WorkermanLaravelBridge
{
    public static function handle(Kernel $kernel, WorkermanRequest $workermanRequest): WorkermanResponse
    {
        $request = self::toLaravelRequest($workermanRequest);
        $bufferLevel = ob_get_level();
        ob_start();

        try {
            $response = $kernel->handle($request);
            $content = $response->getContent();

            if ($content === false) {
                $response->sendContent();
            } else {
                echo $content;
            }

            $kernel->terminate($request, $response);
            while (ob_get_level() > $bufferLevel + 1) {
                ob_end_flush();
            }
            $body = (string)ob_get_clean();
        } catch (Throwable $e) {
            while (ob_get_level() > $bufferLevel) {
                ob_end_clean();
            }
            throw $e;
        }

        if ($request->isMethod('HEAD')) {
            $body = '';
        }

        return self::toWorkermanResponse(
            $response,
            $body,
            $workermanRequest->protocolVersion()
        );
    }

    public static function toLaravelRequest(WorkermanRequest $request): LaravelRequest
    {
        $contentType = strtolower((string)$request->header('content-type', ''));
        $get = (array)$request->get();
        $post = self::postParameters($request, $contentType);
        $cookies = (array)$request->cookie();
        $rawFiles = strpos($contentType, 'multipart/form-data') === false
            ? []
            : (array)$request->file();
        $server = self::serverParameters($request);

        $_GET = $get;
        $_POST = $post;
        $_COOKIE = $cookies;
        $_FILES = $rawFiles;
        $_REQUEST = array_merge($get, $post);
        $_SERVER = $server;

        $parameters = in_array($request->method(), ['GET', 'HEAD'], true) ? $get : $post;

        return LaravelRequest::create(
            $request->uri(),
            $request->method(),
            $parameters,
            $cookies,
            self::convertFiles($rawFiles),
            $server,
            $request->rawBody()
        );
    }

    public static function toWorkermanResponse(
        SymfonyResponse $response,
        string $body,
        string $protocolVersion = '1.1'
    ): WorkermanResponse {
        $headers = [];

        foreach ($response->headers->allPreserveCase() as $name => $values) {
            if (in_array(strtolower($name), ['content-length', 'transfer-encoding'], true)) {
                continue;
            }
            $headers[$name] = $values;
        }

        return (new WorkermanResponse($response->getStatusCode(), $headers, $body))
            ->withProtocolVersion($protocolVersion);
    }

    private static function serverParameters(WorkermanRequest $request): array
    {
        $headers = (array)$request->header();
        $host = $request->host() ?: 'localhost';
        $serverName = $request->host(true) ?: 'localhost';
        $serverPort = self::hostPort($host) ?: 80;
        $remoteAddress = '127.0.0.1';
        $remotePort = 0;
        $localAddress = '127.0.0.1';

        if ($request->connection) {
            $remoteAddress = $request->connection->getRemoteIp();
            $remotePort = $request->connection->getRemotePort();
            $localAddress = $request->connection->getLocalIp();
        }

        $server = [
            'REQUEST_METHOD' => $request->method(),
            'REQUEST_URI' => $request->uri(),
            'QUERY_STRING' => $request->queryString(),
            'SERVER_PROTOCOL' => 'HTTP/' . $request->protocolVersion(),
            'SERVER_NAME' => $serverName,
            'SERVER_PORT' => $serverPort,
            'SERVER_ADDR' => $localAddress,
            'REMOTE_ADDR' => $remoteAddress,
            'REMOTE_PORT' => $remotePort,
            'SERVER_SOFTWARE' => 'workerman',
            'HTTP_HOST' => $host,
            'SCRIPT_NAME' => '',
            'SCRIPT_FILENAME' => '',
            'REQUEST_TIME' => time(),
            'REQUEST_TIME_FLOAT' => microtime(true),
        ];

        foreach ($headers as $name => $value) {
            $key = strtoupper(str_replace('-', '_', $name));
            $server['HTTP_' . $key] = $value;

            if ($key === 'CONTENT_TYPE' || $key === 'CONTENT_LENGTH') {
                $server[$key] = $value;
            }
        }

        return $server;
    }

    private static function hostPort(string $host): ?int
    {
        return preg_match('/:(\d{1,5})$/', $host, $match) ? (int)$match[1] : null;
    }

    private static function postParameters(WorkermanRequest $request, string $contentType): array
    {
        if (
            strpos($contentType, 'json') === false
            && strpos($contentType, 'application/x-www-form-urlencoded') === false
            && strpos($contentType, 'multipart/form-data') === false
        ) {
            return [];
        }

        return (array)$request->post();
    }

    private static function convertFiles(array $files): array
    {
        $converted = [];

        foreach ($files as $key => $file) {
            if (self::isFile($file)) {
                if ((int)$file['error'] !== UPLOAD_ERR_NO_FILE) {
                    $converted[$key] = new UploadedFile(
                        $file['tmp_name'],
                        $file['name'],
                        $file['type'] ?: null,
                        $file['error'],
                        true
                    );
                }
                continue;
            }

            if (is_array($file)) {
                $converted[$key] = self::convertFiles($file);
            }
        }

        return $converted;
    }

    private static function isFile($value): bool
    {
        return is_array($value)
            && isset($value['name'], $value['tmp_name'], $value['size'], $value['error'])
            && array_key_exists('type', $value);
    }
}
