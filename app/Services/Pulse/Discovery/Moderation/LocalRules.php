<?php

namespace App\Services\Pulse\Discovery\Moderation;

use App\Models\PulsePost;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * The deterministic first pass over public text, with no model and no network. It decides whether something is
 * published at once (allow), waits for a person (hold) or is turned back with a reason the author can read (block).
 *
 * It looks for: blocked terms, threats, scam phrases that are common in South Africa (fake proof of payment, fees
 * for jobs and learnerships, advance-fee and inheritance stories, requests for PINs and OTPs, upfront loan fees,
 * deposits before a viewing, "double your money"), link density and shorteners, shouting, repeated text, the same post
 * again, contact details in public text, ID and card numbers, a very new account posting links, and posting speed.
 *
 * It never judges who a person is: no flag looks at a name, a place, a language, an age or a group.
 *
 * ctx keys (all optional): kind (post|offer|role|need|message|comment), title (appended to the text), public (bool,
 * default true: contact details are only flagged in public text), allow_contact (bool), exclude_post_id (a post
 * being re-assessed), recent_texts (list<string>: the author's recent texts of this kind, for duplicate checks),
 * recent_count (int: how many things the author made in the last hour, when the caller knows better than posts).
 */
class LocalRules
{
    public const ALLOW = 'allow';

    public const HOLD = 'hold';

    public const BLOCK = 'block';

    private const SHORTENERS = ['bit.ly', 'tinyurl.com', 't.co', 'goo.gl', 'ow.ly', 'is.gd', 'cutt.ly', 'rb.gy', 'wa.me', 't.me', 'shorturl.at', 'lnkd.in', 'tiny.cc', 'buff.ly', 'rebrand.ly'];

    /**
     * Scam patterns: code => [regex, severity, text for the author]. Order matters only for the reason shown first.
     *
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    private function scamPatterns(): array
    {
        $job = '(?:job|learnership|internship|vacanc|position|apprentice|hiring|recruit|employment|placement)';

        return [
            'scam_fake_pop_fee' => ['/(?:proof of payment|\bpop\b)[^.\n]{0,120}(?:courier|delivery|insurance|release|clearance) fee|(?:courier|delivery|insurance|release|clearance) fee[^.\n]{0,120}(?:proof of payment|\bpop\b)/iu', self::BLOCK,
                'It looks like the fake proof of payment scam, where a buyer "pays" and then asks for a fee to be sent back. It was not published.'],
            'scam_fake_pop' => ['/(?:proof of payment|\bpop\b)[^.\n]{0,60}(?:attached|sent|is in|below)|(?:check|see) (?:your )?(?:account|banking app)[^.\n]{0,40}(?:reflect|pending|paid)|payment (?:is )?pending[^.\n]{0,40}(?:send|release|dispatch)/iu', self::HOLD,
                'Talk of proof of payment is a common scam, so a person will check this first.'],
            'scam_job_fee' => ['/(?:pay|paying|payment of|deposit|send|transfer)[^.\n]{0,50}(?:registration|application|admin(?:istration)?|processing|training|uniform|placement|recruitment|medical|police clearance|visa|interview|screening)(?: fees?)?|(?:registration|application|admin(?:istration)?|placement|recruitment|training|uniform|screening) fees? (?:of|is|are|:|\-)? ?r? ?\d/iu', self::HOLD,
                'Asking for a fee for a job or learnership is a common scam, so a person will check this first.'],
            'scam_job_fee_block' => ['/(?:'.$job.')[^\n]{0,160}(?:pay|deposit|send|transfer)[^.\n]{0,40}r ?\d{2,}[^\n]{0,40}(?:fee|registration|admin|uniform|training|placement|application)|(?:registration|application|admin|uniform|training|placement) fees? (?:of )?r ?\d[^\n]{0,160}'.$job.'/iu', self::BLOCK,
                'Charging a fee to apply for a job or learnership is a scam pattern and is not allowed here. It was not published.'],
            'scam_advance_fee' => ['/next of kin|unclaimed (?:funds?|estate|inheritance)|beneficiary of (?:a |the )?(?:fund|estate|will|inheritance)|you have (?:won|been selected)|lottery (?:winner|draw)|diplomat(?:ic)? (?:consignment|box)|(?:million|billion) (?:usd|dollars|us dollars)|transfer of \$ ?\d|barrister[^.\n]{0,40}(?:fund|estate)/iu', self::BLOCK,
                'It matches an advance-fee story (an inheritance, lottery or fund that needs a payment first). It was not published.'],
            'scam_credentials' => ['/(?:send|give|share|provide|tell)[^.\n]{0,40}\b(?:otp|one[\s-]?time[\s-]?pin|pin number|your pin|password|cvv|card number|banking details|online banking|bank login)\b/iu', self::BLOCK,
                'Asking for a PIN, OTP, password or card details is not allowed. It was not published.'],
            'scam_loan_fee' => ['/(?:loan|credit|finance|funding)[^.\n]{0,70}(?:upfront|up front|advance|processing|insurance|admin|release|legal) ?(?:fee|payment|deposit)|no credit check[^.\n]{0,50}(?:fee|deposit|pay)|blacklisted?[^.\n]{0,50}(?:approved|guaranteed)|guaranteed (?:loan|approval)/iu', self::BLOCK,
                'A loan that needs a fee before you get it is a scam pattern and is not allowed. It was not published.'],
            'scam_investment' => ['/(?:double|triple|10x|multiply) your (?:money|investment|rands?)|guaranteed (?:returns?|profits?|income)|get rich quick|\bponzi\b|pyramid scheme|\d{2,4} ?% (?:returns?|profit|roi) (?:per|a|every|in)(?: \w+)? ?(?:day|week|month|24 hours|48 hours)|crypto doubl|binary options|earn r ?\d{3,} (?:per|a|every) (?:day|hour)|forex signals?[^.\n]{0,30}guaranteed/iu', self::BLOCK,
                'Promises of guaranteed or doubled returns are not allowed. It was not published.'],
            'scam_work_from_home' => ['/work from home[^.\n]{0,60}earn r ?\d|earn r ?\d[^.\n]{0,40}(?:from home|online) (?:every|per|a) (?:day|week)|make money (?:fast|online) (?:today|now)/iu', self::HOLD,
                'Easy-money offers are a common scam, so a person will check this first.'],
            'scam_deposit_viewing' => ['/(?:deposit|booking fee|holding fee|reservation fee)[^.\n]{0,60}(?:before|prior to)[^.\n]{0,25}(?:view|viewing|seeing|keys|inspect|visit)/iu', self::BLOCK,
                'Asking for a deposit before a viewing is a rental scam pattern. It was not published.'],
            'scam_fee_before_delivery' => ['/(?:pay|send|deposit)[^.\n]{0,40}(?:courier|delivery|shipping|insurance|customs|clearance) fee[^.\n]{0,60}(?:before|first|then)|(?:courier|delivery|shipping|customs|clearance) fee[^.\n]{0,40}(?:upfront|up front|first)/iu', self::HOLD,
                'A fee asked for before delivery is a common scam, so a person will check this first.'],
            'scam_grant_phishing' => ['/(?:sassa|srd|r350|grant)[^.\n]{0,80}(?:click|link|verify|update your|apply here)|(?:click|link|verify)[^.\n]{0,80}(?:sassa|srd|r350)/iu', self::HOLD,
                'Grant links are often phishing, so a person will check this first.'],
        ];
    }

    /**
     * @param  array<string, mixed>  $ctx
     * @return array{verdict: string, flags: list<array{code: string, severity: string, text: string}>, reason: ?string, score: int}
     */
    public function assess(string $text, User $author, array $ctx = []): array
    {
        $full = trim(($ctx['title'] ?? '').' '.$text);
        $flags = [];

        if (mb_strlen(trim($full)) === 0) {
            return $this->result([['code' => 'empty', 'severity' => self::HOLD, 'text' => 'There is nothing to publish.']]);
        }

        $lower = mb_strtolower($full);
        $public = (bool) ($ctx['public'] ?? true);

        // Strip ID and card numbers first so they are not also read as phone numbers.
        [$withoutNumbers, $numberFlags] = $this->sensitiveNumbers($full);
        array_push($flags, ...$numberFlags);

        array_push($flags, ...$this->terms($lower));
        array_push($flags, ...$this->scams($full));
        array_push($flags, ...$this->links($full, $author));
        array_push($flags, ...$this->shouting($full));
        array_push($flags, ...$this->repetition($lower));
        array_push($flags, ...$this->duplicates($lower, $author, $ctx));

        if ($public && empty($ctx['allow_contact'])) {
            array_push($flags, ...$this->contactDetails($withoutNumbers));
        }

        array_push($flags, ...$this->velocity($author, $ctx));

        return $this->result($flags);
    }

    // -- rules --------------------------------------------------------------------------------------------------

    /** @return list<array{code: string, severity: string, text: string}> */
    private function terms(string $lower): array
    {
        $out = [];

        foreach ([self::BLOCK => 'blocked_terms', self::HOLD => 'hold_terms'] as $severity => $key) {
            foreach ((array) config("pulse.moderation.{$key}", []) as $term) {
                $term = mb_strtolower(trim((string) $term));

                if ($term !== '' && preg_match('/(?<![\p{L}\p{N}])'.preg_quote($term, '/').'(?![\p{L}\p{N}])/u', $lower)) {
                    $out[] = $this->flag('blocked_term', $severity, $severity === self::BLOCK
                        ? 'It contains words or threats we do not allow. It was not published.'
                        : 'It contains a word a person should look at before it goes live.');
                    break;
                }
            }
        }

        if (preg_match('/\b(?:i(?:\'ll| will| am going to|\'m going to)|gonna|going to) (?:kill|stab|shoot|rape|burn|hurt) (?:you|u|them|him|her)\b|\bkill yourself\b|\bkys\b/iu', $lower)) {
            $out[] = $this->flag('threat', self::BLOCK, 'Threats and calls to self-harm are not allowed. It was not published.');
        }

        return $out;
    }

    /** @return list<array{code: string, severity: string, text: string}> */
    private function scams(string $text): array
    {
        $out = [];
        $warning = (bool) preg_match('/\b(?:beware|scam alert|warning|be careful|watch out|don\'t fall|do not fall|stay safe|how to avoid|red flags?)\b/iu', $text);

        foreach ($this->scamPatterns() as $code => [$regex, $severity, $message]) {
            if (@preg_match($regex, $text)) {
                // A post warning people about a scam is not a scam, but a person should still look.
                if ($warning && $severity === self::BLOCK) {
                    $severity = self::HOLD;
                    $message = 'It talks about a known scam. A person will check it before it goes live.';
                }

                $out[] = $this->flag($code, $severity, $message);
            }
        }

        // The stronger job-fee rule supersedes the softer one.
        $codes = array_column($out, 'code');
        if (in_array('scam_job_fee_block', $codes, true)) {
            $out = array_values(array_filter($out, fn ($f) => $f['code'] !== 'scam_job_fee'));
        }

        return $out;
    }

    /** @return list<array{code: string, severity: string, text: string}> */
    private function links(string $text, User $author): array
    {
        preg_match_all('~(?:https?://|www\.)[^\s<>"\']+|\b[a-z0-9][a-z0-9\-]*\.(?:com|co\.za|org\.za|net|org|io|me|ly|gl|xyz|info|biz|ru|top|click|link|site|online|shop|cc|tk)\b[^\s<>"\']*~iu', $text, $m);
        $links = array_unique($m[0] ?? []);
        $count = count($links);

        if ($count === 0) {
            return [];
        }

        $out = [];
        $max = (int) config('pulse.moderation.max_links', 3);
        $blockAt = (int) config('pulse.moderation.block_links', 6);
        $words = max(1, str_word_count(preg_replace('~\S+://\S+~', '', $text) ?? $text));

        foreach ($links as $link) {
            $host = Str::lower((string) (parse_url(str_contains($link, '://') ? $link : 'http://'.$link, PHP_URL_HOST) ?: $link));
            $host = preg_replace('/^www\./', '', $host) ?? $host;

            if (in_array($host, self::SHORTENERS, true)) {
                $out[] = $this->flag('short_link', self::HOLD, 'Shortened links hide where they go, so a person will check this first.');
                break;
            }
        }

        if ($count >= $blockAt) {
            $out[] = $this->flag('link_flood', self::BLOCK, 'Too many links. It looks like spam and was not published.');
        } elseif ($count >= $max || ($words < 30 && $count >= 2)) {
            $out[] = $this->flag('link_density', self::HOLD, 'It has a lot of links for its length, so a person will check this first.');
        }

        $newHours = (int) config('pulse.moderation.new_account_hours', 24);

        if ($author->created_at !== null && $author->created_at->gt(now()->subHours($newHours))) {
            $out[] = $this->flag('new_account_link', self::HOLD, 'Links from a brand new account wait for a person to check them.');
        }

        return $out;
    }

    /** @return list<array{code: string, severity: string, text: string}> */
    private function shouting(string $text): array
    {
        $letters = preg_match_all('/\p{L}/u', $text);

        if ($letters >= 30) {
            $upper = preg_match_all('/\p{Lu}/u', $text);

            if ($upper / $letters > 0.8) {
                return [$this->flag('shouting', self::HOLD, 'It is mostly capital letters. A person will look at it before it goes live.')];
            }
        }

        return [];
    }

    /** @return list<array{code: string, severity: string, text: string}> */
    private function repetition(string $lower): array
    {
        $words = preg_split('/[^\p{L}\p{N}]+/u', $lower, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $flag = $this->flag('repeated_text', self::HOLD, 'It repeats the same words over and over. A person will look at it before it goes live.');

        if (preg_match('/(.)\1{11,}/u', $lower)) {
            return [$flag];
        }

        $run = 1;
        for ($i = 1, $n = count($words); $i < $n; $i++) {
            $run = ($words[$i] === $words[$i - 1] && mb_strlen($words[$i]) >= 2) ? $run + 1 : 1;
            if ($run >= 6) {
                return [$flag];
            }
        }

        if (count($words) >= 12) {
            $top = max(array_count_values($words));
            $topWord = array_search($top, array_count_values($words), true);

            if (mb_strlen((string) $topWord) >= 3 && $top / count($words) > 0.4) {
                return [$flag];
            }
        }

        $lines = array_filter(array_map('trim', preg_split('/[\r\n.!?]+/', $lower) ?: []), fn ($l) => mb_strlen($l) >= 8);
        if ($lines !== [] && max(array_count_values($lines)) >= 3) {
            return [$flag];
        }

        return [];
    }

    /**
     * The same text from the same author in the last day.
     *
     * @param  array<string, mixed>  $ctx
     * @return list<array{code: string, severity: string, text: string}>
     */
    private function duplicates(string $lower, User $author, array $ctx): array
    {
        $needle = $this->fingerprint($lower);

        if (mb_strlen($needle) < 12) {
            return [];
        }

        $recent = (array) ($ctx['recent_texts'] ?? []);

        if (! isset($ctx['recent_texts']) && $author->exists) {
            $recent = PulsePost::query()
                ->where('user_id', $author->id)
                ->where('created_at', '>=', now()->subDay())
                ->when(isset($ctx['exclude_post_id']), fn ($q) => $q->where('id', '!=', $ctx['exclude_post_id']))
                ->where('status', '!=', 'removed')
                ->latest('id')->limit(40)->get(['title', 'body'])
                ->map(fn ($p) => trim($p->title.' '.$p->body))->all();
        }

        $same = 0;
        foreach ($recent as $r) {
            if ($this->fingerprint(mb_strtolower((string) $r)) === $needle) {
                $same++;
            }
        }

        if ($same >= 3) {
            return [$this->flag('duplicate_flood', self::BLOCK, 'You have posted this same text several times already. It was not published.')];
        }

        return $same >= 1
            ? [$this->flag('duplicate', self::HOLD, 'This is the same as something you posted in the last day. A person will look at it.')]
            : [];
    }

    /** @return list<array{code: string, severity: string, text: string}> */
    private function contactDetails(string $text): array
    {
        $phone = '/(?<![\d\w])(?:\+?27|0)[\s\-.]?\(?[1-9]\d?\)?(?:[\s\-.]?\d){7,9}(?!\d)|(?<![\d\w])\+\d{1,3}[\s\-.]?(?:\d[\s\-.]?){6,12}(?!\d)/u';
        $email = '/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/iu';

        if (preg_match($email, $text) || preg_match($phone, $text)) {
            return [$this->flag('contact_in_public', self::HOLD, 'Phone numbers and email addresses are kept out of public text. They are shared when someone asks. A person will check this.')];
        }

        return [];
    }

    /**
     * @return array{0: string, 1: list<array{code: string, severity: string, text: string}>}
     */
    private function sensitiveNumbers(string $text): array
    {
        $flags = [];

        $text = preg_replace_callback('/(?<![\d])(?:\d[ \-]?){13,19}(?![\d])/', function ($m) use (&$flags) {
            $digits = preg_replace('/\D/', '', $m[0]) ?? '';
            $len = strlen($digits);

            if ($len >= 13 && $len <= 19 && $this->luhn($digits) && ! preg_match('/^\d{2}(0[1-9]|1[0-2])(0[1-9]|[12]\d|3[01])\d{4}[01]\d{2}$/', $digits)) {
                $flags[] = $this->flag('card_number', self::BLOCK, 'It looks like a card number. Never put card details in public text. It was not published.');

                return ' ';
            }

            if ($len === 13 && preg_match('/^\d{2}(0[1-9]|1[0-2])(0[1-9]|[12]\d|3[01])\d{4}[01]\d{2}$/', $digits)) {
                $flags[] = $this->flag('id_number', self::HOLD, 'It looks like an ID number. A person will check this before it goes live.');

                return ' ';
            }

            return $m[0];
        }, $text) ?? $text;

        return [$text, $flags];
    }

    /**
     * Posting speed, from the author's own posts in the last hour unless the caller passes recent_count.
     *
     * @param  array<string, mixed>  $ctx
     * @return list<array{code: string, severity: string, text: string}>
     */
    private function velocity(User $author, array $ctx): array
    {
        if (isset($ctx['recent_count'])) {
            $count = (int) $ctx['recent_count'];
        } elseif ($author->exists) {
            $count = PulsePost::where('user_id', $author->id)
                ->where('created_at', '>=', now()->subMinutes((int) config('pulse.moderation.velocity_window_minutes', 60)))
                ->when(isset($ctx['exclude_post_id']), fn ($q) => $q->where('id', '!=', $ctx['exclude_post_id']))
                ->count();
        } else {
            $count = 0;
        }

        $hold = (int) config('pulse.moderation.velocity_hold', 8);
        $fresh = $author->created_at !== null && $author->created_at->gt(now()->subHour());

        if ($count >= $hold * 3) {
            return [$this->flag('velocity_flood', self::BLOCK, 'You are posting far too fast. Slow down and try again later. It was not published.')];
        }

        if ($count >= $hold || ($fresh && $count >= 4)) {
            return [$this->flag('velocity', self::HOLD, 'You are posting very quickly, so a person will check this first.')];
        }

        return [];
    }

    // -- result -------------------------------------------------------------------------------------------------

    /**
     * @param  list<array{code: string, severity: string, text: string}>  $flags
     * @return array{verdict: string, flags: list<array{code: string, severity: string, text: string}>, reason: ?string, score: int}
     */
    private function result(array $flags): array
    {
        $blocks = array_values(array_filter($flags, fn ($f) => $f['severity'] === self::BLOCK));
        $holds = array_values(array_filter($flags, fn ($f) => $f['severity'] === self::HOLD));

        $verdict = $blocks !== [] ? self::BLOCK : ($holds !== [] ? self::HOLD : self::ALLOW);
        $reason = $blocks[0]['text'] ?? ($holds[0]['text'] ?? null);

        return [
            'verdict' => $verdict,
            'flags' => $flags,
            'reason' => $reason,
            'score' => count($blocks) * 10 + count($holds) * 3,
        ];
    }

    /** @return array{code: string, severity: string, text: string} */
    private function flag(string $code, string $severity, string $text): array
    {
        return ['code' => $code, 'severity' => $severity, 'text' => $text];
    }

    private function fingerprint(string $lower): string
    {
        return preg_replace('/[^\p{L}\p{N}]+/u', '', $lower) ?? '';
    }

    private function luhn(string $digits): bool
    {
        $sum = 0;
        $alt = false;

        for ($i = strlen($digits) - 1; $i >= 0; $i--) {
            $n = (int) $digits[$i];

            if ($alt) {
                $n *= 2;
                if ($n > 9) {
                    $n -= 9;
                }
            }

            $sum += $n;
            $alt = ! $alt;
        }

        return $sum % 10 === 0;
    }
}
