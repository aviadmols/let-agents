<?php

namespace App\Modules\Ai\Tests;

use App\Modules\Admin\Models\User;
use App\Modules\Ai\Contracts\ChatDriver;
use App\Modules\Ai\Contracts\ModelCallFailed;
use App\Modules\Ai\Contracts\ModelReply;
use App\Modules\Ai\Enums\AiProviderName;
use App\Modules\Ai\Enums\ProviderStatus;
use App\Modules\Ai\Models\AiProvider;
use App\Modules\Ai\Support\Drivers\OpenAiChat;
use App\Modules\Ai\Support\KeyFailure;
use App\Modules\Ai\Support\ProviderChatModel;
use App\Modules\Tenancy\Models\Shop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * A key that stops working is said at once, on every operator screen: as Rocks and Gold's answers
 * went unchecked for a night when the Anthropic key ran out ("invalid x-api-key") and nobody saw it.
 */
final class KeyFailureTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_key_refused_or_out_of_credit_is_told_from_an_outage(): void
    {
        $failed = fn (string $message): ModelCallFailed => new ModelCallFailed(ModelCallFailed::PROVIDER_ERROR, new RuntimeException($message));

        $this->assertSame('invalid_key', KeyFailure::of($failed('{"type":"error","error":{"type":"authentication_error","message":"invalid x-api-key"}}')));
        $this->assertSame('invalid_key', KeyFailure::of($failed('Incorrect API key provided: sk-...')));
        $this->assertSame('no_credit', KeyFailure::of($failed('Your credit balance is too low to access the Anthropic API.')));
        $this->assertSame('no_credit', KeyFailure::of($failed('You exceeded your current quota, please check your plan and billing details.')));
        $this->assertNull(KeyFailure::of($failed('Overloaded')), 'an outage passes; the key is fine');
        $this->assertNull(KeyFailure::of(new ModelCallFailed(ModelCallFailed::NOT_JSON)));
    }

    public function test_openai_out_of_credit_is_told_from_a_rate_limit_though_both_are_a_429(): void
    {
        // 29.10: every OpenAI call failed for hours with "Request rate limit has been exceeded.", and no alert said why
        $body = '{"error":{"message":"You have no credits remaining.","type":"insufficient_quota","code":"credit_balance_exhausted"}}';
        $this->assertTrue(OpenAiChat::outOfCredit($body));
        $this->assertFalse(OpenAiChat::outOfCredit('{"error":{"message":"Rate limit reached for gpt-5.4-mini on tokens per min.","type":"tokens","code":"rate_limit_exceeded"}}'));
        $this->assertSame(KeyFailure::NO_CREDIT, KeyFailure::of(new ModelCallFailed(ModelCallFailed::PROVIDER_ERROR, new RuntimeException('OpenAI insufficient_quota: '.$body))), 'marked so, and the operator is told');
    }

    public function test_a_live_call_refused_for_its_key_marks_the_provider_and_the_operator_sees_it_on_every_screen(): void
    {
        $anthropic = AiProvider::query()->create(['provider' => AiProviderName::Anthropic, 'api_key' => 'sk-ant-old']);
        $anthropic->forceFill(['status' => ProviderStatus::Connected])->save();
        $driver = new class implements ChatDriver
        {
            public function provider(): AiProviderName
            {
                return AiProviderName::Anthropic;
            }

            public function json(string $apiKey, string $model, string $system, string $user, int $maxOutputTokens, ?string $reasoningEffort = null): ModelReply
            {
                throw new ModelCallFailed(ModelCallFailed::PROVIDER_ERROR, new RuntimeException('Anthropic Authentication Exception: invalid x-api-key'));
            }
        };

        try {
            (new ProviderChatModel([$driver]))->json(AiProviderName::Anthropic, 'claude-haiku-5-5', 'system', 'user', 100);
            $this->fail('the call fails');
        } catch (ModelCallFailed) {
        }

        $anthropic->refresh();
        $this->assertSame([ProviderStatus::Failed, 'invalid_key'], [$anthropic->status, $anthropic->last_error_code], 'not tried again on every question');

        $operator = User::factory()->operator()->locale('he')->create();
        $this->actingAs($operator)->get('/operator/budgets')->assertOk()
            ->assertSee('data-key-alert', false)
            ->assertSee('מפתח של ספק מודלים לא עובד')
            ->assertSee('Anthropic (Claude)')
            ->assertSee('מפתח לא תקין')
            ->assertSee('לעדכון המפתח');

        // A shop manager never sees the providers.
        $shop = Shop::factory()->create();
        $merchant = User::factory()->create();
        $merchant->attachShop($shop);
        $this->actingAs($merchant)->get("/merchant/{$shop->slug}/overview")->assertOk()->assertDontSee('data-key-alert', false);

        // A new key saved: the alert goes, until its check says otherwise.
        $anthropic->forceFill(['api_key' => 'sk-ant-new'])->save();
        $this->actingAs($operator)->get('/operator/budgets')->assertOk()->assertDontSee('data-key-alert', false);
    }
}
