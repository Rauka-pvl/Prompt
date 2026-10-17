<?php

namespace App\Http\Controllers;

use App\Contracts\ImageGenerator;
use App\Models\Room;
use App\Models\RoomPhoto;
use App\Models\Round;
use App\Services\ForbiddenWordFilter;
use App\Services\ImageSimilarity;
use App\Services\SpeechTranscriber;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

class PlayController extends Controller
{
    public function show(Room $room): View
    {
        return view('play.show', compact('room'));
    }

    public function state(Room $room): JsonResponse
    {
        $room->load(['activePhoto.forbiddenWords', 'latestRound.photo']);

        $current = $room->currentRound();
        $latest = $room->latestRound;
        $active = $room->activePhoto;

        $status = 'lobby';

        if ($current) {
            $status = $current->status;
        } elseif ($latest?->status === Round::STATUS_DONE) {
            $status = Round::STATUS_DONE;
        }

        $visiblePhoto = null;

        if ($status === 'lobby') {
            $visiblePhoto = $active;
        } elseif ($current) {
            $visiblePhoto = $current->photo;
        } elseif ($latest) {
            $visiblePhoto = $latest->photo;
        }

        $round = $current ?? ($status === Round::STATUS_DONE ? $latest : null);

        return response()->json([
            'status' => $status,
            'has_active_photo' => $active !== null,
            'can_start' => $active !== null && $current === null,
            'photo' => $visiblePhoto && $status !== 'lobby' ? $this->photoPayload($visiblePhoto) : (
                $status === 'lobby' && $active ? [
                    'ready' => true,
                ] : null
            ),
            'round' => $round ? [
                'id' => $round->id,
                'status' => $round->status,
                'prompt_raw' => $round->prompt_raw,
                'prompt_clean' => $round->prompt_clean,
                'generated_url' => $round->generatedUrl(),
                'similarity' => $round->similarity,
                'original_url' => $round->photo?->url(),
            ] : null,
        ]);
    }

    public function start(Room $room): JsonResponse
    {
        $room->load('activePhoto');

        if ($room->currentRound()) {
            return $this->state($room);
        }

        $photo = $room->activePhoto;

        if (! $photo) {
            return response()->json([
                'message' => __('messages.play.lobby_wait'),
            ], 422);
        }

        $room->rounds()->create([
            'room_photo_id' => $photo->id,
            'status' => Round::STATUS_PLAYING,
        ]);

        return $this->state($room->fresh());
    }

    public function transcribe(Request $request, Room $room, SpeechTranscriber $transcriber): JsonResponse
    {
        $round = $room->currentRound();

        if (! $round || $round->status !== Round::STATUS_PLAYING) {
            return response()->json([
                'message' => __('messages.play.error_press_start'),
            ], 422);
        }

        $request->validate([
            'audio' => ['required', 'file', 'max:10240'],
        ]);

        try {
            $text = $transcriber->transcribe($request->file('audio'));
        } catch (\Throwable $exception) {
            report($exception);

            return response()->json([
                'message' => __('messages.js.transcribe_error'),
            ], 502);
        }

        return response()->json(['text' => $text]);
    }

    public function submit(Request $request, Room $room, ForbiddenWordFilter $filter): JsonResponse
    {
        $round = $room->currentRound();

        if (! $round || $round->status !== Round::STATUS_PLAYING) {
            return response()->json([
                'message' => __('messages.play.error_press_start'),
            ], 422);
        }

        $data = $request->validate([
            'prompt' => ['required', 'string', 'max:4000'],
        ], [
            'prompt.required' => __('messages.play.validation_prompt_required'),
        ]);

        $round->load('photo.forbiddenWords');
        $words = $round->photo->forbiddenWords->pluck('word')->all();
        $clean = $filter->clean($data['prompt'], $words);

        if ($clean === '') {
            return response()->json([
                'message' => __('messages.play.error_empty_after_filter'),
            ], 422);
        }

        $round->update([
            'prompt_raw' => $data['prompt'],
            'prompt_clean' => $clean,
            'status' => Round::STATUS_PROMPTED,
        ]);

        return $this->state($room->fresh());
    }

    public function generate(
        Request $request,
        Room $room,
        ImageGenerator $generator,
        ImageSimilarity $similarity,
        ForbiddenWordFilter $filter
    ): JsonResponse {
        $round = $room->currentRound();

        if (! $round || $round->status !== Round::STATUS_PROMPTED) {
            return response()->json([
                'message' => __('messages.play.error_submit_first'),
            ], 422);
        }

        $data = $request->validate([
            'prompt' => ['nullable', 'string', 'max:4000'],
        ]);

        $round->load('photo.forbiddenWords');

        if (filled($data['prompt'] ?? null)) {
            $clean = $filter->clean($data['prompt'], $round->photo->forbiddenWords->pluck('word')->all());

            if ($clean === '') {
                return response()->json([
                    'message' => __('messages.play.error_empty_after_filter'),
                ], 422);
            }

            $round->update(['prompt_clean' => $clean]);
        }

        try {
            $generatedPath = $generator->generate(
                (string) $round->prompt_clean,
                'rooms/'.$room->id.'/generated',
                $round->photo->absolutePath()
            );
        } catch (\Throwable $exception) {
            report($exception);

            return response()->json([
                'message' => $exception instanceof \RuntimeException
                    ? $exception->getMessage()
                    : __('messages.play.error_generate_failed'),
            ], 502);
        }

        $round->refresh();

        if ($round->status !== Round::STATUS_PROMPTED) {
            return $this->state($room->fresh());
        }

        $score = $similarity->compare(
            $round->photo->absolutePath(),
            Storage::disk('public')->path($generatedPath)
        );

        DB::transaction(function () use ($room, $round, $generatedPath, $score) {
            $round->update([
                'generated_path' => $generatedPath,
                'similarity' => $score,
                'status' => Round::STATUS_DONE,
            ]);

            $room->increment('plays_count');
        });

        return $this->state($room->fresh());
    }

    public function back(Room $room): JsonResponse
    {
        $round = $room->currentRound();

        if ($round?->status === Round::STATUS_PROMPTED) {
            $round->update(['status' => Round::STATUS_PLAYING]);
        } elseif ($round?->status === Round::STATUS_PLAYING) {
            $room->cancelOpenRounds();
        }

        return $this->state($room->fresh());
    }

    public function reset(Room $room): JsonResponse
    {
        $room->cancelOpenRounds();

        return $this->state($room->fresh());
    }

    /**
     * @return array{url: string, words: list<string>, patterns: list<string>}
     */
    private function photoPayload(RoomPhoto $photo): array
    {
        $photo->loadMissing('forbiddenWords');
        $words = $photo->forbiddenWords->pluck('word')->values()->all();

        return [
            'url' => $photo->url(),
            'words' => $words,
            'patterns' => array_map(
                fn (string $word) => app(ForbiddenWordFilter::class)->patternFor($word),
                $words
            ),
        ];
    }
}
