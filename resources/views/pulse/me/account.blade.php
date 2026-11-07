<x-app-layout title="Account and security" width="wide">
    <div class="me-page">
        @include('livewire.pulse.me.partials.tabs', ['active' => 'account', 'lede' => 'Your sign-in and your devices. Your name and email come from InfoDot.'])
        <div class="me-stack">
            @if (Laravel\Fortify\Features::enabled(Laravel\Fortify\Features::updatePasswords()))
                @livewire('profile.update-password-form')
            @endif

            @if (Laravel\Fortify\Features::canManageTwoFactorAuthentication())
                @livewire('profile.two-factor-authentication-form')
            @endif

            @livewire('profile.logout-other-browser-sessions-form')

            <section class="pk-card me-section jt-section" aria-labelledby="acct-erase">
                <h2 id="acct-erase">Erase your Pulse data</h2>
                <p class="me-section__text">Deleting what Pulse holds about you, and signing out, is on the Privacy page. Your Dot account itself belongs to InfoDot.</p>
                <div><x-pulse.button variant="quiet" :href="pulse_route('me.privacy')">Go to Privacy</x-pulse.button></div>
            </section>
        </div>
    </div>
</x-app-layout>
