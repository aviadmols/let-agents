<?php

namespace App\Modules\Admin\Tests;

use App\Core\Facades\Features;
use App\Core\Facades\Settings;
use App\Modules\Admin\Actions\SaveShopProfile;
use App\Modules\Tenancy\Models\Shop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * "What matters in the search" (Settings · General, the first-run form), as a demo asks it: the look reads each
 * product's picture, the content reads none, both reads them and the content. A trial that holds pictures off still
 * holds them, and gives the choice back at its end.
 */
final class WhatMattersTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_look_reads_the_pictures_and_the_content_reads_none(): void
    {
        $shop = Shop::factory()->create();
        $save = app(SaveShopProfile::class);
        $this->assertSame('content', $save->load($shop)['matters'], 'no picture is read yet: the content, as it is set');

        $save->handle($shop, ['matters' => 'look'] + $save->load($shop));
        $this->assertTrue(Features::enabled('retrieval.image_index', $shop->id));
        $this->assertTrue(Features::enabled('search.photos', $shop->id));
        $this->assertSame(['picture', 'look'], [Settings::get('search.similar_by', $shop->id), Settings::get('search.photo_match', $shop->id)]);
        $this->assertSame('look', $save->load($shop->fresh())['matters']);

        $save->handle($shop->fresh(), ['matters' => 'content'] + $save->load($shop->fresh()));
        $this->assertFalse(Features::enabled('retrieval.image_index', $shop->id));
        $this->assertFalse(Features::enabled('search.photos', $shop->id));
        $this->assertSame(['meaning', 'function'], [Settings::get('search.similar_by', $shop->id), Settings::get('search.photo_match', $shop->id)]);
    }

    public function test_saving_the_rest_of_the_form_leaves_its_pictures_as_they_are(): void
    {
        // Rocks: its pictures were turned on by the team; a name changed in General never turns them off
        $shop = Shop::factory()->create();
        Features::override('retrieval.image_index', true, $shop->id);
        Settings::set('search.similar_by', 'both', $shop->id);
        $save = app(SaveShopProfile::class);

        $save->handle($shop, ['name' => 'Rocks and Gold'] + $save->load($shop));

        $this->assertTrue(Features::enabled('retrieval.image_index', $shop->id));
        $this->assertSame('both', Settings::get('search.similar_by', $shop->id));
        $this->assertSame('both', $shop->fresh()->profile['matters'] ?? null);
    }

    public function test_on_a_trial_that_reads_no_pictures_the_choice_waits_for_its_end(): void
    {
        $shop = Shop::factory()->create();
        Features::override('retrieval.image_index', false, $shop->id);
        $shop->forceFill(['trial_ends_at' => now()->addDays(10), 'trial_saved' => ['retrieval.image_index' => ['feature', null], 'search.photos' => ['feature', null]]])->save();
        $save = app(SaveShopProfile::class);

        $save->handle($shop, ['matters' => 'both'] + $save->load($shop));

        $this->assertFalse(Features::enabled('retrieval.image_index', $shop->id), 'the trial still holds them off');
        $this->assertSame(['feature', true], $shop->fresh()->trial_saved['retrieval.image_index'], 'and gives them back on at its end');
        $this->assertSame('both', $shop->fresh()->profile['matters'] ?? null);
    }
}
