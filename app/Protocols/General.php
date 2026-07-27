<?php

namespace App\Protocols;

use Illuminate\Http\Response;
use App\Utils\Helper;

class General extends AbstractProtocol
{

    public function handle(): Response
    {
        $uri = '';

        foreach ($this->servers as $server) {
            $uri .= Helper::buildUri($this->user['uuid'], $server);
        }
        return $this->response(base64_encode($uri));
    }
}
