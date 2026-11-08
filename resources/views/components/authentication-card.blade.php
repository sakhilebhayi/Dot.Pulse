{{--
    The sign-in card on the guest shell. A bold line on the left says where you are, the card on the right does the work.
    Props: heading (the large line), lead (one sentence under it)
--}}
@props(['heading' => 'Come in.', 'lead' => 'Pulse introduces the people and businesses that fit, and always says why.'])
<div class="pub-wrap au-main">
    <div class="au-grid">
        <div class="au-aside">
            <h1>{{ $heading }}</h1>
            <p>{{ $lead }}</p>
        </div>
        <div {{ $attributes->merge(['class' => 'au-card']) }}>
            {{ $slot }}
        </div>
    </div>
</div>
