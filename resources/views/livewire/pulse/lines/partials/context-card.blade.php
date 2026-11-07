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
<section class="pk-card ln-context" aria-labelledby="ln-context-title">
    <div class="ln-meta">
        <x-pulse.kind-word :kind="$typeWord" />
        <span>What this line is about</span>
    </div>
    <h1 id="ln-context-title" class="ln-title">{{ $ctx['title'] }}</h1>

    @if ($anonymous)
        @include('livewire.pulse.lines.partials.anon-card', ['snap' => $snap])
    @elseif ($facts->isNotEmpty())
        <dl class="ln-facts">
            @foreach ($facts as $key => $label)
                <div class="ln-facts__row{{ in_array($key, ['summary', 'details'], true) ? ' ln-facts__row--wide' : '' }}">
                    <dt>{{ $label }}</dt>
                    <dd>{{ $snap[$key] }}</dd>
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

    <p class="ln-foot">
        @if ($subject)
            <a href="{{ $subject['url'] }}">{{ $subject['label'] }}</a>
        @elseif (in_array($ctx['type'], ['offer', 'role', 'need'], true) && $ctx['id'] !== null)
            This is how it read when the line started. It is no longer live.
        @endif
    </p>
</section>
