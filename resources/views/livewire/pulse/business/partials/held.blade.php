{{-- Waiting for a human: why a listing is not on the floor. Props: state (pending|held|removed), what (page|offer|role|need). --}}
@php
    $why = match ($state) {
        'removed' => 'A moderator removed this '.$what.'. It cannot be put back by you. Contact support if you think it was a mistake.',
        'held' => 'The automatic check flagged something in the text, often a link, a phone number, shouting or repeated words. A person will look at it, usually within a day. Editing the text puts it back in the queue.',
        default => 'Pulse does not know you well enough yet to publish this without a look. A person will check it. Linking an InfoDot company you belong to helps next time.',
    };
@endphp
<div class="bz-notice bz-notice--warn" role="status">
    <x-pulse.icon name="alert" :size="18" />
    <div>
        <strong>{{ $state === 'removed' ? 'Removed' : 'Waiting for a human' }}</strong>
        <p>Only your team can see this {{ $what }} for now. {{ $why }}</p>
    </div>
</div>
