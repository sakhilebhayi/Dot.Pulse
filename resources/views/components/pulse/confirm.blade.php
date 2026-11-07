{{--
    pulse/confirm
    A confirm dialog for anything that cannot be undone, in plain words. Open with data-pk-dialog="the-id". The confirm button takes
    the action: pass wire:click or a form attribute through the `confirm` named attributes by using the `action` slot, or just
    give the component a wire:click and it lands on the confirm button.

    Props: id, title, text, label (confirm button text, default Confirm), cancel (default Cancel), tone (danger|primary)
--}}
@props(['id', 'title', 'text' => null, 'label' => 'Confirm', 'cancel' => 'Cancel', 'tone' => 'danger'])
<dialog id="{{ $id }}" class="pk-dialog pk-dialog--center" role="alertdialog" aria-labelledby="{{ $id }}-title" @if($text) aria-describedby="{{ $id }}-text" @endif>
    <div class="pk-dialog__panel">
        <div class="pk-dialog__head">
            <h2 id="{{ $id }}-title">{{ $title }}</h2>
        </div>
        <div class="pk-dialog__body">
            @if ($text)<p id="{{ $id }}-text">{{ $text }}</p>@endif
            {{ $slot }}
        </div>
        <div class="pk-dialog__foot">
            <button type="button" class="pk-btn pk-btn--quiet" data-pk-close autofocus>{{ $cancel }}</button>
            <button type="{{ $attributes->get('type', 'button') }}" {{ $attributes->except('type')->merge(['class' => 'pk-btn pk-btn--'.($tone === 'danger' ? 'danger' : 'primary'), 'data-pk-close' => true]) }}>{{ $label }}</button>
        </div>
    </div>
</dialog>
