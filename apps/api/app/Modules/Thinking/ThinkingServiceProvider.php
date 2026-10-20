<?php

namespace App\Modules\Thinking;

use App\Core\Modules\ModuleServiceProvider;
use App\Modules\Assistant\Contracts\ProductSets;
use App\Modules\Thinking\Actions\FindProductSet;

/**
 * Requests that need more than one step to answer. First: a question that asks to see a whole set of
 * the store's products (FindProductSet), which the assistant hands here (ProductSets).
 */
final class ThinkingServiceProvider extends ModuleServiceProvider
{
    protected function registerModule(): void
    {
        $this->app->bind(ProductSets::class, FindProductSet::class);
    }

    protected function bootModule(): void
    {
        //
    }
}
