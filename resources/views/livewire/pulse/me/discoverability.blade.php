@php
    $card = $this->preview();
    $profile = $this->profile();
    $jobs = array_values((array) $profile->work_history);
    $current = collect($jobs)->map(fn ($j, $i) => $j + ['_i' => $i])->filter(fn ($j) => ! empty($j['org']) && (! empty($j['current']) || empty($j['to_year'])));
    $openLabels = \App\Livewire\Pulse\Me\Onboarding::OPEN_TO_LABELS;
@endphp
<div class="me-stack">
    <section class="pk-card me-section" aria-labelledby="disc-mode">
        <h2 id="disc-mode">Can employers find you?</h2>
        <p class="me-section__text">Saved the moment you pick. You can change it back any time, and Pulse stops showing you straight away.</p>
        <fieldset class="me-choices">
            <legend class="sr-only">Who can find you</legend>
            @include('livewire.pulse.me.partials.choice', ['live' => true, 'name' => 'mode', 'value' => 'off', 'model' => 'mode', 'title' => 'No one', 'text' => 'You do not show up when employers look. You can still look for work yourself.'])
            @include('livewire.pulse.me.partials.choice', ['live' => true, 'name' => 'mode', 'value' => 'anonymous', 'model' => 'mode', 'title' => 'Anonymously', 'text' => 'Employers find you by skills, experience band, region and headline. Never your name, photo, email or exact place. If one invites you, you choose whether to reveal yourself.'])
            @include('livewire.pulse.me.partials.choice', ['live' => true, 'name' => 'mode', 'value' => 'open', 'model' => 'mode', 'title' => 'By name', 'text' => 'Employers find you with your name and photo, as well as your skills and region.'])
        </fieldset>
        @if ($problem)
            <p role="alert" class="pk-field__error"><x-pulse.icon name="alert" :size="16" /><span>{{ $problem }} <a href="{{ pulse_route('me.profile') }}">Open your profile</a>.</span></p>
        @endif
        <div class="me-note">
            <x-pulse.icon name="shield" :size="18" />
            <p>Employers only see a headcount of matching professionals when at least 5 match. Smaller groups are never counted out loud, because a small group can point at one person.</p>
        </div>
    </section>

    <section class="pk-card me-section" aria-labelledby="disc-preview">
        <h2 id="disc-preview">What an employer sees in anonymous mode</h2>
        <p class="me-section__text">This is the real card, built from your saved profile, and nothing else is added.@if ($mode === 'off') You are set to no one, so it is not shown to anyone today.@endif</p>
        <div class="me-preview">
            <x-pulse.beat kind="talent" :title="$card['headline'] ?: 'No headline yet'" :place="$card['region']">
                <ul class="me-facts">
                    <li><span class="me-facts__k">Experience</span><span>{{ $card['experience_band']['label'] ?? 'Not set' }}</span></li>
                    @if (! empty($card['open_to']))
                        <li><span class="me-facts__k">Open to</span><span>{{ collect($card['open_to'])->map(fn ($v) => $openLabels[$v] ?? $v)->implode(', ') }}</span></li>
                    @endif
                    @if (! empty($card['languages']))
                        <li><span class="me-facts__k">Languages</span><span>{{ implode(', ', $card['languages']) }}</span></li>
                    @endif
                </ul>
                @if (! empty($card['skills']))
                    <ul class="me-tags__list" aria-label="Skills an employer sees">
                        @foreach ($card['skills'] as $skill)<li><span class="me-chip me-chip--static">{{ $skill }}</span></li>@endforeach
                    </ul>
                @else
                    <p class="pk-muted">No skills yet. Add some in your profile or you cannot be found.</p>
                @endif
                <x-slot name="from">
                    <x-pulse.avatar name="Anonymous" />
                    <span class="pk-beat__from-text"><span class="pk-beat__from-name">An anonymous professional</span><x-pulse.pill>Name hidden</x-pulse.pill></span>
                </x-slot>
            </x-pulse.beat>
        </div>
        <p class="pk-field__hint">Not in the card: your name, photo, email, user id, exact place, bio and work history.</p>
    </section>

    <section class="pk-card me-section" aria-labelledby="disc-open">
        <h2 id="disc-open">What you are open to</h2>
        <p class="me-section__text">Only used when employers can find you. Pick any, or none.</p>
        <x-pulse.chips name="openTo" legend="Open to" :options="$openLabels" :selected="$openTo" wire:model.live="openTo" />
    </section>

    <section class="pk-card me-section" aria-labelledby="disc-hide">
        <h2 id="disc-hide">Hide from your current employer</h2>
        <p class="me-section__text">Looking quietly? An employer page with the same name as your employer can never find you, in either mode. Pulse compares names, so spell it the way they do.</p>
        @forelse ($current as $job)
            <x-pulse.toggle :name="'hide-'.$job['_i']" :id="'hide-'.$job['_i']" :label="'Do not show me to '.$job['org']" :hint="$job['title'] ?? null" :checked="! empty($job['hide_from_employer'])" wire:key="hide-{{ $job['_i'] }}" wire:click="toggleHide({{ $job['_i'] }})" />
        @empty
            <x-pulse.empty title="No current job listed." text="Add your job and employer in your profile, then come back to hide it." action="Open your profile" :href="pulse_route('me.profile')" />
        @endforelse
    </section>
</div>
