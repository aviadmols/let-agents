<?php

namespace App\Modules\Widget\Tests;

use App\Modules\Tenancy\Models\Shop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** The looks stores had chosen went with the old designs; their saved answers go too, and nothing else does. */
final class RemovedLooksTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_stores_removed_look_choices_are_cleared_and_its_other_settings_kept(): void
    {
        $scope = (string) Shop::factory()->create()->id;
        $row = fn (string $key, mixed $value, string $scope): array => ['key' => $key, 'scope' => $scope, 'value' => json_encode($value), 'created_at' => now(), 'updated_at' => now()];

        DB::table('setting_overrides')->insert([
            $row('widget.layout', 'circles', $scope),
            $row('search.results', 'drawer', $scope),
            $row('search.button_color', '#000000', 'global'),
            $row('shortlist.heart_plain', true, $scope),
            $row('try_on.monthly_cap_usd', 20, $scope),
            $row('widget.max_products', 6, $scope),
            $row('search.show_price', false, $scope),
        ]);
        DB::table('feature_overrides')->insert([
            ['key' => 'search.mode_choice', 'scope' => $scope, 'enabled' => true, 'created_at' => now(), 'updated_at' => now()],
            ['key' => 'search.similar', 'scope' => $scope, 'enabled' => false, 'created_at' => now(), 'updated_at' => now()],
        ]);

        (require base_path('app/Modules/Widget/Database/Migrations/2026_10_16_600002_stores_forget_the_looks_that_were_removed.php'))->up();

        $this->assertEqualsCanonicalizing(['widget.max_products', 'search.show_price'], DB::table('setting_overrides')->pluck('key')->all());
        $this->assertSame(['search.similar'], DB::table('feature_overrides')->pluck('key')->all());
    }
}
