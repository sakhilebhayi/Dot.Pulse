{{-- R-54: every sign-in failure ends here, in words, with one next step and no automatic redirect. --}}
<x-guest-layout title="Sign-in problem">
    <x-authentication-card heading="That sign-in did not finish." lead="Nothing was changed on your account.">
        <x-slot name="logo"></x-slot>

        <div class="pk-stack" role="alert">
            <div>
                <h2>{{ $headline }}</h2>
                <p class="pk-muted" style="margin-top: 6px">{{ $body }}</p>
            </div>
            @if ($retry)<x-pulse.button variant="signal" :href="$retryUrl" block>Start again</x-pulse.button>@endif
            <x-pulse.button variant="quiet" :href="$homeUrl" block>Back to Dot.Pulse</x-pulse.button>
            <p class="pk-muted">Reference: {{ $code }}</p>
        </div>
    </x-authentication-card>
</x-guest-layout>
