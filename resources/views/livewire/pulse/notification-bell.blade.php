<div x-data="{ open: false }" style="position:relative;">
    <button @click="open = !open" class="topbar-btn" title="Notifications" style="position:relative;">
        <span class="material-symbols-rounded">notifications</span>
        @if($this->unreadCount > 0)
            <span style="position:absolute;top:-2px;right:-2px;background:#f1c62e;color:#08354f;font-size:9px;font-weight:700;border-radius:100px;min-width:15px;height:15px;display:flex;align-items:center;justify-content:center;padding:0 3px;font-family:'JetBrains Mono',monospace;">{{ $this->unreadCount > 9 ? '9+' : $this->unreadCount }}</span>
        @endif
    </button>

    <div x-show="open" x-cloak @click.outside="open = false" class="dot-card" style="position:absolute;top:38px;right:0;width:320px;max-height:400px;overflow-y:auto;z-index:50;padding:0.75rem;">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:0.5rem;">
            <h3 style="font-family:'Syne',sans-serif;font-size:12.5px;font-weight:700;color:#f4f4f5;margin:0;">Notifications</h3>
            @if($this->unreadCount > 0)
                <button wire:click="markAllRead" style="background:none;border:none;color:#71717a;font-size:11px;cursor:pointer;font-family:'Inter',sans-serif;">Mark all read</button>
            @endif
        </div>

        @if($this->recent->isEmpty())
            <p style="font-size:12px;color:#52525b;text-align:center;padding:1.5rem 0;margin:0;">No notifications yet.</p>
        @else
            <div style="display:flex;flex-direction:column;gap:2px;">
                @foreach($this->recent as $notification)
                    @php $row = $this->present($notification); @endphp
                    <a @if($row['url']) href="{{ $row['url'] }}" @endif
                       style="display:flex;gap:8px;align-items:flex-start;padding:8px 10px;border-radius:8px;text-decoration:none;{{ is_null($notification->read_at) ? 'background:rgba(241,198,46,0.06);' : '' }}"
                       wire:key="notification-{{ $notification->id }}">
                        <span aria-hidden="true" style="flex-shrink:0;width:6px;height:6px;border-radius:50%;margin-top:6px;background:{{ is_null($notification->read_at) ? '#f1c62e' : 'transparent' }};"></span>
                        <span style="min-width:0;">
                            <span style="display:block;font-size:12.5px;color:#d4d4d8;">
                                <strong style="color:#f4f4f5;">{{ $row['lead'] }}</strong>{{ $row['text'] }}
                            </span>
                            <span style="display:block;font-size:11px;color:#3f3f46;margin-top:2px;">{{ $notification->created_at->diffForHumans() }}</span>
                        </span>
                    </a>
                @endforeach
            </div>
        @endif
    </div>
</div>
