<?php

namespace App\Http\Controllers;

use App\Models\Room;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class HomeController extends Controller
{
    public function index(): View
    {
        return view('home');
    }

    public function enter(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'key' => ['required', 'string', 'max:64'],
            'role' => ['required', 'in:host,play'],
        ], [
            'key.required' => __('messages.errors.validation_key_required'),
            'role.required' => __('messages.errors.validation_role_required'),
        ]);

        $room = Room::query()->where('key', trim($data['key']))->first();

        if (! $room) {
            return back()
                ->withInput()
                ->withErrors(['key' => __('messages.errors.room_not_found')]);
        }

        $route = $data['role'] === 'host' ? 'host.show' : 'play.show';

        return redirect()->route($route, $room);
    }
}
