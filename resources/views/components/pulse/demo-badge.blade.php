{{--
    pulse/demo-badge
    A small "Demo data" marker for developers: shown only when APP_ENV is local or testing, and only for rows made by
    `pulse:demo-seed` (their owners sign in on @demo.pulse.test). Nothing renders in production, so a visitor never reads
    that a listing is fictional in its own words; the seeder README says so once.
    Props: user-id (the owner or creator of the row)
--}}
@props(['userId' => null])
@if (app()->environment('local', 'testing') && $userId && str_ends_with((string) \App\Models\User::query()->whereKey($userId)->value('email'), '@demo.pulse.test'))
    <span class="pk-demo" title="Demo data. Shown in local and test builds only.">Demo data</span>
@endif
