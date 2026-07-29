<?php

namespace App\Services;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

class TicketImageService
{
    private const HOST = 'https://i.111666.best';
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
            $token = Str::random(32);
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
            try {
                $this->client->request('DELETE', $item['url'], [
                    'headers' => ['Auth-Token' => $batch['token']],
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
            $response = $this->client->request('POST', self::HOST . '/image', [
                'headers' => [
                    'Accept' => 'application/json',
                    'Auth-Token' => $token,
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

        if ($response->getStatusCode() !== 200) {
            throw new \RuntimeException('图床暂时不可用，请稍后重试');
        }

        $payload = json_decode((string)$response->getBody(), true);
        if (!is_array($payload) || empty($payload['ok']) || empty($payload['src'])) {
            throw new \RuntimeException('图床返回了无效结果');
        }

        return ['url' => $this->normalizeUrl((string)$payload['src'])];
    }

    private function normalizeUrl(string $src): string
    {
        $url = preg_match('#^https?://#i', $src)
            ? $src
            : self::HOST . '/' . ltrim($src, '/');
        $parts = parse_url($url);

        if (
            !$parts
            || ($parts['scheme'] ?? '') !== 'https'
            || ($parts['host'] ?? '') !== 'i.111666.best'
            || strpos($parts['path'] ?? '', '/image/') !== 0
        ) {
            throw new \RuntimeException('图床返回了不受支持的图片地址');
        }

        return $url;
    }
}
