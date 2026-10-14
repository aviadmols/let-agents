<?php

namespace App\Modules\Ai\Tests;

use App\Modules\Ai\Contracts\ChatDriver;
use App\Modules\Ai\Contracts\ImageDriver;
use App\Modules\Ai\Contracts\ImageReply;
use App\Modules\Ai\Contracts\ModelCallFailed;
use App\Modules\Ai\Contracts\ModelReply;
use App\Modules\Ai\Contracts\VisionDriver;
use App\Modules\Ai\Enums\AiProviderName;
use App\Modules\Ai\Enums\ProviderStatus;
use App\Modules\Ai\Models\AiProvider;
use App\Modules\Ai\Support\ProviderChatModel;
use App\Modules\Ai\Support\ProviderImageModel;
use App\Modules\Runs\Contracts\CallJournal;
use App\Modules\Runs\Contracts\RecordsRuns;
use App\Modules\Runs\Contracts\RunContext;
use App\Modules\Runs\Models\Run;
use App\Modules\Runs\Models\RunCall;
use App\Modules\Tenancy\Models\Shop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Every model call is written down as it was, under the run it was made in: the prompt, what was
 * sent and what came back, or the error. Pictures by type and size, never their bytes. Kept
 * runs.call_log_days and then pruned.
 */
final class CallJournalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['openai', 'replicate'] as $provider) {
            AiProvider::query()->create(['provider' => $provider, 'api_key' => 'k-'.$provider])->forceFill(['status' => ProviderStatus::Connected])->save();
        }
    }

    public function test_a_call_inside_a_run_is_kept_with_its_prompt_what_was_sent_and_what_came_back(): void
    {
        $shop = Shop::factory()->create();
        $chat = new ProviderChatModel([$this->driver(['answer' => 'כן, יש סוללה'])], app(CallJournal::class));

        $run = app(RecordsRuns::class)->track('enrichment.web_lookup', 'enrichment.look_up_on_the_web', function (RunContext $context) use ($chat): void {
            $chat->json(AiProviderName::OpenAi, 'gpt-5.4-mini', 'You check facts.', '{"product":"מקדחה"}', 500);
        }, $shop->id);

        $call = RunCall::query()->sole();
        $this->assertSame($run->id, $call->run_id);
        $this->assertSame($shop->id, $call->shop_id);
        $this->assertSame('enrichment.look_up_on_the_web', $call->action);
        $this->assertSame('chat', $call->kind);
        $this->assertSame('gpt-5.4-mini', $call->model);
        $this->assertSame('You check facts.', $call->system);
        $this->assertSame('{"product":"מקדחה"}', $call->sent);
        $this->assertStringContainsString('כן, יש סוללה', (string) $call->received);
        $this->assertTrue($call->ok);
        $this->assertSame([12, 3], [$call->input_tokens, $call->output_tokens]);
        $this->assertSame(1, $run->calls()->count());
    }

    public function test_a_failed_call_is_kept_with_its_error_and_still_fails(): void
    {
        $chat = new ProviderChatModel([$this->driver(null)], app(CallJournal::class));

        try {
            $chat->json(AiProviderName::OpenAi, 'gpt-5.4-nano', 'sys', 'hello', 100);
            $this->fail('the failure reaches the caller');
        } catch (ModelCallFailed) {
        }

        $call = RunCall::query()->sole();
        $this->assertFalse($call->ok);
        $this->assertNull($call->run_id, 'outside any run');
        $this->assertNull($call->received);
        $this->assertStringContainsString('ModelCallFailed', (string) $call->error);
    }

    public function test_pictures_are_noted_by_type_and_size_never_by_their_bytes(): void
    {
        $bytes = str_repeat('Z', 4096);
        $chat = new ProviderChatModel([$this->driver(['ok' => true])], app(CallJournal::class));
        $chat->jsonWithImage(AiProviderName::OpenAi, 'gpt-5.4-nano', 'sys', 'Is this a hand?', 'image/jpeg', $bytes, 100);

        $images = new ProviderImageModel([new class implements ImageDriver
        {
            public function provider(): AiProviderName
            {
                return AiProviderName::Replicate;
            }

            public function generate(string $apiKey, string $model, string $prompt, array $images): ImageReply
            {
                return new ImageReply(str_repeat('P', 2048), 'image/png', 4.2, 'pred-1');
            }
        }], app(CallJournal::class));
        $images->generate(AiProviderName::Replicate, 'google/nano-banana', 'Put the ring on the finger', [['mime' => 'image/jpeg', 'bytes' => $bytes]]);

        [$vision, $image] = RunCall::query()->orderBy('id')->get()->all();
        $this->assertSame('vision', $vision->kind);
        $this->assertStringContainsString('[image image/jpeg, 4 KB]', $vision->sent);
        $this->assertSame('image', $image->kind);
        $this->assertStringContainsString('Put the ring on the finger', $image->sent);
        $this->assertStringContainsString('[image image/png, 2 KB]', (string) $image->received);
        $this->assertStringNotContainsString('ZZZZ', $vision->sent.$image->sent.$image->received);
    }

    public function test_calls_older_than_the_setting_are_pruned_and_the_runs_stay(): void
    {
        $run = Run::query()->create(['agent' => 'a.b', 'action' => 'a.c', 'status' => 'succeeded', 'trigger' => 'manual', 'started_at' => now()]);
        $old = RunCall::query()->create(['run_id' => $run->id, 'kind' => 'chat', 'provider' => 'openai', 'model' => 'm', 'sent' => 'old']);
        $old->forceFill(['created_at' => now()->subDays(31)])->save();
        RunCall::query()->create(['run_id' => $run->id, 'kind' => 'chat', 'provider' => 'openai', 'model' => 'm', 'sent' => 'new']);

        $this->artisan('model:prune', ['--model' => [RunCall::class]])->assertSuccessful();

        $this->assertSame(['new'], RunCall::query()->pluck('sent')->all());
        $this->assertTrue($run->exists());
    }

    /** @param array<string, mixed>|null $data null fails the call */
    private function driver(?array $data): ChatDriver
    {
        return new class($data) implements ChatDriver, VisionDriver
        {
            public function __construct(private readonly ?array $data) {}

            public function provider(): AiProviderName
            {
                return AiProviderName::OpenAi;
            }

            public function json(string $apiKey, string $model, string $system, string $user, int $maxOutputTokens, ?string $reasoningEffort = null): ModelReply
            {
                return $this->data === null ? throw new ModelCallFailed(ModelCallFailed::UNSUPPORTED) : new ModelReply($this->data, 12, 3);
            }

            public function jsonWithImage(string $apiKey, string $model, string $system, string $user, string $mime, string $bytes, int $maxOutputTokens, ?string $reasoningEffort = null): ModelReply
            {
                return $this->json($apiKey, $model, $system, $user, $maxOutputTokens);
            }
        };
    }
}
