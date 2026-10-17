<?php

namespace App\Http\Controllers;

use App\Http\Middleware\SetLocale;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class LocaleController extends Controller
{
    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'locale' => ['required', 'string', 'in:'.implode(',', SetLocale::LOCALES)],
            'scope' => ['nullable', 'string', 'in:'.implode(',', SetLocale::SCOPES)],
        ]);

        $scope = $data['scope'] ?? null;

        $request->session()->put(SetLocale::sessionKey($scope), $data['locale']);

        if ($scope === null) {
            foreach (SetLocale::SCOPES as $roleScope) {
                $request->session()->forget(SetLocale::sessionKey($roleScope));
            }
        }

        return back();
    }
}
