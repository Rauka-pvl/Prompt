function csrfToken() {
    return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';
}

function escapeRegExp(value) {
    return value.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
}

export function promptGame(config) {
    return {
        status: 'lobby',
        hasActivePhoto: false,
        canStart: false,
        photo: null,
        round: null,
        prompt: '',
        editedPrompt: '',
        editRoundId: null,
        error: '',
        loading: false,
        flashedWord: '',
        dismissedRoundId: null,
        barReady: false,
        similarityShown: 0,
        pollId: null,
        listening: false,
        speechSupported: Boolean(window.SpeechRecognition || window.webkitSpeechRecognition),
        spokenRaw: '',
        interimText: '',
        recognition: null,
        mediaStream: null,
        recordLimit: 30,
        secondsLeft: 30,
        timerId: null,
        strings: config.strings || {},
        lang: config.lang || 'ru-RU',
        fallbackLang: config.fallbackLang || 'kk-KZ',
        currentSpeechLang: config.lang || 'ru-RU',
        fallbackUsed: false,
        heardSpeech: false,

        get viewStatus() {
            if (this.status === 'done' && this.round && this.dismissedRoundId === this.round.id) {
                return 'lobby';
            }

            return this.status;
        },

        get spokenPreview() {
            return [this.spokenRaw, this.interimText].filter(Boolean).join(' ').trim();
        },

        get hasSpeech() {
            return this.cleanPrompt(this.spokenPreview).trim() !== '';
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
            try {
                const response = await window.axios.get(config.stateUrl);
                this.applyState(response.data);
            } catch (error) {
                this.error = error.response?.data?.message || this.strings.state_error || '';
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
                this.prompt = '';
                this.spokenRaw = '';
                this.interimText = '';
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
                this.prompt = '';
                this.spokenRaw = '';
                this.interimText = '';
                this.secondsLeft = this.recordLimit;
                this.applyState(response.data);
            } catch (error) {
                this.error = error.response?.data?.message || this.strings.start_error || '';
            } finally {
                this.loading = false;
            }
        },

        applySpokenText(raw) {
            const cleaned = this.cleanPrompt(raw);

            if (cleaned !== raw) {
                const removed = this.findRemovedWord(raw, cleaned);
                if (removed) {
                    this.flashedWord = removed.toLowerCase();
                    setTimeout(() => {
                        if (this.flashedWord === removed.toLowerCase()) {
                            this.flashedWord = '';
                        }
                    }, 400);
                }
            }

            this.spokenRaw = raw.trim();
            this.prompt = cleaned.trim();
        },

        async toggleMic() {
            if (this.listening) {
                this.stopMic();
                return;
            }

            await this.startMic();
        },

        async startMic() {
            const SpeechRecognition = window.SpeechRecognition || window.webkitSpeechRecognition;

            if (!SpeechRecognition) {
                this.error = this.strings.mic_unsupported || '';
                return;
            }

            this.error = '';
            this.fallbackUsed = false;
            this.heardSpeech = false;
            this.currentSpeechLang = this.lang;

            try {
                this.mediaStream = await navigator.mediaDevices.getUserMedia({ audio: true });
            } catch {
                this.error = this.strings.mic_denied || '';
                return;
            }

            this.listening = true;
            this.startRecordTimer();
            this.attachRecognition(SpeechRecognition, this.lang);
        },

        attachRecognition(SpeechRecognition, language) {
            this.detachRecognition();

            this.recognition = new SpeechRecognition();
            this.recognition.lang = language;
            this.recognition.continuous = true;
            this.recognition.interimResults = true;
            this.currentSpeechLang = language;

            this.recognition.onresult = (event) => {
                this.heardSpeech = true;
                let finalChunk = '';
                let interim = '';

                for (let i = event.resultIndex; i < event.results.length; i++) {
                    const piece = event.results[i][0]?.transcript ?? '';

                    if (event.results[i].isFinal) {
                        finalChunk += `${piece} `;
                    } else {
                        interim += piece;
                    }
                }

                if (finalChunk.trim() !== '') {
                    const nextRaw = `${this.spokenRaw} ${finalChunk}`.replace(/\s+/g, ' ').trim();
                    this.applySpokenText(nextRaw);
                }

                this.interimText = interim.trim();
            };

            this.recognition.onerror = (event) => {
                if (event.error === 'not-allowed' || event.error === 'service-not-allowed') {
                    this.error = this.strings.mic_blocked || '';
                    this.stopMic();
                    return;
                }

                if (event.error === 'no-speech' || event.error === 'aborted' || event.error === 'network') {
                    return;
                }

                this.error = this.strings.mic_recognition_error || '';
            };

            this.recognition.onend = () => {
                if (!this.listening) {
                    return;
                }

                const tooShort = this.spokenRaw.trim().length < 3;
                let nextLang = this.currentSpeechLang;

                if (!this.fallbackUsed && tooShort) {
                    this.fallbackUsed = true;
                    nextLang = this.currentSpeechLang === this.lang ? this.fallbackLang : this.lang;
                } else if (!tooShort) {
                    nextLang = this.currentSpeechLang === this.lang ? this.fallbackLang : this.lang;
                }

                if (nextLang !== this.currentSpeechLang) {
                    this.attachRecognition(SpeechRecognition, nextLang);
                    return;
                }

                try {
                    this.recognition.start();
                } catch {
                    // Chrome sometimes restarts while still starting.
                }
            };

            try {
                this.recognition.start();
            } catch {
                // already starting
            }
        },

        detachRecognition() {
            if (!this.recognition) {
                return;
            }

            this.recognition.onresult = null;
            this.recognition.onerror = null;
            this.recognition.onend = null;

            try {
                this.recognition.stop();
            } catch {
                // already stopped
            }

            this.recognition = null;
        },

        startRecordTimer() {
            this.clearRecordTimer();
            this.secondsLeft = this.recordLimit;
            this.timerId = setInterval(() => {
                this.secondsLeft -= 1;

                if (this.secondsLeft <= 0) {
                    this.secondsLeft = 0;
                    this.stopMic();
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
            this.interimText = '';
            this.detachRecognition();

            if (this.mediaStream) {
                this.mediaStream.getTracks().forEach((track) => track.stop());
                this.mediaStream = null;
            }
        },

        async submitPrompt() {
            this.stopMic();
            this.loading = true;
            this.error = '';

            try {
                const response = await window.axios.post(config.submitUrl, {
                    prompt: this.spokenRaw.trim() || this.prompt,
                }, {
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

        backToLobby() {
            this.dismissedRoundId = this.round?.id ?? null;
            this.status = 'lobby';
            this.barReady = false;
        },
    };
}
