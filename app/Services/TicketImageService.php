<?php

namespace App\Services;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use Illuminate\Http\UploadedFile;

class TicketImageService
{
    private const API_HOST = 'https://api.nodeimage.com';
    private const CDN_HOST = 'cdn.nodeimage.com';
    private const MAX_MESSAGE_BYTES = 60000;

    private $client;

    public function __construct(?ClientInterface $client = null)
    {
        $this->client = $client ?: new Client();
    }

    /**
     * @param UploadedFile[] $files
     */
    public function uploadBatch(array $files): array
    {
        if (!$files) {
            return ['token' => null, 'items' => []];
        }

        $token = trim((string)config('v2board.ticket_image_auth_token', ''));
        if ($token === '') {
            throw new \RuntimeException('请先在后台配置 NodeImage API Key');
        }

        $batch = ['token' => $token, 'items' => []];

        try {
            foreach ($files as $file) {
                $batch['items'][] = $this->upload($file, $token);
            }
        } catch (\Throwable $e) {
            $this->cleanup($batch);
            if ($e instanceof \RuntimeException) {
                throw $e;
            }
            throw new \RuntimeException('工单图片上传失败，请稍后重试');
        }

        return $batch;
    }

    public function appendToMessage(string $message, array $batch): string
    {
        if (empty($batch['items'])) {
            return $message;
        }

        $images = [];
        foreach ($batch['items'] as $index => $item) {
            $images[] = sprintf('![附件 %d](%s)', $index + 1, $item['url']);
        }

        $message = rtrim($message);
        $message .= ($message === '' ? '' : "\n\n")
            . "## 附加图片\n\n"
            . implode("\n\n", $images);

        if (strlen($message) > self::MAX_MESSAGE_BYTES) {
            throw new \RuntimeException('工单内容过长，请减少文字或图片');
        }

        return $message;
    }

    public function cleanup(array $batch): void
    {
        if (empty($batch['token']) || empty($batch['items'])) {
            return;
        }

        foreach ($batch['items'] as $item) {
            if (empty($item['id'])) {
                continue;
            }

            try {
                $this->client->request('DELETE', self::API_HOST . '/api/image/' . rawurlencode($item['id']), [
                    'headers' => ['X-API-Key' => $batch['token']],
                    'connect_timeout' => 5,
                    'timeout' => 10,
                    'http_errors' => false,
                    'allow_redirects' => false,
                ]);
            } catch (\Throwable $e) {
                // 清理失败不覆盖原始业务异常，也不能记录删除凭据。
            }
        }
    }

    private function upload(UploadedFile $file, string $token): array
    {
        $stream = fopen($file->getRealPath(), 'rb');
        if ($stream === false) {
            throw new \RuntimeException('无法读取工单图片');
        }

        try {
            $response = $this->client->request('POST', self::API_HOST . '/api/upload', [
                'headers' => [
                    'Accept' => 'application/json',
                    'X-API-Key' => $token,
                ],
                'multipart' => [[
                    'name' => 'image',
                    'contents' => $stream,
                    'filename' => $file->getClientOriginalName(),
                ]],
                'connect_timeout' => 5,
                'timeout' => 20,
                'http_errors' => false,
                'allow_redirects' => false,
            ]);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        $statusCode = $response->getStatusCode();
        if ($statusCode === 401 || $statusCode === 403) {
            throw new \RuntimeException('NodeImage API Key 无效或无权限');
        }
        if ($statusCode < 200 || $statusCode >= 300) {
            throw new \RuntimeException('NodeImage 暂时不可用，请稍后重试');
        }

        $payload = json_decode((string)$response->getBody(), true);
        $image = is_array($payload['data'] ?? null) ? $payload['data'] : $payload;
        $id = is_array($image) ? (string)($image['id'] ?? $image['image_id'] ?? '') : '';
        $url = is_array($image) ? (string)($image['url'] ?? $image['direct_url'] ?? '') : '';

        if ($id === '' || !preg_match('/^[A-Za-z0-9_-]{1,128}$/', $id) || $url === '') {
            throw new \RuntimeException('NodeImage 返回了无效结果');
        }

        return ['id' => $id, 'url' => $this->normalizeUrl($url)];
    }

    private function normalizeUrl(string $url): string
    {
        $parts = parse_url($url);

        if (
            !$parts
            || ($parts['scheme'] ?? '') !== 'https'
            || ($parts['host'] ?? '') !== self::CDN_HOST
            || strpos($parts['path'] ?? '', '/i/') !== 0
        ) {
            throw new \RuntimeException('NodeImage 返回了不受支持的图片地址');
        }

        return $url;
    }
}
