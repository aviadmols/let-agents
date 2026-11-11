<?php

namespace App\Modules\Connections\Contracts;

/**
 * The edit links of one platform. A new platform is one class and one tag (connections.edit_links).
 */
interface PlatformEditLinks extends StoreEditLinks
{
    public function platform(): string;
}
