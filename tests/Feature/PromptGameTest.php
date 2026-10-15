<?php

namespace Tests\Feature;

use App\Models\Room;
use App\Services\ForbiddenWordFilter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PromptGameTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_create_unique_room_keys(): void
    {
        $this->post('/admin/login', ['password' => 'njjh-gjk-2000'])
            ->assertRedirect(route('admin.rooms'));

        $this->post('/admin/rooms', ['key' => 'party-01'])
            ->assertRedirect();

        $this->assertDatabaseHas('rooms', ['key' => 'party-01', 'plays_count' => 0]);

        $this->post('/admin/rooms', ['key' => 'party-01'])
            ->assertSessionHasErrors('key');

        $this->assertSame(1, Room::query()->count());
    }

    public function test_home_page_defaults_to_russian_and_can_switch_to_kazakh(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('Опиши фото.', false)
            ->assertSee('Я участник', false)
            ->assertSee('Қазақша', false)
            ->assertSee('Русский', false);

        $this->from('/')->post('/locale', ['locale' => 'kk'])
            ->assertRedirect('/');

        $this->get('/')
            ->assertOk()
            ->assertSee('Фотоны сипатта.', false)
            ->assertSee('Мен ойыншымын', false);

        $this->from('/')->post('/locale', ['locale' => 'ru'])
            ->assertRedirect('/');

        $this->get('/')
            ->assertOk()
            ->assertSee('Опиши фото.', false);
    }

    public function test_invalid_locale_is_rejected(): void
    {
        $this->from('/')->post('/locale', ['locale' => 'en'])
            ->assertSessionHasErrors('locale');
    }

    public function test_play_page_uses_kazakh_speech_locale(): void
    {
        $room = Room::query()->create(['key' => 'demo']);

        $this->withSession(['locale' => 'kk'])
            ->get("/play/{$room->key}")
            ->assertOk()
            ->assertSee('kk-KZ', false)
            ->assertSee('Ойыншы экраны', false)
            ->assertSee('Промпт құрастыр', false)
            ->assertSee('Промптылар', false)
            ->assertDontSee('play-room-pill', false);
    }

    public function test_unknown_room_key_is_rejected(): void
    {
        $this->post('/enter', ['key' => 'missing', 'role' => 'play'])
            ->assertSessionHasErrors('key');

        $this->get('/play/missing')->assertNotFound();
        $this->get('/host/missing')->assertNotFound();
    }

    public function test_full_round_increments_usage_and_filters_words(): void
    {
        Storage::fake('public');

        $room = Room::query()->create(['key' => 'demo']);

        $upload = $this->post("/host/{$room->key}/photos", [
            'photos' => [UploadedFile::fake()->image('cat.jpg', 200, 200)],
        ]);
        $upload->assertRedirect();

        $photo = $room->photos()->first();
        $this->assertNotNull($photo);

        $this->post("/host/{$room->key}/photos/{$photo->id}/words", [
            'word' => 'кот, шляпе',
        ])->assertRedirect();

        $this->post("/host/{$room->key}/photos/{$photo->id}/activate")->assertRedirect();

        $this->postJson("/play/{$room->key}/start")->assertOk();

        $this->postJson("/play/{$room->key}/submit", [
            'prompt' => 'Рыжий кот в шляпе сидит на окне',
        ])->assertOk()
            ->assertJsonPath('round.prompt_clean', 'Рыжий в сидит на окне');

        $response = $this->postJson("/play/{$room->key}/generate");
        $response->assertOk()->assertJsonPath('status', 'done');
        $this->assertNotNull($response->json('round.similarity'));

        $this->assertSame(1, $room->fresh()->plays_count);
    }

    public function test_player_can_edit_prompt_before_generation(): void
    {
        Storage::fake('public');

        $room = Room::query()->create(['key' => 'demo']);

        $this->post("/host/{$room->key}/photos", [
            'photos' => [UploadedFile::fake()->image('car.jpg', 200, 200)],
        ])->assertRedirect();

        $photo = $room->photos()->firstOrFail();

        $this->post("/host/{$room->key}/photos/{$photo->id}/words", ['word' => 'голубой'])->assertRedirect();
        $this->post("/host/{$room->key}/photos/{$photo->id}/activate")->assertRedirect();

        $this->postJson("/play/{$room->key}/start")->assertOk();
        $this->postJson("/play/{$room->key}/submit", ['prompt' => 'машина у дома'])
            ->assertJsonPath('status', 'prompted');

        $this->postJson("/play/{$room->key}/generate", ['prompt' => 'голубая старая машина'])
            ->assertOk()
            ->assertJsonPath('status', 'done')
            ->assertJsonPath('round.prompt_raw', 'машина у дома')
            ->assertJsonPath('round.prompt_clean', 'старая машина');
    }

    public function test_edited_prompt_cannot_be_only_forbidden_words(): void
    {
        Storage::fake('public');

        $room = Room::query()->create(['key' => 'demo']);

        $this->post("/host/{$room->key}/photos", [
            'photos' => [UploadedFile::fake()->image('car.jpg', 200, 200)],
        ])->assertRedirect();

        $photo = $room->photos()->firstOrFail();

        $this->post("/host/{$room->key}/photos/{$photo->id}/words", ['word' => 'голубой'])->assertRedirect();
        $this->post("/host/{$room->key}/photos/{$photo->id}/activate")->assertRedirect();

        $this->postJson("/play/{$room->key}/start")->assertOk();
        $this->postJson("/play/{$room->key}/submit", ['prompt' => 'машина'])->assertOk();

        $this->postJson("/play/{$room->key}/generate", ['prompt' => 'голубого'])
            ->assertStatus(422);

        $this->getJson("/play/{$room->key}/state")->assertJsonPath('status', 'prompted');
    }

    public function test_recorded_speech_is_transcribed_on_the_server(): void
    {
        Storage::fake('public');
        config(['prompt.fal_key' => 'test-key']);

        Http::fake([
            'rest.alpha.fal.ai/*' => Http::response([
                'upload_url' => 'https://v3b.fal.media/files/upload?sig=1',
                'file_url' => 'https://v3b.fal.media/files/speech.webm',
            ]),
            'v3b.fal.media/*' => Http::response('', 200),
            'fal.run/fal-ai/elevenlabs/*' => Http::response([
                'text' => 'Көк машина (laughs) рядом серая стена',
            ]),
        ]);

        $room = Room::query()->create(['key' => 'demo']);

        $this->post("/host/{$room->key}/photos", [
            'photos' => [UploadedFile::fake()->image('car.jpg', 200, 200)],
        ])->assertRedirect();

        $photo = $room->photos()->firstOrFail();
        $this->post("/host/{$room->key}/photos/{$photo->id}/activate")->assertRedirect();

        $audio = UploadedFile::fake()->create('speech.webm', 40, 'audio/webm');

        $this->post("/play/{$room->key}/transcribe", ['audio' => $audio])
            ->assertStatus(422);

        $this->postJson("/play/{$room->key}/start")->assertOk();

        $this->post("/play/{$room->key}/transcribe", ['audio' => $audio])
            ->assertOk()
            ->assertJsonPath('text', 'Көк машина рядом серая стена');

        Http::assertSent(fn ($request) => str_contains($request->url(), 'fal.run/fal-ai/elevenlabs/speech-to-text')
            && $request['audio_url'] === 'https://v3b.fal.media/files/speech.webm'
            && ! isset($request['language_code']));
    }

    public function test_transcription_falls_back_to_whisper(): void
    {
        Storage::fake('public');
        config(['prompt.fal_key' => 'test-key']);

        Http::fake([
            'rest.alpha.fal.ai/*' => Http::response([
                'upload_url' => 'https://v3b.fal.media/files/upload?sig=1',
                'file_url' => 'https://v3b.fal.media/files/speech.webm',
            ]),
            'v3b.fal.media/*' => Http::response('', 200),
            'fal.run/fal-ai/elevenlabs/*' => Http::response(['detail' => 'down'], 500),
            'fal.run/fal-ai/whisper' => Http::response(['text' => ' Қызыл үй. ']),
        ]);

        $room = Room::query()->create(['key' => 'demo']);

        $this->post("/host/{$room->key}/photos", [
            'photos' => [UploadedFile::fake()->image('house.jpg', 200, 200)],
        ])->assertRedirect();

        $photo = $room->photos()->firstOrFail();
        $this->post("/host/{$room->key}/photos/{$photo->id}/activate")->assertRedirect();
        $this->postJson("/play/{$room->key}/start")->assertOk();

        $this->post("/play/{$room->key}/transcribe", [
            'audio' => UploadedFile::fake()->create('speech.webm', 40, 'audio/webm'),
        ])->assertOk()->assertJsonPath('text', 'Қызыл үй.');
    }

    public function test_changing_active_photo_cancels_open_round(): void
    {
        Storage::fake('public');

        $room = Room::query()->create(['key' => 'demo']);

        $this->post("/host/{$room->key}/photos", [
            'photos' => [
                UploadedFile::fake()->image('one.jpg', 200, 200),
                UploadedFile::fake()->image('two.jpg', 200, 200),
            ],
        ])->assertRedirect();

        $photos = $room->photos()->orderBy('id')->get();
        $first = $photos[0];
        $second = $photos[1];

        $this->post("/host/{$room->key}/photos/{$first->id}/activate")->assertRedirect();
        $this->postJson("/play/{$room->key}/start")->assertOk();
        $this->postJson("/play/{$room->key}/submit", [
            'prompt' => 'синий дом у реки',
        ])->assertJsonPath('status', 'prompted');

        $this->post("/host/{$room->key}/photos/{$second->id}/activate")->assertRedirect();

        $this->getJson("/play/{$room->key}/state")
            ->assertOk()
            ->assertJsonPath('status', 'lobby')
            ->assertJsonPath('can_start', true);

        $this->assertDatabaseHas('rounds', [
            'room_id' => $room->id,
            'room_photo_id' => $first->id,
            'status' => 'cancelled',
        ]);
    }

    public function test_host_can_delete_a_forbidden_word(): void
    {
        Storage::fake('public');

        $room = Room::query()->create(['key' => 'demo']);

        $this->post("/host/{$room->key}/photos", [
            'photos' => [UploadedFile::fake()->image('cat.jpg', 200, 200)],
        ])->assertRedirect();

        $photo = $room->photos()->firstOrFail();

        $this->post("/host/{$room->key}/photos/{$photo->id}/words", [
            'word' => 'кот',
        ])->assertRedirect();

        $word = $photo->forbiddenWords()->firstOrFail();

        $this->delete("/host/{$room->key}/photos/{$photo->id}/words/{$word->id}")
            ->assertRedirect();

        $this->assertDatabaseMissing('forbidden_words', ['id' => $word->id]);
    }

    public function test_forbidden_word_filter_uses_word_boundaries(): void
    {
        $filter = new ForbiddenWordFilter;

        $this->assertSame(
            'синий автомобиль у дома',
            $filter->clean('синий автомобиль у дома', ['кот'])
        );

        $this->assertSame(
            'большой сидит',
            $filter->clean('большой кот сидит', ['кот'])
        );
    }

    public function test_forbidden_word_filter_blocks_inflected_forms(): void
    {
        $filter = new ForbiddenWordFilter;

        $this->assertSame(
            'машина, цвета, двери',
            $filter->clean('голубая машина, голубого цвета, голубые двери', ['голубой'])
        );

        $this->assertSame(
            ', , . котлета и который',
            $filter->clean('Кота, котики, котом. котлета и который', ['кот'])
        );

        $this->assertSame(
            'және',
            $filter->clean('мысығы мысықтар және мысықтардың', ['мысық'])
        );

        $this->assertSame(
            'жасыл',
            $filter->clean('бас киімі жасыл', ['бас киім'])
        );

        $this->assertSame('', $filter->clean('ёлки елку', ['ёлка']));
    }
}
