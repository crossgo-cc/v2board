<?php

namespace App\Http\Controllers\V1\Guest;

use App\Http\Controllers\Controller;

class AppController extends Controller
{
    public function fetch()
    {
        return response([
            'data' => [
                'windows' => [
                    'version' => config('v2board.windows_version'),
                    'download_url' => config('v2board.windows_download_url')
                ],
                'macos' => [
                    'version' => config('v2board.macos_version'),
                    'download_url' => config('v2board.macos_download_url')
                ],
                'android' => [
                    'version' => config('v2board.android_version'),
                    'download_url' => config('v2board.android_download_url')
                ]
            ]
        ]);
    }
}
