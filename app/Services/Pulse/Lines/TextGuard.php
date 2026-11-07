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
    private const TLDS = 'com|net|org|co\.za|org\.za|za|io|app|me|ly|link|xyz|info|biz|shop|store|online|site|dev|ai|tv|cc|gl|to|ws|us|uk|co|africa|cloud|click|page|website|tech|live|club|space|top|vip|fun';

    public const UNLOCK_MESSAGE = 'Links and contact details unlock once they reply. Tell them what you need and they can take it from there.';

    /**
     * Codes of what was found: link, email, phone, handle, app. Empty when the text is plain.
     *
     * @return list<string>
     */
    public function violations(string $text): array
    {
        $found = [];
        $lower = mb_strtolower($text);

        if (preg_match('~(?:https?|ftp)://|\bwww\s*\.|\b(?:bit\.ly|tinyurl\.com|t\.co|wa\.me|t\.me)\b~i', $text)
            || preg_match('~\b(?:[a-z0-9][a-z0-9-]*\.)+(?:'.self::TLDS.')\b~i', $text)
            || preg_match('~\b(?:dot|\(dot\)|\[dot\]|\[\.\])\s*(?:com|net|org|co|za)\b~i', $text)) {
            $found[] = 'link';
        }

        if (preg_match('~[a-z0-9._%+-]+\s*(?:@|\(at\)|\[at\])\s*[a-z0-9-]+(?:\.|\s*(?:dot|\(dot\))\s*)[a-z]{2,}~i', $text)
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

        return array_values(array_unique($found));
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

        if (! class_exists($class)) {
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
