<?php

namespace App\View\Components;

use Illuminate\View\Component;
use Illuminate\View\View;

/**
 * The signed-in shell (resources/views/layouts/app.blade.php).
 *
 * Usage: <x-app-layout title="Trade" width="floor"> ... </x-app-layout>
 *  - width "wide" (default): a fluid stage up to 1100px, for settings and legacy pages.
 *  - width "floor": a 680px column of beats with an optional context column (the named slot `context`) that folds
 *    into a drawer below 1200px.
 *  - dial: null shows the mode dial on the five Floor routes only; true or false forces it.
 *  - focus: a single-task page (the welcome flow). No rail, no phone bar, no search, no Compose: nothing to leave by.
 */
class AppLayout extends Component
{
    public function __construct(
        public string $width = 'wide',
        public ?string $title = null,
        public ?bool $dial = null,
        public ?string $description = null,
        public bool $focus = false,
    ) {}

    public function render(): View
    {
        return view('layouts.app');
    }
}
