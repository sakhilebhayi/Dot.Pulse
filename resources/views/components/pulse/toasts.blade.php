{{--
    pulse/toasts
    The toast region: a polite live region that the shell fills. Include it once in a layout.
    Trigger from JavaScript with window.pulseToast('Saved', {kind: 'good'}), from a window event
    (new CustomEvent('pulse-toast', {detail: {message}})) or from Livewire with $this->dispatch('toast', message: 'Saved').
    kinds: good, bad, or none.
--}}
<div class="pk-toasts" role="status" aria-live="polite" aria-atomic="false"></div>
