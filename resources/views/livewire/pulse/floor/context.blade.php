{{-- Your desk: one label, one value, one place to act, per row. Real counts of the person's own records only. --}}
<section class="fl-desk" aria-labelledby="fl-desk-h" data-floor-desk>
    <div class="fl-lh"><h2 id="fl-desk-h" class="fl-lh__title">Your desk</h2></div>
    <dl class="fl-kv">
        <div>
            <dt>Door</dt>
            @if ($door)
                <dd>Open until {{ $doorUntil }}</dd>
                <dd class="fl-kv__act"><a href="{{ pulse_route('door') }}">Manage your door</a></dd>
            @else
                <dd class="fl-kv__zero">Closed</dd>
                <dd class="fl-kv__act"><a href="{{ pulse_route('door') }}">Open a door</a></dd>
            @endif
        </div>
        <div>
            <dt>Lines</dt>
            @if ($lines > 0)
                <dd>{{ $lines }} with something new</dd>
                <dd class="fl-kv__act"><a href="{{ pulse_route('lines') }}">Open Lines</a></dd>
            @else
                <dd class="fl-kv__zero">None waiting</dd>
                @endif
        </div>
        <div>
            <dt>Requests</dt>
            <dd @class(['pk-mono' => $requests > 0, 'fl-kv__zero' => $requests === 0])>{{ $requests > 0 ? $requests.' waiting for an answer' : 'None waiting' }}</dd>
        </div>
        <div>
            <dt>Needs open</dt>
            <dd class="pk-mono">{{ $needs }}</dd>
            <dd class="fl-kv__act"><a href="{{ $action['href'] }}">{{ $action['label'] }}</a></dd>
        </div>
        <div>
            <dt>Roles live</dt>
            <dd class="pk-mono">{{ $roles }}</dd>
        </div>
        <div>
            <dt>Saved</dt>
            <dd class="pk-mono">{{ $saved }}</dd>
            <dd class="fl-kv__act"><a href="{{ pulse_route('saved') }}">Saved beats</a></dd>
        </div>
        <div>
            <dt>Quiet</dt>
            <dd class="fl-kv__zero">Hours, digest, mutes</dd>
            <dd class="fl-kv__act"><a href="{{ pulse_route_first(['me.notifications', 'profile.show']) }}">Quiet settings</a></dd>
        </div>
    </dl>
</section>
