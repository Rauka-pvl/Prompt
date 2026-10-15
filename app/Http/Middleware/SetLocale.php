<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SetLocale
{
    public const LOCALES = ['ru', 'kk'];

    public function handle(Request $request, Closure $next): Response
    {
        $locale = $request->session()->get('locale', 'ru');

        if (! in_array($locale, self::LOCALES, true)) {
            $locale = 'ru';
        }

        app()->setLocale($locale);

        return $next($request);
    }
}
