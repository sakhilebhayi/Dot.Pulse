<?php

namespace App\Services\Pulse\Lines;

use App\Models\User;
use App\Notifications\StepUpCode;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * Step-up confirmation for downloading and erasing your data. Pulse has no passwords to re-ask (everyone signs in
 * through InfoDot, and a recent-sign-in check would need an InfoDot round trip), so the person confirms in Pulse itself:
 * they type the phrase for the action and, when mail is configured, a 6 digit code emailed to them that works once for
 * 10 minutes. Without mail the typed phrase is the whole step, which is stated plainly in docs/programme/R41_PRIVACY.md.
 *
 * A code is stored only as a keyed hash, tried at most 5 times, and replaced by a newer one. Sending is limited to 3
 * an hour per person and action.
 */
class StepUp
{
    public const EXPORT = 'export';

    public const ERASE = 'erase';

    public const PHRASES = [self::EXPORT => 'download my data', self::ERASE => 'erase my data'];

    private const MAX_TRIES = 5;

    public static function phrase(string $action): string
    {
        return self::PHRASES[$action] ?? throw new \InvalidArgumentException("Unknown step-up action [{$action}].");
    }

    /** Whether a code is part of the step: forced by config, or automatic when a real mailer is set up. */
    public static function codeRequired(): bool
    {
        return match ((string) config('pulse.privacy.step_up_code', 'auto')) {
            'on', '1', 'true' => true,
            'off', '0', 'false' => false,
            default => ! in_array((string) config('mail.default'), ['log', 'array', 'null', ''], true),
        };
    }

    /** @throws ValidationException when sent too often */
    public function sendCode(User $user, string $action): void
    {
        self::phrase($action);

        $limit = "pulse-stepup-send:{$action}:{$user->id}";

        if (RateLimiter::tooManyAttempts($limit, 3)) {
            throw ValidationException::withMessages(['code' => 'A code was sent a few times already. Try again in an hour.']);
        }

        RateLimiter::hit($limit, 3600);

        $minutes = (int) config('pulse.privacy.step_up_minutes', 10);
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        Cache::put($this->key($user, $action), ['hash' => $this->hash($user, $action, $code), 'tries' => 0], now()->addMinutes($minutes));

        $user->notify(new StepUpCode($code, $action, $minutes));
        AuditLog::record($user, 'privacy.stepup_sent', null, ['action' => $action]);
    }

    /**
     * Whether the typed phrase and (when required) the code are right. A wrong code counts as a try; a right one is used up.
     *
     * @return string|null the thing to tell the person when it is not right, or null when it is
     */
    public function problem(User $user, string $action, string $typed, string $code): ?string
    {
        if (mb_strtolower(trim($typed)) !== self::phrase($action)) {
            return 'Type "'.self::phrase($action).'" exactly to go on.';
        }

        if (! self::codeRequired()) {
            return null;
        }

        $code = preg_replace('/\D/', '', $code) ?? '';
        $entry = Cache::get($this->key($user, $action));

        if ($entry === null || strlen($code) !== 6) {
            return $entry === null ? 'Ask for a code first. It arrives by email and works for 10 minutes.' : 'Enter the 6 digit code from the email.';
        }

        if ((int) $entry['tries'] >= self::MAX_TRIES) {
            Cache::forget($this->key($user, $action));

            return 'Too many wrong codes. Ask for a new one.';
        }

        if (! hash_equals($entry['hash'], $this->hash($user, $action, $code))) {
            Cache::put($this->key($user, $action), ['hash' => $entry['hash'], 'tries' => $entry['tries'] + 1], now()->addMinutes((int) config('pulse.privacy.step_up_minutes', 10)));
            AuditLog::record($user, 'privacy.stepup_failed', null, ['action' => $action]);

            return 'That code is not right.';
        }

        Cache::forget($this->key($user, $action));

        return null;
    }

    private function key(User $user, string $action): string
    {
        return "pulse:stepup:{$action}:{$user->id}";
    }

    private function hash(User $user, string $action, string $code): string
    {
        return hash_hmac('sha256', "{$user->id}|{$action}|{$code}", (string) config('app.key'));
    }
}
