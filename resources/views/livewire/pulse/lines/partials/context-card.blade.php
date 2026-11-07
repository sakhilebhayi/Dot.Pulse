{{--
    What the line is about, copied when it started, so it keeps its meaning if the listing changes.
    Needs: $line (present()), $subject (live link or null).
--}}
@php
    use App\Livewire\Pulse\Lines\Support\Words;
    $ctx = $line['context'];
    $snap = $ctx['snapshot'] ?? [];
    $anonymous = ! empty($snap['anonymous']);
    $labels = [
        'summary' => 'Summary', 'category' => 'Category', 'price' => 'Price', 'from' => 'From',
        'employment_type' => 'Type', 'work_mode' => 'Work mode', 'pay' => 'Pay',
        'details' => 'Details', 'budget' => 'Budget', 'needed_by' => 'Needed by', 'excerpt' => 'About',
    ];
    $facts = collect($labels)->filter(fn ($l, $k) => ! empty($snap[$k]));
    $typeWord = $anonymous ? 'Talent' : ($ctx['type'] ? ucfirst($ctx['type']) : Words::kind($line['kind']));
@endphp
{{-- Folded by default: one line says what the line is about and the request opens on a tap. The conversation is the page. --}}
<section class="pk-card ln-context" aria-labelledby="ln-context-title">
    <div class="ln-context__line">
        <x-pulse.kind-word :kind="$typeWord" />
        <h1 id="ln-context-title" class="ln-title">{{ $ctx['title'] }}</h1>
    </div>

    <details class="ln-details" wire:ignore.self @if ($open ?? false) open @endif>
    <summary>What was asked</summary>
    @if ($anonymous)
        @include('livewire.pulse.lines.partials.anon-card', ['snap' => $snap])
    @elseif ($facts->isNotEmpty())
        <dl class="ln-facts">
            @foreach ($facts as $key => $label)
                <div class="ln-facts__row{{ in_array($key, ['summary', 'details'], true) ? ' ln-facts__row--wide' : '' }}">
                    <dt>{{ $label }}</dt>
                    <dd>{{ $key === 'needed_by' ? Words::date($snap[$key]) : $snap[$key] }}</dd>
                </div>
            @endforeach
        </dl>
    @endif

    @if (! empty($line['card']))
        <div class="ln-sub">
            <h2 class="ln-subhead">The request</h2>
            @include('livewire.pulse.lines.partials.card-fields', ['card' => $line['card']])
        </div>
    @endif

    </details>

    <p class="ln-foot">
        @if ($subject && $subject['live'] && $subject['url'])
            <a href="{{ $subject['url'] }}">{{ $subject['label'] }}</a>
        @elseif ($subject && ! $subject['live'])
            This is how it read when the line started. It is no longer live.
        @endif
    </p>
</section>
