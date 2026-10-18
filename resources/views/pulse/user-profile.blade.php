<x-app-layout>

<div class="pulse-page">

    <a href="{{ route('dashboard') }}" style="font-size:0.78rem;color:#52525b;text-decoration:none;">&larr; Back to feed</a>

    <div style="margin-top:1rem;">
        <livewire:pulse.user-profile :profile-user="$profileUser" />
    </div>

</div>

</x-app-layout>
