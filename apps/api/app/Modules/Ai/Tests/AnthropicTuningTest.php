<?php

namespace App\Modules\Ai\Tests;

use App\Modules\Ai\Support\Drivers\AnthropicChat;
use PHPUnit\Framework\TestCase;

/**
 * A Claude model of the 4.6 and 5 families thinks on its own at a medium effort unless told otherwise; the effort a
 * caller asks for is sent (Rocks, 28.10: the site checker, Haiku 5.5, took 8.8 s and 1,800 output tokens on a reply it
 * was never told to think less about). An older model takes neither, and is sent as before.
 */
final class AnthropicTuningTest extends TestCase
{
    public function test_a_current_model_is_sent_adaptive_thinking_at_the_effort_asked_for(): void
    {
        $this->assertSame(['thinking' => ['type' => 'adaptive'], 'effort' => 'low'], AnthropicChat::tuning('claude-haiku-5-5', 'low'));
        $this->assertSame(['thinking' => ['type' => 'adaptive'], 'effort' => 'low'], AnthropicChat::tuning('claude-sonnet-4-6', 'minimal'), 'minimal is low here');
        $this->assertSame(['thinking' => ['type' => 'adaptive'], 'effort' => 'high'], AnthropicChat::tuning('claude-opus-5-5', 'high'));
    }

    public function test_without_an_effort_or_on_an_older_model_nothing_is_sent(): void
    {
        $this->assertSame([], AnthropicChat::tuning('claude-haiku-5-5', null), 'the model\'s default, as before');
        $this->assertSame([], AnthropicChat::tuning('claude-haiku-5-5', 'model_default'));
        $this->assertSame([], AnthropicChat::tuning('claude-haiku-4-5', 'low'), 'Haiku 4.5 takes budget_tokens, never effort');
        $this->assertSame([], AnthropicChat::tuning('claude-sonnet-4-5', 'low'));
    }
}
