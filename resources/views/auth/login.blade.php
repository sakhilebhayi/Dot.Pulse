<x-guest-layout title="Sign in">
    <x-authentication-card heading="Come in through InfoDot." lead="InfoDot is the only door. One sign-in, and Pulse knows who you are.">
        <x-slot name="logo"></x-slot>

        <div class="pk-stack">
            <div>
                <h2>Continue with InfoDot</h2>
                <p class="pk-muted" style="margin-top: 6px">The button takes you to InfoDot. Sign in there, then open Dot.Pulse from your dashboard and you land back here signed in.</p>
            </div>

            @session('status')
                <p class="au-status" role="status">{{ $value }}</p>
            @endsession

            <x-pulse.button variant="signal" :href="pulse_infodot_url()" block>Sign in with InfoDot</x-pulse.button>
        </div>

        <details class="au-or" @if ($errors->any()) open @endif>
            <summary>Use a Pulse password instead</summary>
            <form method="POST" action="{{ route('login') }}" class="pk-stack">
                @csrf

                <x-pulse.field name="email" label="Email" type="email" :value="old('email')" required autocomplete="username" />
                <x-pulse.field name="password" label="Password" type="password" required autocomplete="current-password" />

                <label class="au-check" for="remember_me">
                    <x-checkbox id="remember_me" name="remember" />
                    <span>Keep me signed in</span>
                </label>

                <div class="pk-cluster" style="justify-content: space-between">
                    <x-pulse.button type="submit" variant="primary">Sign in</x-pulse.button>
                    @if (Route::has('password.request'))
                        <a href="{{ route('password.request') }}">Forgot your password?</a>
                    @endif
                </div>
            </form>
        </details>
    </x-authentication-card>
</x-guest-layout>
