<?php

namespace App\Livewire\Pulse\Lines\Support;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * The words the Lines screens use, in one place so the voice stays the same: short, plain, a little bossy, kind.
 * Pure functions over the arrays LineService::present() and ::thread() return. Nothing here reads the database.
 */
class Words
{
    public const KINDS = [
        'dm' => 'Message',
        'enquiry' => 'Enquiry',
        'quote' => 'Quote',
        'application' => 'Application',
        'intro' => 'Intro',
        'collab' => 'Collab',
        'invite' => 'Invite',
    ];

    public const OUTCOMES = [
        'won' => 'Won',
        'hired' => 'Hired',
        'partnered' => 'Partnered',
        'lost' => 'Not this time',
    ];

    public static function kind(?string $kind): string
    {
        return self::KINDS[$kind] ?? 'Line';
    }

    public static function outcome(?string $outcome): string
    {
        return self::OUTCOMES[$outcome] ?? '';
    }

    /** How the outcome reads in a sentence ("marked this as won"). */
    public static function outcomeInline(?string $outcome): string
    {
        return match ($outcome) {
            'won' => 'won (the deal happened)',
            'hired' => 'hired',
            'partnered' => 'a partnership',
            'lost' => 'not this time (no deal)',
            default => '',
        };
    }

    /** A short relative time: "2h ago", "3d ago". */
    public static function ago(mixed $when): string
    {
        $when = self::carbon($when);

        return $when ? $when->diffForHumans(['short' => true, 'parts' => 1]) : '';
    }

    /** A length of time with no direction: "3 days", "2 hours". */
    public static function span(mixed $since): string
    {
        $since = self::carbon($since);

        if (! $since) {
            return '';
        }

        return abs($since->diffInSeconds(now())) < 60
            ? 'a moment'
            : $since->diffForHumans(now(), ['syntax' => CarbonInterface::DIFF_ABSOLUTE, 'parts' => 1]);
    }

    public static function date(mixed $when): string
    {
        $when = self::carbon($when);

        return $when ? $when->format('j M Y') : '';
    }

    public static function time(mixed $when): string
    {
        $when = self::carbon($when);

        return $when ? $when->format('j M, H:i') : '';
    }

    /**
     * The state of a line as the viewer should read it: a label, a tone for the pill and whether it needs them.
     *
     * @param  array<string, mixed>  $line  from LineService::present()
     * @return array{label: string, tone: string, needs_you: bool}
     */
    public static function state(array $line): array
    {
        $status = $line['status'];
        $side = $line['side'];
        $reason = $line['closed_reason'] ?? null;

        if ($line['outcome']['confirmed'] ?? false) {
            return ['label' => self::outcome($line['outcome']['value']).', confirmed', 'tone' => $line['outcome']['value'] === 'lost' ? 'neutral' : 'good', 'needs_you' => false];
        }

        return match ($status) {
            'requested' => $side === 'recipient'
                ? ['label' => 'Needs your answer', 'tone' => 'signal', 'needs_you' => true]
                : ['label' => 'Waiting for them', 'tone' => 'neutral', 'needs_you' => false],
            'open' => ($line['outcome']['awaiting_me'] ?? false)
                ? ['label' => 'Confirm the outcome', 'tone' => 'signal', 'needs_you' => true]
                : ['label' => 'Open', 'tone' => 'good', 'needs_you' => false],
            'declined' => $side === 'initiator'
                ? ['label' => $line['declined_label'] ?: 'Not now', 'tone' => 'neutral', 'needs_you' => false]
                : ['label' => 'You said not now', 'tone' => 'neutral', 'needs_you' => false],
            default => match ($reason) {
                'expired' => $side === 'initiator'
                    ? ['label' => 'Closed, no reply', 'tone' => 'warn', 'needs_you' => false]
                    : ['label' => 'Lapsed, no answer from you', 'tone' => 'neutral', 'needs_you' => false],
                'not_chosen' => ['label' => 'Not chosen', 'tone' => 'neutral', 'needs_you' => false],
                'withdrawn' => ['label' => 'Withdrawn', 'tone' => 'neutral', 'needs_you' => false],
                default => ['label' => 'Closed', 'tone' => 'neutral', 'needs_you' => false],
            },
        };
    }

    /**
     * Plain words for a system message, written for the person reading it. The service writes one sentence for both
     * sides, so the screen rewrites the ones that read wrongly for the side that did the thing.
     *
     * @param  array<string, mixed>  $message  from LineService::thread()
     */
    public static function system(array $message, ?string $viewerSide): string
    {
        $event = $message['meta']['event'] ?? null;
        $mine = $viewerSide !== null && ($message['side'] ?? null) === $viewerSide;
        $outcome = $message['meta']['outcome'] ?? null;

        return match ($event) {
            'outcome_proposed' => $mine
                ? 'You marked this as '.self::outcomeInline($outcome).'. Waiting for them to confirm. It does not count yet.'
                : 'They marked this as '.self::outcomeInline($outcome).'. Confirm it if it is right. It does not count until you do.',
            'outcome_confirmed' => 'Both sides confirmed: '.self::outcomeInline($outcome).'.',
            'outcome_disputed' => $mine ? 'You disputed the outcome. It was cleared.' : 'They disputed the outcome. It was cleared.',
            'revealed' => $mine ? 'You showed who you are.' : 'They showed who they are.',
            'accepted' => $mine ? 'You said yes. Contact details can be shared now.' : 'They said yes. Contact details can be shared now.',
            'closed' => $mine ? 'You closed this line.' : 'This line was closed.',
            'quote_accepted' => $mine ? 'You accepted the quote.' : 'They accepted the quote.',
            'quote_declined' => $mine ? 'You declined the quote.' : 'They declined the quote.',
            default => (string) $message['body'],
        };
    }

    public static function rand(?int $cents): string
    {
        if ($cents === null) {
            return '';
        }

        return 'R'.number_format(intdiv($cents, 100), 0, '.', ' ').($cents % 100 ? sprintf('.%02d', $cents % 100) : '');
    }

    /** A person's own picture, or null. The default avatar service is never called: nothing about a person leaves Pulse. */
    public static function avatarSrc(?string $url): ?string
    {
        if ($url === null || $url === '' || str_contains($url, 'ui-avatars.com')) {
            return null;
        }

        return $url;
    }

    /** The URL of an entity's logo through the authorised media route, or null. */
    public static function logo(?int $mediaId): ?string
    {
        return $mediaId ? pulse_route('pulse.media', ['mediaFile' => $mediaId, 'size' => 'thumb']) : null;
    }

    private static function carbon(mixed $when): ?Carbon
    {
        if ($when === null || $when === '') {
            return null;
        }

        return $when instanceof Carbon ? $when : Carbon::parse($when);
    }
}
