<?php

namespace App\Modules\Ai\Contracts;

/**
 * A model's JSON answer after it searched the web: the reply, the addresses the search returned
 * (so code can tell a source the model was shown from one it made up), and how many searches it ran.
 */
final class WebAnswer
{
    /** @param list<string> $urls */
    public function __construct(
        public readonly ModelReply $reply,
        public readonly array $urls,
        public readonly int $searches,
    ) {}
}
