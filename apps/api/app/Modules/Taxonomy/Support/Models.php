<?php

namespace App\Modules\Taxonomy\Support;

use App\Core\Facades\Settings;
use App\Modules\Ai\Contracts\AgentModel;
use App\Modules\Ai\Contracts\ChatModel;
use App\Modules\Ai\Contracts\ModelCallFailed;
use App\Modules\Ai\Contracts\ModelReply;
use App\Modules\Ai\Contracts\SpendCapReached;
use App\Modules\Ai\Contracts\SpendGuard;
use App\Modules\Ai\Contracts\StructuredChatModel;
use App\Modules\Runs\Contracts\RunContext;

/**
 * One call to a model for this module: SpendGuard first, the prompt file of the version asked
 * (Prompts/{name}.v{n}.md) with its schema, and the tokens and cost recorded on the run after.
 * A role is a setting prefix: taxonomy.{role}_provider, _model, _input_usd_per_million and
 * _output_usd_per_million.
 */
final class Models
{
    /** Why the last call failed (ModelCallFailed's reason, or "spend_cap"); null when it answered. */
    public ?string $failure = null;

    public function __construct(
        private readonly ChatModel $models,
        private readonly SpendGuard $spend,
    ) {}

    public static function of(string $role): AgentModel
    {
        return AgentModel::from("taxonomy.{$role}_provider", "taxonomy.{$role}_model");
    }

    /**
     * @param  string  $prefix  stable text after the prompt (a store's bank), so the provider can cache it
     * @param  array<string, mixed>  $input
     */
    public function call(RunContext $run, string $prompt, int $version, string $role, array $input, string $prefix = ''): ?ModelReply
    {
        $who = self::of($role);
        $file = __DIR__.'/../Prompts/'.$prompt.'.v'.$version.'.md';
        $system = (string) file_get_contents($file).($prefix === '' ? '' : "\n\n".$prefix);
        $schema = json_decode((string) file_get_contents(substr($file, 0, -3).'.schema.json'), true);
        $user = (string) json_encode($input, JSON_UNESCAPED_UNICODE);
        $maxOutput = (int) Settings::get("taxonomy.{$role}_max_output_tokens");
        $in = (float) Settings::get("taxonomy.{$role}_input_usd_per_million");
        $out = (float) Settings::get("taxonomy.{$role}_output_usd_per_million");

        $this->failure = null;

        try {
            $this->spend->assertCanSpend(((mb_strlen($system) + mb_strlen($user)) / 2 * $in + $maxOutput * $out) / 1_000_000);
            $reply = is_array($schema) && $this->models instanceof StructuredChatModel
                ? $this->models->structured($who->provider, $who->model, $system, $user, $schema, $maxOutput)
                : $this->models->json($who->provider, $who->model, $system, $user, $maxOutput);
            $run->usage($who->provider->value, $who->model, $reply->inputTokens, $reply->outputTokens, 0, $reply->costUsd($in, $out));

            return $reply;
        } catch (SpendCapReached $e) {
            $this->failure = 'spend_cap';
            $run->fail('taxonomy::runs.spend_cap', [], $e->getMessage());
        } catch (ModelCallFailed $e) {
            $this->failure = $e->reason;
            $run->fail('taxonomy::runs.model_failed', ['reason' => $e->reason], $e->getMessage());
        }

        return null;
    }

    /** Whether two roles are of other families, as every writer and its checker must be. */
    public static function crossed(string $writer, string $checker): bool
    {
        return self::of($writer)->provider->family() !== self::of($checker)->provider->family();
    }
}
