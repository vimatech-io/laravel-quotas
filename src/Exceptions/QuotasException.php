<?php

declare(strict_types=1);

namespace VimaTech\LaravelQuotas\Exceptions;

use RuntimeException;

/**
 * Base class of every exception this package throws on its own behalf.
 *
 * Catch this to handle "anything quota-related went wrong" in one place —
 * a portal controller, a job middleware — without enumerating the concrete
 * failures, which remain catchable individually.
 */
class QuotasException extends RuntimeException {}
