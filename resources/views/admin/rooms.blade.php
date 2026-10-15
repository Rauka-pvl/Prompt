@extends('layouts.app')

@section('title', __('messages.admin.title_rooms'))

@section('nav')
    <form action="{{ route('admin.logout') }}" method="POST">
        @csrf
        <button class="btn-ghost rounded-full px-4 py-2">{{ __('messages.admin.logout') }}</button>
    </form>
@endsection

@section('content')
    <div class="flex flex-col gap-5 lg:flex-row lg:gap-8">
        <section class="card-panel w-full rounded-3xl p-5 sm:p-6 lg:max-w-sm">
            <h1 class="font-display text-2xl font-extrabold sm:text-3xl">{{ __('messages.admin.new_key') }}</h1>
            <p class="mt-2 text-sm text-white/55">{{ __('messages.admin.key_rules') }}</p>

            <form action="{{ route('admin.rooms.store') }}" method="POST" class="mt-6">
                @csrf
                <input
                    name="key"
                    value="{{ old('key') }}"
                    class="w-full rounded-2xl border border-white/10 bg-black/30 px-4 py-3 outline-none ring-gold/40 focus:ring-2"
                    placeholder="party-01"
                >
                @error('key')
                    <p class="mt-2 text-sm text-red-300">{{ $message }}</p>
                @enderror
                <input
                    name="name"
                    value="{{ old('name') }}"
                    maxlength="120"
                    class="mt-3 w-full rounded-2xl border border-white/10 bg-black/30 px-4 py-3 outline-none ring-gold/40 focus:ring-2"
                    placeholder="{{ __('messages.admin.name_placeholder') }}"
                >
                @error('name')
                    <p class="mt-2 text-sm text-red-300">{{ $message }}</p>
                @enderror
                <button class="btn-gold mt-4 w-full rounded-2xl px-4 py-3">{{ __('messages.admin.create') }}</button>
            </form>
        </section>

        <section class="card-panel min-w-0 flex-1 rounded-3xl p-5 sm:p-6">
            <h2 class="font-display text-2xl font-extrabold sm:text-3xl">{{ __('messages.admin.rooms') }}</h2>
            <div class="admin-rooms-cards mt-5">
                @forelse ($rooms as $room)
                    <article class="rounded-2xl border border-white/8 bg-black/20 p-4">
                        <p class="font-semibold text-gold-soft">{{ $room->key }}</p>
                        @if ($room->name)
                            <p class="mt-0.5 text-sm text-white/75">{{ $room->name }}</p>
                        @endif
                        <p class="mt-1 text-sm text-white/55">{{ __('messages.admin.games') }} {{ $room->plays_count }} · {{ __('messages.admin.photos') }} {{ $room->photos_count }}</p>
                        <p class="mt-1 text-xs text-white/40">{{ $room->created_at->format('d.m.Y H:i') }}</p>
                        <div class="mt-3 flex flex-wrap gap-2">
                            <a href="{{ route('host.show', $room) }}" class="btn-ghost rounded-full px-3 py-1.5 text-sm">{{ __('messages.admin.host') }}</a>
                            <form action="{{ route('admin.rooms.destroy', $room) }}" method="POST" onsubmit="return confirm('{{ __('messages.admin.delete_confirm', ['key' => $room->key]) }}')">
                                @csrf
                                @method('DELETE')
                                <button class="rounded-full px-3 py-1.5 text-sm text-red-300 hover:bg-red-500/10">{{ __('messages.admin.delete') }}</button>
                            </form>
                        </div>
                    </article>
                @empty
                    <p class="py-6 text-white/45">{{ __('messages.admin.empty') }}</p>
                @endforelse
            </div>
            <div class="admin-rooms-table-wrap mt-6 overflow-x-auto">
                <table class="admin-rooms-table w-full min-w-[520px] text-left text-sm">
                    <thead class="text-white/45">
                        <tr>
                            <th class="pb-3 font-medium">{{ __('messages.admin.col_key') }}</th>
                            <th class="pb-3 font-medium">{{ __('messages.admin.col_name') }}</th>
                            <th class="pb-3 font-medium">{{ __('messages.admin.col_games') }}</th>
                            <th class="pb-3 font-medium">{{ __('messages.admin.col_photos') }}</th>
                            <th class="pb-3 font-medium">{{ __('messages.admin.col_created') }}</th>
                            <th class="pb-3 font-medium"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($rooms as $room)
                            <tr class="border-t border-white/8">
                                <td class="py-3 font-semibold text-gold-soft">{{ $room->key }}</td>
                                <td class="py-3 text-white/75">{{ $room->name ?? '—' }}</td>
                                <td class="py-3">{{ $room->plays_count }}</td>
                                <td class="py-3">{{ $room->photos_count }}</td>
                                <td class="py-3 text-white/55">{{ $room->created_at->format('d.m.Y H:i') }}</td>
                                <td class="py-3 text-right">
                                    <div class="flex justify-end gap-2">
                                        <a href="{{ route('host.show', $room) }}" class="btn-ghost rounded-full px-3 py-1.5">{{ __('messages.admin.host') }}</a>
                                        <form action="{{ route('admin.rooms.destroy', $room) }}" method="POST" onsubmit="return confirm('{{ __('messages.admin.delete_confirm', ['key' => $room->key]) }}')">
                                            @csrf
                                            @method('DELETE')
                                            <button class="rounded-full px-3 py-1.5 text-red-300 hover:bg-red-500/10">{{ __('messages.admin.delete') }}</button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="py-8 text-white/45">{{ __('messages.admin.empty') }}</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>
    </div>
@endsection
