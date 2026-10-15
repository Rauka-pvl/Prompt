@extends('layouts.app')

@section('title', __('messages.host.title').' — '.$room->key)

@section('nav')
    <span class="max-w-[40vw] truncate text-xs sm:max-w-none sm:text-sm">{{ __('messages.host.key') }} <strong class="text-gold-soft">{{ $room->key }}</strong></span>
    <a href="{{ route('play.show', $room) }}" class="btn-ghost rounded-full px-3 py-1.5 text-xs text-white sm:px-4 sm:py-2 sm:text-sm">{{ __('messages.host.role_player') }}</a>
@endsection

@section('content')
    <div class="mb-5 flex flex-wrap items-end justify-between gap-3 sm:mb-8 sm:gap-4">
        <div class="min-w-0">
            <p class="text-xs uppercase tracking-[0.25em] text-gold/70 sm:text-sm">{{ __('messages.host.kicker') }}</p>
            <h1 class="font-display mt-1 text-2xl font-extrabold sm:mt-2 sm:text-4xl">{{ __('messages.host.headline') }}</h1>
            <p class="mt-2 max-w-2xl text-sm text-white/55">{{ __('messages.host.lead') }}</p>
        </div>
        <div class="card-panel rounded-2xl px-4 py-3 text-sm text-white/70">
            {{ __('messages.host.plays') }} <span class="font-semibold text-gold">{{ $room->plays_count }}</span>
        </div>
    </div>

    @if ($errors->any())
        <div class="mb-6 rounded-2xl border border-red-400/30 bg-red-500/10 px-4 py-3 text-sm text-red-200">
            {{ $errors->first() }}
        </div>
    @endif

    <form action="{{ route('host.photos.upload', $room) }}" method="POST" enctype="multipart/form-data" class="card-panel mb-5 rounded-3xl p-4 sm:mb-8 sm:p-6">
        @csrf
        <label class="mb-3 block text-sm text-white/70">{{ __('messages.host.upload_label') }}</label>
        <div class="flex flex-col gap-3 sm:flex-row">
            <input
                type="file"
                name="photos[]"
                accept="image/jpeg,image/png,image/webp"
                multiple
                class="flex-1 rounded-2xl border border-white/10 bg-black/30 px-4 py-3 text-sm file:mr-4 file:rounded-full file:border-0 file:bg-gold file:px-4 file:py-2 file:text-sm file:font-semibold file:text-black"
            >
            <button class="btn-gold rounded-2xl px-6 py-3">{{ __('messages.host.upload_button') }}</button>
        </div>
        @error('photos')
            <p class="mt-2 text-sm text-red-300">{{ $message }}</p>
        @enderror
        @error('photos.*')
            <p class="mt-2 text-sm text-red-300">{{ $message }}</p>
        @enderror
    </form>

    @if ($room->photos->isEmpty())
        <div class="card-panel rounded-3xl p-10 text-center text-white/50">
            {{ __('messages.host.no_photos') }}
        </div>
    @else
        <div class="host-grid">
            @foreach ($room->photos as $photo)
                <article class="card-panel overflow-hidden rounded-3xl {{ $photo->is_active ? 'ring-2 ring-gold/60' : '' }}">
                    <div class="relative aspect-[4/3] bg-black/40">
                        <img src="{{ $photo->url() }}" alt="" class="h-full w-full object-cover">
                        @if ($photo->is_active)
                            <span class="absolute left-4 top-4 rounded-full bg-gold px-3 py-1 text-xs font-bold text-black">{{ __('messages.host.active') }}</span>
                        @endif
                    </div>
                    <div class="p-5">
                        <div class="mb-4 flex flex-wrap gap-2">
                            @foreach ($photo->forbiddenWords as $word)
                                <form action="{{ route('host.words.destroy', [$room, $photo, $word]) }}" method="POST">
                                    @csrf
                                    @method('DELETE')
                                    <button class="chip chip-danger" title="{{ __('messages.host.delete_word') }}">
                                        {{ $word->word }}
                                        <span aria-hidden="true">×</span>
                                    </button>
                                </form>
                            @endforeach
                            @if ($photo->forbiddenWords->isEmpty())
                                <span class="text-sm text-white/40">{{ __('messages.host.no_words') }}</span>
                            @endif
                        </div>

                        <form action="{{ route('host.words.store', [$room, $photo]) }}" method="POST" class="mb-4 flex flex-col gap-2 sm:flex-row">
                            @csrf
                            <input
                                name="word"
                                class="min-w-0 flex-1 rounded-xl border border-white/10 bg-black/30 px-3 py-2 text-sm outline-none ring-gold/40 focus:ring-2"
                                placeholder="{{ __('messages.host.word_placeholder') }}"
                            >
                            <button class="btn-ghost rounded-xl px-3 py-2 text-sm">{{ __('messages.host.add_word') }}</button>
                        </form>
                        <p class="-mt-2 mb-4 text-xs text-white/40">{{ __('messages.host.word_forms_hint') }}</p>

                        <div class="flex flex-wrap gap-2">
                            <form action="{{ route('host.photos.activate', [$room, $photo]) }}" method="POST">
                                @csrf
                                <button class="btn-gold rounded-xl px-4 py-2 text-sm" @disabled($photo->is_active)>
                                    {{ $photo->is_active ? __('messages.host.already_active') : __('messages.host.show_photo') }}
                                </button>
                            </form>
                            <form action="{{ route('host.photos.destroy', [$room, $photo]) }}" method="POST" onsubmit="return confirm('{{ __('messages.host.delete_photo_confirm') }}')">
                                @csrf
                                @method('DELETE')
                                <button class="rounded-xl px-4 py-2 text-sm text-red-300 hover:bg-red-500/10">{{ __('messages.host.delete_photo') }}</button>
                            </form>
                        </div>
                    </div>
                </article>
            @endforeach
        </div>
    @endif

    @if ($room->latestRound?->status === 'done')
        <section class="card-panel mt-10 rounded-3xl p-6">
            <h2 class="font-display text-2xl font-extrabold">{{ __('messages.host.latest_title') }}</h2>
            <p class="mt-2 text-white/55">{{ __('messages.host.latest_prompt') }} {{ $room->latestRound->prompt_clean }}</p>
            <p class="mt-2 text-gold">{{ __('messages.host.latest_similarity') }} {{ number_format($room->latestRound->similarity, 1) }}%</p>
            <div class="mt-5 grid gap-4 sm:grid-cols-2">
                <img src="{{ $room->latestRound->photo?->url() }}" alt="{{ __('messages.host.original_alt') }}" class="max-h-64 w-full rounded-2xl object-cover sm:max-h-80">
                @if ($room->latestRound->generatedUrl())
                    <img src="{{ $room->latestRound->generatedUrl() }}" alt="{{ __('messages.host.generated_alt') }}" class="max-h-64 w-full rounded-2xl object-cover sm:max-h-80">
                @endif
            </div>
        </section>
    @endif
@endsection
