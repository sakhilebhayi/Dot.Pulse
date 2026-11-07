<?php

namespace App\Livewire\Pulse\Floor\Support;

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\HtmlString;

/**
 * The sync meter for one beat, with "Stop using this" only on the reasons a person actually controls.
 *
 * Topics, places, businesses and "remote" are inputs a person can switch off. "Fresh", "quick to answer" and
 * "talked in the same place before" are facts about the listing, so they get no button. The kit meter draws a button
 * on every reason when it is given a remove method; this removes the ones that would only say no.
 */
final class MeterHtml
{
    /** Reasons Floor::removeReason refuses. */
    public const FIXED = ['fresh', 'responsive', 'mutual_context'];

    /**
     * @param  list<array<string, mixed>>  $reasons
     */
    public static function render(array $reasons, ?int $matchId, bool $removable = true, string $themLabel = 'Them', bool $mini = false): HtmlString
    {
        if ($reasons === []) {
            return new HtmlString('');
        }

        if ($mini) {
            return new HtmlString(Blade::render('<x-pulse.sync-meter :reasons="$reasons" :mini="true" />', ['reasons' => $reasons]));
        }

        $remove = $removable && $matchId ? 'removeReason.bind(null, '.(int) $matchId.')' : null;

        $html = Blade::render(
            '<x-pulse.sync-meter :reasons="$reasons" :remove="$remove" :them-label="$them" />',
            ['reasons' => $reasons, 'remove' => $remove, 'them' => $themLabel],
        );

        if ($remove) {
            $codes = implode('|', self::FIXED);
            $html = preg_replace('~<button type="button" wire:click="[^"]*\(\'('.$codes.')\'\)"[^>]*>.*?</button>~s', '', $html) ?? $html;
        }

        return new HtmlString($html);
    }
}
