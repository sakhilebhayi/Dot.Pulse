{{-- Jetstream's danger button, drawn in the Floor's bad colour. Always sits beside words that say what will happen. --}}
<button {{ $attributes->merge(['type' => 'button', 'class' => 'pk-btn pk-btn--danger']) }}>
    {{ $slot }}
</button>
