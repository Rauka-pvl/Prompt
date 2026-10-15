@extends('layouts.app')

@section('title', __('messages.admin.title_login'))

@section('content')
    <section class="mx-auto flex min-h-[70dvh] max-w-md flex-col justify-center">
        <h1 class="font-display text-3xl font-extrabold sm:text-4xl">{{ __('messages.admin.headline') }}</h1>
        <p class="mt-3 text-sm text-white/60 sm:text-base">{{ __('messages.admin.lead') }}</p>

        <form action="{{ route('admin.login.submit') }}" method="POST" class="card-panel mt-6 rounded-3xl p-5 sm:mt-8 sm:p-6">
            @csrf
            <label for="password" class="mb-2 block text-sm text-white/70">{{ __('messages.admin.password') }}</label>
            <input
                id="password"
                name="password"
                type="password"
                class="w-full rounded-2xl border border-white/10 bg-black/30 px-4 py-3 outline-none ring-gold/40 focus:ring-2"
            >
            @error('password')
                <p class="mt-2 text-sm text-red-300">{{ $message }}</p>
            @enderror

            <button class="btn-gold mt-6 w-full rounded-2xl px-4 py-3">{{ __('messages.admin.login') }}</button>
        </form>
    </section>
@endsection
