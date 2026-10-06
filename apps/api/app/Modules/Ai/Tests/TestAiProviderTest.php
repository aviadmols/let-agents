<?php

namespace App\Modules\Ai\Tests;

use App\Modules\Admin\Models\User;
use App\Modules\Ai\Actions\TestAiProvider;
use App\Modules\Ai\Contracts\ListsProviderModels;
use App\Modules\Ai\Enums\AiProviderName;
use App\Modules\Ai\Enums\ProviderStatus;
use App\Modules\Ai\Filament\Operator\Resources\AiProviders\Pages\CreateAiProvider;
use App\Modules\Ai\Models\AiProvider;
use App\Modules\Ai\Support\ProviderCheckFailed;
use App\Modules\Runs\Enums\RunStatus;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class TestAiProviderTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<array{0: AiProviderName, 1: string}> */
    private array $calls = [];

    private ?ProviderCheckFailed $failWith = null;

    protected function setUp(): void
    {
        parent::setUp();

        // No network in tests: the model list comes from a fake.
        $this->app->instance(ListsProviderModels::class, new class($this) implements ListsProviderModels
        {
            public function __construct(private TestAiProviderTest $test) {}

            public function list(AiProviderName $provider, string $apiKey): array
            {
                return $this->test->fakeList($provider, $apiKey);
            }
        });
    }

    /** @return list<array{id: string, name: string, created_at: ?string}> */
    public function fakeList(AiProviderName $provider, string $apiKey): array
    {
        $this->calls[] = [$provider, $apiKey];

        if ($this->failWith !== null) {
            throw $this->failWith;
        }

        return [
            ['id' => 'claude-opus-5', 'name' => 'Claude Opus 5', 'created_at' => '2026-08-01T00:00:00+00:00'],
            ['id' => 'claude-haiku-4-5', 'name' => 'Claude Haiku 4.5', 'created_at' => '2025-10-01T00:00:00+00:00'],
        ];
    }

    public function test_a_working_key_saves_the_model_list_and_logs_the_check(): void
    {
        $provider = AiProvider::query()->create(['provider' => 'anthropic', 'api_key' => ' sk-ant-api03-SECRETVALUE-wxyz ']);

        $run = app(TestAiProvider::class)->handle($provider);

        $this->assertSame([[AiProviderName::Anthropic, 'sk-ant-api03-SECRETVALUE-wxyz']], $this->calls, 'the key is trimmed');

        $provider->refresh();
        $this->assertSame(ProviderStatus::Connected, $provider->status);
        $this->assertSame(['claude-opus-5', 'claude-haiku-4-5'], array_column($provider->models, 'id'));
        $this->assertSame('sk-ant-…wxyz', $provider->key_hint);

        $this->assertSame(RunStatus::Succeeded, $run->status);
        $this->assertNull($run->shop_id, 'a provider key belongs to no shop');
        $this->assertStringContainsString('2', (string) $run->summary());
        $this->assertStringNotContainsString('SECRETVALUE', json_encode($run->fresh()->toArray()));
    }

    public function test_the_key_is_encrypted_at_rest(): void
    {
        $provider = AiProvider::query()->create(['provider' => 'openai', 'api_key' => 'sk-proj-SECRETVALUE1234']);

        $stored = DB::table('ai_providers')->where('id', $provider->id)->value('api_key');

        $this->assertStringNotContainsString('SECRETVALUE', $stored);
        $this->assertSame('sk-proj-SECRETVALUE1234', $provider->fresh()->api_key);
        $this->assertSame('sk-proj-…1234', $provider->key_hint);
    }

    public function test_a_rejected_key_is_explained(): void
    {
        $provider = AiProvider::query()->create(['provider' => 'openai', 'api_key' => 'sk-wrong-0000']);
        $this->failWith = new ProviderCheckFailed(ProviderCheckFailed::INVALID_KEY, 401);

        $run = app(TestAiProvider::class)->handle($provider);

        $this->assertSame(RunStatus::Failed, $run->status);
        $this->assertSame('ai::runs.failures.invalid_key', $run->summary_key);
        $this->assertSame(401, $run->output['http_status']);
        $this->assertSame(ProviderStatus::Failed, $provider->fresh()->status);
        $this->assertSame('invalid_key', $provider->fresh()->last_error_code);
    }

    public function test_replacing_the_key_clears_the_old_result(): void
    {
        $provider = AiProvider::query()->create(['provider' => 'anthropic', 'api_key' => 'sk-ant-one-1111']);
        app(TestAiProvider::class)->handle($provider);

        $provider->fresh()->update(['api_key' => 'sk-ant-two-2222']);

        $provider->refresh();
        $this->assertSame(ProviderStatus::Untested, $provider->status);
        $this->assertNull($provider->models);
    }

    /** @return array<string, array{0: ?int, 1: string}> */
    public static function statuses(): array
    {
        return [
            '401' => [401, ProviderCheckFailed::INVALID_KEY],
            '403' => [403, ProviderCheckFailed::PERMISSION_DENIED],
            '429' => [429, ProviderCheckFailed::RATE_LIMITED],
            '529' => [529, ProviderCheckFailed::PROVIDER_ERROR],
            'none' => [null, ProviderCheckFailed::PROVIDER_ERROR],
        ];
    }

    #[DataProvider('statuses')]
    public function test_http_statuses_map_to_reasons(?int $status, string $reason): void
    {
        $this->assertSame($reason, ProviderCheckFailed::fromStatus($status)->reason);
    }

    public function test_the_operator_adds_a_key_and_sees_it_checked(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('operator'));
        $this->actingAs(User::factory()->operator()->create());

        Livewire::test(CreateAiProvider::class)
            ->fillForm(['provider' => 'anthropic', 'api_key' => 'sk-ant-api03-abc-9999'])
            ->call('create')
            ->assertHasNoFormErrors()
            ->assertNotified(__('ai::providers.notifications.connected'));

        $this->assertSame(ProviderStatus::Connected, AiProvider::for(AiProviderName::Anthropic)->status);

        // Anthropic is taken now, so only OpenAI is offered.
        Livewire::test(CreateAiProvider::class)
            ->assertFormFieldExists('provider', fn ($field): bool => array_keys($field->getOptions()) === ['openai', 'gemini']);

        foreach (['he', 'en'] as $locale) {
            $this->withHeader('Accept-Language', $locale)->get('/operator/ai-providers')
                ->assertOk()
                ->assertSee(__('ai::providers.plural', [], $locale))
                ->assertDontSee('api03-abc');
        }
    }
}
