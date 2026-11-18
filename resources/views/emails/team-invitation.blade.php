@component('mail::message')
{{ __('You have been invited to join the :team team!', ['team' => $invitation->team->name]) }}

@if (Laravel\Fortify\Features::enabled(Laravel\Fortify\Features::registration()))
{{ __('If you do not have an account, you may create one by clicking the button below. After creating an account, you may click the invitation acceptance button in this email to accept the team invitation:') }}

@component('mail::button', ['url' => route('register')])
{{ __('Create Account') }}
@endcomponent

{{ __('If you already have an account, you may accept this invitation by clicking the button below:') }}

@elseif (\App\Support\CentralSignIn::active())
{{ __('If you do not have an account yet, create one on InfoDot first (you come straight back here), then accept the invitation with the button below:') }}

@component('mail::button', ['url' => \App\Support\CentralSignIn::register(route('login'))])
{{ __('Create Account') }}
@endcomponent

{{ __('If you already have an account, you may accept this invitation by clicking the button below:') }}

@else
{{ __('You may accept this invitation by clicking the button below:') }}
@endif


@component('mail::button', ['url' => $acceptUrl])
{{ __('Accept Invitation') }}
@endcomponent

{{ __('If you did not expect to receive an invitation to this team, you may discard this email.') }}
@endcomponent
