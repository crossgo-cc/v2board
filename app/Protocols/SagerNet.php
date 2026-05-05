<?php

namespace App\Protocols;


use App\Utils\Helper;

class SagerNet
{
    public $flag = 'sagernet';
    private $servers;
    private $user;

    public function __construct($user, $servers)
    {
        $this->user = $user;
        $this->servers = $servers;
    }

    public function handle()
    {
        $uri = '';

        foreach ($this->servers as $server) {
            $uri .= Helper::buildUri($this->user['uuid'], $server, 'sagernet');
        }
        return base64_encode($uri);
    }
}
