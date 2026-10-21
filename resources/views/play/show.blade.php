@extends('layouts.app')

@section('title', __('messages.game.title'))
@section('html_class', 'play-html')
@section('body_class', 'play-page')
@section('shell_class', 'play-shell')

@section('header')
    <header class="play-header">
        <div class="flex min-w-0 items-center gap-3">
            <button
                type="button"
                class="play-back"
                x-data
                @click="$dispatch('play-back')"
                aria-label="{{ __('messages.play.back') }}"
            >
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <path d="M15 18l-6-6 6-6"/>
                </svg>
                <span>{{ __('messages.play.back') }}</span>
            </button>
            <a href="{{ route('home') }}" class="truncate font-display text-xl font-extrabold text-gold">{{ __('messages.game.title') }}</a>
        </div>
        @include('partials.locale-switcher')
    </header>
@endsection

@section('content')
    <div
        @play-back.window="goBack()"
        x-data="promptGame({
            homeUrl: @js(route('home')),
            backUrl: @js(route('play.back', $room)),
            stateUrl: @js(route('play.state', $room)),
            startUrl: @js(route('play.start', $room)),
            transcribeUrl: @js(route('play.transcribe', $room)),
            submitUrl: @js(route('play.submit', $room)),
            generateUrl: @js(route('play.generate', $room)),
            resetUrl: @js(route('play.reset', $room)),
            strings: @js(__('messages.js')),
        })"
        class="play-app"
    >
        <template x-if="error">
            <div class="status-banner status-banner-error" x-text="error"></div>
        </template>

        <section class="play-stage play-stage-center" :class="viewStatus !== 'lobby' && 'is-hidden'" x-cloak>
            <p class="play-kicker">{{ __('messages.play.screen_kicker') }}</p>
            <h1 class="font-display text-3xl font-extrabold sm:text-5xl">{{ __('messages.play.lobby_title') }}</h1>
            <p class="play-lead" x-text="hasActivePhoto ? @js(__('messages.play.lobby_ready')) : @js(__('messages.play.lobby_wait'))"></p>
            <button
                type="button"
                class="btn-gold play-btn"
                :disabled="!canStart || loading"
                @click="startRound()"
            >
                <span x-text="loading ? @js(__('messages.play.starting')) : @js(__('messages.play.start'))"></span>
            </button>
        </section>

        <section class="play-stage play-stage-playing" :class="viewStatus !== 'playing' && 'is-hidden'" x-cloak>
            <div class="play-photo card-panel">
                <img :src="photo?.url" alt="{{ __('messages.play.photo_alt') }}">
            </div>

            <aside class="play-controls card-panel">
                <div class="play-words">
                    <p class="play-kicker">{{ __('messages.play.forbidden') }}</p>
                    <div class="play-chips">
                        <template x-for="word in (photo?.words || [])" :key="word">
                            <span
                                class="chip chip-danger"
                                :class="flashedWord === word ? 'flash-word' : ''"
                                x-text="word"
                            ></span>
                        </template>
                    </div>
                </div>

                <div class="play-mic-block">
                    <button
                        type="button"
                        class="mic-button"
                        :class="listening ? 'is-listening' : ''"
                        :disabled="!speechSupported || loading || transcribing"
                        @click="toggleMic()"
                    >
                        <span class="mic-pulse" x-show="listening"></span>
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor">
                            <path d="M12 14a3 3 0 0 0 3-3V6a3 3 0 1 0-6 0v5a3 3 0 0 0 3 3Zm5-3a5 5 0 0 1-10 0H5a7 7 0 0 0 6 6.92V20H8v2h8v-2h-3v-2.08A7 7 0 0 0 19 11h-2Z"/>
                        </svg>
                    </button>
                    <p class="play-timer" :class="listening && secondsLeft <= 10 ? 'is-urgent' : ''" x-show="listening" x-text="formattedTime"></p>
                    <div class="play-timer-track" x-show="listening">
                        <div class="play-timer-fill" :class="secondsLeft <= 10 ? 'is-urgent' : ''" :style="`--left: ${timerProgress}`"></div>
                    </div>
                    <p class="play-mic-status" :class="listening ? 'is-live' : ''">
                        <span x-show="!speechSupported">{{ __('messages.play.mic_unsupported') }}</span>
                        <span x-show="speechSupported && listening">{{ __('messages.play.mic_listening') }}</span>
                        <span x-show="transcribing">{{ __('messages.play.mic_transcribing') }}</span>
                        <span x-show="speechSupported && !listening && !transcribing && secondsLeft === 0 && !hasSpeech">{{ __('messages.play.mic_timeout_empty') }}</span>
                        <span x-show="speechSupported && !listening && !transcribing && secondsLeft === 0 && hasSpeech">{{ __('messages.play.mic_timeout_ready') }}</span>
                        <span x-show="speechSupported && !listening && !transcribing && secondsLeft !== 0 && !hasSpeech">{{ __('messages.play.mic_idle_empty') }}</span>
                        <span x-show="speechSupported && !listening && !transcribing && secondsLeft !== 0 && hasSpeech">{{ __('messages.play.mic_idle_ready') }}</span>
                    </p>
                </div>

                <div class="play-live" x-show="listening || transcribing || hasSpeech">
                    <p class="play-kicker">{{ __('messages.play.live_kicker') }}</p>
                    <p class="play-live-text" x-show="hasSpeech" x-html="liveHtml"></p>
                    <p class="play-live-placeholder" x-show="!hasSpeech">{{ __('messages.play.live_placeholder') }}</p>
                    <p class="play-live-pending" x-show="listening && pendingChunks > 0">
                        <span class="play-live-dot"></span>{{ __('messages.play.mic_transcribing') }}
                    </p>
                    <p class="play-live-hint" x-show="hasSpeech && (photo?.words || []).length">{{ __('messages.play.live_hint') }}</p>
                </div>

                <button
                    type="button"
                    class="btn-gold play-btn"
                    :disabled="loading || transcribing || (!listening && !hasSpeech)"
                    @click="submitPrompt()"
                >
                    <span x-text="loading ? @js(__('messages.play.saving')) : @js(__('messages.play.review_button'))"></span>
                </button>
            </aside>
        </section>

        <section class="play-stage play-stage-review" :class="viewStatus !== 'prompted' && 'is-hidden'" x-cloak>
            <div class="play-review card-panel">
                <p class="play-kicker">{{ __('messages.play.review_kicker') }}</p>
                <p class="play-quote play-quote-soft" x-text="round?.prompt_raw || spokenRaw"></p>
                <div class="play-review-clean">
                    <label for="play-edit" class="play-kicker">{{ __('messages.play.edit_kicker') }}</label>
                    <textarea
                        id="play-edit"
                        class="play-edit"
                        rows="4"
                        maxlength="4000"
                        placeholder="{{ __('messages.play.edit_placeholder') }}"
                        :value="editedPrompt"
                        :disabled="loading"
                        @input="onEditInput($event)"
                    ></textarea>
                    <div class="play-chips mt-3" x-show="(photo?.words || []).length">
                        <template x-for="word in (photo?.words || [])" :key="word">
                            <span
                                class="chip chip-danger"
                                :class="flashedWord === word ? 'flash-word' : ''"
                                x-text="word"
                            ></span>
                        </template>
                    </div>
                </div>
            </div>
            <button
                type="button"
                class="btn-gold play-btn"
                :disabled="loading || editedPrompt.trim() === ''"
                @click="generateImage()"
            >
                <span x-text="loading ? @js(__('messages.play.generating')) : @js(__('messages.play.generate_button'))"></span>
            </button>
            <button
                type="button"
                class="btn-ghost play-btn"
                @click="abandonRound()"
            >
                {{ __('messages.play.new_round') }}
            </button>
        </section>

        <section class="play-stage play-stage-done" :class="viewStatus !== 'done' && 'is-hidden'" x-cloak>
            <div class="play-compare">
                <figure class="card-panel play-compare-card">
                    <img :src="round?.original_url" alt="{{ __('messages.play.original') }}">
                    <figcaption>{{ __('messages.play.original') }}</figcaption>
                </figure>
                <figure class="card-panel play-compare-card">
                    <img :src="round?.generated_url" alt="{{ __('messages.play.generated') }}">
                    <figcaption>{{ __('messages.play.generated') }}</figcaption>
                </figure>
            </div>
            <div class="play-score card-panel">
                <p class="play-score-prompt" x-text="round?.prompt_clean"></p>
                <div class="play-score-row">
                    <span class="play-kicker">{{ __('messages.play.similarity') }}</span>
                    <strong class="font-display text-gold"><span x-text="similarityShown"></span>%</strong>
                </div>
                <div class="similarity-track" :class="barReady ? 'is-animating' : ''">
                    <div class="similarity-fill" :class="barReady ? 'is-ready' : ''" :style="`--pct: ${round?.similarity || 0}%`"></div>
                    <div class="similarity-glow"></div>
                </div>
                <button type="button" class="btn-ghost play-btn" @click="backToLobby()">{{ __('messages.play.again') }}</button>
            </div>
        </section>
    </div>
@endsection
