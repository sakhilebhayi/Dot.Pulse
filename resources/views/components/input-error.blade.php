@props(['for'])

@error($for)
    <p {{ $attributes->merge(['class' => 'pk-field__error', 'style' => 'margin-top: 6px']) }}>{{ $message }}</p>
@enderror
