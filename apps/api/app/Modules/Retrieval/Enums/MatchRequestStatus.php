<?php

namespace App\Modules\Retrieval\Enums;

enum MatchRequestStatus: string
{
    case Answered = 'answered';

    /** Code found fewer than two candidates, so there was nothing for a model to choose. */
    case TooFew = 'too_few';

    case Failed = 'failed';
}
