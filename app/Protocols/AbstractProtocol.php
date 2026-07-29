<?php

namespace App\Protocols;

use Illuminate\Http\Response;

abstract class AbstractProtocol
{
    protected $user;
    protected $servers;

    public function __construct($user, array $servers)
    {
        $this->user = $user;
        $this->servers = $servers;
    }

    abstract public function handle(): Response;

    protected function response(string $content, array $headers = [], int $status = 200): Response
    {
        return new Response($content, $status, array_merge([
            'subscription-userinfo' => $this->userInfoHeader(),
            'profile-update-interval' => (string)config('v2board.subscribe_update_interval', 2),
        ], $headers));
    }

    protected function userInfoHeader(): string
    {
        return "upload={$this->user['u']}; download={$this->user['d']}; total={$this->user['transfer_enable']}; expire={$this->user['expired_at']}";
    }
}
