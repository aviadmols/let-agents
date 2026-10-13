<?php

namespace App\Modules\Connections\Contracts;

/**
 * The blog of one platform. A new platform is one class and one tag (connections.store_blogs).
 */
interface PlatformStoreBlog extends StoreBlog
{
    public function platform(): string;
}
