<?php

namespace App\View\Components;

use Illuminate\View\Component;
use Illuminate\View\View;

/** The signed-out shell used by sign-in and the other auth screens (resources/views/layouts/guest.blade.php). */
class GuestLayout extends Component
{
    public function __construct(public ?string $title = null, public ?string $description = null) {}

    public function render(): View
    {
        return view('layouts.guest');
    }
}
