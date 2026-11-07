{{-- The rules' flags on an item. The word says what the rules did (held or blocked); colour only repeats it. Var: flags --}}
@if (! empty($flags))
    <ul class="mq-flags" aria-label="What the rules flagged">
        @foreach ($flags as $flag)
            <li>
                <x-pulse.pill :tone="($flag['severity'] ?? 'hold') === 'block' ? 'bad' : 'warn'">{{ ($flag['severity'] ?? 'hold') === 'block' ? 'Would be blocked' : 'Held' }}</x-pulse.pill>
                <span>{{ $flag['text'] }}</span>
            </li>
        @endforeach
    </ul>
@endif
