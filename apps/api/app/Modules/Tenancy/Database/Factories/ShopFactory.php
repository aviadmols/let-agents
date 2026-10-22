<?php

namespace App\Modules\Tenancy\Database\Factories;

use App\Modules\Tenancy\Enums\ShopPlatform;
use App\Modules\Tenancy\Enums\ShopStatus;
use App\Modules\Tenancy\Models\Shop;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Shop>
 */
class ShopFactory extends Factory
{
    protected $model = Shop::class;

    public function definition(): array
    {
        $name = fake()->unique()->company();

        return [
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(4)),
            'platform' => ShopPlatform::WooCommerce,
            'domain' => Str::lower(Str::random(10)).'.example.com',
            'content_locale' => 'he',
            'currency' => 'ILS',
            'timezone' => 'Asia/Jerusalem',
            'status' => ShopStatus::Active,
            // a shop in a test is past its first-run screens unless it asks for them (onboarding())
            'onboarded_at' => now(),
        ];
    }

    /** A shop just created: its panel opens on the first-run screens. */
    public function onboarding(): static
    {
        return $this->state(['onboarded_at' => null]);
    }

    public function paused(): static
    {
        return $this->state(['status' => ShopStatus::Paused]);
    }

    public function disabled(): static
    {
        return $this->state(['status' => ShopStatus::Disabled]);
    }
}
