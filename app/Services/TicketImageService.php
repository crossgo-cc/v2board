<?php

namespace App\Services;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use Illuminate\Http\UploadedFile;

class TicketImageService
{
    private const MAX_MESSAGE_BYTES = 60000;
    private const REGION = 'auto';
    private const SERVICE = 's3';

    private $client;
    private $clock;

    public function __construct(?ClientInterface $client = null, ?callable $clock = null)
    {
        $this->client = $client ?: new Client();
        $this->clock = $clock ?: function () {
            return gmdate('Ymd\THis\Z');
        };
    }

    /**
     * @param UploadedFile[] $files
     */
    public function uploadBatch(array $files): array
    {
        if (!$files) {
            return ['items' => []];
        }

        if (!(int)config('v2board.ticket_image_enable', 0)) {
            throw new \RuntimeException('工单图片上传功能未启用');
        }

        $config = $this->config();
        $batch = ['items' => []];

        try {
            foreach ($files as $file) {
                $batch['items'][] = $this->upload($file, $config);
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
            . implode("\n\n", $images);

        if (strlen($message) > self::MAX_MESSAGE_BYTES) {
            throw new \RuntimeException('工单内容过长，请减少文字或图片');
        }

        return $message;
    }

    public function cleanup(array $batch): void
    {
        if (empty($batch['items'])) {
            return;
        }

        try {
            $config = $this->config();
        } catch (\Throwable $e) {
            return;
        }

        foreach ($batch['items'] as $item) {
            if (empty($item['key'])) {
                continue;
            }

            try {
                $this->request('DELETE', $item['key'], '', '', $config);
            } catch (\Throwable $e) {
                // 清理失败不覆盖原始业务异常，也不能记录 R2 凭据。
            }
        }
    }

    public function deleteExpired(int $days): int
    {
        if ($days <= 0) {
            return 0;
        }

        $config = $this->config();
        $cutoff = strtotime(($this->clock)()) - ($days * 86400);
        $startAfter = null;
        $deleted = 0;

        do {
            $query = [
                'list-type' => '2',
                'max-keys' => '100',
                'prefix' => 'ticket-images/',
            ];
            if ($startAfter !== null) {
                $query['start-after'] = $startAfter;
            }

            $response = $this->request('GET', '', '', '', $config, $query);
            if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 300) {
                throw new \RuntimeException('Cloudflare R2 图片列表获取失败');
            }

            $xml = @simplexml_load_string((string)$response->getBody());
            if ($xml === false) {
                throw new \RuntimeException('Cloudflare R2 图片列表格式无效');
            }

            $keys = [];
            $startAfter = null;
            foreach ($xml->Contents as $object) {
                $key = (string)$object->Key;
                $modifiedAt = strtotime((string)$object->LastModified);
                $startAfter = $key;
                if ($key !== '' && $modifiedAt !== false && $modifiedAt < $cutoff) {
                    $keys[] = $key;
                }
            }

            if ($keys) {
                $this->deleteObjects($keys, $config);
                $deleted += count($keys);
            }

            $truncated = strtolower((string)$xml->IsTruncated) === 'true';
            if ($truncated && $startAfter === null) {
                throw new \RuntimeException('Cloudflare R2 图片列表分页信息无效');
            }
        } while ($truncated);

        return $deleted;
    }

    private function deleteObjects(array $keys, array $config): void
    {
        $body = '<Delete xmlns="http://s3.amazonaws.com/doc/2006-03-01/"><Quiet>true</Quiet>';
        foreach ($keys as $key) {
            $body .= '<Object><Key>'
                . htmlspecialchars($key, ENT_XML1 | ENT_QUOTES, 'UTF-8')
                . '</Key></Object>';
        }
        $body .= '</Delete>';

        $response = $this->request(
            'POST',
            '',
            $body,
            'application/xml',
            $config,
            ['delete' => '']
        );
        if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 300) {
            throw new \RuntimeException('Cloudflare R2 过期图片清理失败');
        }

        $xml = @simplexml_load_string((string)$response->getBody());
        if ($xml === false || count($xml->Error) > 0) {
            throw new \RuntimeException('Cloudflare R2 过期图片清理不完整');
        }
    }

    private function upload(UploadedFile $file, array $config): array
    {
        $mimeType = (string)$file->getMimeType();
        $extensions = [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'image/gif' => 'gif',
        ];

        if (!isset($extensions[$mimeType])) {
            throw new \RuntimeException('图片格式不受支持');
        }

        $body = file_get_contents($file->getRealPath());
        if ($body === false) {
            throw new \RuntimeException('无法读取工单图片');
        }

        $key = sprintf(
            'ticket-images/%s/%s.%s',
            gmdate('Y/m/d'),
            bin2hex(random_bytes(16)),
            $extensions[$mimeType]
        );
        $response = $this->request('PUT', $key, $body, $mimeType, $config);
        $statusCode = $response->getStatusCode();

        if ($statusCode === 401 || $statusCode === 403) {
            throw new \RuntimeException('Cloudflare R2 凭据无效或无权限');
        }
        if ($statusCode < 200 || $statusCode >= 300) {
            throw new \RuntimeException('Cloudflare R2 暂时不可用，请稍后重试');
        }

        return [
            'key' => $key,
            'url' => $config['public_url'] . '/' . $key,
        ];
    }

    private function request(
        string $method,
        string $key,
        string $body,
        string $contentType,
        array $config,
        array $query = []
    ) {
        $host = $config['account_id'] . '.r2.cloudflarestorage.com';
        $canonicalUri = '/' . rawurlencode($config['bucket']);
        if ($key !== '') {
            $canonicalUri .= '/' . implode('/', array_map('rawurlencode', explode('/', $key)));
        }
        ksort($query);
        $canonicalQuery = http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        $payloadHash = hash('sha256', $body);
        $amzDate = ($this->clock)();
        $date = substr($amzDate, 0, 8);

        $headers = [
            'host' => $host,
            'x-amz-content-sha256' => $payloadHash,
            'x-amz-date' => $amzDate,
        ];
        if ($contentType !== '') {
            $headers['content-type'] = $contentType;
        }
        if (array_key_exists('delete', $query)) {
            $headers['content-md5'] = base64_encode(md5($body, true));
        }
        ksort($headers);

        $canonicalHeaders = '';
        foreach ($headers as $name => $value) {
            $canonicalHeaders .= $name . ':' . trim($value) . "\n";
        }
        $signedHeaders = implode(';', array_keys($headers));
        $canonicalRequest = implode("\n", [
            $method,
            $canonicalUri,
            $canonicalQuery,
            $canonicalHeaders,
            $signedHeaders,
            $payloadHash,
        ]);
        $scope = $date . '/' . self::REGION . '/' . self::SERVICE . '/aws4_request';
        $stringToSign = implode("\n", [
            'AWS4-HMAC-SHA256',
            $amzDate,
            $scope,
            hash('sha256', $canonicalRequest),
        ]);
        $signature = hash_hmac(
            'sha256',
            $stringToSign,
            $this->signingKey($config['secret_access_key'], $date),
            false
        );
        $headers['authorization'] = sprintf(
            'AWS4-HMAC-SHA256 Credential=%s/%s, SignedHeaders=%s, Signature=%s',
            $config['access_key_id'],
            $scope,
            $signedHeaders,
            $signature
        );

        $url = 'https://' . $host . $canonicalUri;
        if ($canonicalQuery !== '') {
            $url .= '?' . $canonicalQuery;
        }

        return $this->client->request($method, $url, [
            'headers' => $headers,
            'body' => $body,
            'connect_timeout' => 5,
            'timeout' => 20,
            'http_errors' => false,
            'allow_redirects' => false,
        ]);
    }

    private function signingKey(string $secret, string $date): string
    {
        $dateKey = hash_hmac('sha256', $date, 'AWS4' . $secret, true);
        $regionKey = hash_hmac('sha256', self::REGION, $dateKey, true);
        $serviceKey = hash_hmac('sha256', self::SERVICE, $regionKey, true);

        return hash_hmac('sha256', 'aws4_request', $serviceKey, true);
    }

    private function config(): array
    {
        $config = [
            'account_id' => trim((string)config('v2board.ticket_image_r2_account_id', '')),
            'access_key_id' => trim((string)config('v2board.ticket_image_r2_access_key_id', '')),
            'secret_access_key' => trim((string)config('v2board.ticket_image_r2_secret_access_key', '')),
            'bucket' => trim((string)config('v2board.ticket_image_r2_bucket', '')),
            'public_url' => rtrim(trim((string)config('v2board.ticket_image_r2_public_url', '')), '/'),
        ];

        if (in_array('', $config, true)) {
            throw new \RuntimeException('请先在后台完整配置 Cloudflare R2');
        }

        $url = parse_url($config['public_url']);
        if (!$url || ($url['scheme'] ?? '') !== 'https' || empty($url['host'])) {
            throw new \RuntimeException('Cloudflare R2 公开访问地址必须是 HTTPS URL');
        }

        return $config;
    }
}
