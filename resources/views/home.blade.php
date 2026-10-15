@extends('layouts.app')

@section('title', __('messages.home.title'))

@section('content')
    <section class="mx-auto flex min-h-[70dvh] max-w-xl flex-col justify-center">
        <p class="mb-3 text-xs uppercase tracking-[0.25em] text-gold/70 sm:text-sm">{{ __('messages.home.kicker') }}</p>
        <h1 class="font-display text-4xl font-extrabold leading-none sm:text-5xl md:text-6xl">{{ __('messages.home.headline_1') }}<br>{{ __('messages.home.headline_2') }}</h1>
        <p class="mt-4 text-sm text-white/60 sm:mt-5 sm:text-base">{{ __('messages.home.lead') }}</p>

        <div class="mt-6 grid grid-cols-2 gap-3 sm:mt-8">
            @foreach (['kk' => 'Қазақша', 'ru' => 'Русский'] as $code => $label)
                <form action="{{ route('locale') }}" method="POST">
                    @csrf
                    <input type="hidden" name="locale" value="{{ $code }}">
                    <button
                        type="submit"
                        class="w-full rounded-2xl px-4 py-3 font-semibold {{ app()->getLocale() === $code ? 'btn-gold' : 'btn-ghost' }}"
                    >{{ $label }}</button>
                </form>
            @endforeach
        </div>

        <form action="{{ route('enter') }}" method="POST" class="card-panel mt-4 rounded-3xl p-5 sm:p-8">
            @csrf
            <label for="key" class="mb-2 block text-sm text-white/70">{{ __('messages.home.key_label') }}</label>
            <input
                id="key"
                name="key"
                type="text"
                value="{{ old('key') }}"
                autocomplete="off"
                spellcheck="false"
                class="w-full rounded-2xl border border-white/10 bg-black/30 px-4 py-3 text-lg outline-none ring-gold/40 focus:ring-2"
                placeholder="{{ __('messages.home.key_placeholder') }}"
            >
            @error('key')
                <p class="mt-2 text-sm text-red-300">{{ $message }}</p>
            @enderror

            <div class="mt-6 grid grid-cols-1 gap-3 sm:grid-cols-2">
                <button name="role" value="host" class="btn-ghost rounded-2xl px-4 py-3 font-semibold">
                    {{ __('messages.home.role_host') }}
                </button>
                <button name="role" value="play" class="btn-gold rounded-2xl px-4 py-3">
                    {{ __('messages.home.role_player') }}
                </button>
            </div>
        </form>
    </section>
@endsection
