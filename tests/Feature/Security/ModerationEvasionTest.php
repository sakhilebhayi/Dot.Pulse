<?php

namespace Tests\Feature\Security;

use App\Models\User;
use App\Services\Pulse\Discovery\Moderation\LocalRules;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** The deterministic moderation pass must not be beaten by zero width characters, look-alike letters or leetspeak. */
class ModerationEvasionTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, array{0: string, 1: string}> verdict at least this strong: text */
    public static function scams(): array
    {
        $zw = "\u{200B}";

        return [
            'plain pop fee' => ['block', 'Proof of payment attached, now pay the courier fee R450 to release the goods'],
            'zero width in proof' => ['block', "Pro{$zw}of of pay{$zw}ment attached, now pay the courier fee R450 to release the goods"],
            'cyrillic o in courier' => ['block', "Proof of payment attached, now pay the c\u{043E}urier fee R450 to release the goods"],
            'leet courier' => ['block', 'Proof of payment attached, now pay the c0ur1er fee R450 to release the goods'],
            'plain job fee' => ['block', 'Learnership vacancies open, pay a registration fee of R350 to secure your placement'],
            'job fee leet' => ['block', 'L3arnership vacancies open, pay a registr4tion fee of R350 to secure your placement'],
            'job fee zero width' => ['block', "Learnership vacancies open, pay a regis{$zw}tration fee of R350 to secure your placement"],
            'plain otp' => ['block', 'Please send me your OTP so I can verify the payment'],
            'otp spaced' => ['block', 'Please send me your O T P so I can verify the payment'],
            'otp zero width' => ['block', "Please send me your O{$zw}TP so I can verify the payment"],
            'plain doubling' => ['block', 'Double your money in 48 hours, guaranteed returns'],
            'doubling homoglyph' => ['block', "D\u{043E}uble your m\u{043E}ney in 48 hours, guaranteed returns"],
            'doubling zero width' => ['block', "Double your mon{$zw}ey in 48 hours, guaranteed returns"],
            'plain threat' => ['block', 'I will kill you if you come back'],
            'threat zero width' => ['block', "I will ki{$zw}ll you if you come back"],
            'blocked term' => ['block', 'cheap viagra for everyone'],
            'blocked term leet' => ['block', 'ch3ap v1agra for everyone'],
            'blocked term zero width' => ['block', "cheap via{$zw}gra for everyone"],
            'blocked term spaced' => ['block', 'cheap v i a g r a for everyone'],
            'hold term' => ['hold', 'Looking for a sugar daddy tonight'],
            'hold term homoglyph' => ['hold', "Looking for a sugar d\u{0430}ddy tonight"],
            'contact in public text' => ['hold', 'Call me on 082 123 4567 for a quote'],
            'contact zero width' => ['hold', "Call me on 082{$zw} 123{$zw} 4567 for a quote"],
            'contact arabic digits' => ['hold', 'Call me on ٠٨٢ ١٢٣ ٤٥٦٧ for a quote'],
        ];
    }

    #[DataProvider('scams')]
    public function test_scam_and_blocked_text_is_caught_however_it_is_dressed(string $minimum, string $text): void
    {
        $author = User::factory()->create(['created_at' => now()->subDays(90)]);

        $verdict = app(LocalRules::class)->assess($text, $author, ['public' => true])['verdict'];

        $rank = ['allow' => 0, 'hold' => 1, 'block' => 2];
        $this->assertGreaterThanOrEqual($rank[$minimum], $rank[$verdict], "Slipped through as {$verdict}: ".json_encode($text));
    }

    public function test_ordinary_posts_with_accents_digits_and_emoji_stay_allowed(): void
    {
        $author = User::factory()->create(['created_at' => now()->subDays(90)]);

        foreach ([
            'Café on the corner needs a baker from December, R18 000 a month, Cape Town',
            'We cleared 3 cars today and sold them for R120 000. Great team. 🙂',
            'Looking for a bookkeeper who knows VAT and payroll in Soweto.',
            'The yoga class is on at 6 am every Tuesday in Braamfontein',
        ] as $text) {
            $this->assertSame('allow', app(LocalRules::class)->assess($text, $author, ['public' => true])['verdict'], $text);
        }
    }
}
