@props(['value'])

<label {{ $attributes->merge(['class' => 'block text-sm font-bold']) }}>
    {{ $value ?? $slot }}
</label>
