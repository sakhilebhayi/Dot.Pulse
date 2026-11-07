{{--
    pulse/toggle  (the consent row)
    One plain sentence and one switch. Use it for every permission Pulse relies on, so the person reads what they are agreeing to
    and can withdraw it with one tap. The control is a real checkbox with role="switch"; the state is also written (On, Off).

    Props: name, label (the sentence), hint (optional), checked (bool), id
    Extra attributes (wire:model.live, value) go to the checkbox.
--}}
@props(['name', 'label', 'hint' => null, 'checked' => false, 'id' => null])
@php $id = $id ?: $name; @endphp
<label class="pk-toggle" for="{{ $id }}">
    <span class="pk-toggle__text">
        <span class="pk-toggle__label">{{ $label }}</span>
        @if ($hint)<span class="pk-toggle__hint" id="{{ $id }}-hint">{{ $hint }}</span>@endif
    </span>
    <span class="pk-toggle__switch">
        <input type="checkbox" role="switch" id="{{ $id }}" name="{{ $name }}" value="1" @checked(old($name, $checked)) @if($hint) aria-describedby="{{ $id }}-hint" @endif {{ $attributes }}>
        <span class="pk-toggle__track"></span>
        <span class="pk-toggle__thumb"></span>
    </span>
</label>
