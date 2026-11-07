{{-- The enquiry card: at most four plain fields. Needs: $card (array). --}}
@php
    $labels = ['what' => 'What', 'when_or_qty' => 'When or how many', 'where' => 'Where', 'budget_band' => 'Budget'];
    $filled = collect($labels)->filter(fn ($label, $key) => ! empty($card[$key]));
@endphp
@if ($filled->isNotEmpty())
    <dl class="ln-facts">
        @foreach ($filled as $key => $label)
            <div class="ln-facts__row{{ $key === 'what' ? ' ln-facts__row--wide' : '' }}">
                <dt>{{ $label }}</dt>
                <dd>{{ $card[$key] }}</dd>
            </div>
        @endforeach
    </dl>
@endif
