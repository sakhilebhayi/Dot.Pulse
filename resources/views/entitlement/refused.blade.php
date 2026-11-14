{{-- R-50: the company does not hold Dot.Pulse. Reuses the error shell (errors.layout) with ONE primary action; the
     person's other companies, if any, are quiet switches below it. Rendered with HTTP 403 by App\Support\EntitlementRefusal.
     It shows nothing from Pulse: no profile, no signals, no counts. --}}
@extends('errors.layout')
@section('code', '403')
@section('title', $title)
@section('message', $body)
@section('actions')
    <a class="pk-btn pk-btn--signal" href="{{ $platformsUrl }}" data-primary-action>Open Platforms in InfoDot</a>
@endsection
@section('after')
    @auth
        <div class="er-gate-others">
            @if ($otherCompanies->isNotEmpty())
                <p class="er-gate-others__label">Your other {{ $otherCompanies->count() === 1 ? 'company' : 'companies' }} here</p>
                <ul>
                    @foreach ($otherCompanies as $other)
                        <li>
                            <span>{{ $other->name }}</span>
                            <form method="POST" action="{{ route('current-team.update') }}">
                                @csrf
                                @method('PUT')
                                <input type="hidden" name="team_id" value="{{ $other->id }}">
                                <button type="submit" class="pk-btn pk-btn--quiet" aria-label="Switch to {{ $other->name }}">Switch</button>
                            </form>
                        </li>
                    @endforeach
                </ul>
            @endif
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button type="submit" class="er-gate-others__out">Sign out</button>
            </form>
        </div>
    @endauth
@endsection
