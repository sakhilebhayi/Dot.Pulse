<?php

namespace App\Livewire\Pulse\Me\Concerns;

use App\Services\Pulse\Discovery\Taxonomy;
use Illuminate\Support\Str;

/**
 * Typed-ahead chip lists (skills, interests, languages) for a Livewire component that declares public array
 * properties of the same names. Suggestions come from the curated Taxonomy; anything typed can still be added.
 */
trait HasTags
{
    /** @var array<string, string> field => what the person is typing */
    public array $tagQuery = [];

    /** @return array<string, array{0: int, 1: int}> field => [max items, max length] */
    protected function tagLimits(): array
    {
        return ['skills' => [12, 40], 'interests' => [15, 40], 'languages' => [10, 40]];
    }

    /** @var list<string> */
    private const LANGUAGES = [
        'English', 'Afrikaans', 'isiZulu', 'isiXhosa', 'Sesotho', 'Setswana', 'Sepedi', 'Xitsonga', 'siSwati', 'Tshivenda',
        'isiNdebele', 'Portuguese', 'French', 'Arabic', 'Swahili', 'Shona', 'Chichewa', 'German', 'Mandarin', 'Hindi',
    ];

    public function addTag(string $field, ?string $value = null): void
    {
        $limits = $this->tagLimits();

        if (! isset($limits[$field])) {
            return;
        }

        $value = trim((string) ($value ?? ($this->tagQuery[$field] ?? '')));
        $this->tagQuery[$field] = '';

        if ($value === '' || mb_strlen($value) > $limits[$field][1] || count($this->{$field}) >= $limits[$field][0]) {
            return;
        }

        foreach ($this->{$field} as $have) {
            if (mb_strtolower($have) === mb_strtolower($value)) {
                return;
            }
        }

        $this->{$field}[] = $value;
    }

    public function removeTag(string $field, int $index): void
    {
        if (! isset($this->tagLimits()[$field]) || ! isset($this->{$field}[$index])) {
            return;
        }

        unset($this->{$field}[$index]);
        $this->{$field} = array_values($this->{$field});
    }

    /** @return list<string> labels the person has not picked yet */
    public function tagSuggestions(string $field): array
    {
        $q = trim((string) ($this->tagQuery[$field] ?? ''));

        if (mb_strlen($q) < 2 || ! isset($this->tagLimits()[$field])) {
            return [];
        }

        $have = array_map('mb_strtolower', $this->{$field});

        $labels = $field === 'languages'
            ? array_values(array_filter(self::LANGUAGES, fn ($l) => Str::contains(mb_strtolower($l), mb_strtolower($q))))
            : array_column(app(Taxonomy::class)->suggest($q, $field === 'skills' ? 'skill' : 'topic', 8), 'label');

        return array_values(array_filter($labels, fn ($l) => ! in_array(mb_strtolower($l), $have, true)));
    }
}
