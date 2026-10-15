<?php

namespace App\Http\Controllers;

use App\Models\Room;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

class AdminController extends Controller
{
    public function loginForm(): View|RedirectResponse
    {
        if (session('admin_authenticated')) {
            return redirect()->route('admin.rooms');
        }

        return view('admin.login');
    }

    public function login(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'password' => ['required', 'string'],
        ], [
            'password.required' => __('messages.admin.validation_password'),
        ]);

        if (! hash_equals((string) config('prompt.admin_password'), $data['password'])) {
            return back()->withErrors(['password' => __('messages.admin.validation_password_wrong')]);
        }

        $request->session()->regenerate();
        $request->session()->put('admin_authenticated', true);

        return redirect()->route('admin.rooms');
    }

    public function logout(Request $request): RedirectResponse
    {
        $request->session()->forget('admin_authenticated');
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('admin.login');
    }

    public function rooms(): View
    {
        $rooms = Room::query()
            ->withCount('photos')
            ->latest()
            ->get();

        return view('admin.rooms', compact('rooms'));
    }

    public function storeRoom(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'key' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9\-_]+$/', 'unique:rooms,key'],
            'name' => ['nullable', 'string', 'max:120'],
        ], [
            'key.required' => __('messages.admin.validation_key_required'),
            'key.unique' => __('messages.admin.validation_key_unique'),
            'key.regex' => __('messages.admin.validation_key_regex'),
        ]);

        Room::query()->create([
            'key' => $data['key'],
            'name' => filled($data['name'] ?? null) ? trim($data['name']) : null,
        ]);

        return back()->with('status', __('messages.admin.status_created'));
    }

    public function destroyRoom(Room $room): RedirectResponse
    {
        foreach ($room->photos as $photo) {
            Storage::disk('public')->delete($photo->path);
        }

        foreach ($room->rounds as $round) {
            if ($round->generated_path) {
                Storage::disk('public')->delete($round->generated_path);
            }
        }

        Storage::disk('public')->deleteDirectory('rooms/'.$room->id);
        $room->delete();

        return back()->with('status', __('messages.admin.status_deleted'));
    }
}
