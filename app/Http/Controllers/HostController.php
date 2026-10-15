<?php

namespace App\Http\Controllers;

use App\Models\ForbiddenWord;
use App\Models\Room;
use App\Models\RoomPhoto;
use App\Models\Round;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

class HostController extends Controller
{
    public function show(Room $room): View
    {
        $room->load(['photos.forbiddenWords', 'latestRound.photo']);

        return view('host.show', compact('room'));
    }

    public function upload(Request $request, Room $room): RedirectResponse
    {
        $request->validate([
            'photos' => ['required', 'array', 'min:1'],
            'photos.*' => ['required', 'image', 'mimes:jpeg,jpg,png,webp', 'max:8192'],
        ], [
            'photos.required' => __('messages.host.validation_photos_required'),
            'photos.*.image' => __('messages.host.validation_photo_image'),
            'photos.*.max' => __('messages.host.validation_photo_max'),
        ]);

        $sort = (int) $room->photos()->max('sort');

        foreach ($request->file('photos', []) as $file) {
            $sort++;
            $path = $file->store('rooms/'.$room->id, 'public');

            $room->photos()->create([
                'path' => $path,
                'is_active' => false,
                'sort' => $sort,
            ]);
        }

        return back()->with('status', __('messages.host.status_uploaded'));
    }

    public function destroyPhoto(Room $room, RoomPhoto $photo): RedirectResponse
    {
        $this->assertPhoto($room, $photo);

        Storage::disk('public')->delete($photo->path);
        $photo->delete();

        return back()->with('status', __('messages.host.status_photo_deleted'));
    }

    public function activate(Room $room, RoomPhoto $photo): RedirectResponse
    {
        $this->assertPhoto($room, $photo);

        DB::transaction(function () use ($room, $photo) {
            $room->photos()->update(['is_active' => false]);
            $photo->update(['is_active' => true]);

            $room->rounds()
                ->whereIn('status', [Round::STATUS_PLAYING, Round::STATUS_PROMPTED])
                ->where('room_photo_id', '!=', $photo->id)
                ->update(['status' => Round::STATUS_CANCELLED]);
        });

        return back()->with('status', __('messages.host.status_photo_active'));
    }

    public function storeWord(Request $request, Room $room, RoomPhoto $photo): RedirectResponse
    {
        $this->assertPhoto($room, $photo);

        $data = $request->validate([
            'word' => ['required', 'string', 'max:500'],
        ], [
            'word.required' => __('messages.host.validation_word_required'),
        ]);

        $parts = preg_split('/[,;]+/u', $data['word']) ?: [];
        $added = 0;

        foreach ($parts as $part) {
            $word = mb_strtolower(trim($part));

            if ($word === '' || mb_strlen($word) > 64) {
                continue;
            }

            $exists = $photo->forbiddenWords()
                ->whereRaw('LOWER(word) = ?', [$word])
                ->exists();

            if ($exists) {
                continue;
            }

            $photo->forbiddenWords()->create(['word' => $word]);
            $added++;
        }

        if ($added === 0) {
            return back()->withErrors(['word' => __('messages.host.error_words')]);
        }

        return back()->with('status', $added === 1
            ? __('messages.host.status_word_added')
            : __('messages.host.status_words_added', ['count' => $added]));
    }

    public function destroyWord(Room $room, RoomPhoto $photo, ForbiddenWord $word): RedirectResponse
    {
        $this->assertPhoto($room, $photo);

        if ($word->room_photo_id !== $photo->id) {
            abort(404);
        }

        $word->delete();

        return back()->with('status', __('messages.host.status_word_deleted'));
    }

    private function assertPhoto(Room $room, RoomPhoto $photo): void
    {
        if ($photo->room_id !== $room->id) {
            abort(404);
        }
    }
}
