<div class="flex items-center rounded-full border border-white/10 bg-black/20 p-0.5 text-xs font-semibold">
    @foreach (['kk' => 'ҚАЗ', 'ru' => 'РУС'] as $code => $label)
        <form action="{{ route('locale') }}" method="POST">
            @csrf
            <input type="hidden" name="locale" value="{{ $code }}">
            @if ($localeScope ?? null)
                <input type="hidden" name="scope" value="{{ $localeScope }}">
            @endif
            <button
                type="submit"
                class="rounded-full px-2.5 py-1 transition {{ app()->getLocale() === $code ? 'bg-gold text-black' : 'text-white/55 hover:text-white' }}"
                @if (app()->getLocale() === $code) aria-current="true" @endif
            >{{ $label }}</button>
        </form>
    @endforeach
</div>
