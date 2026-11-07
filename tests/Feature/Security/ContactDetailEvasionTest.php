<?php

namespace Tests\Feature\Security;

use App\Services\Pulse\Lines\TextGuard;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * First messages and enquiry cards may not carry a link, email, phone number, handle or other app until the other side
 * replies (D18). These are the ways people hide one: zero-width characters, full-width and look-alike characters,
 * spelled out dots and ats, spaced out letters, HTML entities, digits in other scripts.
 */
class ContactDetailEvasionTest extends TestCase
{
    /** @return array<string, array{0: string}> */
    public static function evasions(): array
    {
        $zw = "\u{200B}";

        return [
            'plain url' => ['see https://evil.example/pay now'],
            'zero width inside domain' => ["visit shop{$zw}.example.com tonight"],
            'zero width inside scheme' => ["htt{$zw}ps://evil.example"],
            'soft hyphen in domain' => ["pay at evil\u{00AD}.co.za please"],
            'word joiner in email' => ["mail me thandi{$zw}@proton.me"],
            'full width dot' => ['go to evil．com now'],
            'ideographic full stop' => ['go to evil。com now'],
            'full width letters' => ['ｗｗｗ．ｅｖｉｌ．ｃｏｍ'],
            'dot spelled out' => ['evil dot com is my site'],
            'dot spelled out with brackets' => ['evil [dot] co [dot] za'],
            'spaced dots' => ['evil . com'],
            'spaced out letters' => ['w w w . e v i l . c o m'],
            'spaced tld letters' => ['evil.c o m'],
            'html entity dot' => ['evil&#46;com'],
            'html entity at' => ['thandi&#64;proton.me'],
            'hex entity scheme' => ['&#104;ttps://evil.example'],
            'leet dot' => ['evil d0t com'],
            'at spelled out' => ['thandi at proton dot me'],
            'at in brackets' => ['thandi [at] proton [dot] me'],
            'arabic indic digits' => ['call ٠٨٢ ١٢٣ ٤٥٦٧'],
            'full width digits' => ['call ０８２１２３４５６７'],
            'phone with zero width' => ["call 082{$zw}123{$zw}4567"],
            'phone dotted' => ['call 082.123.4567'],
            'phone with words' => ['call zero eight two one two three four five six seven'],
            'phone dashes unicode' => ["call 082\u{2011}123\u{2011}4567"],
            'international' => ['+27 82 123 4567'],
            'handle' => ['dm me @thandi_books'],
            'handle after zero width' => ["dm me @{$zw}thandi_books"],
            'whatsapp' => ['message me on whats app'],
            'whatsapp leet' => ['message me on w h a t s a p p'],
            'whatsapp zero width' => ["message me on whats{$zw}app"],
            'telegram homoglyph' => ["message me on t\u{0435}legram"],
            'gmail' => ['use my gmail'],
            'less common tld' => ['send money to payhere.ru today'],
            'less common tld two' => ['visit mysite.tk please'],
            'ip address' => ['go to 41.76.12.4'],
            'ip with port' => ['http-less 41.76.12.4:8080/pay'],
        ];
    }

    #[DataProvider('evasions')]
    public function test_the_evasion_is_still_caught(string $text): void
    {
        $this->assertNotSame([], (new TextGuard)->violations($text), 'Not caught: '.json_encode($text));
    }

    /** @return array<string, array{0: string}> */
    public static function plainText(): array
    {
        return [
            'ordinary request' => ['I need a plumber on Thursday in Soweto. Budget R1000 to R3000.'],
            'sentence end then word' => ['Please come early. Me and my son will be there.'],
            'sentence end then com' => ['It is urgent. Come as soon as you can.'],
            'times and dates' => ['Thursday 14:30 or on 2026-11-12'],
            'price' => ['R1,500.00 for 3 sessions'],
            'ellipsis' => ['Not sure... maybe two weeks'],
            'sentence no space after full stop' => ['Please come early.Thanks a lot for the quick reply.'],
            'short numbers' => ['2 bedroom flat, 120 m2, 3 geysers'],
            'abbreviation dots' => ['e.g. a leaking geyser, i.e. urgent'],
            'dot word' => ['The polka dot dress needs altering'],
            'at word' => ['Meet at the gate at 8'],
        ];
    }

    #[DataProvider('plainText')]
    public function test_ordinary_text_is_not_flagged(string $text): void
    {
        $this->assertSame([], (new TextGuard)->violations($text), 'False positive: '.json_encode($text));
    }
}
