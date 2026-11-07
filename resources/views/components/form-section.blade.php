{{-- Jetstream's form section drawn as a Floor card: title and line above, the fields, the save button. One column. --}}
@props(['submit'])
<section {{ $attributes->merge(['class' => 'pk-card me-section jt-section']) }}>
    <header class="jt-head">
        <h2>{{ $title }}</h2>
        <p class="me-section__text">{{ $description }}</p>
    </header>
    <form wire:submit="{{ $submit }}" class="jt-form">
        <div class="jt-fields">{{ $form }}</div>
        @if (isset($actions))
            <div class="jt-actions">{{ $actions }}</div>
        @endif
    </form>
</section>
