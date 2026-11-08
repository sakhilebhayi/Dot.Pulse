{{--
    pulse/drawer  (drawer and sheet)
    A native <dialog>: focus is trapped, Escape closes, the page behind is inert, the backdrop closes it. Open it with any element
    that has data-pk-dialog="the-id". Close it with data-pk-close.

    Props: id, title, side (right = drawer, bottom = sheet, center), description (optional sentence under the title)
    Slots: default (body), footer (buttons)
--}}
@props(['id', 'title', 'side' => 'right', 'description' => null])
<dialog id="{{ $id }}" class="pk-dialog pk-dialog--{{ $side }}" aria-labelledby="{{ $id }}-title" @if($description) aria-describedby="{{ $id }}-desc" @endif {{ $attributes }}>
    <div class="pk-dialog__panel">
        <div class="pk-dialog__head">
            <div>
                <h2 id="{{ $id }}-title">{{ $title }}</h2>
                @if ($description)<p id="{{ $id }}-desc" class="pk-muted" style="margin-top: 6px">{{ $description }}</p>@endif
            </div>
            <button type="button" class="pk-dialog__x" data-pk-close aria-label="Close"><x-pulse.icon name="x" /></button>
        </div>
        <div class="pk-dialog__body">{{ $slot }}</div>
        @isset($footer)<div class="pk-dialog__foot">{{ $footer }}</div>@endisset
    </div>
</dialog>
