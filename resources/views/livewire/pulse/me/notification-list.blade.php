@php
    $words = ['lines' => 'Conversation', 'outcomes' => 'Outcome', 'system' => 'Account', 'matches' => 'Match', 'digest' => 'Summary', 'community' => 'Community'];
@endphp
<section class="me-section" aria-labelledby="nl-title">
    <div class="me-section__head">
        <h2 id="nl-title">What happened</h2>
        <div class="pk-cluster">
            <x-pulse.button variant="{{ $unreadOnly ? 'primary' : 'quiet' }}" size="sm" wire:click="$toggle('unreadOnly')" type="button" aria-pressed="{{ $unreadOnly ? 'true' : 'false' }}">Unread only</x-pulse.button>
            @if ($this->unreadCount > 0)
                <x-pulse.button variant="quiet" size="sm" wire:click="markAllRead" type="button">Mark all read</x-pulse.button>
            @endif
        </div>
    </div>

    @if ($this->notifications->isEmpty())
        <x-pulse.empty :title="$unreadOnly ? 'Nothing unread.' : 'Nothing yet.'" :text="$unreadOnly ? 'You are caught up.' : 'When someone answers a request, a quote lands or a result is confirmed, it shows here.'" />
    @else
        <ul class="me-list" wire:loading.class="is-busy">
            @foreach ($this->notifications->take($limit) as $n)
                @php $row = $this->presentNotification($n); $unread = is_null($n->read_at); @endphp
                <li wire:key="n-{{ $n->id }}">
                    <div class="pk-row{{ $unread ? ' pk-row--unread' : '' }}">
                        <div class="pk-row__main">
                            <div class="pk-row__title">
                                @if ($unread)<span class="sr-only">New: </span>@endif
                                @if ($row['url'])
                                    <a href="{{ $row['url'] }}" wire:click.prevent="open('{{ $n->id }}')">{{ $row['lead'] }}</a>{{ $row['text'] }}
                                @else
                                    {{ $row['lead'] }}{{ $row['text'] }}
                                @endif
                            </div>
                            <div class="pk-row__meta">{{ $words[$row['category']] ?? 'Pulse' }}, <x-pulse.when :at="$n->created_at" /></div>
                        </div>
                        <div class="pk-row__end">
                            @if ($unread)
                                <x-pulse.button variant="quiet" size="sm" wire:click="markRead('{{ $n->id }}')" type="button">Mark read</x-pulse.button>
                            @else
                                <x-pulse.button variant="quiet" size="sm" wire:click="markUnread('{{ $n->id }}')" type="button">Mark unread</x-pulse.button>
                            @endif
                        </div>
                    </div>
                </li>
            @endforeach
        </ul>
        @if ($this->hasMore)
            <div><x-pulse.button variant="quiet" wire:click="more" type="button">Show older</x-pulse.button></div>
        @endif
    @endif
</section>
