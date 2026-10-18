<?php

namespace App\Enums;

enum ReportReason: string
{
    case Spam = 'spam';
    case Harassment = 'harassment';
    case Hate = 'hate';
    case Violence = 'violence';
    case Sexual = 'sexual';
    case Misinformation = 'misinformation';
    case Privacy = 'privacy';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Spam => 'Spam or scam',
            self::Harassment => 'Harassment or bullying',
            self::Hate => 'Hate',
            self::Violence => 'Threats or violence',
            self::Sexual => 'Sexual content',
            self::Misinformation => 'Misleading or false',
            self::Privacy => 'Shares private information',
            self::Other => 'Something else',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $r) => $r->value, self::cases());
    }
}
