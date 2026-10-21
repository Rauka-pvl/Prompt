<?php

namespace App\Services;

/**
 * Blocks a forbidden word together with its inflected forms
 * (голубой → голубая, голубого; мысық → мысығы, мысықтар; машина → машинаға).
 *
 * Endings attach in fixed slots instead of arbitrary chains, so unrelated
 * words that merely start with the same letters survive (кот ≠ котлета, көк ≠ көктем).
 *
 * Patterns use only syntax shared by PCRE and JavaScript `u` regexes,
 * so the browser receives the exact same patterns via patternsFor().
 */
class ForbiddenWordFilter
{
    private const MIN_STEM = 3;

    private const KAZAKH_LETTERS = ['ә', 'ғ', 'қ', 'ң', 'ө', 'ұ', 'ү', 'һ', 'і'];

    private const RUSSIAN_DIMINUTIVES = ['ик', 'ок', 'ек', 'ёк', 'чик', 'ичк', 'очк', 'ечк', 'оньк', 'еньк', 'к'];

    private const RUSSIAN_ENDINGS = [
        // adjectives
        'ая', 'яя', 'ое', 'ее', 'ые', 'ие', 'ый', 'ий', 'ой', 'ей',
        'ого', 'его', 'ому', 'ему', 'ым', 'им', 'ыми', 'ими', 'ых', 'их', 'ую', 'юю', 'ою', 'ею',
        // nouns
        'а', 'я', 'о', 'е', 'ы', 'и', 'у', 'ю', 'ь',
        'ом', 'ем', 'ам', 'ям', 'ами', 'ями', 'ах', 'ях', 'ов', 'ев', 'ью', 'ья', 'ье', 'ьи', 'ьев',
        // verbs
        'ть', 'ать', 'ять', 'ить', 'еть', 'уть', 'ал', 'ял', 'ил', 'ел', 'ала', 'ило', 'ила', 'ели', 'ала', 'али', 'или',
        'ет', 'ит', 'ут', 'ют', 'ат', 'ят', 'ешь', 'ишь', 'ете', 'ите', 'ем', 'им',
    ];

    private const RUSSIAN_REFLEXIVE = ['ся', 'сь'];

    private const KAZAKH_PLURAL = ['лар', 'лер', 'дар', 'дер', 'тар', 'тер'];

    private const KAZAKH_POSSESSIVE = [
        'ы', 'і', 'сы', 'сі', 'ым', 'ім', 'м', 'ың', 'ің', 'ң',
        'мыз', 'міз', 'ымыз', 'іміз', 'ңыз', 'ңіз', 'ыңыз', 'іңіз', 'ңдар', 'ңдер',
    ];

    private const KAZAKH_CASES = [
        'ның', 'нің', 'дың', 'дің', 'тың', 'тің',
        'ға', 'ге', 'қа', 'ке', 'на', 'не', 'а', 'е',
        'ны', 'ні', 'ды', 'ді', 'ты', 'ті', 'н',
        'да', 'де', 'та', 'те', 'нда', 'нде',
        'дан', 'ден', 'тан', 'тен', 'нан', 'нен',
        'мен', 'бен', 'пен',
    ];

    /** Final consonants that voice before Kazakh vowel suffixes (мысық → мысығы). */
    private const ALTERNATIONS = [
        'қ' => '[қғ]', 'ғ' => '[қғ]',
        'к' => '[кг]', 'г' => '[кг]',
        'п' => '[пб]', 'б' => '[пб]',
    ];

    /** @var array<string, string> */
    private static array $tails = [];

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
        $cleaned = preg_replace('/\s+([,.;:!?])/u', '$1', $cleaned) ?? $cleaned;
        $cleaned = preg_replace('/^[\s,.;:!?]+/u', '', $cleaned) ?? $cleaned;
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

        $branches = [];

        if (! $this->isKazakh($token)) {
            $branches[] = $this->charsPattern($this->stem($token, self::RUSSIAN_ENDINGS), false).self::tail('ru');
        }

        // Single letters are never stripped from Kazakh words: киім is a stem, not киі + м.
        $kazakhStem = $this->isKazakh($token)
            ? $this->stem($token, array_filter(
                [...self::KAZAKH_PLURAL, ...self::KAZAKH_POSSESSIVE, ...self::KAZAKH_CASES],
                fn (string $ending) => mb_strlen($ending) > 1
            ))
            : $this->stem($token, self::KAZAKH_PLURAL);

        $branches[] = $this->charsPattern($kazakhStem, true).self::tail('kk');

        return '(?:'.implode('|', array_unique($branches)).')';
    }

    private function isKazakh(string $token): bool
    {
        foreach (self::KAZAKH_LETTERS as $letter) {
            if (str_contains($token, $letter)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<string>  $endings
     */
    private function stem(string $token, array $endings): string
    {
        $best = $token;

        foreach ($endings as $ending) {
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

    private static function tail(string $language): string
    {
        return self::$tails[$language] ??= $language === 'ru'
            ? self::slot(self::RUSSIAN_DIMINUTIVES).self::slot(self::RUSSIAN_ENDINGS).self::slot(self::RUSSIAN_REFLEXIVE)
            : self::slot(self::KAZAKH_PLURAL).self::slot(self::KAZAKH_POSSESSIVE).self::slot(self::KAZAKH_CASES);
    }

    /**
     * @param  list<string>  $endings
     */
    private static function slot(array $endings): string
    {
        $endings = array_values(array_unique($endings));
        usort($endings, fn (string $a, string $b) => mb_strlen($b) <=> mb_strlen($a));

        return '(?:'.implode('|', array_map(
            fn (string $ending) => strtr($ending, ['е' => '[её]', 'ё' => '[её]']),
            $endings
        )).')?';
    }
}
