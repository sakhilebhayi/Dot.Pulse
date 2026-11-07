{{-- A mounted bell opens a short list of the latest notifications and links to the full page (Me, Notifications). The
     shell itself uses a plain badge link driven by the poller, so this only renders where a view still embeds it. --}}
<div class="sh-menuwrap" x-data="{ open: false }" @keydown.escape.window="open = false" @click.outside="open = false">
    <button type="button" class="sh-iconbtn" @click="open = !open" :aria-expanded="open.toString()" aria-expanded="false" aria-label="Notifications{{ $this->unreadCount > 0 ? ', '.$this->unreadCount.' unread' : '' }}">
        <x-pulse.icon name="bell" />
        @if ($this->unreadCount > 0)
            <span class="sh-badge" aria-hidden="true">{{ $this->unreadCount > 9 ? '9+' : $this->unreadCount }}</span>
        @endif
    </button>

    <div class="sh-menu" x-show="open" x-cloak>
        @if ($this->recent->isEmpty())
            <p class="sh-menu__label">Nothing yet.</p>
        @else
            @foreach ($this->recent->take(8) as $notification)
                @php $row = $this->present($notification); @endphp
                <a @if ($row['url']) href="{{ $row['url'] }}" @else href="{{ pulse_route('me.notifications') }}" @endif wire:key="notification-{{ $notification->id }}" @if (is_null($notification->read_at)) aria-label="New: {{ $row['lead'] }}" @endif>
                    <span><strong>{{ $row['lead'] }}</strong>{{ $row['text'] }}<br><span class="pk-muted">{{ $notification->created_at->diffForHumans() }}</span></span>
                </a>
            @endforeach
        @endif
        <a href="{{ pulse_route('me.notifications') }}">See all notifications</a>
        @if ($this->unreadCount > 0)
            <button type="button" class="sh-menu__item" wire:click="markAllRead">Mark all read</button>
        @endif
    </div>
</div>
