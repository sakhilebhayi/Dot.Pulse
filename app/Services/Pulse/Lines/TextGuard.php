<?php

namespace App\Services\Pulse\Lines;

use App\Models\User;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Text rules for first contact. Until the other side has replied, a message may not carry a link, an email
 * address, a phone number, a handle or a pointer to another app: contact details unlock once they accept.
 * Also the one place that asks the deterministic moderation rules (Discovery\Moderation\LocalRules, written by
 * another agent) for a verdict when that class exists.
 */
class TextGuard
{
    private const TLDS = 'com|net|org|co\.za|org\.za|za|io|app|me|ly|link|xyz|info|biz|shop|store|online|site|dev|ai|tv|cc|gl|to|ws|us|uk|co|africa|cloud|click|page|website|tech|live|club|space|top|vip|fun|ru|tk|ml|ga|cf|gq|pw|cn|de|fr|nl|ng|ke|gh|zw|bw|na|mz|zm|ug|tz|ae|br|mx|es|ca|au|nz|ch|se|pl|ua|kz|su|ir|pk|bd|ph|vn|sg|hk|jp|kr|eu|asia|mobi|zip|casino|icu|monster|buzz|cyou|sbs';

    public const UNLOCK_MESSAGE = 'Links and contact details unlock once they reply. Tell them what you need and they can take it from there.';

    /**
     * Codes of what was found: link, email, phone, handle, app. Empty when the text is plain.
     *
     * The text is checked as written and again after it is flattened (see flatten): zero width characters, look-alike
     * and full width characters, HTML entities, spelled out dots and ats, spaced out letters and digits in other
     * scripts all collapse to the plain form the rules below know.
     *
     * @return list<string>
     */
    public function violations(string $text): array
    {
        $found = $this->scan($text);

        [$flat, $leet] = $this->flatten($text);

        foreach ([$flat, $leet] as $variant) {
            if ($variant !== $text) {
                $found = array_merge($found, $this->scan($variant));
            }
        }

        return array_values(array_unique($found));
    }

    /** @return list<string> */
    private function scan(string $text): array
    {
        $found = [];
        $lower = mb_strtolower($text);

        if (preg_match('~(?:https?|ftp)://|\bwww\s*\.|\b(?:bit\.ly|tinyurl\.com|t\.co|wa\.me|t\.me)\b~i', $text)
            || preg_match('~\b(?:[a-z0-9][a-z0-9-]*\.)+(?:'.self::TLDS.')\b~i', $text)
            || preg_match('~\b(?:dot|\(dot\)|\[dot\]|\[\.\])\s*(?:com|net|org|co|za)\b~i', $text)
            || preg_match('~(?<!\d)\d{1,3}(?:\.\d{1,3}){3}(?!\d)~', $text)) {
            $found[] = 'link';
        }

        if (preg_match('~[a-z0-9._%+-]+\s*(?:@|\(at\)|\[at\])\s*[a-z0-9-]+(?:\.|\s*(?:dot|\(dot\))\s*)[a-z]{2,}~i', $text)
            || preg_match('~\b[a-z0-9._%+-]+\s+at\s+[a-z0-9-]+\s*(?:\.|dot)\s*[a-z]{2,}\b~i', $text)
            || preg_match('~\b(?:gmail|yahoo|hotmail|outlook|icloud|proton\s*mail)\b~i', $lower)) {
            $found[] = 'email';
        }

        if ($this->hasPhoneNumber($text)) {
            $found[] = 'phone';
        }

        if (preg_match('~(?<![\w@])@[a-z0-9_.]{3,}~i', $text)) {
            $found[] = 'handle';
        }

        if (preg_match('~\b(?:whats\s*app|telegram|wechat|skype|insta(?:gram)?|snap(?:chat)?|facebook|messenger|signal\s+app)\b~i', $text)) {
            $found[] = 'app';
        }

        return $found;
    }

    /**
     * Two flattened copies of the text for detection only (never stored or shown): the first keeps digits and
     * punctuation exact; the second also folds leetspeak (d0t, wh4tsapp) for the word rules.
     *
     * @return array{0: string, 1: string}
     */
    public function flatten(string $text): array
    {
        $t = $text;

        // HTML entities, twice, so &amp;#46; cannot hide a dot either.
        for ($i = 0; $i < 2; $i++) {
            $decoded = html_entity_decode($t, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if ($decoded === $t) {
                break;
            }
            $t = $decoded;
        }

        if (class_exists(\Normalizer::class)) {
            $t = \Normalizer::normalize($t, \Normalizer::FORM_KC) ?: $t;
        }

        // Format characters (zero width space and joiners, soft hyphen, word joiner, bidi marks) and combining marks.
        $t = preg_replace('/[\p{Cf}\p{Mn}\x{FE00}-\x{FE0F}]+/u', '', $t) ?? $t;
        // Control characters and non breaking spaces become plain spaces.
        $t = preg_replace('/[\p{Cc}\p{Z}]+/u', ' ', $t) ?? $t;
        // Full stops, dashes and ats in other scripts.
        $t = strtr($t, ["\u{3002}" => '.', "\u{FF61}" => '.', "\u{FE52}" => '.', "\u{2024}" => '.', "\u{FF0E}" => '.', "\u{2027}" => '.', "\u{00B7}" => '.',
            "\u{2010}" => '-', "\u{2011}" => '-', "\u{2012}" => '-', "\u{2013}" => '-', "\u{2014}" => '-', "\u{2212}" => '-', "\u{FF1A}" => ':', "\u{FF20}" => '@', "\u{FE6B}" => '@']);
        $t = $this->asciiDigits($t);
        $t = $this->latinLookalikes($t);
        $t = mb_strtolower($t);

        // Number words: seven or more in a row is a phone number said out loud.
        $t = preg_replace_callback('~(?:\b(?:zero|oh|one|two|three|four|five|six|seven|eight|nine)\b[\s,.\-]*){7,}~', function ($m) {
            $map = ['zero' => 0, 'oh' => 0, 'one' => 1, 'two' => 2, 'three' => 3, 'four' => 4, 'five' => 5, 'six' => 6, 'seven' => 7, 'eight' => 8, 'nine' => 9];

            return ' '.preg_replace_callback('~[a-z]+~', fn ($w) => (string) $map[$w[0]], $m[0]).' ';
        }, $t) ?? $t;

        $t = $this->closeUp($t);

        $leet = $this->closeUp(strtr($t, ['0' => 'o', '1' => 'i', '3' => 'e', '4' => 'a', '5' => 's', '7' => 't', '$' => 's', '!' => 'i']));

        return [$t, $leet];
    }

    /** Joins spaced out letters and digits, and folds spelled out dots and ats into the real characters. */
    private function closeUp(string $t): string
    {
        // "w w w . e v i l . c o m", "0 8 2 1 2 3": runs of three or more single characters lose their separators.
        $t = preg_replace_callback('~(?<![\p{L}\p{N}])(?:[\p{L}\p{N}][ \t._\-*]+){2,}[\p{L}\p{N}](?![\p{L}\p{N}])~u', fn ($m) => preg_replace('~[ \t_\-*]+~', '', $m[0]) ?? $m[0], $t) ?? $t;

        // Spelled out punctuation. A bare "dot" or "at" counts only next to something that looks like an address.
        $t = preg_replace('~\s*[\(\[\{]\s*dot\s*[\)\]\}]\s*~', '.', $t) ?? $t;
        $t = preg_replace('~\s*[\(\[\{]\s*at\s*[\)\]\}]\s*~', '@', $t) ?? $t;
        $t = preg_replace('~(?<=[a-z0-9])\s+dot\s+(?=[a-z0-9])~', '.', $t) ?? $t;
        $t = preg_replace('~(?<=[a-z0-9])\s+at\s+(?=[a-z0-9-]+\.[a-z])~', '@', $t) ?? $t;

        // "evil . com" and "evil .com": a dot with a space on both sides, or before it, joins the words around it.
        $t = preg_replace('~(?<=[a-z0-9])\s+\.\s+(?=[a-z0-9])~', '.', $t) ?? $t;
        $t = preg_replace('~(?<=[a-z0-9])\s+\.(?=[a-z0-9])~', '.', $t) ?? $t;

        return $t;
    }

    private function asciiDigits(string $t): string
    {
        return preg_replace_callback('/\p{Nd}/u', function ($m) {
            $ch = $m[0];
            if (class_exists(\IntlChar::class)) {
                $v = \IntlChar::charDigitValue($ch);

                return $v >= 0 ? (string) $v : $ch;
            }
            $cp = mb_ord($ch, 'UTF-8');
            foreach ([0x0660, 0x06F0, 0x0966, 0x09E6, 0xFF10] as $zero) {
                if ($cp >= $zero && $cp <= $zero + 9) {
                    return (string) ($cp - $zero);
                }
            }

            return $ch;
        }, $t) ?? $t;
    }

    /** Cyrillic and Greek letters that look like Latin ones. */
    private function latinLookalikes(string $t): string
    {
        return strtr($t, [
            "\u{0430}" => 'a', "\u{0435}" => 'e', "\u{043E}" => 'o', "\u{0440}" => 'p', "\u{0441}" => 'c', "\u{0445}" => 'x',
            "\u{0443}" => 'y', "\u{0456}" => 'i', "\u{0458}" => 'j', "\u{0455}" => 's', "\u{04BB}" => 'h', "\u{0501}" => 'd',
            "\u{0410}" => 'A', "\u{0412}" => 'B', "\u{0415}" => 'E', "\u{041A}" => 'K', "\u{041C}" => 'M', "\u{041D}" => 'H',
            "\u{041E}" => 'O', "\u{0420}" => 'P', "\u{0421}" => 'C', "\u{0422}" => 'T', "\u{0425}" => 'X',
            "\u{03BF}" => 'o', "\u{03B1}" => 'a', "\u{03BD}" => 'v', "\u{03C1}" => 'p', "\u{03B9}" => 'i', "\u{0261}" => 'g',
            "\u{0131}" => 'i', "\u{1D00}" => 'a', "\u{0274}" => 'n', "\u{1D1B}" => 't',
        ]);
    }

    public function hasPhoneNumber(string $text): bool
    {
        // Dates and times are not phone numbers.
        $text = preg_replace('~\b\d{4}-\d{2}-\d{2}\b|\b\d{1,2}[/.-]\d{1,2}[/.-]\d{2,4}\b~', ' ', $text);

        if (! preg_match_all('~(?<!\d)(\+?\d[\d\s().\-]{6,}\d)~', $text, $m)) {
            return false;
        }

        foreach ($m[1] as $candidate) {
            $digits = strlen(preg_replace('/\D/', '', $candidate));
            $leading = $candidate[0] === '+' || $candidate[0] === '0';

            if ($digits >= 10 || ($digits >= 9 && $leading)) {
                return true;
            }
        }

        return false;
    }

    /** @throws ValidationException when the text carries a link or contact detail. */
    public function assertPlain(string $text, string $field = 'body'): void
    {
        if ($this->violations($text) !== []) {
            throw ValidationException::withMessages([$field => self::UNLOCK_MESSAGE]);
        }
    }

    /** Single line text: tags stripped, control characters removed, whitespace collapsed. */
    public function line(?string $text, int $max): string
    {
        $text = strip_tags((string) $text);
        $text = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $text) ?? '';
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? '');

        return mb_substr($text, 0, $max);
    }

    /** Multi line text: tags stripped, control characters other than newlines removed, runs of blank lines collapsed. */
    public function block(?string $text, int $max): string
    {
        $text = strip_tags((string) $text);
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = preg_replace('/[^\P{C}\n]+/u', '', $text) ?? '';
        $text = preg_replace("/\n{3,}/", "\n\n", $text) ?? '';

        return mb_substr(trim($text), 0, $max);
    }

    /**
     * The deterministic moderation verdict, when the rules class exists: 'allow', 'hold' or 'block'. Without it
     * (or if it fails) the text is allowed: the rules in this class still apply, and a person can always report.
     *
     * @return array{verdict: string, flags: array}
     */
    public function moderate(string $text, User $author, array $ctx = []): array
    {
        $class = 'App\\Services\\Pulse\\Discovery\\Moderation\\LocalRules';

        if (! class_exists($class) && ! app()->bound($class)) {
            return ['verdict' => 'allow', 'flags' => []];
        }

        try {
            $result = app($class)->assess($text, $author, $ctx);
        } catch (Throwable $e) {
            report($e);

            return ['verdict' => 'allow', 'flags' => ['rules_unavailable']];
        }

        return ['verdict' => $result['verdict'] ?? 'allow', 'flags' => $result['flags'] ?? []];
    }

    /** @throws ValidationException when the rules block the text. */
    public function assertNotBlocked(string $text, User $author, string $field, array $ctx = []): array
    {
        $result = $this->moderate($text, $author, $ctx);

        if ($result['verdict'] === 'block') {
            throw ValidationException::withMessages([$field => 'That text breaks the Pulse rules, so it was not sent. Reword it and try again.']);
        }

        return $result;
    }
}
