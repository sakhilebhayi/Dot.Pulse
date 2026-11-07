<?php

namespace App\View\Components;

use Illuminate\View\Component;
use Illuminate\View\View;

/** The public shell for the landing page and the policy pages (resources/views/layouts/public.blade.php). */
class PublicLayout extends Component
{
    public function __construct(public ?string $title = null, public ?string $description = null) {}

    public function render(): View
    {
        return view('layouts.public');
    }
}
