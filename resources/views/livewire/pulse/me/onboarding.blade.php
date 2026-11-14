@php
    $key = $this->stepKey();
    $total = count(\App\Livewire\Pulse\Me\Onboarding::STEPS);
    $skippable = $key !== 'age';
    $lastStep = $this->isLast();
@endphp
<div class="me-flow"
     x-data
     x-init="if (window.Intl) { $wire.setTimezone(Intl.DateTimeFormat().resolvedOptions().timeZone) }">
    @if ($step === 1)
        {{-- A new person arrives here before the Floor: the original logo, a welcome from their own records, and the way back. --}}
        @php
            $arrival = app(\App\Livewire\Pulse\Floor\Support\Arrival::class);
            $first = $arrival->firstName(auth()->user());
            $company = $arrival->company(auth()->user());
            $infodot = $arrival->infodot();
        @endphp
        <div class="me-flow__arrive" data-floor-arrival>
            <img class="fl-arrive__logo" src="{{ asset(\App\Livewire\Pulse\Floor\Support\Arrival::LOGO) }}" alt="Dot.Pulse" width="1181" height="886" decoding="async">
            <div>
                <p class="me-flow__welcome"><strong data-arrival-welcome>Welcome{{ $first !== '' ? ', '.$first : '' }}.</strong>@if ($company) You are here for <strong>{{ $company }}</strong>.@endif Dot.Pulse introduces businesses and people that fit, and says why. {{ $total }} short questions first, so nothing reaches you that you did not choose.</p>
                <nav class="fl-arrive__back" aria-label="Dot ecosystem">
                    <a href="{{ $infodot['home'] }}">InfoDot</a>
                    <a href="{{ $infodot['platforms'] }}">Platforms</a>
                </nav>
            </div>
        </div>
    @endif

    <div class="me-flow__top">
        <p class="me-flow__count pk-mono">Step {{ $step }} of {{ $total }}</p>
        <div class="me-progress" role="progressbar" aria-label="Welcome progress" aria-valuemin="1" aria-valuemax="{{ $total }}" aria-valuenow="{{ $step }}">
            <span style="transform: scaleX({{ $step / $total }})"></span>
        </div>
    </div>

    <form wire:submit="next" class="me-flow__step" wire:key="step-{{ $step }}"
          x-init="$nextTick(() => $el.querySelector('h1')?.focus({ preventScroll: true }))">

        @if ($key === 'age')
            <h1 class="me-flow__q" tabindex="-1">Pulse is for people who are 18 or over.</h1>
            <p class="me-flow__why">We ask once, and we keep the time you said it. Nobody under 18 has a profile here, gets matched or is shown to anyone.</p>
            <label class="me-confirm">
                <input type="checkbox" wire:model="adult" @error('adult') aria-invalid="true" aria-describedby="adult-error" @enderror>
                <span class="me-confirm__box" aria-hidden="true"><x-pulse.icon name="check" :size="16" /></span>
                <span>I am 18 or over.</span>
            </label>
            @error('adult')<p class="pk-field__error" id="adult-error"><x-pulse.icon name="alert" :size="16" /><span>{{ $message }}</span></p>@enderror
            <p class="me-flow__small">Under 18? Pulse is not for you yet, and that is the right answer. Come back when you are.</p>

        @elseif ($key === 'you')
            <h1 class="me-flow__q" tabindex="-1">What do you do?</h1>
            <p class="me-flow__why">This sets how Pulse talks to you. You can change it any time.</p>
            <fieldset class="me-choices">
                <legend class="sr-only">What you do</legend>
                @include('livewire.pulse.me.partials.choice', ['name' => 'kind', 'value' => 'individual', 'model' => 'kind', 'title' => 'I am here for myself', 'text' => 'To buy, sell, learn and ask. No work profile.'])
                @include('livewire.pulse.me.partials.choice', ['name' => 'kind', 'value' => 'professional', 'model' => 'kind', 'title' => 'I work in a trade or a profession', 'text' => 'A profile for what you do, with the option to be found for it.'])
            </fieldset>
            <x-pulse.chips name="openTo" legend="What are you open to? Pick any, or none." :options="\App\Livewire\Pulse\Me\Onboarding::OPEN_TO_LABELS" :selected="$openTo" wire:model="openTo" />

        @elseif ($key === 'place')
            <h1 class="me-flow__q" tabindex="-1">Where are you based?</h1>
            <p class="me-flow__why">Approximate on purpose. Pulse stores the place you pick, a city or a region, and never where you are standing. Without one, Pulse still matches you across the country.</p>
            @include('livewire.pulse.me.partials.place-picker')
            <x-pulse.toggle name="showPlace" label="Show my city on my profile" hint="Off means people see your region at most." :checked="$showPlace" wire:model="showPlace" />

        @elseif ($key === 'interests')
            <h1 class="me-flow__q" tabindex="-1">What can you do, and what do you care about?</h1>
            <p class="me-flow__why">This is how Pulse explains a match: "You need bookkeeping, they offer bookkeeping." You can remove any of it later.</p>
            @include('livewire.pulse.me.partials.tag-input', ['field' => 'skills', 'label' => 'What you can do', 'hint' => 'Skills, trades, tools. Up to 12.', 'placeholder' => 'Bookkeeping, plumbing, design', 'items' => $skills, 'suggestions' => $this->tagSuggestions('skills')])
            @include('livewire.pulse.me.partials.tag-input', ['field' => 'interests', 'label' => 'What you care about', 'hint' => 'Topics you want to hear about. Up to 15.', 'placeholder' => 'Cycling, solar, local food', 'items' => $interests, 'suggestions' => $this->tagSuggestions('interests')])

        @elseif ($key === 'discover')
            <h1 class="me-flow__q" tabindex="-1">Can employers find you?</h1>
            <p class="me-flow__why">Your choice, and it starts on no. You can change it in one tap on your profile.</p>
            <fieldset class="me-choices">
                <legend class="sr-only">Who can find you</legend>
                @include('livewire.pulse.me.partials.choice', ['live' => true, 'name' => 'discoverability', 'value' => 'off', 'model' => 'discoverability', 'title' => 'No one', 'text' => 'You do not show up when employers look. You can still look for work yourself.'])
                @include('livewire.pulse.me.partials.choice', ['live' => true, 'name' => 'discoverability', 'value' => 'anonymous', 'model' => 'discoverability', 'title' => 'Anonymously', 'text' => 'Employers find you by skills, experience, region and headline. Never your name, photo, email or exact place. If one invites you, you decide whether to reveal yourself.'])
                @include('livewire.pulse.me.partials.choice', ['live' => true, 'name' => 'discoverability', 'value' => 'open', 'model' => 'discoverability', 'title' => 'By name', 'text' => 'Employers find you with your name and photo, as well as your skills and region.'])
            </fieldset>
            @if ($discoverability !== 'off')
                <x-pulse.field name="headline" label="Your headline" hint="One line employers read first. No phone numbers, emails or links." wire:model="headline" maxlength="120" placeholder="Bookkeeper and payroll specialist" />
                <p class="me-flow__small">You also need at least one skill. You have {{ count($skills) }}.</p>
            @endif

        @elseif ($key === 'contact')
            <h1 class="me-flow__q" tabindex="-1">Who can message you first?</h1>
            <p class="me-flow__why">Strangers never get a straight line to you unless you choose that. People you already talk to are not strangers.</p>
            <fieldset class="me-choices">
                <legend class="sr-only">Who can message you first</legend>
                @include('livewire.pulse.me.partials.choice', ['name' => 'contactPolicy', 'value' => 'nobody', 'model' => 'contactPolicy', 'title' => 'Nobody new', 'text' => 'Nothing reaches you from people you have not talked to.'])
                @include('livewire.pulse.me.partials.choice', ['name' => 'contactPolicy', 'value' => 'context', 'model' => 'contactPolicy', 'title' => 'Only about something of mine', 'text' => 'A need, a door or a talent card of yours that is live. They send a request, you choose. Pulse starts you here.'])
                @include('livewire.pulse.me.partials.choice', ['name' => 'contactPolicy', 'value' => 'requests', 'model' => 'contactPolicy', 'title' => 'Anyone, as a request', 'text' => 'Anyone can send a request. Nothing reaches you until you accept it.'])
                @include('livewire.pulse.me.partials.choice', ['name' => 'contactPolicy', 'value' => 'anyone', 'model' => 'contactPolicy', 'title' => 'Anyone, directly', 'text' => 'Anyone can message you straight away. Quickest, and the noisiest.'])
            </fieldset>

        @elseif ($key === 'notify')
            <h1 class="me-flow__q" tabindex="-1">How should Pulse reach you?</h1>
            <p class="me-flow__why">Everything shows in the app. Email is for the things you would hate to miss. Pulse never sends you marketing from a business.</p>
            <div class="pk-stack" style="gap: 8px">
                <x-pulse.toggle name="emailConversations" label="Email me when a conversation or a result needs me" hint="Requests, replies, quotes, outcomes. About things you are part of." :checked="$emailConversations" wire:model="emailConversations" />
                <x-pulse.toggle name="digest" label="Send me a daily summary" hint="Off by default. Lists new matches, each with its reason. Sent only when there is something real in it." :checked="$digest" wire:model.live="digest" />
            </div>
            @if ($digest)
                <x-pulse.field name="digestHour" label="When should it arrive?" as="select" :options="array_combine($this->digestHours(), $this->digestHours())" :value="$digestHour" wire:model="digestHour" hint="In your time zone: {{ str_replace('_', ' ', $timezone) }}." />
            @endif
        @endif

        @if ($problem)<p role="alert" class="pk-field__error"><x-pulse.icon name="alert" :size="16" /><span>{{ $problem }}</span></p>@endif

        <div class="me-flow__actions">
            @if ($step > 1)<x-pulse.button variant="quiet" wire:click="back" type="button">Back</x-pulse.button>@endif
            <span class="me-flow__spacer"></span>
            @if ($skippable)<x-pulse.button variant="ghost" wire:click="skip" type="button">{{ $lastStep ? 'Skip and finish' : 'Skip this' }}</x-pulse.button>@endif
            <x-pulse.button variant="signal" type="submit" wire:loading.attr="disabled" wire:target="next,finish" data-primary-action>{{ $lastStep ? 'Finish and go to the Floor' : 'Continue' }}</x-pulse.button>
        </div>
        @if ($skippable)<p class="me-flow__small">Skipping keeps the careful default. Nothing here is permanent.</p>@endif
    </form>
</div>
