<?php

namespace Tests\Feature\Discovery;

use App\Services\Pulse\Discovery\Taxonomy;
use Tests\TestCase;

class TaxonomyTest extends TestCase
{
    private function tax(): Taxonomy
    {
        return new Taxonomy;
    }

    public function test_the_lists_are_broad_and_grouped_and_slugs_are_unique(): void
    {
        $topics = $this->tax()->topics();
        $skills = $this->tax()->skills();

        $this->assertGreaterThanOrEqual(150, count($topics));
        $this->assertGreaterThanOrEqual(350, count($skills));
        $this->assertSame(count($topics), count(array_unique(array_column($topics, 'slug'))));
        $this->assertSame(count($skills), count(array_unique(array_column($skills, 'slug'))));
        $this->assertGreaterThanOrEqual(8, count(array_unique(array_column($topics, 'group'))));
        $this->assertGreaterThanOrEqual(8, count(array_unique(array_column($skills, 'group'))));

        // South Africa aware: artisans, retail, agriculture, mining, ICT, finance, creative, health, education, transport, hospitality.
        $slugs = array_column($topics, 'slug');
        foreach (['plumbing', 'electrical-work', 'spaza-and-tuck-shop-supplies', 'poultry', 'mining', 'solar-installation', 'bookkeeping', 'tax-services', 'graphic-design', 'healthcare-services', 'tutoring-and-lessons', 'courier-and-delivery', 'catering', 'learnerships'] as $expected) {
            if ($expected === 'learnerships') {
                $this->assertNotEmpty($this->tax()->suggest('learnership', 'topic'), 'learnerships are findable');

                continue;
            }
            $this->assertContains($expected, $slugs, $expected);
        }
    }

    public function test_aliases_resolve_to_the_canonical_slug(): void
    {
        $this->assertSame('plumbing', Taxonomy::canonical('Plumber'));
        $this->assertSame('plumbing', Taxonomy::canonical('plumbers'));
        $this->assertSame('bookkeeping', Taxonomy::canonical('Book Keeping'));
        $this->assertSame('tax-services', Taxonomy::canonical('Tax'));
        $this->assertSame('something-unlisted', Taxonomy::canonical('Something Unlisted'), 'unknown text is kept, not rejected');
        $this->assertSame('', Taxonomy::canonical('   '));
    }

    public function test_normalise_dedupes_and_ignores_junk(): void
    {
        $this->assertSame(['plumbing', 'bookkeeping'], Taxonomy::normalise(['Plumber', 'plumbing', 'Book keeping', '', null, 12, ['x']]));
        $this->assertSame([], Taxonomy::normalise(null));
        $this->assertCount(3, Taxonomy::normalise(['a1', 'b2', 'c3', 'd4', 'e5'], 3));
    }

    public function test_extract_finds_known_phrases_in_free_text(): void
    {
        $this->assertSame(['bookkeeping'], Taxonomy::extract('Need a bookkeeper in Sandton'));
        $this->assertEqualsCanonicalizing(['plumbing', 'electrical-work'], Taxonomy::extract('Plumber and electrician wanted for a renovation job'));
        $this->assertSame([], Taxonomy::extract('Looking for someone nice to chat to'));
        $this->assertNotContains('machine-learning', Taxonomy::extract('We go to the shops'), '"go" alone is too ambiguous');
    }

    public function test_suggest_ranks_prefix_before_alias_before_contains(): void
    {
        $s = $this->tax()->suggest('plum');
        $this->assertSame('plumbing', $s[0]['slug']);
        $this->assertSame([], $this->tax()->suggest('p'));
        $this->assertNotEmpty($this->tax()->suggest('geyser'), 'aliases are searched');
        $this->assertSame('skill', $this->tax()->suggest('python', 'skill')[0]['kind']);
        $this->assertSame([], $this->tax()->suggest('python', 'topic'));
        $this->assertLessThanOrEqual(8, count($this->tax()->suggest('a')) + count($this->tax()->suggest('ing')));
    }

    public function test_labels_and_modes(): void
    {
        $this->assertSame('Bookkeeping', Taxonomy::label('bookkeeping'));
        $this->assertSame('Some odd thing', Taxonomy::label('some-odd-thing'));
        $this->assertTrue(Taxonomy::isTrade('plumbing'));
        $this->assertFalse(Taxonomy::isTrade('mining'));
        $this->assertSame('trade', Taxonomy::modeOf('offer'));
        $this->assertSame('work', Taxonomy::modeOf('role'));
        $this->assertSame('learn', Taxonomy::modeOf('event'));
        $this->assertSame('trade', Taxonomy::modeOf('collab', ['plumbing']));
        $this->assertSame('work', Taxonomy::modeOf('collab', ['leadership']));
        $this->assertNull(Taxonomy::kindsOfMode('all'));
        $this->assertNull(Taxonomy::kindsOfMode('local'));
    }

    public function test_experience_bands_and_seniority(): void
    {
        $this->assertSame('entry', Taxonomy::experienceBand(0)['key']);
        $this->assertSame('junior', Taxonomy::experienceBand(3)['key']);
        $this->assertSame('mid', Taxonomy::experienceBand(7)['key']);
        $this->assertSame('senior', Taxonomy::experienceBand(25)['key']);
        $this->assertNull(Taxonomy::experienceBand(null));
        $this->assertSame('senior', Taxonomy::seniorityBand('Senior Engineer'));
        $this->assertSame('entry', Taxonomy::seniorityBand('Graduate trainee'));
        $this->assertNull(Taxonomy::seniorityBand('Wizard'));
        $this->assertGreaterThan(Taxonomy::bandRank('junior'), Taxonomy::bandRank('mid'));
    }
}
