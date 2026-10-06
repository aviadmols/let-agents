<?php

namespace App\Modules\Retrieval\Enums;

enum MatchStatus: string
{
    case Accepted = 'accepted';

    /** The model picked it and code refused it; the reason is kept with the row. */
    case Rejected = 'rejected';
}
