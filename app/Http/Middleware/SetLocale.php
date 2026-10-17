<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * Host and player screens keep their own language, so both can run
 * in one browser in different languages. Other pages use the general one.
 */
class SetLocale
{
    public const LOCALES = ['ru', 'kk'];

    public const SCOPES = ['host', 'play'];

    public function handle(Request $request, Closure $next): Response
    {
        $scope = self::scopeFor($request);
        $session = $request->session();

        $locale = ($scope ? $session->get(self::sessionKey($scope)) : null) ?? $session->get('locale', 'ru');

        if (! in_array($locale, self::LOCALES, true)) {
            $locale = 'ru';
        }

        app()->setLocale($locale);
        View::share('localeScope', $scope);

        return $next($request);
    }

    public static function scopeFor(Request $request): ?string
    {
        foreach (self::SCOPES as $scope) {
            if ($request->is($scope, $scope.'/*')) {
                return $scope;
            }
        }

        return null;
    }

    public static function sessionKey(?string $scope): string
    {
        return $scope ? 'locale_'.$scope : 'locale';
    }
}
