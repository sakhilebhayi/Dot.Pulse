{{-- R-61: InfoDot has switched Dot.Pulse off (maintenance or unavailable). Rendered with HTTP 503 by
     App\Http\Middleware\EnsureCompanyEntitled. It says so plainly and shows nothing from Pulse: what is here is kept as it is.
     It is not a purchase or permission problem, so there is no switch-company action. --}}
@extends('errors.layout')
@section('code', '503')
@section('title', 'Dot.Pulse is not available right now.')
@section('message', ($message ?? 'Dot.Pulse is temporarily unavailable.').' Your profile, pages, signals and lines are kept as they are.')
@section('actions')
    <a class="pk-btn pk-btn--signal" href="{{ url()->current() }}" data-primary-action>Try again</a>
@endsection
@section('after')
    @auth
        <div class="er-gate-others">
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button type="submit" class="er-gate-others__out">Sign out</button>
            </form>
        </div>
    @endauth
@endsection
