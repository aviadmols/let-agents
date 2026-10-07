<?php

namespace App\Modules\Ai\Tests;

use App\Core\Facades\Features;
use App\Core\Facades\Settings;
use App\Modules\Admin\Models\User;
use App\Modules\Ai\Filament\Operator\Pages\Agents;
use App\Modules\Ai\Support\AgentCatalog;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * One screen for every agent that calls a model: each declared agent is real (its settings,
 * flags and prompts exist, its words are in both languages), and the operator can change its
 * provider and model and turn it on or off.
 */
final class AgentsScreenTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_declared_agent_points_at_settings_flags_and_prompts_that_exist(): void
    {
        $agents = AgentCatalog::all();

        $this->assertContains('retrieval.image_indexer', array_column($agents, 'key'), 'the picture vectors are an agent like any other');
        $this->assertGreaterThanOrEqual(12, count($agents));

        foreach ($agents as $agent) {
            foreach ($agent['features'] as $feature) {
                Features::enabled($feature);
            }

            foreach ($agent['roles'] as $role) {
                foreach ([$role['provider'], $role['model'], ...$role['prices']] as $key) {
                    Settings::get($key);
                }
            }

            foreach (['he', 'en'] as $locale) {
                $this->assertNotSame($agent['slug'].'::agents.'.$agent['name'], __($agent['slug'].'::agents.'.$agent['name'], [], $locale), "{$agent['key']} has no name in {$locale}");
                $this->assertNotSame($agent['slug'].'::agents_about.'.$agent['name'], __($agent['slug'].'::agents_about.'.$agent['name'], [], $locale), "{$agent['key']} has no description in {$locale}");
            }
        }

        $prompts = collect($agents)->pluck('roles')->flatten(1)->pluck('prompts')->flatten(1)->pluck('name')->all();
        foreach (['resolve', 'tags', 'answer_site', 'verify_site', 'review', 'match', 'product_extraction', 'write_cta', 'propose_rules'] as $name) {
            $this->assertContains($name, $prompts);
        }
    }

    public function test_the_operator_changes_a_model_turns_an_agent_on_and_sees_a_same_family_pair(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('operator'));
        $this->actingAs(User::factory()->operator()->create());

        $page = Livewire::test(Agents::class)
            ->assertSee('מכין וקטורים לתמונות')
            ->assertSet('values.'.Agents::field('retrieval.image_model'), 'gemini-embedding-2')
            ->assertSee('פותר חיפושים בלי תוצאות')
            ->assertSee('You work for one online store', false)
            ->assertSee('הכותב והבודק מאותה משפחה', false);

        $page->set('values.'.Agents::field('search.resolve_model'), 'gpt-5.5-mini')
            ->call('save', 'search.resolver');
        $this->assertSame('gpt-5.5-mini', Settings::get('search.resolve_model'));

        $this->assertFalse(Features::enabled('retrieval.image_index'));
        $page->call('toggle', 'retrieval.image_index', true);
        $this->assertTrue(Features::enabled('retrieval.image_index'));

        $page->call('toggle', 'widget.layout', true)->assertNotFound();
    }
}
