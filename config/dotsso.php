<?php

use App\Sso\PulseProvisioner;

/*
 * Dot.Pulse's own choices for the shared sign-in package (packages/dot-sso-client). Everything else, including
 * DOTSSO_ENABLED (default false), comes from the package's defaults and the host .env.
 */
return [
    'provisioner' => PulseProvisioner::class,

    // Pulse has always created an account (with its PulseProfile) for a person InfoDot vouches for, so that stays.
    'auto_provision' => (bool) env('DOTSSO_AUTO_PROVISION', env('INFODOT_PROVISION_USERS', true)),

    // Package default landing (/dashboard): Pulse's /dashboard redirects signed-in people to the Floor.

    // Any same-origin path (the Floor, a business page a stranger was reading before choosing to knock, onboarding) except
    // the package's own and the sign-in pages. /dev/* only exists locally and is never a deep link.
    'deep_link' => [
        'allow' => ['#^/#'],
        'deny' => ['#^/(sso|login|register|logout|forgot-password|reset-password|auth|oauth|two-factor-challenge|user/confirm|dev)(/|\?|$)#i'],
    ],

    // Never intercepted: nothing extra. Pulse's machine doors (/api/*, the signed digest unsubscribe, broadcasting auth,
    // /up, guardian health) are outside the pages the package takes over.
    'allow_paths' => [],
];
