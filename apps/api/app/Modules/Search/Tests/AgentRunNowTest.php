<?php

namespace App\Modules\Search\Tests;

use App\Modules\Admin\Models\User;
use App\Modules\Admin\Support\CurrentShop;
use App\Modules\Ai\Filament\Operator\Pages\Agents;
use App\Modules\Ai\Support\AgentCatalog;
use App\Modules\Tenancy\Models\Shop;
use Filament\Facades\Filament;
use Illuminate\Foundation\Console\QueuedCommand;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The agents screen runs an agent now for the shop chosen in the top bar, on the queue, with the
 * agent's own command. (Here, because it needs a shop and the Ai module does not own shops.)
 */
final class AgentRunNowTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_run_command_exists_and_takes_its_arguments(): void
    {
        $commands = Artisan::all();
        $runs = array_filter(array_column(AgentCatalog::all(), 'run', 'key'));

        $this->assertArrayHasKey('retrieval.image_indexer', $runs, 'the picture scan can be run now');

        foreach ($runs as $key => $run) {
            $this->assertArrayHasKey($run['command'], $commands, "{$key} runs a command that does not exist");
            $definition = $commands[$run['command']]->getDefinition();
            $this->assertTrue($definition->hasArgument($run['shop_argument'] ?? 'target'), "{$key}: no shop argument");

            if (isset($run['step'])) {
                $this->assertTrue($definition->hasArgument('step'), "{$key}: no step argument");
            }
        }
    }

    public function test_the_operator_runs_the_picture_scan_now_for_the_chosen_shop(): void
    {
        Queue::fake();
        $shop = Shop::factory()->create(['slug' => 'gueta']);
        Shop::factory()->create();

        Filament::setCurrentPanel(Filament::getPanel('operator'));
        $this->actingAs(User::factory()->operator()->create());
        CurrentShop::set($shop->id);

        Livewire::test(Agents::class)
            ->assertSee(__('ai::agents_screen.run_now', ['shop' => $shop->name]))
            ->call('runNow', 'retrieval.image_indexer')
            ->assertNotified(__('ai::agents_screen.run_started', ['shop' => $shop->name]));

        Queue::assertPushed(QueuedCommand::class, 1);
    }

    public function test_with_every_shop_shown_it_asks_to_choose_one_first(): void
    {
        Queue::fake();
        Shop::factory()->count(2)->create();

        Filament::setCurrentPanel(Filament::getPanel('operator'));
        $this->actingAs(User::factory()->operator()->create());
        CurrentShop::set(null);

        Livewire::test(Agents::class)
            ->assertSee(__('ai::agents_screen.run_needs_shop'))
            ->call('runNow', 'retrieval.image_indexer')
            ->assertNotified(__('ai::agents_screen.choose_shop'));

        Queue::assertNothingPushed();
    }
}
