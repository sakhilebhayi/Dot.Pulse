<div class="me-stack">
    <section class="pk-card me-section" aria-labelledby="set-contact">
        <h2 id="set-contact">Who can message you first</h2>
        <p class="me-section__text">Strangers never get a straight line to you unless you pick that. People you already talk to are not strangers. Saved when you pick.</p>
        <fieldset class="me-choices">
            <legend class="sr-only">Who can message you first</legend>
            @include('livewire.pulse.me.partials.choice', ['live' => true, 'name' => 'contactPolicy', 'value' => 'nobody', 'model' => 'contactPolicy', 'title' => 'Nobody new', 'text' => 'Nothing reaches you from people you have not talked to.'])
            @include('livewire.pulse.me.partials.choice', ['live' => true, 'name' => 'contactPolicy', 'value' => 'context', 'model' => 'contactPolicy', 'title' => 'Only about something of mine', 'text' => 'A need, a door or a talent card of yours that is live. They send a request and you choose.'])
            @include('livewire.pulse.me.partials.choice', ['live' => true, 'name' => 'contactPolicy', 'value' => 'requests', 'model' => 'contactPolicy', 'title' => 'Anyone, as a request', 'text' => 'Anyone can send a request. Nothing reaches you until you accept it.'])
            @include('livewire.pulse.me.partials.choice', ['live' => true, 'name' => 'contactPolicy', 'value' => 'anyone', 'model' => 'contactPolicy', 'title' => 'Anyone, directly', 'text' => 'Anyone can message you straight away. Quickest, and the noisiest.'])
        </fieldset>
        @if ($problem)<p role="alert" class="pk-field__error"><x-pulse.icon name="alert" :size="16" /><span>{{ $problem }}</span></p>@endif
    </section>

    <section class="pk-card me-section" aria-labelledby="set-place">
        <h2 id="set-place">Where you are</h2>
        <p class="me-section__text">Approximate on purpose. Pulse keeps the place you pick, a city or a region, never where you are standing, and never anything finer than a city.</p>
        @include('livewire.pulse.me.partials.place-picker')
        <x-pulse.toggle name="showPlace" label="Show my city on my profile" hint="Off means people see your region at most. In anonymous mode employers only ever see the region." :checked="$showPlace" wire:model.live="showPlace" />
        <fieldset class="me-choices me-choices--row">
            <legend class="pk-field__label">How far you look</legend>
            @include('livewire.pulse.me.partials.choice', ['live' => true, 'name' => 'reach', 'value' => 'city', 'model' => 'reach', 'title' => 'My city', 'text' => 'Things near me.'])
            @include('livewire.pulse.me.partials.choice', ['live' => true, 'name' => 'reach', 'value' => 'region', 'model' => 'reach', 'title' => 'My region', 'text' => 'Pulse starts here.'])
            @include('livewire.pulse.me.partials.choice', ['live' => true, 'name' => 'reach', 'value' => 'country', 'model' => 'reach', 'title' => 'My country', 'text' => 'Anywhere nearby by road or rail.'])
            @include('livewire.pulse.me.partials.choice', ['live' => true, 'name' => 'reach', 'value' => 'remote', 'model' => 'reach', 'title' => 'Anywhere', 'text' => 'Remote work and delivery.'])
        </fieldset>
    </section>

    <section class="pk-card me-section" aria-labelledby="set-theme">
        <h2 id="set-theme">Theme</h2>
        <p class="me-section__text">Kept in this browser only.</p>
        <div class="me-theme" x-data="{ t: (document.documentElement.dataset.theme || 'system') }" role="group" aria-label="Theme">
            @foreach (['light' => 'Light', 'dark' => 'Dark', 'system' => 'Follow my device'] as $value => $label)
                <button type="button" class="pk-btn pk-btn--quiet" :aria-pressed="t === '{{ $value }}'" aria-pressed="false"
                        @click="t = '{{ $value }}'; if (t === 'system') { document.documentElement.removeAttribute('data-theme'); try { localStorage.removeItem('pulse-theme') } catch (e) {} } else { document.documentElement.dataset.theme = t; try { localStorage.setItem('pulse-theme', t) } catch (e) {} }">{{ $label }}</button>
            @endforeach
        </div>
    </section>

    <section class="pk-card me-section" aria-labelledby="set-account">
        <h2 id="set-account">Your Dot account</h2>
        <p class="me-section__text">Password, two factor sign-in and your signed-in browsers live with your Dot account. Your name and email come from InfoDot.</p>
        <div><x-pulse.button variant="quiet" :href="pulse_route('me.account')">Account and security</x-pulse.button></div>
    </section>
</div>
