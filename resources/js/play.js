import { ChunkedSpeechRecorder, speechRecordingSupported } from './speech-recorder';

function csrfToken() {
    return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';
}

function escapeRegExp(value) {
    return value.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
}

function escapeHtml(value) {
    return value.replace(/[&<>"']/g, (char) => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
    })[char]);
}

const recordTailMs = 700;

function readDismissedRound(key) {
    try {
        const value = Number(window.localStorage.getItem(key));

        return Number.isFinite(value) && value > 0 ? value : null;
    } catch {
        return null;
    }
}

function storeDismissedRound(key, roundId) {
    try {
        if (roundId) {
            window.localStorage.setItem(key, String(roundId));
        } else {
            window.localStorage.removeItem(key);
        }
    } catch {
        // localStorage can be unavailable in private mode.
    }
}

export function promptGame(config) {
    const dismissedKey = `prompt:dismissed:${config.stateUrl}`;
    // Kept outside Alpine's reactive state: MediaRecorder and AudioContext break when proxied.
    let speech = null;
    let finishing = null;

    return {
        status: 'lobby',
        hasActivePhoto: false,
        canStart: false,
        photo: null,
        round: null,
        editedPrompt: '',
        editRoundId: null,
        error: '',
        loading: false,
        flashedWord: '',
        dismissedRoundId: readDismissedRound(dismissedKey),
        barReady: false,
        similarityShown: 0,
        pollId: null,
        refreshing: false,
        failedRefreshes: 0,
        listening: false,
        speechSupported: speechRecordingSupported(),
        spokenRaw: '',
        pendingChunks: 0,
        transcribing: false,
        recordLimit: 30,
        secondsLeft: 30,
        timerId: null,
        strings: config.strings || {},

        get viewStatus() {
            if (this.status === 'done' && this.round && this.dismissedRoundId === this.round.id) {
                return 'lobby';
            }

            return this.status;
        },

        get hasSpeech() {
            return this.spokenRaw.trim() !== '';
        },

        get liveHtml() {
            const html = escapeHtml(this.spokenRaw);
            const patterns = this.wordPatterns();

            if (patterns.length === 0) {
                return html;
            }

            return html.replace(
                this.wordRegExp(patterns.join('|'), 'giu'),
                (word) => `<mark class="play-live-forbidden">${word}</mark>`,
            );
        },

        get formattedTime() {
            return `0:${String(Math.max(0, this.secondsLeft)).padStart(2, '0')}`;
        },

        get timerProgress() {
            return `${(Math.max(0, this.secondsLeft) / this.recordLimit) * 100}%`;
        },

        init() {
            this.refresh();
            this.pollId = setInterval(() => this.refresh(), 2000);
            this.$watch('viewStatus', (value) => {
                if (value !== 'playing') {
                    this.stopMic();
                }
            });
        },

        destroy() {
            if (this.pollId) {
                clearInterval(this.pollId);
            }

            this.stopMic();
        },

        async refresh() {
            if (this.refreshing) {
                return;
            }

            this.refreshing = true;

            try {
                const response = await window.axios.get(config.stateUrl, { timeout: 10000 });
                this.applyState(response.data);
                this.failedRefreshes = 0;

                if (this.error === this.strings.state_error) {
                    this.error = '';
                }
            } catch {
                this.failedRefreshes += 1;

                if (this.failedRefreshes >= 3) {
                    this.error = this.strings.state_error || '';
                }
            } finally {
                this.refreshing = false;
            }
        },

        applyState(data) {
            const previousStatus = this.status;

            if (data.status === 'lobby' || (previousStatus === 'prompted' && data.status !== 'prompted')) {
                this.loading = false;
            }

            this.status = data.status;
            this.hasActivePhoto = data.has_active_photo;
            this.canStart = data.can_start;
            this.photo = data.photo?.url ? data.photo : this.photo;
            this.round = data.round;

            if (data.status === 'playing' && data.photo?.url) {
                this.photo = data.photo;
            }

            if (data.status === 'lobby' && previousStatus !== 'lobby') {
                this.spokenRaw = '';
                this.secondsLeft = this.recordLimit;
                this.stopMic();
            }

            if (data.status === 'prompted' && data.round && this.editRoundId !== data.round.id) {
                this.editRoundId = data.round.id;
                this.editedPrompt = data.round.prompt_clean || '';
            }

            if (data.status === 'done' && previousStatus !== 'done' && this.dismissedRoundId !== data.round?.id) {
                this.animateSimilarity(data.round?.similarity ?? 0);
            }
        },

        wordPatterns() {
            const words = this.photo?.words || [];
            const patterns = this.photo?.patterns || [];

            return words.map((word, index) => patterns[index] || escapeRegExp(word));
        },

        wordRegExp(source, flags) {
            return new RegExp(`(?<![\\p{L}\\p{N}_])(?:${source})(?![\\p{L}\\p{N}_])`, flags);
        },

        cleanPrompt(text) {
            const patterns = this.wordPatterns();

            if (patterns.length === 0) {
                return text;
            }

            return text
                .replace(this.wordRegExp(patterns.join('|'), 'giu'), '')
                .replace(/[^\S\n]{2,}/g, ' ');
        },

        onEditInput(event) {
            const next = event.target.value;
            const cleaned = this.cleanPrompt(next);

            if (cleaned !== next) {
                const removed = this.findRemovedWord(next, cleaned);
                if (removed) {
                    this.flashedWord = removed.toLowerCase();
                    setTimeout(() => {
                        if (this.flashedWord === removed.toLowerCase()) {
                            this.flashedWord = '';
                        }
                    }, 400);
                }

                const caret = Math.max(0, event.target.selectionStart - (next.length - cleaned.length));
                event.target.value = cleaned;
                event.target.setSelectionRange(caret, caret);
            }

            this.editedPrompt = cleaned;
        },

        findRemovedWord(before, after) {
            const words = this.photo?.words || [];
            const patterns = this.wordPatterns();

            for (let i = 0; i < words.length; i++) {
                const pattern = this.wordRegExp(patterns[i], 'iu');
                if (pattern.test(before) && !pattern.test(after)) {
                    return words[i];
                }
            }

            return '';
        },

        async startRound() {
            this.loading = true;
            this.error = '';
            this.dismissedRoundId = null;

            try {
                const response = await window.axios.post(config.startUrl, {}, {
                    headers: { 'X-CSRF-TOKEN': csrfToken() },
                });
                this.spokenRaw = '';
                this.secondsLeft = this.recordLimit;
                this.applyState(response.data);
            } catch (error) {
                this.error = error.response?.data?.message || this.strings.start_error || '';
            } finally {
                this.loading = false;
            }
        },

        async toggleMic() {
            if (this.listening) {
                await this.finishRecording();
                return;
            }

            await this.startMic();
        },

        async startMic() {
            if (!this.speechSupported) {
                this.error = this.strings.mic_unsupported || '';
                return;
            }

            if (this.listening || finishing) {
                return;
            }

            this.error = '';
            this.spokenRaw = '';
            this.pendingChunks = 0;

            const recorder = new ChunkedSpeechRecorder({
                transcribe: (blob, extension, signal) => this.transcribeChunk(blob, extension, signal),
                onUpdate: ({ text, pending }) => {
                    if (speech === recorder) {
                        this.spokenRaw = text;
                        this.pendingChunks = pending;
                    }
                },
            });

            speech = recorder;

            try {
                await recorder.start();
            } catch {
                recorder.abort();
                speech = null;
                this.error = this.strings.mic_denied || '';
                return;
            }

            if (speech !== recorder) {
                recorder.abort();
                return;
            }

            this.listening = true;
            this.startRecordTimer();
        },

        async transcribeChunk(blob, extension, signal) {
            const form = new FormData();
            form.append('audio', blob, `speech.${extension}`);

            const response = await window.axios.post(config.transcribeUrl, form, {
                headers: { 'X-CSRF-TOKEN': csrfToken() },
                timeout: 45000,
                signal,
            });

            return (response.data?.text || '').trim();
        },

        /**
         * Stops recording and resolves once every segment is transcribed,
         * so the final text always contains the last words.
         */
        finishRecording() {
            if (finishing) {
                return finishing;
            }

            const recorder = speech;

            if (!recorder || !this.listening) {
                return Promise.resolve();
            }

            this.clearRecordTimer();
            this.listening = false;
            this.transcribing = true;

            finishing = recorder.stop(recordTailMs)
                .then(({ text, failed }) => {
                    if (speech !== recorder) {
                        return;
                    }

                    this.spokenRaw = text;

                    if (failed > 0) {
                        this.error = this.strings.transcribe_error || '';
                    }
                })
                .finally(() => {
                    if (speech === recorder) {
                        speech = null;
                    }

                    finishing = null;
                    this.transcribing = false;
                    this.pendingChunks = 0;
                });

            return finishing;
        },

        startRecordTimer() {
            this.clearRecordTimer();
            this.secondsLeft = this.recordLimit;
            this.timerId = setInterval(() => {
                this.secondsLeft -= 1;

                if (this.secondsLeft <= 0) {
                    this.secondsLeft = 0;
                    this.finishRecording();
                }
            }, 1000);
        },

        clearRecordTimer() {
            if (this.timerId) {
                clearInterval(this.timerId);
                this.timerId = null;
            }
        },

        stopMic() {
            this.clearRecordTimer();
            this.listening = false;
            this.pendingChunks = 0;

            if (speech) {
                speech.abort();
                speech = null;
            }
        },

        async submitPrompt() {
            this.loading = true;
            this.error = '';
            await this.finishRecording();

            const prompt = this.spokenRaw.trim();

            if (prompt === '' || this.viewStatus !== 'playing') {
                this.loading = false;
                return;
            }

            try {
                const response = await window.axios.post(config.submitUrl, { prompt }, {
                    headers: { 'X-CSRF-TOKEN': csrfToken() },
                });
                this.applyState(response.data);
            } catch (error) {
                this.error = error.response?.data?.message || error.response?.data?.errors?.prompt?.[0] || this.strings.submit_error || '';
            } finally {
                this.loading = false;
            }
        },

        async generateImage() {
            this.loading = true;
            this.error = '';

            try {
                const response = await window.axios.post(config.generateUrl, {
                    prompt: this.editedPrompt.trim(),
                }, {
                    headers: { 'X-CSRF-TOKEN': csrfToken() },
                    timeout: 120000,
                });
                this.applyState(response.data);
                this.animateSimilarity(response.data.round?.similarity ?? 0);
            } catch (error) {
                this.error = error.response?.data?.message || this.strings.generate_error || '';
            } finally {
                this.loading = false;
            }
        },

        animateSimilarity(value) {
            this.barReady = false;
            this.similarityShown = 0;
            const target = Math.round(Number(value) || 0);

            requestAnimationFrame(() => {
                this.barReady = true;
            });

            const started = performance.now();
            const duration = 5000;

            const tick = (now) => {
                const progress = Math.min(1, (now - started) / duration);
                const eased = progress < 0.5
                    ? 4 * progress ** 3
                    : 1 - (-2 * progress + 2) ** 3 / 2;
                this.similarityShown = Math.round(target * eased);

                if (progress < 1) {
                    requestAnimationFrame(tick);
                } else {
                    this.similarityShown = target;
                }
            };

            requestAnimationFrame(tick);
        },

        async abandonRound() {
            this.loading = true;
            this.error = '';

            try {
                const response = await window.axios.post(config.resetUrl, {}, {
                    headers: { 'X-CSRF-TOKEN': csrfToken() },
                });
                this.applyState(response.data);
            } catch (error) {
                this.error = error.response?.data?.message || this.strings.reset_error || '';
            } finally {
                this.loading = false;
            }
        },

        async goBack() {
            if (this.loading) {
                return;
            }

            const status = this.viewStatus;

            if (status === 'lobby') {
                window.location.href = config.homeUrl;
                return;
            }

            if (status === 'done') {
                this.backToLobby();
                return;
            }

            const spoken = this.round?.prompt_raw || this.spokenRaw;
            this.stopMic();
            this.loading = true;
            this.error = '';

            try {
                const response = await window.axios.post(config.backUrl, {}, {
                    headers: { 'X-CSRF-TOKEN': csrfToken() },
                });

                if (status === 'prompted') {
                    this.editRoundId = null;
                    this.secondsLeft = this.recordLimit;
                    this.spokenRaw = (spoken || '').trim();
                }

                this.applyState(response.data);
            } catch (error) {
                this.error = error.response?.data?.message || this.strings.reset_error || '';
            } finally {
                this.loading = false;
            }
        },

        backToLobby() {
            this.dismissedRoundId = this.round?.id ?? null;
            storeDismissedRound(dismissedKey, this.dismissedRoundId);
            this.status = 'lobby';
            this.barReady = false;
        },
    };
}
