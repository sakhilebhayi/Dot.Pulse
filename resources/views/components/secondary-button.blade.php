{{-- Jetstream's secondary button, drawn as the Floor's quiet button. --}}
<button {{ $attributes->merge(['type' => 'button', 'class' => 'pk-btn pk-btn--quiet']) }}>
    {{ $slot }}
</button>
