{{--
    Earned reviews: written blind, shown together. Needs: $r (reviewState).
    Authors are never named here: "Your review" and "Their review", so an anonymous line stays anonymous.
--}}
@php
    use App\Livewire\Pulse\Lines\Support\Words;
    $stars = fn (int $n) => collect(range(1, 5))->map(fn ($i) => '<span class="ln-star'.($i <= $n ? ' is-on' : '').'" aria-hidden="true"></span>')->implode('');
@endphp
<section class="pk-card ln-review" aria-labelledby="ln-review-h">
    <h2 id="ln-review-h" class="ln-subhead">Reviews</h2>

    @if ($r['mine'] && $r['theirs'])
        <p class="ln-muted">Both of you have written one, so both are showing.</p>
    @elseif ($r['mine'])
        <p class="ln-muted">Written. Theirs shows with yours once they have written theirs, or on {{ Words::date($r['reveals_on']) }}, whichever comes first.</p>
    @elseif ($r['can_write'])
        <p class="ln-muted">Reviews are written blind. You do not see theirs, and they do not see yours, until you have both written one or 14 days pass. That way nobody writes back at the other.</p>
    @endif

    @foreach (['mine' => 'Your review', 'theirs' => 'Their review'] as $key => $title)
        @if ($r[$key])
            <article class="ln-review__item" aria-label="{{ $title }}">
                <div class="ln-review__top">
                    <h3>{{ $title }}</h3>
                    <span class="ln-stars" role="img" aria-label="{{ $r[$key]->rating }} out of 5">{!! $stars($r[$key]->rating) !!}</span>
                    <span class="pk-mono">{{ $r[$key]->rating }}/5</span>
                </div>
                @if ($r[$key]->body)<p>{{ $r[$key]->body }}</p>@endif
                @if ($key === 'mine' && $r['mine']->moderation === 'held')<p class="ln-muted">A moderator is reading this before it shows.</p>@endif
                <p class="ln-muted">Shown as {{ $r[$key]->pseudonymous ? 'a person without a name' : 'a first name and initial' }}.</p>
            </article>
        @endif
    @endforeach

    @if ($r['can_write'])
        <form wire:submit="submitReview" class="ln-reviewform">
            <fieldset class="ln-rating">
                <legend>Your rating</legend>
                <div class="ln-rating__list">
                    @foreach ([1 => 'Poor', 2 => 'Fair', 3 => 'Good', 4 => 'Very good', 5 => 'Excellent'] as $n => $word)
                        <label class="ln-rate">
                            <input type="radio" name="rating" value="{{ $n }}" wire:model="review.rating">
                            <span><strong>{{ $n }}</strong><small>{{ $word }}</small></span>
                        </label>
                    @endforeach
                </div>
                @error('review.rating')<p class="pk-field__error"><x-pulse.icon name="alert" :size="16" /><span>{{ $message }}</span></p>@enderror
            </fieldset>

            <x-pulse.field as="textarea" name="review.body" id="review-body" label="What happened" optional wire:model="review.body" rows="4" maxlength="{{ config('pulse.lines.review_max', 1000) }}" hint="Short and true. No links or contact details." />

            @if ($r['force_pseudonym'])
                <p class="ln-note"><x-pulse.icon name="lock" :size="16" />This line is anonymous, so your review is shown without your name.</p>
            @else
                <x-pulse.toggle name="review_pseudonymous" label="Show me without my name" hint="Your review reads as from a customer, a partner or someone who was hired. Pulse still knows it was real." wire:model="review.pseudonymous" />
            @endif

            <x-pulse.button type="submit" variant="signal">Write the review</x-pulse.button>
        </form>
    @elseif ($r['closed'])
        <p class="ln-muted">The time to write a review for this has passed.</p>
    @elseif ($r['unavailable'])
        <p class="ln-muted">You can review once they have shown who they are.</p>
    @endif
</section>
