<?php

declare(strict_types=1);

namespace IndianOpinion\GenQueue;

/**
 * A payload is not a plain array of scalars, nulls and arrays, is not valid UTF-8, or is too large.
 */
class InvalidPayloadException extends GenQueueException
{
}
