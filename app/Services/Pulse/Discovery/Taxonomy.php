<?php

namespace App\Services\Pulse\Discovery;

use Illuminate\Support\Str;

/**
 * The curated topic and skill lists (config/pulse_taxonomy.php) with alias resolution.
 *
 * Signals, offers, needs and profiles all store topics and skills as normalised slugs. Anything a person types
 * ("Plumber", "book keeping") is run through canonical() so that a need for "plumber" and an offer for "plumbing"
 * meet on the same slug. Unknown words are not rejected: they become their own slug and still match each other.
 */
class Taxonomy
{
    /** @var array<string, array<string, mixed>>|null slug => entry, topics */
    private static ?array $topics = null;

    /** @var array<string, array<string, mixed>>|null */
    private static ?array $skills = null;

    /** @var array<string, string>|null alias slug => canonical slug (topics first, then skills) */
    private static ?array $aliases = null;

    /** @var array<string, string>|null canonical slug => label (topics first) */
    private static ?array $labels = null;

    public static function flush(): void
    {
        self::$topics = self::$skills = self::$aliases = self::$labels = null;
    }

    /** @return list<array{slug: string, label: string, group: string, group_label: string, trade: bool, aliases: list<string>}> */
    public function topics(): array
    {
        return array_values(self::loadTopics());
    }

    /** @return list<array{slug: string, label: string, group: string, group_label: string, trade: bool, aliases: list<string>}> */
    public function skills(): array
    {
        return array_values(self::loadSkills());
    }

    /**
     * Typed-ahead suggestions from the curated lists.
     *
     * @param  string  $kind  topic | skill | any
     * @return list<array{slug: string, label: string, group: string, kind: string}>
     */
    public function suggest(string $q, string $kind = 'any', int $limit = 8): array
    {
        $needle = Str::lower(trim($q));

        if (mb_strlen($needle) < 2) {
            return [];
        }

        $pools = match ($kind) {
            'topic' => ['topic' => self::loadTopics()],
            'skill' => ['skill' => self::loadSkills()],
            default => ['topic' => self::loadTopics(), 'skill' => self::loadSkills()],
        };

        $scored = [];

        foreach ($pools as $poolKind => $entries) {
            foreach ($entries as $slug => $entry) {
                $label = Str::lower($entry['label']);
                $score = 0;

                if (str_starts_with($label, $needle)) {
                    $score = 4;
                } elseif (str_contains($label, $needle)) {
                    $score = 2;
                }

                foreach ($entry['aliases'] as $alias) {
                    if (str_starts_with($alias, $needle)) {
                        $score = max($score, 3);
                    } elseif (str_contains($alias, $needle)) {
                        $score = max($score, 1);
                    }
                }

                if ($score === 0) {
                    continue;
                }

                // A topic and a skill that share a slug are one suggestion.
                $key = $slug;

                if (! isset($scored[$key]) || $scored[$key]['score'] < $score) {
                    $scored[$key] = [
                        'score' => $score,
                        'row' => ['slug' => $slug, 'label' => $entry['label'], 'group' => $entry['group_label'], 'kind' => $poolKind],
                    ];
                }
            }
        }

        uasort($scored, fn ($a, $b) => [$b['score'], $a['row']['label']] <=> [$a['score'], $b['row']['label']]);

        return array_values(array_map(fn ($s) => $s['row'], array_slice($scored, 0, $limit, true)));
    }

    /** Resolve free text ("Plumber") to the canonical slug ("plumbing"); unknown text becomes its own slug. */
    public static function canonical(string $text): string
    {
        $slug = Str::slug(trim($text));

        if ($slug === '') {
            return '';
        }

        $aliases = self::loadAliases();

        if (isset($aliases[$slug])) {
            return $aliases[$slug];
        }

        // Crude plural folding: "plumbers" -> "plumber" -> "plumbing".
        if (str_ends_with($slug, 's') && isset($aliases[substr($slug, 0, -1)])) {
            return $aliases[substr($slug, 0, -1)];
        }

        return $slug;
    }

    /**
     * Normalise a list of free text into a unique list of canonical slugs.
     *
     * @param  iterable<mixed>|null  $items
     * @return list<string>
     */
    public static function normalise(?iterable $items, int $max = 30): array
    {
        $out = [];

        foreach ($items ?? [] as $item) {
            if (! is_string($item) || trim($item) === '') {
                continue;
            }

            $slug = self::canonical($item);

            if ($slug !== '' && ! in_array($slug, $out, true)) {
                $out[] = $slug;
            }

            if (count($out) >= $max) {
                break;
            }
        }

        return $out;
    }

    /**
     * Known taxonomy topics and skills mentioned in free text ("Need a bookkeeper in Sandton" -> ["bookkeeping"]).
     * Looks for one to four word phrases against the label and alias index; unknown words are ignored.
     *
     * @return list<string>
     */
    public static function extract(string $text, int $max = 8): array
    {
        $words = preg_split('/[^a-z0-9]+/', Str::lower($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $aliases = self::loadAliases();
        $found = [];
        $n = count($words);

        for ($i = 0; $i < $n; $i++) {
            for ($len = min(4, $n - $i); $len >= 1; $len--) {
                $key = implode('-', array_slice($words, $i, $len));
                $hit = $aliases[$key] ?? (str_ends_with($key, 's') ? ($aliases[substr($key, 0, -1)] ?? null) : null);

                // Single short words are too ambiguous ("pr", "go", "ai") unless they are the whole text.
                if ($hit !== null && ($len > 1 || strlen($key) >= 4 || $n === 1)) {
                    if (! in_array($hit, $found, true)) {
                        $found[] = $hit;
                    }
                    $i += $len - 1;
                    break;
                }
            }

            if (count($found) >= $max) {
                break;
            }
        }

        return $found;
    }

    /** Plain label for a slug ("bookkeeping" -> "Bookkeeping"); unknown slugs are humanised. */
    public static function label(string $slug): string
    {
        $labels = self::loadLabels();

        return $labels[$slug] ?? Str::of($slug)->replace('-', ' ')->ucfirst()->toString();
    }

    /** Whether a topic slug belongs to a group the Trade mode of the Floor covers. */
    public static function isTrade(string $slug): bool
    {
        return (self::loadTopics()[$slug]['trade'] ?? false) === true;
    }

    /**
     * The Floor mode a signal kind belongs to by default (collab is decided by its topics).
     *
     * @param  list<string>  $topics
     */
    public static function modeOf(string $kind, array $topics = []): string
    {
        if ($kind === 'collab') {
            foreach ($topics as $t) {
                if (self::isTrade($t)) {
                    return 'trade';
                }
            }

            return 'work';
        }

        foreach ((array) config('pulse_taxonomy.modes') as $mode => $kinds) {
            if (in_array($kind, $kinds, true)) {
                return (string) $mode;
            }
        }

        return 'learn';
    }

    /** Kinds that can ever appear in a mode (collab is then filtered by topic). Local has no kind restriction. */
    public static function kindsOfMode(string $mode): ?array
    {
        if ($mode === 'all' || $mode === 'local') {
            return null;
        }

        return config("pulse_taxonomy.modes.{$mode}");
    }

    /** @return array{key: string, label: string}|null */
    public static function experienceBand(?int $years): ?array
    {
        if ($years === null || $years < 0) {
            return null;
        }

        foreach ((array) config('pulse_taxonomy.experience_bands') as [$key, $label, $min, $max]) {
            if ($years >= $min && $years <= $max) {
                return ['key' => $key, 'label' => $label];
            }
        }

        return null;
    }

    /** The experience band key a role's free text seniority asks for ("Senior" -> "senior"), or null. */
    public static function seniorityBand(?string $seniority): ?string
    {
        if ($seniority === null || trim($seniority) === '') {
            return null;
        }

        $map = (array) config('pulse_taxonomy.seniority_bands');

        foreach (preg_split('/[^a-z]+/', Str::lower($seniority), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $word) {
            if (isset($map[$word])) {
                return $map[$word];
            }
        }

        return null;
    }

    /** Order of band keys, for "meets or exceeds" comparisons. */
    public static function bandRank(string $key): int
    {
        $i = 0;

        foreach ((array) config('pulse_taxonomy.experience_bands') as [$bandKey]) {
            if ($bandKey === $key) {
                return $i;
            }
            $i++;
        }

        return -1;
    }

    // -- loading ------------------------------------------------------------------------------------------------

    /** @return array<string, array<string, mixed>> */
    private static function loadTopics(): array
    {
        return self::$topics ??= self::parse((array) config('pulse_taxonomy.topics'));
    }

    /** @return array<string, array<string, mixed>> */
    private static function loadSkills(): array
    {
        return self::$skills ??= self::parse((array) config('pulse_taxonomy.skills'));
    }

    /** @return array<string, string> */
    private static function loadAliases(): array
    {
        if (self::$aliases !== null) {
            return self::$aliases;
        }

        $map = [];

        foreach ([self::loadTopics(), self::loadSkills()] as $entries) {
            foreach ($entries as $slug => $entry) {
                $map[$slug] ??= $slug;

                foreach ($entry['aliases'] as $alias) {
                    $map[Str::slug($alias)] ??= $slug;
                }
            }
        }

        return self::$aliases = $map;
    }

    /** @return array<string, string> */
    private static function loadLabels(): array
    {
        if (self::$labels !== null) {
            return self::$labels;
        }

        $labels = [];

        foreach ([self::loadTopics(), self::loadSkills()] as $entries) {
            foreach ($entries as $slug => $entry) {
                $labels[$slug] ??= $entry['label'];
            }
        }

        return self::$labels = $labels;
    }

    /**
     * @param  array<string, array<string, mixed>>  $groups
     * @return array<string, array<string, mixed>>
     */
    private static function parse(array $groups): array
    {
        $out = [];

        foreach ($groups as $groupKey => $group) {
            foreach ($group['items'] ?? [] as $line) {
                $parts = array_map('trim', explode('|', (string) $line));
                $label = array_shift($parts);
                $slug = Str::slug($label);

                if ($slug === '' || isset($out[$slug])) {
                    continue;
                }

                $out[$slug] = [
                    'slug' => $slug,
                    'label' => $label,
                    'group' => (string) $groupKey,
                    'group_label' => (string) ($group['label'] ?? $groupKey),
                    'trade' => (bool) ($group['trade'] ?? false),
                    'aliases' => array_values(array_filter(array_map(fn ($a) => Str::lower($a), $parts))),
                ];
            }
        }

        return $out;
    }
}
