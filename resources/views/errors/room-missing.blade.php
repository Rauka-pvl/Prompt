@extends('layouts.app')

@section('title', __('messages.errors.room_title'))

@section('content')
    <section class="mx-auto flex min-h-[60dvh] max-w-lg flex-col items-center justify-center text-center">
        <p class="text-sm uppercase tracking-[0.25em] text-gold/70">404</p>
        <h1 class="font-display mt-3 text-3xl font-extrabold sm:text-4xl">{{ __('messages.errors.room_title') }}</h1>
        <p class="mt-4 text-white/60">{{ __('messages.errors.room_lead') }}</p>
        <a href="{{ route('home') }}" class="btn-gold mt-8 inline-flex rounded-2xl px-6 py-3">{{ __('messages.errors.to_home') }}</a>
    </section>
@endsection
