<div class="fl-context">
    <section class="pk-card fl-ctx" aria-labelledby="ctx-door">
        <h2 id="ctx-door" class="fl-ctx__title">Your door</h2>
        @if ($door)
            <p><x-pulse.pill tone="good" icon="door">Open until {{ $doorUntil }}</x-pulse.pill></p>
            <p class="pk-muted fl-small">{{ $door->purpose }}</p>
            <x-pulse.button size="sm" variant="quiet" :href="pulse_route('door')">Manage your door</x-pulse.button>
        @else
            <p class="pk-muted">Closed. Open it for a day or a week when you want strangers to knock.</p>
            <x-pulse.button size="sm" variant="quiet" icon="door" :href="pulse_route('door')">Open a door</x-pulse.button>
        @endif
    </section>

    <section class="pk-card fl-ctx" aria-labelledby="ctx-open">
        <h2 id="ctx-open" class="fl-ctx__title">What you have open</h2>
        <dl class="fl-facts">
            <div><dt>Needs</dt><dd class="pk-mono">{{ $needs }}</dd></div>
            <div><dt>Roles live</dt><dd class="pk-mono">{{ $roles }}</dd></div>
            <div><dt><a href="{{ pulse_route('saved') }}">Saved</a></dt><dd class="pk-mono">{{ $saved }}</dd></div>
        </dl>
        <div class="pk-cluster">
            <x-pulse.button size="sm" variant="quiet" :href="pulse_route('needs.create')">Say what you need</x-pulse.button>
        </div>
    </section>

    <section class="pk-card fl-ctx" aria-labelledby="ctx-quiet">
        <h2 id="ctx-quiet" class="fl-ctx__title">Quiet</h2>
        <p class="pk-muted">Choose what reaches you and when. Quiet hours, the digest, what to mute.</p>
        <x-pulse.button size="sm" variant="quiet" :href="pulse_route_first(['me.notifications', 'profile.show'])">Quiet settings</x-pulse.button>
    </section>

    <section class="fl-keys" aria-labelledby="ctx-keys">
        <h2 id="ctx-keys" class="fl-ctx__title">Keys</h2>
        <p class="fl-small"><kbd>n</kbd> next beat, <kbd>p</kbd> previous, <kbd>s</kbd> save, <kbd>Enter</kbd> open.</p>
    </section>
</div>
