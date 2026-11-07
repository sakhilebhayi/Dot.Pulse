{{-- Jetstream's action section drawn as a Floor card. --}}
<section {{ $attributes->merge(['class' => 'pk-card me-section jt-section']) }}>
    <header class="jt-head">
        <h2>{{ $title }}</h2>
        <p class="me-section__text">{{ $description }}</p>
    </header>
    <div class="jt-content">{{ $content }}</div>
</section>
