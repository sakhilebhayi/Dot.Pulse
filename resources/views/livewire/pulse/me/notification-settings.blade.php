<form wire:submit="save" class="me-section" aria-labelledby="ns-title" x-data>
    <h2 id="ns-title">What reaches you</h2>
    <p class="me-section__text">Everything shows in the app. Email is yours to set. Pulse never emails you marketing from a business.</p>

    <div class="me-cats">
        @foreach (\App\Livewire\Pulse\Me\NotificationSettings::LABELS as $cat => [$name, $text])
            @php $always = in_array($cat, \App\Services\Pulse\Lines\NotificationPrefs::TRANSACTIONAL, true); @endphp
            <fieldset class="me-cat" wire:key="cat-{{ $cat }}">
                <legend>{{ $name }}</legend>
                <p class="me-cat__text">{{ $text }}</p>
                <div class="me-cat__ctl">
                    @if ($always)
                        <p class="me-always"><x-pulse.icon name="check" :size="14" />In the app: always</p>
                    @else
                        <x-pulse.toggle :name="'inApp-'.$cat" :id="'inapp-'.$cat" label="In the app" :checked="$inApp[$cat] ?? true" wire:model="inApp.{{ $cat }}" />
                    @endif
                    @if ($cat === 'system')
                        <p class="me-always"><x-pulse.icon name="check" :size="14" />By email: always</p>
                    @else
                        <x-pulse.toggle :name="'email-'.$cat" :id="'email-'.$cat" label="By email" :checked="$email[$cat] ?? false" wire:model="email.{{ $cat }}" />
                    @endif
                </div>
            </fieldset>
        @endforeach
    </div>

    <fieldset class="me-choices">
        <legend class="pk-field__label">How fast email arrives</legend>
        @include('livewire.pulse.me.partials.choice', ['name' => 'frequency', 'value' => 'instant', 'model' => 'frequency', 'title' => 'Right away', 'text' => 'One email when it happens.'])
        @include('livewire.pulse.me.partials.choice', ['name' => 'frequency', 'value' => 'daily', 'model' => 'frequency', 'title' => 'Daily', 'text' => 'Held back and folded into your daily summary.'])
        @include('livewire.pulse.me.partials.choice', ['name' => 'frequency', 'value' => 'weekly', 'model' => 'frequency', 'title' => 'Weekly', 'text' => 'Held back and folded into one weekly summary.'])
        @include('livewire.pulse.me.partials.choice', ['name' => 'frequency', 'value' => 'off', 'model' => 'frequency', 'title' => 'Off', 'text' => 'No email from Pulse. Safety messages about your account still reach you.'])
    </fieldset>

    <div class="me-subsection">
        <h3>Daily summary</h3>
        <x-pulse.toggle name="digest" label="Send me a daily summary" hint="Off until you turn it on. Sent only when there is something real in it, with a link that switches it off." :checked="$digest" wire:model.live="digest" />
        @if ($digest)
            <x-pulse.select name="digestHour" label="Arrives at" :options="array_combine($this->hours(), $this->hours())" :value="$digestHour" wire:model="digestHour" />
        @endif
    </div>

    <div class="me-subsection">
        <h3>Time and quiet hours</h3>
        <div class="me-zone">
            <x-pulse.select name="timezone" label="Your time zone" :options="$this->zones()" :value="$timezone" wire:model="timezone" />
            <x-pulse.button variant="quiet" size="sm" type="button" x-on:click="$wire.useDeviceZone(Intl.DateTimeFormat().resolvedOptions().timeZone)">Use this device's zone</x-pulse.button>
        </div>
        <p class="me-section__text">During quiet hours Pulse holds email. In app notifications still arrive.</p>
        <div class="me-quiet">
            <x-pulse.field name="quietStart" type="time" label="Quiet from" wire:model="quietStart" optional />
            <x-pulse.field name="quietEnd" type="time" label="Until" wire:model="quietEnd" optional />
            @if ($quietStart || $quietEnd)<x-pulse.button variant="quiet" size="sm" type="button" wire:click="clearQuiet">Clear quiet hours</x-pulse.button>@endif
        </div>
    </div>

    <div class="me-savebar">
        <x-pulse.button variant="signal" type="submit" wire:loading.attr="disabled" wire:target="save">Save notification settings</x-pulse.button>
    </div>
</form>
