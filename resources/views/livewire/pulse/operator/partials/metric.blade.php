{{--
    One operator metric: label, the number (or "No data yet"), an optional breakdown, and the definition in one line.
    Vars: label, value (string|null), scope ('now'|'range'), definition, note (optional), rows (optional label => string)
--}}
<div class="op-metric{{ ($lead ?? false) ? ' op-metric--lead' : '' }}">
    <div class="op-metric__head">
        <h3 class="op-metric__label">{{ $label }}</h3>
        @if ($showScope ?? false)<span class="op-metric__scope">{{ ($scope ?? 'range') === 'now' ? 'Right now' : 'In range' }}</span>@endif
    </div>
    @if ($value === null)
        <p class="op-metric__none">No data yet</p>
    @else
        <p class="op-metric__value">{{ $value }}</p>
    @endif
    @if (! empty($note) && $value !== null)<p class="op-metric__note">{{ $note }}</p>@endif
    @if (! empty($rows) && $value !== null)
        <dl class="op-metric__rows">
            @foreach ($rows as $rowLabel => $rowValue)
                <div><dt>{{ $rowLabel }}</dt><dd>{{ $rowValue }}</dd></div>
            @endforeach
        </dl>
    @endif
    <p class="op-metric__def">{{ $definition }}</p>
</div>
