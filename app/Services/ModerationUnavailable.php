<?php

namespace App\Services;

/**
 * The automated reviewer gave no usable verdict (no key, timeout, API
 * error, unparseable answer). Never read as "approved": the caller retries
 * and, when retries run out, holds the post for a person.
 */
class ModerationUnavailable extends \RuntimeException {}
