<?php

namespace App\Modules\Retrieval\Tests;

use App\Modules\Retrieval\Actions\BuildIndex;
use App\Modules\Retrieval\Contracts\Passages;
use App\Modules\Retrieval\Contracts\SectionQuestions;
use App\Modules\Retrieval\Models\RetrievalChunk;
use App\Modules\Retrieval\Support\Topics;
use App\Modules\Retrieval\Tests\Concerns\BuildsShop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A question finds the section that answers it by more than its words: the topic both are about
 * (returns, shipping...), read in code, and the questions the section answers, embedded as its
 * second vector. "אפשר להחזיר טבעת אירוסין שקניתי?" finds the return policy, not the blog post
 * that shares its words.
 */
final class SectionTopicsTest extends TestCase
{
    use BuildsShop;
    use RefreshDatabase;

    private const QUESTION = 'אפשר להחזיר טבעת אירוסין שקניתי?';

    /** @var array<string, list<string>> */
    public array $written = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildShop();
        $this->article('1', 'מדיניות החזרות', 'ניתן להשיב פריט תוך 14 יום באריזה המקורית, והכסף יוחזר לכרטיס.');
        $this->article('2', 'טבעת אירוסין', 'איך בוחרים טבעת אירוסין שקניתי לבת הזוג: יהלום, זהב ומידה.');
    }

    public function test_a_section_and_a_question_say_their_topics_in_code(): void
    {
        $this->assertSame(['returns'], Topics::ofQuestion(self::QUESTION));
        $this->assertSame(['shipping'], Topics::ofQuestion('תוך כמה זמן מגיע המשלוח?'));
        $this->assertSame(['returns'], Topics::ofSection('מדיניות החזרות', 'ניתן להשיב פריט תוך 14 יום.'), 'its heading names it');
        $this->assertSame([], Topics::ofSection('בלוג', 'הזמנו משלוח של אבנים לסדנה, ובסוף היום ישבנו כולנו לעצב טבעות ושרשראות חדשות לקולקציית החורף.'), 'named once in passing is not what it is about');
        $this->assertSame(['shipping'], Topics::ofSection('שאלות', 'המשלוח לוקח 3 ימים. משלוח חינם מעל 500.'));

        app(BuildIndex::class)->handle($this->shop->id);
        $topics = $this->inShop(fn () => RetrievalChunk::query()->pluck('topics', 'external_id')->all());

        $this->assertSame(' returns ', $topics['1']);
        $this->assertStringNotContainsString('returns', (string) $topics['2'], 'the post is not about returns');
    }

    public function test_a_question_of_the_topic_brings_the_section_and_its_questions_bring_it_first(): void
    {
        app(BuildIndex::class)->handle($this->shop->id);
        $near = fn (): array => $this->inShop(fn () => app(Passages::class)->near($this->shop->id, self::QUESTION, ['content'], 2));

        $this->assertSame(['2', '1'], array_column($near(), 'external_id'), 'the post shares the words; the policy is there by its topic');
        $this->assertGreaterThan(0, $near()[1]['similarity'], 'nearer for its topic, though it shares no word');

        // The questions the policy answers, as another module wrote them: its second vector.
        $hash = $this->inShop(fn () => RetrievalChunk::query()->where('external_id', '1')->value('text_hash'));
        $this->written = [$hash => [self::QUESTION, 'תוך כמה ימים אפשר להחזיר?']];
        $this->app->instance(WrittenForTest::class, new WrittenForTest($this));
        $this->app->tag([WrittenForTest::class], SectionQuestions::TAG);

        app(BuildIndex::class)->handle($this->shop->id);
        $this->assertContains("מדיניות החזרות\n".self::QUESTION."\nתוך כמה ימים אפשר להחזיר?", $this->embedder->texts, 'its title and its questions, embedded together');
        $this->assertSame('1', $near()[0]['external_id'], 'the questions it answers bring it first');

        $calls = $this->embedder->calls;
        app(BuildIndex::class)->handle($this->shop->id);
        $this->assertSame($calls, $this->embedder->calls, 'the same questions are never embedded again');

        $this->written = [];
        app(BuildIndex::class)->handle($this->shop->id);
        $this->assertNull($this->inShop(fn () => RetrievalChunk::query()->where('external_id', '1')->value('asks_hash')), 'questions gone: the vector goes too');
    }
}

final class WrittenForTest implements SectionQuestions
{
    public function __construct(private readonly SectionTopicsTest $test) {}

    public function questions(string $shopId, array $textHashes): array
    {
        return array_intersect_key($this->test->written, array_flip($textHashes));
    }
}
