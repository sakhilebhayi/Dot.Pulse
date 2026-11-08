{{-- Jetstream's primary button, drawn as the Floor's cobalt button. New screens use <x-pulse.button>. --}}
<button {{ $attributes->merge(['type' => 'submit', 'class' => 'pk-btn pk-btn--primary']) }}>
    {{ $slot }}
</button>
