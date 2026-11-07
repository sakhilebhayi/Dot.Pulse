@php
    use App\Livewire\Pulse\Operator\Dashboard as D;
    $range = $m['range'];
    $from = $range['from']->format('j M Y');
    $to = $range['to']->format('j M Y');
    $people = $m['people'];
    $listings = $m['listings'];
    $req = $m['requests'];
    $res = $m['responses'];
    $out = $m['outcomes'];
    $rep = $m['reports'];
    $queue = $m['queue'];
    $label = fn (string $s) => ucfirst(str_replace('_', ' ', $s));
    $named = fn (array $by) => collect($by)->mapWithKeys(fn ($n, $k) => [$label($k) => number_format($n)])->all();
@endphp
<div class="op">
    <header class="op-head">
        <div>
            <h1 class="op-title">Operator</h1>
            <p class="op-sub">Real counts from real rows. Nothing here is estimated. A number with nothing behind it says so.</p>
        </div>
        <div class="op-range" role="group" aria-label="Date range">
            @foreach (D::RANGES as $r)
                <button type="button" class="pk-btn pk-btn--sm {{ $days === $r ? 'pk-btn--primary' : 'pk-btn--quiet' }}" wire:click="setRange({{ $r }})" aria-pressed="{{ $days === $r ? 'true' : 'false' }}">{{ $r }} days</button>
            @endforeach
        </div>
    </header>

    <p class="op-window">
        <span class="op-window__word">Range</span> {{ $from }} to {{ $to }}, {{ $range['days'] }} days.
        Figures marked <strong>Right now</strong> are counted at {{ $range['to']->format('H:i') }} on {{ $to }} and ignore the range.
    </p>

    <section class="op-section" aria-labelledby="op-who">
        <h2 id="op-who">Who is here</h2>
        <div class="op-grid">
            @include('livewire.pulse.operator.partials.metric', [
                'label' => 'People', 'scope' => 'now', 'value' => D::count($people['total']),
                'rows' => $named($people['by_kind']),
                'definition' => 'People who finished onboarding, split by the kind of profile they chose.',
            ])
            @include('livewire.pulse.operator.partials.metric', [
                'label' => 'How findable they are', 'scope' => 'now', 'value' => D::count($people['total']),
                'rows' => $named($people['by_discoverability']),
                'note' => 'people in total',
                'definition' => 'The same people by what they allow: off, anonymous (skills and region only) or open (name shown).',
            ])
            @include('livewire.pulse.operator.partials.metric', [
                'label' => 'Business pages', 'scope' => 'now', 'value' => D::count($m['entities']),
                'definition' => 'Pages that are live and approved, by the rules or by a person.',
            ])
        </div>
    </section>

    <section class="op-section" aria-labelledby="op-on">
        <h2 id="op-on">What is on the floor</h2>
        <div class="op-grid">
            @include('livewire.pulse.operator.partials.metric', [
                'label' => 'Offers', 'scope' => 'now', 'value' => D::count($listings['offers']['total']),
                'rows' => $named($listings['offers']['by_kind']),
                'definition' => 'Offers that are live and approved, by product or service.',
            ])
            @include('livewire.pulse.operator.partials.metric', [
                'label' => 'Roles', 'scope' => 'now', 'value' => D::count($listings['roles']['total']),
                'rows' => $named($listings['roles']['by_kind']),
                'definition' => 'Roles that are live and approved, by type of work.',
            ])
            @include('livewire.pulse.operator.partials.metric', [
                'label' => 'Needs', 'scope' => 'now', 'value' => D::count($listings['needs']['total']),
                'rows' => $named($listings['needs']['by_kind']),
                'definition' => 'Needs that are open and approved, by what the person is after.',
            ])
        </div>
    </section>

    <section class="op-section" aria-labelledby="op-lines">
        <h2 id="op-lines">Floor and lines</h2>
        <div class="op-grid">
            @include('livewire.pulse.operator.partials.metric', [
                'label' => 'Floor rounds served', 'value' => D::count($m['floor']['seen']),
                'note' => $m['floor']['people'] !== null ? 'seen by '.D::count($m['floor']['people']).' '.\Illuminate\Support\Str::plural('person', $m['floor']['people']) : null,
                'definition' => 'Matches shown to someone on the floor, counted when first seen.',
            ])
            @include('livewire.pulse.operator.partials.metric', [
                'label' => 'Knocks sent', 'value' => D::count($m['knocks']),
                'definition' => 'First contacts that went out with a request card. A direct message to someone who takes them is not a knock.',
            ])
            @include('livewire.pulse.operator.partials.metric', [
                'label' => 'Response rate', 'value' => D::percent($res['rate']),
                'note' => $res['received'] ? D::count($res['answered']).' answered of '.D::count($res['received']) : null,
                'definition' => 'Of requests started in the range, the share answered. A request counts once it is answered or 48 hours old. Spam is left out.',
            ])
            @include('livewire.pulse.operator.partials.metric', [
                'label' => 'Median time to answer', 'value' => D::duration($res['median_seconds']),
                'definition' => 'The middle time from a request starting to its first answer, among those answered.',
            ])
        </div>

        <div class="op-charts">
            <div class="pk-card">
                @if ($m['knocks'] === null)
                    <h3 class="op-bars__title">Knocks per day</h3>
                    <p class="op-metric__none">No data yet</p>
                @else
                    @include('livewire.pulse.operator.partials.daily', ['series' => $m['series']['knocks_per_day'], 'noun' => 'knocks'])
                @endif
            </div>
            <div class="pk-card">
                @if ($req['sent'] === null)
                    <h3 class="op-bars__title">How requests ended</h3>
                    <p class="op-metric__none">No data yet</p>
                @else
                    @include('livewire.pulse.operator.partials.bars', ['title' => 'How the '.number_format($req['sent']).' knocks in this range ended', 'rows' => ['Accepted' => $req['accepted'], 'Declined' => $req['declined'], 'Lapsed' => $req['lapsed'], 'Still waiting' => $req['waiting']]])
                    <p class="op-metric__def">Accepted means answered and opened. Lapsed means nobody answered in 14 days. A request that was withdrawn or marked as spam is in none of the four, so they may not add up to the total.</p>
                @endif
            </div>
        </div>
    </section>

    <section class="op-section" aria-labelledby="op-results">
        <h2 id="op-results">Results</h2>
        <div class="op-grid">
            @include('livewire.pulse.operator.partials.metric', [
                'label' => 'Confirmed results', 'value' => D::count($out['total']),
                'rows' => $out['total'] !== null ? collect($out['by_outcome'])->mapWithKeys(fn ($n, $k) => [['won' => 'Won', 'hired' => 'Hired', 'partnered' => 'Partnered'][$k] ?? $label($k) => number_format($n)])->all() : [],
                'definition' => 'Won, hired or partnered, confirmed by both sides in the range. One side saying so is not counted, and neither is "not this time".',
            ])
            @include('livewire.pulse.operator.partials.metric', [
                'label' => 'Reviews revealed', 'value' => D::count($m['reviews']),
                'definition' => 'Reviews that became public in the range: both sides wrote one, or 14 days passed. Removed reviews are left out.',
            ])
            @include('livewire.pulse.operator.partials.metric', [
                'label' => 'Digests sent', 'value' => D::count($m['digests']),
                'definition' => 'Digest emails sent in the range. One is sent only when it holds something real.',
            ])
        </div>
    </section>

    <section class="op-section" aria-labelledby="op-safety">
        <h2 id="op-safety">Safety</h2>
        <div class="op-grid">
            @include('livewire.pulse.operator.partials.metric', [
                'label' => 'Reports opened', 'value' => D::count($rep['opened']),
                'rows' => $rep['opened'] !== null ? ['Resolved' => D::count($rep['resolved']), 'Dismissed' => D::count($rep['dismissed'])] : [],
                'definition' => 'Reports filed in the range. Resolved and dismissed are the ones a moderator closed in the range, whenever they were filed.',
            ])
            <div class="op-metric">
                <div class="op-metric__head">
                    <h3 class="op-metric__label">Moderation queue</h3>
                    <span class="op-metric__scope">Right now</span>
                </div>
                @if ($queue['waiting'] === 0)
                    <p class="op-metric__none">Nothing waiting</p>
                @else
                    <p class="op-metric__value">{{ D::count($queue['waiting']) }}</p>
                    <dl class="op-metric__rows">
                        <div><dt>Oldest has waited</dt><dd>{{ D::duration($queue['oldest_seconds']) }}</dd></div>
                        <div><dt>Middle wait</dt><dd>{{ D::duration($queue['median_seconds']) }}</dd></div>
                    </dl>
                @endif
                <p class="op-metric__def">Held posts, held listings, open reports and held reviews. <a href="{{ route('moderation') }}">Open the queue</a>.</p>
            </div>
            @include('livewire.pulse.operator.partials.metric', [
                'label' => 'Blocks', 'value' => D::count($m['blocks']),
                'definition' => 'Blocks made in the range, between people and from a person to a page.',
            ])
            @include('livewire.pulse.operator.partials.metric', [
                'label' => 'Out of first contacts', 'scope' => 'now', 'value' => D::count($m['exhausted']),
                'note' => 'people with none left this week',
                'definition' => 'People who have used this week\'s allowance of first contacts to strangers, refills and shrinks included.',
            ])
        </div>
    </section>
</div>
