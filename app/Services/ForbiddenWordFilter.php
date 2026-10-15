<?php

namespace App\Services;

/**
 * Blocks a forbidden word together with its inflected forms
 * (голубой → голубая, голубого; мысық → мысығы, мысықтар).
 *
 * Patterns use only syntax shared by PCRE and JavaScript `u` regexes,
 * so the browser receives the exact same patterns via patternsFor().
 */
class ForbiddenWordFilter
{
    private const MIN_STEM = 3;

    private const MAX_SUFFIXES = 3;

    private const RUSSIAN_ENDINGS = [
        // adjectives
        'ая', 'яя', 'ое', 'ее', 'ые', 'ие', 'ый', 'ий', 'ой', 'ей',
        'ого', 'его', 'ому', 'ему', 'ым', 'им', 'ыми', 'ими', 'ых', 'их', 'ую', 'юю', 'ою', 'ею',
        // nouns
        'а', 'я', 'о', 'е', 'ы', 'и', 'у', 'ю', 'ь',
        'ом', 'ем', 'ам', 'ям', 'ами', 'ями', 'ах', 'ях', 'ов', 'ев', 'ью', 'ья', 'ье', 'ьи', 'ьев',
        // diminutives
        'ик', 'ок', 'ек', 'ёк', 'чик', 'очк', 'ечк', 'еньк', 'оньк', 'ка', 'ки', 'ку', 'кой',
        // verbs
        'ть', 'ать', 'ять', 'ить', 'еть', 'уть', 'ал', 'ял', 'ил', 'ел', 'ла', 'ло', 'ли',
        'ет', 'ит', 'ут', 'ют', 'ат', 'ят', 'ешь', 'ишь', 'ем', 'им', 'ете', 'ите', 'сь', 'ся',
    ];

    private const KAZAKH_ENDINGS = [
        // plural
        'лар', 'лер', 'дар', 'дер', 'тар', 'тер',
        // possessive
        'ы', 'і', 'сы', 'сі', 'ым', 'ім', 'м', 'ың', 'ің', 'ң', 'мыз', 'міз', 'ңыз', 'ңіз', 'ңдар', 'ңдер',
        // cases
        'ның', 'нің', 'дың', 'дің', 'тың', 'тің',
        'ға', 'ге', 'қа', 'ке', 'на', 'не', 'а', 'е',
        'ны', 'ні', 'ды', 'ді', 'ты', 'ті', 'н',
        'да', 'де', 'та', 'те', 'нда', 'нде',
        'дан', 'ден', 'тан', 'тен', 'нан', 'нен',
        'мен', 'бен', 'пен',
        // derivational
        'лы', 'лі', 'сыз', 'сіз', 'дай', 'дей', 'тай', 'тей', 'ша', 'ше', 'шық', 'шік',
    ];

    /** Final consonants that voice before Kazakh vowel suffixes (мысық → мысығы). */
    private const ALTERNATIONS = [
        'қ' => '[қғ]',
        'к' => '[кг]',
        'п' => '[пб]',
    ];

    private static ?string $suffixAlternation = null;

    /**
     * @param  list<string>  $words
     */
    public function clean(string $text, array $words): string
    {
        $patterns = $this->patternsFor($words);

        if ($patterns === []) {
            return trim(preg_replace('/\s{2,}/u', ' ', $text) ?? $text);
        }

        $pattern = '/(?<![\p{L}\p{N}_])(?:'.implode('|', $patterns).')(?![\p{L}\p{N}_])/iu';

        $cleaned = preg_replace($pattern, '', $text) ?? $text;
        $cleaned = preg_replace('/[^\S\n]{2,}/u', ' ', $cleaned) ?? $cleaned;
        $cleaned = preg_replace('/\n{3,}/u', "\n\n", $cleaned) ?? $cleaned;

        return trim($cleaned);
    }

    /**
     * @param  list<string>  $words
     * @return list<string>
     */
    public function patternsFor(array $words): array
    {
        return array_map(fn (string $word) => $this->patternFor($word), $this->normalizeWords($words));
    }

    public function patternFor(string $word): string
    {
        $tokens = preg_split('/\s+/u', mb_strtolower(trim($word))) ?: [];

        return implode('\s+', array_map(fn (string $token) => $this->tokenPattern($token), $tokens));
    }

    /**
     * @param  list<string>  $words
     * @return list<string>
     */
    public function normalizeWords(array $words): array
    {
        $normalized = [];

        foreach ($words as $word) {
            $word = trim((string) $word);

            if ($word !== '') {
                $normalized[] = $word;
            }
        }

        return array_values(array_unique($normalized));
    }

    private function tokenPattern(string $token): string
    {
        if (mb_strlen($token) < self::MIN_STEM) {
            return $this->charsPattern($token, false);
        }

        return $this->charsPattern($this->stem($token), true)
            .'(?:'.self::suffixAlternation().'){0,'.self::MAX_SUFFIXES.'}';
    }

    private function stem(string $token): string
    {
        $best = $token;

        foreach (self::endings() as $ending) {
            if (! str_ends_with($token, $ending)) {
                continue;
            }

            $candidate = mb_substr($token, 0, mb_strlen($token) - mb_strlen($ending));

            if (mb_strlen($candidate) >= self::MIN_STEM && mb_strlen($candidate) < mb_strlen($best)) {
                $best = $candidate;
            }
        }

        return $best;
    }

    private function charsPattern(string $value, bool $alternateLast): string
    {
        $chars = mb_str_split($value);
        $last = count($chars) - 1;
        $pattern = '';

        foreach ($chars as $index => $char) {
            if ($char === 'е' || $char === 'ё') {
                $pattern .= '[её]';
            } elseif ($alternateLast && $index === $last && isset(self::ALTERNATIONS[$char])) {
                $pattern .= self::ALTERNATIONS[$char];
            } else {
                $pattern .= $this->escape($char);
            }
        }

        return $pattern;
    }

    /**
     * Escapes only characters that are syntax in both PCRE and JS `u` mode.
     */
    private function escape(string $char): string
    {
        return str_contains('\\^$.|?*+()[]{}/', $char) ? '\\'.$char : $char;
    }

    /**
     * @return list<string>
     */
    private static function endings(): array
    {
        $endings = array_values(array_unique([...self::RUSSIAN_ENDINGS, ...self::KAZAKH_ENDINGS]));
        usort($endings, fn (string $a, string $b) => mb_strlen($b) <=> mb_strlen($a));

        return $endings;
    }

    private static function suffixAlternation(): string
    {
        return self::$suffixAlternation ??= implode('|', array_map(
            fn (string $ending) => strtr($ending, ['е' => '[её]', 'ё' => '[её]']),
            self::endings()
        ));
    }
}
