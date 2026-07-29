<?php

namespace Tests\Unit;

use App\Http\Middleware\Language;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Tests\TestCase;

class LanguageTest extends TestCase
{
    public function testItRestoresDefaultLocaleWhenHeaderIsMissing(): void
    {
        App::setLocale('en');
        $request = Request::create('/api/test');

        (new Language())->handle($request, function () {
            return response('ok');
        });

        $this->assertSame(config('app.locale'), App::getLocale());
    }

    public function testItUsesRequestedLocale(): void
    {
        $request = Request::create('/api/test', 'GET', [], [], [], [
            'HTTP_CONTENT_LANGUAGE' => 'en',
        ]);

        (new Language())->handle($request, function () {
            return response('ok');
        });

        $this->assertSame('en', App::getLocale());
    }
}
