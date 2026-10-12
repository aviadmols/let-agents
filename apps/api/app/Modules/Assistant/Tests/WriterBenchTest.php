<?php

namespace App\Modules\Assistant\Tests;

use App\Core\Facades\Features;
use App\Modules\Ai\Contracts\ChatModel;
use App\Modules\Ai\Contracts\ModelCallFailed;
use App\Modules\Ai\Contracts\ModelReply;
use App\Modules\Ai\Enums\AiProviderName;
use App\Modules\Assistant\Actions\AnswerSiteQuestion;
use App\Modules\Assistant\Benchmark\LocalWriter;
use App\Modules\Assistant\Models\AssistantAnswer;
use App\Modules\Assistant\Models\AssistantSearchAsk;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Enrichment\Tests\Concerns\BuildsCatalog;
use App\Modules\Retrieval\Contracts\Passages;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The writer benchmark: a question shoppers asked, its context built once and nothing of that
 * building kept, the current writer and other writers on that same input, all through the search
 * box's own gate and checker, then one blind judge. Nothing reaches a shopper or is saved.
 */
final class WriterBenchTest extends TestCase
{
    use BuildsCatalog;
    use RefreshDatabase;

    /** @var list<array{provider: AiProviderName, model: string, system: string, user: string}> */
    public array $calls = [];

    /** @var list<array<string, mixed>> */
    public array $replies = [];

    /** @var list<array<string, mixed>> */
    public array $passages = [];

    private string $file;

    protected function setUp(): void
    {
        parent::setUp();
        Features::override('assistant.site_scope', false);
        $this->buildShop();
        $product = $this->product('31538', 'בורג נירוסטה לדק 5X50', 'בורג לעץ קשה.', [], ['in_stock' => true, 'price' => '49.00']);
        $this->product('31539', 'בורג מגולוון 4X40', 'בורג לעץ רך.', [], ['in_stock' => false, 'price' => '19.00']);
        $guide = $this->article('600', 'כמה ברגים צריך למטר דק', 'למטר מרובע של דק צריך כ-30 ברגים, שניים בכל מפגש של קרש עם קורה.');
        $this->passages = [
            ['source' => 'content', 'source_id' => $guide->id, 'external_id' => '600', 'title' => 'כמה ברגים צריך למטר דק', 'text' => 'למטר מרובע של דק צריך כ-30 ברגים, שניים בכל מפגש של קרש עם קורה.', 'similarity' => 0.71],
            ['source' => 'product', 'source_id' => $product->id, 'external_id' => '31538', 'title' => 'בורג נירוסטה לדק 5X50', 'text' => 'בורג לעץ קשה.', 'similarity' => 0.52],
        ];
        $this->inShop(fn () => AssistantSearchAsk::query()->create([
            'shop_id' => $this->shop->id, 'day' => now()->toDateString(), 'question' => 'כמה ברגים צריך למטר דק?',
            'products' => ['31538', '31539'], 'outcome' => 'answered', 'from' => 'model', 'picks' => [],
        ]));

        $test = $this;
        $this->app->instance(ChatModel::class, new class($test) implements ChatModel
        {
            public function __construct(private readonly WriterBenchTest $test) {}

            public function json(AiProviderName $provider, string $model, string $system, string $user, int $maxOutputTokens, ?string $reasoningEffort = null): ModelReply
            {
                $this->test->calls[] = ['provider' => $provider, 'model' => $model, 'system' => $system, 'user' => $user];

                return new ModelReply(array_shift($this->test->replies) ?? [], 900, 100);
            }
        });
        // Finding the passages writes, as the real search does (a stored vector's use): the benchmark must keep none of it.
        $this->app->instance(Passages::class, new class($test) implements Passages
        {
            public function __construct(private readonly WriterBenchTest $test) {}

            public function near(string $shopId, string $text, array $sources, int $limit): array
            {
                CatalogProduct::query()->where('external_id', '31538')->update(['title' => 'written while reading']);

                return array_slice($this->test->passages, 0, $limit);
            }
        });

        config(['local_ai.enabled' => true, 'local_ai.api' => 'openai', 'local_ai.url' => 'https://router.test/api', 'local_ai.key' => 'k', 'local_ai.body' => ['usage' => ['include' => true]]]);
        $this->file = sys_get_temp_dir().'/writer-bench-'.uniqid().'.jsonl';
    }

    protected function tearDown(): void
    {
        foreach (glob(preg_replace('~\.jsonl$~', '', $this->file).'*') ?: [] as $file) {
            @unlink($file);
        }
        parent::tearDown();
    }

    public function test_every_writer_gets_the_same_input_and_is_checked_and_judged_alike(): void
    {
        // 1. Production: the context (nothing of its building kept), the current writer and the checker.
        $this->replies = [
            ['found' => true, 'answer' => 'בערך 30 ברגים למטר מרובע, שניים בכל מפגש.', 'refs' => ['s1'], 'picks' => [['ref' => 'p1', 'why' => 'בורג נירוסטה לדק']]],
            ['supported' => true, 'on_topic' => true, 'safe' => true, 'picks_fit' => true],
        ];
        $this->artisan('assistant:writer-bench', ['step' => 'snapshot', 'file' => $this->file, '--limit' => 3])->assertSuccessful();

        $this->assertSame('בורג נירוסטה לדק 5X50', $this->inShop(fn () => CatalogProduct::query()->where('external_id', '31538')->value('title')), 'what building the context wrote is undone');
        $record = $this->lines($this->file)[0];
        $this->assertSame(['31538'], $record['writers']['current']['picks']);
        $this->assertSame('kept', $record['writers']['current']['check']['verdict']);
        $this->assertSame($this->calls[0]['user'], $record['context']['input'], 'the input the current writer got is the one kept');

        // 2. Two other writers through OpenRouter, each on that very input.
        $reply = fn (string $answer, array $picks): array => ['choices' => [['message' => ['content' => json_encode(['found' => true, 'answer' => $answer, 'refs' => ['s1'], 'picks' => $picks], JSON_UNESCAPED_UNICODE)]]],
            'usage' => ['prompt_tokens' => 1200, 'completion_tokens' => 80, 'cost' => 0.0014], 'provider' => 'Alibaba'];
        Http::fake(['router.test/api/v1/chat/completions' => Http::sequence()
            ->push($reply('צריך כ-30 ברגים למטר מרובע של דק.', [['ref' => 'p1', 'why' => 'מתאים לדק']]))
            ->push($reply('כ-30 ברגים (p1) למטר.', []))]);
        config(['local_ai.model' => 'qwen/qwen3.5-27b']);
        $this->artisan('assistant:writer-bench', ['step' => 'write', 'file' => $this->file])->assertSuccessful();
        config(['local_ai.model' => 'qwen/qwen3.5-35b-a3b']);
        $this->artisan('assistant:writer-bench', ['step' => 'write', 'file' => $this->file])->assertSuccessful();

        Http::assertSent(fn (Request $request): bool => $request->hasHeader('Authorization', 'Bearer k') && $request['temperature'] === 0
            && $request['messages'][0]['content'] === AnswerSiteQuestion::prompt('answer_site') && $request['messages'][1]['content'] === $record['context']['input']
            && $request['response_format']['json_schema']['schema'] === LocalWriter::SCHEMA && $request['usage'] === ['include' => true]);
        $writers = $this->lines(str_replace('.jsonl', '.writers.jsonl', $this->file))[0]['writers'];
        $this->assertSame(['current', 'qwen/qwen3.5-27b', 'qwen/qwen3.5-35b-a3b'], array_keys($writers));
        $this->assertSame(0.0014, $writers['qwen/qwen3.5-27b']['cost_usd']);
        $this->assertSame(['p1'], $writers['qwen/qwen3.5-35b-a3b']['code']['internal_ids'], 'an internal ref shown to the shopper is caught');

        // 3. Production: the checker on both, then one blind judge for all three.
        $judged = ['picks_correct' => true, 'answer_correct' => true, 'hallucination' => false, 'wrong_attribution' => false, 'language_correct' => true, 'language_issues' => [], 'ok' => true];
        $this->replies = [
            ['supported' => true, 'on_topic' => true, 'safe' => true, 'picks_fit' => true],
            ['answerable' => true, 'replies' => ['A' => $judged, 'B' => $judged, 'C' => $judged], 'best' => 'A', 'reason' => 'x'],
        ];
        $this->artisan('assistant:writer-bench', ['step' => 'judge', 'file' => str_replace('.jsonl', '.writers.jsonl', $this->file)])->assertSuccessful();

        $judge = end($this->calls);
        $this->assertSame([AiProviderName::Anthropic, 'claude-sonnet-5-5'], [$judge['provider'], $judge['model']]);
        $this->assertStringNotContainsString('qwen', $judge['user'], 'blind: the judge is not told who wrote what');
        $result = $this->lines(str_replace('.jsonl', '.judged.jsonl', $this->file))[0];
        $this->assertEqualsCanonicalizing(['current', 'qwen/qwen3.5-27b', 'qwen/qwen3.5-35b-a3b'], array_keys($result['judge']['verdicts']));
        $this->assertSame(['A', 'B', 'C'], array_keys($result['judge']['order']), 'the replies by letter, in an order of their own');
        $this->assertSame(['skipped', 'number_not_in_the_pages'], [$result['writers']['qwen/qwen3.5-35b-a3b']['check']['verdict'], $result['writers']['qwen/qwen3.5-35b-a3b']['check']['reason']], '"(p1)" is a number the pages lack: code removes the answer before any checker');

        $summary = json_decode((string) file_get_contents(str_replace('.jsonl', '.summary.json', $this->file)), true);
        $this->assertSame(1, $summary['questions']);
        $this->assertEquals(100, $summary['writers']['current']['final_accuracy_after_checker_pct']);
        $this->assertEquals(100, $summary['writers']['qwen/qwen3.5-35b-a3b']['internal_id_leak_pct']);
        $this->assertSame(0.0014, $summary['writers']['qwen/qwen3.5-27b']['cost_per_question_usd']['writer']);
        $this->assertEquals(0, $summary['writers']['qwen/qwen3.5-35b-a3b']['final_accuracy_after_checker_pct'], 'an answerable question left with nothing to show');

        // Nothing for shoppers: no saved answer, no new line in the search box's log.
        $this->assertSame(0, $this->inShop(fn () => AssistantAnswer::query()->count()));
        $this->assertSame(1, $this->inShop(fn () => AssistantSearchAsk::query()->count()));
    }

    public function test_code_catches_a_made_up_number_and_a_product_not_given(): void
    {
        $this->replies = [
            ['found' => true, 'answer' => 'בערך 30 ברגים למטר.', 'refs' => ['s1'], 'picks' => []],
            ['supported' => true, 'on_topic' => true, 'safe' => true, 'picks_fit' => true],
        ];
        $this->artisan('assistant:writer-bench', ['step' => 'snapshot', 'file' => $this->file])->assertSuccessful();
        Http::fake(['router.test/*' => Http::response(['choices' => [['message' => ['content' => json_encode(['found' => true, 'answer' => 'צריך 45 ברגים למטר.', 'refs' => ['s1'], 'picks' => [['ref' => 'p7', 'why' => 'בורג']]], JSON_UNESCAPED_UNICODE)]]]])]);
        config(['local_ai.model' => 'qwen/qwen3.5-27b']);

        $this->artisan('assistant:writer-bench', ['step' => 'write', 'file' => $this->file])->assertSuccessful();

        $side = $this->lines(str_replace('.jsonl', '.writers.jsonl', $this->file))[0]['writers']['qwen/qwen3.5-27b'];
        $this->assertSame('number_not_in_the_pages', $side['refusal'], 'the search box would not show it');
        $this->assertTrue($side['code']['invented_number']);
        $this->assertSame(1, $side['code']['invalid_picks']);
        $this->assertSame([], $side['picks']);
    }

    public function test_the_local_writer_reads_only_the_writers_shape(): void
    {
        $this->assertSame(['found' => true, 'answer' => 'כן', 'refs' => [], 'picks' => []],
            LocalWriter::validated("<think>hmm</think>\n```json\n{\"found\": true, \"answer\": \"כן\", \"refs\": [], \"picks\": []}\n```"));

        foreach (['not json', '{"found": "yes", "answer": "", "refs": [], "picks": []}', '{"found": true, "answer": "x", "refs": [1], "picks": []}', '{"found": true, "answer": "x", "refs": [], "picks": [{"ref": "p1"}]}'] as $bad) {
            try {
                LocalWriter::validated($bad);
                $this->fail("accepted: {$bad}");
            } catch (ModelCallFailed $e) {
                $this->assertSame(ModelCallFailed::NOT_JSON, $e->reason);
            }
        }

        config(['local_ai.enabled' => false]);
        $this->expectException(ModelCallFailed::class);
        (new LocalWriter)->write('s', 'u', 100);
    }

    public function test_a_server_that_fails_is_a_failure_of_that_writer_not_of_the_run(): void
    {
        $this->replies = [
            ['found' => true, 'answer' => 'בערך 30 ברגים למטר.', 'refs' => ['s1'], 'picks' => []],
            ['supported' => true, 'on_topic' => true, 'safe' => true, 'picks_fit' => true],
        ];
        $this->artisan('assistant:writer-bench', ['step' => 'snapshot', 'file' => $this->file])->assertSuccessful();
        Http::fake(['router.test/*' => Http::response('model not found', 404)]);
        config(['local_ai.model' => 'qwen/qwen3.5-27b']);
        $this->artisan('assistant:writer-bench', ['step' => 'write', 'file' => $this->file])->assertSuccessful();

        $this->replies = [['answerable' => true, 'replies' => ['A' => ['picks_correct' => true, 'answer_correct' => true, 'ok' => true]], 'best' => 'A', 'reason' => 'x']];
        $this->artisan('assistant:writer-bench', ['step' => 'judge', 'file' => str_replace('.jsonl', '.writers.jsonl', $this->file)])->assertSuccessful();

        $result = $this->lines(str_replace('.jsonl', '.judged.jsonl', $this->file))[0];
        $this->assertSame('provider_error', $result['writers']['qwen/qwen3.5-27b']['error']);
        $this->assertSame(['current'], array_values($result['judge']['order']), 'the judge reads only the replies there are');
        $summary = json_decode((string) file_get_contents(str_replace('.jsonl', '.summary.json', $this->file)), true);
        $this->assertSame(1, $summary['writers']['qwen/qwen3.5-27b']['failures']);
    }

    /** @return list<array<string, mixed>> */
    private function lines(string $file): array
    {
        return array_map(fn (string $line): array => json_decode($line, true), file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []);
    }
}
