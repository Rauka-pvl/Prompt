const MIME_TYPES = ['audio/webm;codecs=opus', 'audio/webm', 'audio/mp4', 'audio/ogg;codecs=opus'];

const TICK_MS = 50;
const MIN_SEGMENT_MS = 6000;
const SOFT_MAX_SEGMENT_MS = 10000;
const HARD_MAX_SEGMENT_MS = 14000;
const PAUSE_MS = 400;
const SHORT_PAUSE_MS = 150;
const MIN_VOICED_MS = 250;
const MIN_THRESHOLD = 0.012;

export function speechRecordingSupported() {
    return Boolean(window.MediaRecorder && navigator.mediaDevices?.getUserMedia);
}

function extensionFor(type) {
    if (type.includes('mp4')) {
        return 'm4a';
    }

    return type.includes('ogg') ? 'ogg' : 'webm';
}

/**
 * Records the microphone in segments cut at pauses, so every segment is
 * transcribed while the player is still talking and no word is split.
 */
export class ChunkedSpeechRecorder {
    constructor({ transcribe, onUpdate }) {
        this.transcribeSegment = transcribe;
        this.onUpdate = onUpdate;
        this.texts = [];
        this.joints = [];
        this.failed = 0;
        this.inFlight = new Set();
        this.cuts = new Set();
        this.controller = new AbortController();
        this.segment = null;
        this.nextIndex = 0;
        this.stopping = false;
        this.aborted = false;
        this.noiseFloor = MIN_THRESHOLD / 2;
    }

    get text() {
        let result = '';
        let previous = -1;

        this.texts.forEach((piece, index) => {
            let text = (piece || '').trim();

            if (text === '') {
                return;
            }

            if (result !== '' && !/[.!?…,;:]$/.test(result)) {
                if (this.joints[previous] === 'pause') {
                    result += '.';
                } else if (/^\p{Lu}\p{Ll}/u.test(text)) {
                    text = text[0].toLowerCase() + text.slice(1);
                }
            }

            result = result === '' ? text : `${result} ${text}`;
            previous = index;
        });

        return result.replace(/\s+/g, ' ').trim();
    }

    async start() {
        this.stream = await navigator.mediaDevices.getUserMedia({
            audio: { channelCount: 1, echoCancellation: true, noiseSuppression: true, autoGainControl: true },
        });

        this.mimeType = MIME_TYPES.find((type) => window.MediaRecorder.isTypeSupported?.(type)) || '';
        this.startMeter();
        this.startSegment();
    }

    startMeter() {
        const AudioContext = window.AudioContext || window.webkitAudioContext;

        if (!AudioContext) {
            return;
        }

        try {
            this.audioContext = new AudioContext();
            this.audioContext.resume?.();
            this.analyser = this.audioContext.createAnalyser();
            this.analyser.fftSize = 1024;
            this.audioContext.createMediaStreamSource(this.stream).connect(this.analyser);
            this.samples = new Float32Array(this.analyser.fftSize);
            this.meterId = setInterval(() => this.tick(), TICK_MS);
        } catch {
            this.analyser = null;
        }
    }

    level() {
        this.analyser.getFloatTimeDomainData(this.samples);
        let sum = 0;

        for (const sample of this.samples) {
            sum += sample * sample;
        }

        return Math.sqrt(sum / this.samples.length);
    }

    tick() {
        const segment = this.segment;

        if (!segment || this.stopping || !this.analyser) {
            return;
        }

        const level = this.level();
        const threshold = Math.max(MIN_THRESHOLD, this.noiseFloor * 3);
        const voiced = level > threshold;

        if (voiced) {
            segment.voicedMs += TICK_MS;
            segment.silenceMs = 0;
        } else {
            segment.silenceMs += TICK_MS;
            this.noiseFloor = this.noiseFloor * 0.95 + level * 0.05;
        }

        const age = performance.now() - segment.startedAt;
        const pauseCut = age >= MIN_SEGMENT_MS && segment.silenceMs >= PAUSE_MS;
        const softCut = age >= SOFT_MAX_SEGMENT_MS && segment.silenceMs >= SHORT_PAUSE_MS;

        if (pauseCut || softCut || age >= HARD_MAX_SEGMENT_MS) {
            this.joints[segment.index] = pauseCut ? 'pause' : 'split';
            this.cutSegment();
        }
    }

    startSegment() {
        const recorder = new MediaRecorder(this.stream, {
            audioBitsPerSecond: 24000,
            ...(this.mimeType ? { mimeType: this.mimeType } : {}),
        });

        const segment = {
            index: this.nextIndex++,
            recorder,
            chunks: [],
            startedAt: performance.now(),
            voicedMs: 0,
            silenceMs: 0,
        };

        recorder.ondataavailable = (event) => {
            if (event.data?.size) {
                segment.chunks.push(event.data);
            }
        };

        segment.stopped = new Promise((resolve) => {
            recorder.onstop = () => resolve();
        });

        recorder.start(1000);
        this.segment = segment;
    }

    cutSegment({ restart = true } = {}) {
        const segment = this.segment;

        if (!segment) {
            return Promise.resolve();
        }

        if (restart) {
            this.startSegment();
        } else {
            this.segment = null;
        }

        if (segment.recorder.state !== 'inactive') {
            segment.recorder.stop();
        }

        const done = segment.stopped.then(() => this.processSegment(segment));
        this.cuts.add(done);
        done.finally(() => this.cuts.delete(done));

        return done;
    }

    processSegment(segment) {
        if (this.aborted) {
            return;
        }

        // Without a level meter every segment is sent, since silence cannot be detected.
        const hasVoice = !this.analyser || segment.voicedMs >= MIN_VOICED_MS;
        const type = segment.recorder.mimeType || segment.chunks[0]?.type || 'audio/webm';
        const blob = new Blob(segment.chunks, { type });

        if (!hasVoice || blob.size < 1000) {
            return;
        }

        const task = this.send(segment.index, blob, extensionFor(type));
        this.inFlight.add(task);
        this.notify();
        task.finally(() => {
            this.inFlight.delete(task);
            this.notify();
        });
    }

    async send(index, blob, extension) {
        for (let attempt = 0; attempt < 2; attempt++) {
            try {
                const text = await this.transcribeSegment(blob, extension, this.controller.signal);

                if (!this.aborted) {
                    this.texts[index] = text;
                }

                return;
            } catch (error) {
                if (this.aborted || this.controller.signal.aborted) {
                    return;
                }

                if (attempt === 1) {
                    this.failed += 1;
                }
            }
        }
    }

    notify() {
        if (!this.aborted) {
            this.onUpdate?.({ text: this.text, pending: this.inFlight.size, failed: this.failed });
        }
    }

    /**
     * Keeps recording for `tailMs` (the last word is usually still being spoken),
     * then waits until every segment is transcribed.
     */
    async stop(tailMs = 0) {
        if (this.stopping) {
            return this.finished;
        }

        this.stopping = true;
        this.finished = (async () => {
            if (tailMs > 0) {
                await new Promise((resolve) => setTimeout(resolve, tailMs));
            }

            await this.cutSegment({ restart: false });
            this.release();

            while (this.cuts.size > 0 || this.inFlight.size > 0) {
                await Promise.allSettled([...this.cuts, ...this.inFlight]);
            }

            return { text: this.text, failed: this.failed };
        })();

        return this.finished;
    }

    abort() {
        this.aborted = true;
        this.stopping = true;
        this.controller.abort();

        if (this.segment && this.segment.recorder.state !== 'inactive') {
            this.segment.recorder.ondataavailable = null;
            this.segment.recorder.stop();
        }

        this.segment = null;
        this.release();
    }

    release() {
        if (this.meterId) {
            clearInterval(this.meterId);
            this.meterId = null;
        }

        this.audioContext?.close?.().catch(() => {});
        this.audioContext = null;
        this.stream?.getTracks().forEach((track) => track.stop());
        this.stream = null;
    }
}
