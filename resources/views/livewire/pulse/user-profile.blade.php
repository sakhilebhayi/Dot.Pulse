@php
    $card = $this->card;
    $profile = $this->profile;
    $own = $this->isOwnProfile;
    $open = $card !== null;
    $contact = $this->contact;
    $openLabels = \App\Livewire\Pulse\Me\Onboarding::OPEN_TO_LABELS;
    $mode = $profile?->discoverability ?? 'off';
    $name = $profileUser->name;
    $typeWords = ['discussion' => 'Discussion', 'question' => 'Question', 'announcement' => 'Announcement', 'solution' => 'Solution'];
@endphp
<div class="up">
    <a class="up-back" href="{{ pulse_route_first(['floor', 'dashboard']) }}">Back to the floor</a>

    <header class="pk-card up-head">
        <x-pulse.avatar :name="$name" :src="$open ? ($card['avatar_url'] ?? null) : null" size="xl" />
        <div class="up-head__main">
            <h1 class="up-name">{{ $name }}</h1>
            <div class="pk-cluster">
                @if ($open && $profile->kind === 'professional')<x-pulse.pill>Professional</x-pulse.pill>@endif
                @if ($profile?->role && $profile->role !== 'customer')<x-pulse.pill>{{ ucfirst($profile->role) }}</x-pulse.pill>@endif
                @if ($own)<x-pulse.pill tone="signal">This is you</x-pulse.pill>@endif
            </div>
            @if ($open && ! empty($card['headline']))<p class="up-headline">{{ $card['headline'] }}</p>@endif
            @if ($open && (! empty($card['place']) || ! empty($card['experience_band'])))
                <div class="pk-cluster up-facts">
                    @if (! empty($card['place']))<x-pulse.place-label :name="$card['place']" />@endif
                    @if (! empty($card['experience_band']))<span class="pk-muted">{{ $card['experience_band']['label'] }}</span>@endif
                </div>
            @endif
        </div>

        <div class="up-actions">
            @if ($own)
                <x-pulse.button variant="primary" :href="pulse_route('me.profile')">Edit your profile</x-pulse.button>
            @else
                <livewire:pulse.follow-button :user="$profileUser" wire:key="follow-{{ $profileUser->id }}" />
                @if ($contact && $contact['allowed'])
                    <livewire:pulse.knock-sheet :target="['user' => $profileUser->id]" kind="intro" label="Knock" wire:key="knock-{{ $profileUser->id }}" />
                @endif
                @can('report', $profileUser)
                    <livewire:pulse.report-button target-type="user" :target-id="$profileUser->id" wire:key="report-user-{{ $profileUser->id }}" />
                @endcan
            @endif
        </div>

        @if (! $own && $contact && ! $contact['allowed'])
            <p class="up-why"><x-pulse.icon name="lock" :size="16" /><span>{{ $contact['why'] }}</span></p>
        @elseif (! $own && $contact && $contact['allowed'])
            <p class="up-why"><x-pulse.icon name="info" :size="16" /><span>{{ $contact['mode'] === 'direct' ? 'You can message them directly.' : 'A knock goes to them as a request. They choose whether to answer.' }}</span></p>
        @endif
    </header>

    @if ($own)
        <section class="up-own" aria-label="How your page looks to others">
            <x-pulse.icon name="shield" :size="18" />
            <p>
                @if ($mode === 'open')
                    Employers can find you by name, and people see everything below.
                @elseif ($mode === 'anonymous')
                    You are anonymous to employers, so nobody else can open this page. Only you see it.
                @else
                    Nobody can find you as a professional. Other people see your name and your posts only.
                @endif
                <a href="{{ pulse_route('me.discover') }}">Change who can find you</a>
            </p>
        </section>
    @endif

    @if ($open)
        @if (! empty($card['bio']))
            <section class="pk-card up-section" aria-labelledby="up-about"><h2 id="up-about">About</h2><p class="up-prose">{{ $card['bio'] }}</p></section>
        @endif

        @if (! empty($card['skills']) || ! empty($card['open_to']) || ! empty($card['languages']) || ! empty($card['interests']))
            <section class="pk-card up-section" aria-labelledby="up-skills">
                <h2 id="up-skills">What they do</h2>
                @if (! empty($card['skills']))
                    <ul class="me-tags__list" aria-label="Skills">@foreach ($card['skills'] as $s)<li><span class="me-chip me-chip--static">{{ $s }}</span></li>@endforeach</ul>
                @endif
                <ul class="me-facts">
                    @if (! empty($card['open_to']))<li><span class="me-facts__k">Open to</span><span>{{ collect($card['open_to'])->map(fn ($v) => $openLabels[$v] ?? $v)->implode(', ') }}</span></li>@endif
                    @if (! empty($card['languages']))<li><span class="me-facts__k">Languages</span><span>{{ implode(', ', $card['languages']) }}</span></li>@endif
                    @if (! empty($card['interests']))<li><span class="me-facts__k">Interests</span><span>{{ implode(', ', $card['interests']) }}</span></li>@endif
                </ul>
            </section>
        @endif

        @if (! empty($card['achievements']))
            <section class="pk-card up-section" aria-labelledby="up-ach"><h2 id="up-ach">Achievements</h2>
                <ul class="me-bullets">@foreach ($card['achievements'] as $a)<li>{{ $a }}</li>@endforeach</ul>
            </section>
        @endif

        @if (! empty($card['work_history']))
            <section class="pk-card up-section" aria-labelledby="up-work"><h2 id="up-work">Work</h2>
                <ol class="up-jobs">
                    @foreach ($card['work_history'] as $job)
                        <li><span class="up-jobs__title">{{ $job['title'] ?? '' }}</span>
                            <span class="pk-muted">{{ $job['org'] ?? '' }}@if (! empty($job['from_year'])), <span class="pk-mono">{{ $job['from_year'] }} to {{ ! empty($job['current']) ? 'now' : ($job['to_year'] ?? 'now') }}</span>@endif</span></li>
                    @endforeach
                </ol>
            </section>
        @endif
    @endif

    <section class="pk-card up-section" aria-labelledby="up-posts">
        <div class="me-section__head">
            <h2 id="up-posts">Posts</h2>
            <div class="pk-cluster">
                <x-pulse.button variant="ghost" size="sm" data-pk-dialog="up-followers" type="button">Followers</x-pulse.button>
                <x-pulse.button variant="ghost" size="sm" data-pk-dialog="up-following" type="button">Following</x-pulse.button>
            </div>
        </div>
        @if ($this->posts->isEmpty())
            <x-pulse.empty title="No posts yet." text="Nothing published to show." />
        @else
            <ul class="me-list">
                @foreach ($this->posts as $post)
                    <li wire:key="post-{{ $post->id }}">
                        <div class="pk-row">
                            <div class="pk-row__main">
                                <div class="pk-row__title"><a href="{{ route('posts.show', $post) }}">{{ $post->title ?: \Illuminate\Support\Str::limit($post->body, 80) }}</a></div>
                                @if ($post->title)<p class="up-excerpt">{{ \Illuminate\Support\Str::limit($post->body, 140) }}</p>@endif
                                <div class="pk-row__meta">{{ $typeWords[$post->type] ?? ucfirst(str_replace('_', ' ', $post->type)) }}@if ($post->community), in {{ $post->community->name }}@endif, {{ $post->created_at->diffForHumans() }}</div>
                            </div>
                        </div>
                    </li>
                @endforeach
            </ul>
        @endif
    </section>

    @foreach (['followers' => 'Followers', 'following' => 'Following'] as $listMode => $listTitle)
        <dialog id="up-{{ $listMode }}" class="pk-dialog pk-dialog--center" aria-labelledby="up-{{ $listMode }}-title">
            <div class="pk-dialog__panel">
                <div class="pk-dialog__head"><h2 id="up-{{ $listMode }}-title">{{ $listTitle }}</h2><button type="button" class="pk-dialog__x" data-pk-close aria-label="Close {{ strtolower($listTitle) }}"><x-pulse.icon name="x" /></button></div>
                <div class="pk-dialog__body"><livewire:pulse.follow-list :user="$profileUser" :mode="$listMode" wire:key="list-{{ $listMode }}-{{ $profileUser->id }}" /></div>
            </div>
        </dialog>
    @endforeach
</div>
