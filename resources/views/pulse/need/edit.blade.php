<x-app-layout title="Edit your need" width="wide">
    <x-slot name="header">
        <div>
            <h1 class="bz-pagetitle">Edit your need</h1>
            <p><a href="{{ route('needs.show', ['need' => $need->id]) }}">Back to the need</a></p>
        </div>
    </x-slot>
    <livewire:pulse.need.need-form :need="$need" />
</x-app-layout>
