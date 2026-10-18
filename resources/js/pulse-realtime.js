/**
 * Live feed + comment delivery (see App\Events\PostPublished / CommentPosted
 * and App\Providers\BroadcastServiceProvider).
 *
 * Every subscription is a PRIVATE channel, authorised by /broadcasting/auth
 * with the session cookie. There is no public channel any more.
 *  - pulse.feed: posts with no community or in a public one. Any signed-in
 *    page may join; only HomeFeed's own #[On('post-created')] listener does
 *    anything with it.
 *  - community.{id}: posts in a private community, one per community the
 *    viewer belongs to (ids come from <body data-community-channels>).
 *  - post.{id}: only when a [data-post-id] element is present (PostShow).
 *  - user.{id}: notifications; subscribed on every authenticated page.
 */
function initPulseRealtime() {
    if (typeof window.Echo === 'undefined') {
        console.error('Laravel Echo not found. Ensure bootstrap.js loaded before pulse-realtime.js.');
        return;
    }

    // HomeFeed's own #[On('post-created')] handler already busts its
    // #[Computed] posts() cache and re-renders -- the exact mechanism
    // CreatePost::publish() dispatches after the viewer's own post. The feed
    // is re-queried server-side with the viewer's visibility rules, so the
    // event payload is never rendered.
    const refreshFeed = () => window.Livewire.dispatch('post-created');

    window.Echo.private('pulse.feed').listen('.post.published', refreshFeed);

    (document.body.dataset.communityChannels || '')
        .split(',')
        .filter(Boolean)
        .forEach((id) => window.Echo.private(`community.${id}`).listen('.post.published', refreshFeed));

    const postId = document.querySelector('[data-post-id]')?.dataset.postId;

    if (postId) {
        window.Echo.private(`post.${postId}`).listen('.comment.posted', () => {
            // PostShow's own #[On('comment-posted')] handler busts its
            // #[Computed] comments() cache, mirroring HomeFeed's pattern.
            window.Livewire.dispatch('comment-posted');
        });
    }

    // Private per-user channel, subscribed on every authenticated page
    // (NotificationBell is mounted in the shared topbar, not one specific
    // page) -- unlike post.{id} above, this one is never a no-op
    // subscription. App\Notifications\NewFollower broadcasts here.
    const userId = document.body.dataset.userId;

    if (userId) {
        window.Echo.private(`user.${userId}`).listen('.notification.new', () => {
            window.Livewire.dispatch('notification-received');
        });
    }
}

if (typeof window.Livewire === 'undefined') {
    document.addEventListener('livewire:init', initPulseRealtime);
} else {
    initPulseRealtime();
}
