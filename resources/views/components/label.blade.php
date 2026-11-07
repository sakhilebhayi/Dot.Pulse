@props(['value'])

<label {{ $attributes->merge(['class' => 'block text-sm font-bold', 'style' => 'font-stretch: 104%']) }}>
    {{ $value ?? $slot }}
</label>
