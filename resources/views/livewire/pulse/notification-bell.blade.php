<a class="sh-iconbtn" href="{{ pulse_route('me.notifications') }}" aria-label="Notifications{{ $this->unreadCount > 0 ? ', '.$this->unreadCount.' unread' : '' }}">
    <x-pulse.icon name="bell" />
    @if ($this->unreadCount > 0)
        <span class="sh-badge" aria-hidden="true">{{ $this->unreadCount > 9 ? '9+' : $this->unreadCount }}</span>
    @endif
</a>
