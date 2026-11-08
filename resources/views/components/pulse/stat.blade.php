{{--
    pulse/stat
    One real number with its label. Never pass a number that was not counted from real rows.
    Props: label, value, note (optional, e.g. "since 1 Nov")
--}}
@props(['label', 'value', 'note' => null])
<div {{ $attributes->merge(['class' => 'pk-stat']) }}>
    @php $figure = preg_match('/^[R$\d\s.,%]+( of [\d\s.,]+)?$/', trim((string) $value)); @endphp
    <span class="pk-stat__value{{ $figure ? '' : ' pk-stat__value--text' }}">{{ $value }}</span>
    <span class="pk-stat__label">{{ $label }}</span>
    @if ($note)<span class="pk-stat__note">{{ $note }}</span>@endif
</div>
