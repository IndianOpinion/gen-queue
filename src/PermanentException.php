<?php

declare(strict_types=1);

namespace IndianOpinion\GenQueue;

/**
 * Thrown by a handler when the job can never succeed: bad request, refused content, unusable payload, missing target. The job fails at once.
 */
class PermanentException extends GenQueueException
{
}
