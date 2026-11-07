{{--
    What an employer may know of an anonymous professional: a headline, skills, an experience band and a region. Nothing else.
    Needs: $snap (the line's context snapshot).
--}}
@if (! empty($snap['headline']) || ! empty($snap['skills']) || ! empty($snap['experience_band']) || ! empty($snap['region']))
    <dl class="ln-facts ln-anoncard">
        @if (! empty($snap['headline']))<div class="ln-facts__row ln-facts__row--wide"><dt>Headline</dt><dd>{{ $snap['headline'] }}</dd></div>@endif
        @if (! empty($snap['experience_band']))<div class="ln-facts__row"><dt>Experience</dt><dd>{{ $snap['experience_band'] }}</dd></div>@endif
        @if (! empty($snap['region']))<div class="ln-facts__row"><dt>Region</dt><dd>{{ $snap['region'] }}</dd></div>@endif
        @if (! empty($snap['skills']))
            <div class="ln-facts__row ln-facts__row--wide">
                <dt>Skills</dt>
                <dd class="ln-skills">@foreach ($snap['skills'] as $skill)<span class="ln-skill">{{ $skill }}</span>@endforeach</dd>
            </div>
        @endif
    </dl>
@endif
