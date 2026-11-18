{{-- R-54: the claim page, in The Floor (the guest sign-in card). Shown when an account here already uses the email address
     of the InfoDot sign-in. Nothing on the account is replaced; the code proves the address. Variables come from the
     shared package. --}}
<x-guest-layout title="Confirm it is you">
    <x-authentication-card heading="Confirm it is you." lead="An account here already uses this email address. A code proves it is yours.">
        <x-slot name="logo"></x-slot>

        <div class="pk-stack">
            <p>We sent a {{ $codeLength }}-digit code to <strong>{{ $maskedEmail }}</strong>. Enter it to connect the two. Your password, your profile and your Lines stay as they are.</p>

            @if (session('dotsso_status'))
                <p class="au-status" role="status">{{ session('dotsso_status') }}</p>
            @endif

            <form method="POST" action="{{ $verifyUrl }}" class="pk-stack" novalidate>
                @csrf
                <x-pulse.field name="code" label="Code from the email" :error="$errors->first('code') ?: null"
                               :hint="'It works for '.$minutes.' minutes. You have '.$maxAttempts.' tries.'"
                               inputmode="numeric" pattern="[0-9]*" autocomplete="one-time-code" maxlength="{{ $codeLength }}"
                               required autofocus class="pk-control au-code" />
                <x-pulse.button type="submit" variant="signal" block>Confirm and continue</x-pulse.button>
            </form>

            <div class="au-claim-aside">
                <form method="POST" action="{{ $resendUrl }}">@csrf<x-pulse.button type="submit" variant="ghost">Send a new code</x-pulse.button></form>
                <form method="POST" action="{{ $cancelUrl }}">@csrf<x-pulse.button type="submit" variant="ghost">Cancel</x-pulse.button></form>
            </div>
        </div>
    </x-authentication-card>
</x-guest-layout>
