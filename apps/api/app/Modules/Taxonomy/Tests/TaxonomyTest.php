<?php

namespace App\Modules\Taxonomy\Tests;

use App\Core\Facades\Features;
use App\Core\Facades\Settings;
use App\Core\Tenancy\TenantContext;
use App\Modules\Ai\Contracts\ChatModel;
use App\Modules\Ai\Contracts\ModelReply;
use App\Modules\Ai\Enums\AiProviderName;
use App\Modules\Catalog\Events\CatalogProductsChanged;
use App\Modules\Catalog\Models\CatalogCategory;
use App\Modules\Catalog\Models\CatalogContent;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Runs\Models\Run;
use App\Modules\Search\Actions\BuildSearchIndex;
use App\Modules\Search\Models\SearchIndex;
use App\Modules\Taxonomy\Actions\AssignTags;
use App\Modules\Taxonomy\Actions\BuildTagBank;
use App\Modules\Taxonomy\Actions\MergeTagProposals;
use App\Modules\Taxonomy\Actions\ReviewPictureGroups;
use App\Modules\Taxonomy\Actions\ScanBankGaps;
use App\Modules\Taxonomy\Jobs\TagChangesJob;
use App\Modules\Taxonomy\Listeners\TagWhatChanged;
use App\Modules\Taxonomy\Models\TaxonomyAssignment;
use App\Modules\Taxonomy\Models\TaxonomyGroup;
use App\Modules\Taxonomy\Models\TaxonomyProposal;
use App\Modules\Taxonomy\Models\TaxonomySubject;
use App\Modules\Taxonomy\Models\TaxonomyTag;
use App\Modules\Taxonomy\Support\Bank;
use App\Modules\Taxonomy\Support\Evaluation;
use App\Modules\Taxonomy\Support\Meaning;
use App\Modules\Taxonomy\Support\Norm;
use App\Modules\Taxonomy\Support\Sample;
use App\Modules\Tenancy\Models\Shop;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * A store's tag bank and its products' tags, in a store that sells books and shirts: the bank built
 * from a sample and reviewed by the other family; each tag kept only with its evidence and in its
 * scope, checked by the other family; only what changed tagged again; the bank grown from what was
 * proposed often enough; and the tagging measured against cases a person reviewed.
 */
final class TaxonomyTest extends TestCase
{
    use RefreshDatabase;

    private Shop $shop;

    /** @var list<array{system: string, user: array<string, mixed>, provider: AiProviderName}> */
    public array $calls = [];

    /** @var array<string, Closure(array<string, mixed>): array<string, mixed>> by the prompt's opening words */
    public array $replies = [];

    /** @var array<string, CatalogCategory> */
    private array $categories = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->shop = Shop::factory()->create(['name' => 'ספרים וחולצות']);
        $test = $this;
        $this->app->instance(ChatModel::class, new class($test) implements ChatModel
        {
            public function __construct(private readonly TaxonomyTest $test) {}

            public function json(AiProviderName $provider, string $model, string $system, string $user, int $maxOutputTokens, ?string $reasoningEffort = null): ModelReply
            {
                $input = (array) json_decode($user, true);
                $this->test->calls[] = ['system' => $system, 'user' => $input, 'provider' => $provider];
                foreach ($this->test->replies as $opening => $reply) {
                    if (str_starts_with($system, $opening)) {
                        return new ModelReply($reply($input), 1000, 200);
                    }
                }

                return new ModelReply([], 0, 0);
            }
        });

        $this->product('b1', 'חטוף - אלי שרעבי', '491 יום שרד אלי שרעבי בשבי החמאס.', ['ספרים']);
        $this->product('b2', 'דרקולה', 'עורך דין בריטי מגיע לטרנסילבניה. ספר האימה הקלאסי.', ['ספרים']);
        $this->product('s1', 'חולצת פשתן לבנה', 'חולצה מבד פשתן מכובס, קלה לקיץ.', ['חולצות'], [['name' => 'בד', 'values' => ['פשתן']]]);
    }

    public function test_a_bank_from_before_the_picture_rule_is_told_its_groups_and_what_no_longer_holds_is_taken_out(): void
    {
        // Rocks: a bank of tag_bank v1, every group "no picture", and a picture's word on a metal kept all the same
        $this->bank();
        app(BuildTagBank::class)->handle($this->shop->id);
        [$linen, $calm, $shirt] = app(TenantContext::class)->run($this->shop->id, function (): array {
            TaxonomyGroup::query()->update(['from_picture' => true]);
            $tag = fn (string $he): TaxonomyTag => TaxonomyTag::query()->where('label_he', $he)->sole();
            $shirt = CatalogProduct::query()->where('external_id', 's1')->value('id');
            $give = fn (TaxonomyTag $t) => TaxonomyAssignment::query()->create(['shop_id' => $this->shop->id, 'subject_type' => 'product', 'subject_id' => $shirt, 'tag_id' => $t->id,
                'source' => 'picture', 'evidence' => 'נראה כך', 'confidence' => 0.9, 'weight' => 0.8, 'status' => 'kept', 'checked' => 'held', 'input_hash' => 'h', 'bank_version' => 1]);

            return [$give($tag('פשתן')), $give($tag('אימה')), $shirt];
        });
        $this->replies['You decide, for each group'] = fn (array $in): array => ['groups' => array_map(fn (array $g): array => [
            'group' => $g['group'], 'from_picture' => in_array($g['group'], ['mood', 'genre'], true), 'why' => 'x'], $in['groups'])];

        $run = app(ReviewPictureGroups::class)->handle($this->shop->id);

        $this->assertSame(1, $run->output['removed'], 'what a fabric is, only the product says');
        app(TenantContext::class)->run($this->shop->id, function () use ($linen, $calm): void {
            $this->assertSame(['rejected', 'rule'], [$linen->fresh()->status, $linen->fresh()->checked]);
            $this->assertSame('kept', $calm->fresh()->status, 'a group the reviewer allows a picture for keeps it');
            $this->assertFalse(TaxonomyGroup::query()->where('key', 'fabric')->value('from_picture'));
        });

        $asked = count($this->calls);
        app(ReviewPictureGroups::class)->handle($this->shop->id);
        $this->assertCount($asked, $this->calls, 'the same bank is never asked twice');
    }

    public function test_the_teams_word_on_a_tag_holds_when_the_product_is_tagged_again(): void
    {
        $this->bank();
        app(BuildTagBank::class)->handle($this->shop->id);
        $this->tagging();
        app(AssignTags::class)->handle($this->shop->id, posts: false);
        $book = fn (): array => app(TenantContext::class)->run($this->shop->id, fn () => TaxonomyAssignment::query()->where('subject_id', CatalogProduct::query()->where('external_id', 'b1')->value('id'))->with('tag')->get()
            ->mapWithKeys(fn (TaxonomyAssignment $a): array => [$a->tag->label_he => $a->status.'/'.$a->checked])->all());
        $out = $book();
        $this->assertSame('rejected/taken_out', $out['מתח'], 'the check took the thriller out');

        app(TenantContext::class)->run($this->shop->id, function (): void {
            $ids = TaxonomyAssignment::query()->with('tag')->get()->keyBy(fn (TaxonomyAssignment $a): string => $a->tag->label_he.'|'.$a->subject_id);
            $b1 = CatalogProduct::query()->where('external_id', 'b1')->value('id');
            app(Meaning::class)->decide($ids['מתח|'.$b1]->id, true);
            app(Meaning::class)->decide($ids['חטופים|'.$b1]->id, false);
        });
        app(AssignTags::class)->handle($this->shop->id, posts: false, all: true);

        $after = $book();
        $this->assertSame('kept/team', $after['מתח'], 'brought back by the team, and kept when tagged again');
        $this->assertSame('rejected/team_out', $after['חטופים'], 'taken out by the team, and not given again');
    }

    public function test_a_shop_that_tags_by_itself_tags_what_changed_within_minutes_and_nothing_twice(): void
    {
        $this->bank();
        app(BuildTagBank::class)->handle($this->shop->id);
        $this->tagging();
        $runs = fn (): int => Run::query()->where('action', AssignTags::ACTION)->count();

        // switch off: a change is not even queued
        Queue::fake();
        app(TagWhatChanged::class)->handle(new CatalogProductsChanged($this->shop->id, ['b1']));
        Queue::assertNothingPushed();

        Features::override('taxonomy.tagging', true, $this->shop->id);
        app(TagWhatChanged::class)->handle(new CatalogProductsChanged($this->shop->id, ['b1']));
        app(TagWhatChanged::class)->handle(new CatalogProductsChanged($this->shop->id, ['b2']));
        Queue::assertPushed(TagChangesJob::class, fn (TagChangesJob $job): bool => ! $job->everything);
        $this->assertSame(['b1', 'b2'], Cache::get(TagChangesJob::WAITING.$this->shop->id), 'changes in a row wait for one run');

        // the job: the two changed books tagged, the shirt left, the search told
        Event::fake([CatalogProductsChanged::class]);
        app()->call([new TagChangesJob($this->shop->id), 'handle']);
        $tagged = app(TenantContext::class)->run($this->shop->id, fn (): array => CatalogProduct::query()
            ->whereIn('id', TaxonomySubject::query()->select('subject_id'))->orderBy('external_id')->pluck('external_id')->all());
        $this->assertSame(['b1', 'b2'], $tagged);
        Event::assertDispatched(CatalogProductsChanged::class, fn (CatalogProductsChanged $e): bool => $e->externalIds !== [] && array_diff($e->externalIds, ['b1', 'b2']) === []);
        $this->assertSame(1, $runs());

        // told again with nothing changed in their words (their stock, a rule's pairs, its own event): no call, no run
        $asked = count($this->calls);
        TagChangesJob::products($this->shop->id, ['b1']);
        app()->call([new TagChangesJob($this->shop->id), 'handle']);
        $this->assertCount($asked, $this->calls);
        $this->assertSame(1, $runs());

        // the night: the shirt and the store's post, not the platform's own rows of what it learned of the store
        app(TenantContext::class)->run($this->shop->id, function (): void {
            CatalogContent::query()->create(['type' => CatalogContent::POST, 'external_id' => 'post-1', 'title' => 'מדריך לספרי מתח', 'body' => 'ספרי מתח לחופשה.', 'hash' => 'p1']);
            CatalogContent::query()->create(['type' => CatalogContent::KNOWLEDGE, 'external_id' => CatalogContent::OWN_PREFIX.'knowledge-posts', 'title' => '', 'body' => '- משלוח חינם', 'hash' => 'k1']);
        });
        $this->assertSame(2, app(AssignTags::class)->waiting($this->shop->id));
        $this->artisan('taxonomy:tag-changes', ['--now' => true])->assertSuccessful();
        $this->assertSame(0, app(AssignTags::class)->waiting($this->shop->id));
        $this->assertSame(2, $runs());
    }

    public function test_the_bank_is_designed_from_a_sample_and_reviewed_by_the_other_family(): void
    {
        $this->bank();

        app(BuildTagBank::class)->handle($this->shop->id);

        $this->assertSame([AiProviderName::Anthropic, AiProviderName::OpenAi], array_column($this->calls, 'provider'));
        $this->assertCount(3, $this->calls[0]['user']['products'], 'the sample: every product of a store this small');
        app(TenantContext::class)->run($this->shop->id, function (): void {
            $groups = TaxonomyGroup::query()->orderBy('position')->get()->keyBy('key');
            $this->assertSame(['genre', 'topic', 'fabric'], $groups->keys()->all(), 'a group of one tag is not kept');
            $this->assertSame(['ספרים'], $groups['genre']->scope, 'only the store\'s own categories: "מדפים" is not one');
            $this->assertSame(TaxonomyGroup::FACTUAL, $groups['fabric']->kind);
            $this->assertFalse($groups['genre']->filter, 'the reviewer set it right');
            $genre = TaxonomyTag::query()->where('group_id', $groups['genre']->id)->orderBy('key')->get();
            $this->assertSame(['fantasy', 'horror', 'thriller'], $genre->pluck('key')->all(), 'the duplicate wording kept once, "scary" merged into horror, "misc" removed, fantasy added');
            $this->assertContains('מפחיד', $genre->firstWhere('key', 'horror')->words);
            $this->assertSame(1, Bank::version());
        });

        // asked again: the bank stands
        app(BuildTagBank::class)->handle($this->shop->id);
        $this->assertCount(2, $this->calls);
    }

    public function test_the_sample_reads_every_narrow_category_of_a_store_whose_categories_stand_side_by_side(): void
    {
        // Sela Meir: every book is in "ספרים" too, all at one depth; the sample took its first four
        foreach (range(1, 12) as $i) {
            $this->product('e'.$i, 'כלכלה '.$i, 'ספר.', ['ספרים', 'כלכלה']);
            $this->product('g'.$i, 'ביוגרפיה '.$i, 'ספר.', ['ספרים', 'ביוגרפיות']);
        }
        $this->product('h1', 'שירה 1', 'ספר.', ['ספרים', 'שירה']);

        $sample = app(TenantContext::class)->run($this->shop->id, fn () => Sample::products(12, 3)->pluck('title')->all());

        $this->assertCount(12, $sample, 'full, past three of each');
        $this->assertContains('שירה 1', $sample, 'the smallest category too');
        $this->assertGreaterThanOrEqual(4, count(array_filter($sample, fn (string $t): bool => str_starts_with($t, 'כלכלה'))));
        $this->assertContains('כלכלה 7', $sample, 'spread through the category, not its first');
    }

    public function test_tags_are_kept_only_with_their_evidence_in_their_scope_and_checked_by_the_other_family(): void
    {
        $this->bank();
        app(BuildTagBank::class)->handle($this->shop->id);
        $this->calls = [];
        $this->tagging();

        $run = app(AssignTags::class)->handle($this->shop->id, posts: false);

        $this->assertSame('succeeded', $run->status->value);
        $this->assertSame([AiProviderName::OpenAi, AiProviderName::Anthropic], array_column($this->calls, 'provider'));
        $this->assertStringContainsString('# The bank', $this->calls[0]['system'], 'the bank after the instructions, the same every call');
        $out = $run->output;
        $this->assertSame(1, $out['dropped_unsupported'], 'a factual tag with no quote');
        $this->assertSame(1, $out['dropped_scope'], 'a genre for a shirt');
        $this->assertSame(1, $out['dropped_unknown'], 'a tag the bank does not have');
        $this->assertSame(1, $out['dropped_picture'], 'a fabric read from a picture');
        $this->assertSame(0, $out['unquoted'], 'a passage reworded a little still stands in the text');

        $tags = $this->tagsOf();
        $this->assertSame(['topic:hostages' => 'kept', 'genre:thriller' => 'rejected'], $tags['b1'], 'the checker took the thriller out');
        $this->assertSame(['genre:horror' => 'kept'], $tags['b2'], 'from knowledge of a well-known work, sure enough; not also fantasy: one genre');
        $this->assertSame(['fabric:linen' => 'kept'], $tags['s1'], 'a factual tag quoted from the store\'s words');
        app(TenantContext::class)->run($this->shop->id, function (): void {
            $a = TaxonomyAssignment::query()->whereHas('tag', fn ($q) => $q->where('key', 'horror'))->sole();
            $this->assertSame('knowledge', $a->source);
            $this->assertSame('held', $a->checked);
            $this->assertFalse($a->explicit, 'inferred: the store\'s words do not say "אימה" of it');
            $hostages = TaxonomyAssignment::query()->whereHas('tag', fn ($q) => $q->where('key', 'hostages'))->sole();
            $this->assertTrue($hostages->explicit, 'its words say "שבי", one of the tag\'s words');
            $this->assertSame(1.0, $hostages->weight, 'what the book is mainly about');
            $this->assertSame(1, TaxonomyProposal::query()->count());
            $this->assertSame(3, TaxonomySubject::query()->count());
        });

        // nothing changed: nothing asked
        $this->calls = [];
        app(AssignTags::class)->handle($this->shop->id, posts: false);
        $this->assertSame([], $this->calls);

        // one product changed: only it
        app(TenantContext::class)->run($this->shop->id, fn () => CatalogProduct::query()->where('external_id', 'b2')->first()->forceFill(['title' => 'דרקולה - מהדורה חדשה'])->save());
        app(AssignTags::class)->handle($this->shop->id, posts: false);
        $this->assertSame(['דרקולה - מהדורה חדשה'], array_column($this->calls[0]['user']['items'], 'title'));
    }

    public function test_the_bank_grows_from_what_was_proposed_often_enough_and_only_those_are_tagged_again(): void
    {
        $this->bank();
        app(BuildTagBank::class)->handle($this->shop->id);
        $this->tagging();
        app(AssignTags::class)->handle($this->shop->id, posts: false);
        $this->replies['You decide which proposed tags'] = fn (array $in): array => ['decisions' => [['id' => 'p1', 'action' => 'add', 'into' => '', 'key' => 'october_7', 'label_he' => 'שבעה באוקטובר', 'label_en' => 'October 7', 'words' => ['7.10'], 'why' => 'נשאל הרבה']]];

        // a shop that asks two products of a proposal (one, by default: a concept is weighed by its relevance, not its count)
        $this->assertSame(1, Settings::get('taxonomy.min_support', $this->shop->id));
        Settings::set('taxonomy.min_support', 2, $this->shop->id);
        app(MergeTagProposals::class)->handle($this->shop->id);
        $this->assertSame(0, app(TenantContext::class)->run($this->shop->id, fn () => TaxonomyTag::query()->where('key', 'october_7')->count()));

        app(TenantContext::class)->run($this->shop->id, function (): void {
            $proposal = TaxonomyProposal::query()->sole();
            $proposal->subjects = [...$proposal->subjects, ['product', CatalogProduct::query()->where('external_id', 'b2')->value('id')]];
            $proposal->save();
        });
        app(MergeTagProposals::class)->handle($this->shop->id);

        app(TenantContext::class)->run($this->shop->id, function (): void {
            $tag = TaxonomyTag::query()->where('key', 'october_7')->sole();
            $this->assertSame(2, $tag->version, 'it came in with the bank\'s next version');
            $this->assertSame('added', TaxonomyProposal::query()->sole()->status);
            $this->assertSame(['s1'], CatalogProduct::query()->whereIn('id', TaxonomySubject::query()->pluck('subject_id'))->pluck('external_id')->all(), 'the two it was proposed for wait to be tagged again; the shirt does not');
        });
    }

    public function test_where_tags_are_in_search_a_product_is_found_by_its_central_tags_and_only_there(): void
    {
        $this->bank();
        app(BuildTagBank::class)->handle($this->shop->id);
        $this->tagging();
        app(AssignTags::class)->handle($this->shop->id, posts: false);
        $kw = function (): string {
            app(BuildSearchIndex::class)->handle($this->shop->id);
            $index = (array) json_decode((string) app(TenantContext::class)->run($this->shop->id, fn () => SearchIndex::query()->sole()->items), true);
            $items = $index['items'] ?? $index;

            // its tags, apart from its words, each with how central it is
            return (string) json_encode(collect($items)->firstWhere('title', 'חטוף - אלי שרעבי')['tg'] ?? [], JSON_UNESCAPED_UNICODE);
        };

        $this->assertSame('[]', $kw(), 'off: the search as it was');

        Features::override('taxonomy.in_search', true, $this->shop->id);
        $words = $kw();
        $this->assertStringContainsString('["חטופים",', $words);
        $this->assertStringContainsString('["Hostages",', $words);
        $this->assertStringNotContainsString('Thriller', $words, 'a tag the checker took out is no word of it');
    }

    public function test_only_tags_sure_enough_to_narrow_by_become_a_filter_beside_the_results(): void
    {
        foreach (range(1, 8) as $n) {
            $this->product('f'.$n, 'ספר '.$n, 'ספר', $n <= 3 ? ['מתח'] : ($n <= 6 ? ['קלאסיקה'] : []));
        }
        $ids = app(TenantContext::class)->run($this->shop->id, fn () => CatalogProduct::query()->orderBy('external_id')->pluck('id', 'external_id'));
        app(TenantContext::class)->run($this->shop->id, function () use ($ids): void {
            $group = TaxonomyGroup::query()->create(['shop_id' => $this->shop->id, 'key' => 'genre', 'label_he' => 'ז\'אנר', 'label_en' => 'Genre', 'kind' => 'inferred', 'filter' => true, 'version' => 1]);
            $tag = fn (string $key, string $he): TaxonomyTag => TaxonomyTag::query()->create(['shop_id' => $this->shop->id, 'group_id' => $group->id, 'key' => $key, 'label_he' => $he, 'label_en' => $key, 'version' => 1]);
            [$scifi, $horror, $thriller, $fantasy] = [$tag('scifi', 'מדע בדיוני'), $tag('horror', 'אימה'), $tag('thriller', 'מתח'), $tag('fantasy', 'פנטזיה')];
            $give = fn (TaxonomyTag $t, string $p, array $o = []) => TaxonomyAssignment::query()->create($o + [
                'shop_id' => $this->shop->id, 'subject_type' => 'product', 'subject_id' => $ids[$p], 'tag_id' => $t->id, 'source' => 'catalog',
                'confidence' => 0.9, 'weight' => 0.9, 'status' => 'kept', 'checked' => 'held', 'input_hash' => 'h', 'bank_version' => 1,
            ]);
            foreach (['f1', 'f2', 'f3'] as $p) {
                $give($scifi, $p);
                $give($thriller, $p);
            }
            foreach (['f4', 'f5', 'f6'] as $p) {
                $give($horror, $p);
            }
            $give($horror, 'f7', ['weight' => 0.4]);
            $give($horror, 'f8', ['checked' => null]);
            foreach (['f6', 'f7', 'f8'] as $p) {
                $give($fantasy, $p, ['source' => 'knowledge']);
            }
        });
        $facets = function (): array {
            app(BuildSearchIndex::class)->handle($this->shop->id);

            return (array) json_decode((string) app(TenantContext::class)->run($this->shop->id, fn () => SearchIndex::query()->sole()->items), true);
        };

        $this->assertNotContains('tag:genre', array_column($facets()['facets'], 'k'), 'off: the filters as they were');

        Features::override('taxonomy.in_filters', true, $this->shop->id);
        $index = $facets();
        $at = array_search('tag:genre', array_column($index['facets'], 'k'), true);
        $this->assertNotFalse($at);
        $this->assertSame(['אימה', 'מדע בדיוני'], array_column($index['facets'][$at]['v'], 'l'),
            'not "מתח", the store\'s own category; not "פנטזיה", known of the work but not said by the store; "אימה" on the three held and central');
        $f1 = collect($index['items'])->firstWhere('title', 'ספר 1')['f'];
        $f7 = collect($index['items'])->firstWhere('title', 'ספר 7')['f'] ?? [];
        $this->assertContains($at * 1000 + 1, $f1);
        $this->assertNotContains($at * 1000, $f7, 'a tag in its background narrows nothing');
    }

    public function test_the_gap_scan_reads_the_products_the_bank_does_not_describe_and_proposes_what_it_lacks(): void
    {
        $this->bank();
        app(BuildTagBank::class)->handle($this->shop->id);
        $this->tagging();
        app(AssignTags::class)->handle($this->shop->id, posts: false);
        $this->calls = [];
        $this->replies['You find what one online store'] = fn (array $in): array => ['proposals' => [
            ['group' => 'fabric', 'label_he' => 'פשתן מכובס', 'label_en' => 'Washed linen', 'words' => [], 'why' => 'what it is', 'items' => [collect($in['items'])->firstWhere('title', 'חולצת פשתן לבנה')['id'] ?? 'x']],
            ['group' => 'genre', 'label_he' => 'אימה', 'label_en' => 'Horror', 'words' => [], 'why' => 'dup', 'items' => ['i1']],
            ['group' => 'nope', 'label_he' => 'משהו', 'label_en' => 'x', 'words' => [], 'why' => '', 'items' => ['i1']],
        ]];

        $run = app(ScanBankGaps::class)->handle($this->shop->id);

        $titles = array_column($this->calls[0]['user']['items'], 'title');
        $this->assertContains('חולצת פשתן לבנה', $titles, 'no inferred tag central to it');
        $this->assertNotContains('חטוף - אלי שרעבי', $titles, 'its topic is central to it');
        $this->assertSame(['proposed' => 1, 'known' => 1], array_intersect_key($run->output, ['proposed' => 1, 'known' => 1]));
        $this->assertSame('פשתן מכובס', app(TenantContext::class)->run($this->shop->id, fn () => TaxonomyProposal::query()->where('norm', Norm::label('פשתן מכובס'))->value('label_he')));
    }

    public function test_the_tagging_is_measured_against_cases_a_person_reviewed(): void
    {
        $this->bank();
        app(BuildTagBank::class)->handle($this->shop->id);
        $this->tagging();
        app(AssignTags::class)->handle($this->shop->id, posts: false);

        $eval = app(TenantContext::class)->run($this->shop->id, fn () => Evaluation::of($this->shop->id, ['cases' => [
            ['title' => 'חטוף - אלי שרעבי', 'expect' => ['חטופ|hostage'], 'reject' => ['מתח|thriller']],
            ['title' => 'דרקולה', 'expect' => ['אימה|horror'], 'reject' => ['מדע בדיוני|science fiction']],
            ['title' => 'חולצת פשתן לבנה', 'expect' => ['כותנה|cotton']],
        ]]));

        $this->assertSame(66.7, $eval['gold']['recall'], 'the shirt is linen, not cotton');
        $this->assertSame(100.0, $eval['gold']['negative_pass'], 'the thriller the checker took out is not counted against it');
        $this->assertSame(3, $eval['assigned']['products_tagged']);
        $this->assertEqualsCanonicalizing(['knowledge' => 1, 'catalog' => 2], $eval['assigned']['source']);
        $this->assertSame([], $eval['bank']['duplicates']);
        $this->assertGreaterThan(0, $eval['cost']['per_product_usd']);
    }

    private function bank(): void
    {
        $this->replies['You design the tag bank'] = fn (array $in): array => ['groups' => [
            ['key' => 'genre', 'label_he' => 'ז\'אנר', 'label_en' => 'Genre', 'kind' => 'inferred', 'multiple' => false, 'searchable' => true, 'filter' => true, 'recommend' => true, 'about' => '', 'scope' => ['ספרים', 'מדפים'], 'tags' => [
                ['key' => 'horror', 'label_he' => 'אימה', 'label_en' => 'Horror', 'words' => ['horror'], 'about' => ''],
                ['key' => 'horror_books', 'label_he' => 'האימה', 'label_en' => 'Horror books', 'words' => [], 'about' => ''],
                ['key' => 'scary', 'label_he' => 'מפחיד', 'label_en' => 'Scary', 'words' => [], 'about' => ''],
                ['key' => 'thriller', 'label_he' => 'מתח', 'label_en' => 'Thriller', 'words' => [], 'about' => ''],
                ['key' => 'misc', 'label_he' => 'שונות', 'label_en' => 'Misc', 'words' => [], 'about' => ''],
            ]],
            ['key' => 'topic', 'label_he' => 'נושא', 'label_en' => 'Topic', 'kind' => 'inferred', 'multiple' => true, 'searchable' => true, 'filter' => false, 'recommend' => true, 'about' => '', 'scope' => ['ספרים'], 'tags' => [
                ['key' => 'hostages', 'label_he' => 'חטופים', 'label_en' => 'Hostages', 'words' => ['שבי', 'חטוף'], 'about' => ''],
                ['key' => 'economics', 'label_he' => 'כלכלה', 'label_en' => 'Economics', 'words' => [], 'about' => ''],
            ]],
            ['key' => 'fabric', 'label_he' => 'בד', 'label_en' => 'Fabric', 'kind' => 'factual', 'multiple' => false, 'searchable' => true, 'filter' => true, 'recommend' => false, 'about' => '', 'scope' => ['חולצות'], 'tags' => [
                ['key' => 'linen', 'label_he' => 'פשתן', 'label_en' => 'Linen', 'words' => [], 'about' => ''],
                ['key' => 'cotton', 'label_he' => 'כותנה', 'label_en' => 'Cotton', 'words' => [], 'about' => ''],
            ]],
            ['key' => 'mood', 'label_he' => 'מצב רוח', 'label_en' => 'Mood', 'kind' => 'inferred', 'multiple' => true, 'searchable' => true, 'filter' => false, 'recommend' => true, 'about' => '', 'scope' => [], 'tags' => [
                ['key' => 'calm', 'label_he' => 'רגוע', 'label_en' => 'Calm', 'words' => [], 'about' => ''],
            ]],
        ]];
        $this->replies['You review the tag bank'] = fn (array $in): array => [
            'remove' => [['group' => 'genre', 'tag' => 'misc', 'why' => 'vague'], ['group' => 'nope', 'tag' => 'x', 'why' => '']],
            'merge' => [['group' => 'genre', 'tag' => 'scary', 'into' => 'horror', 'why' => 'same']],
            'fix_groups' => [['group' => 'genre', 'kind' => 'inferred', 'searchable' => true, 'filter' => false, 'recommend' => true, 'scope' => ['ספרים'], 'why' => 'few filters']],
            'add' => [['group' => 'genre', 'label_he' => 'פנטזיה', 'label_en' => 'Fantasy', 'words' => [], 'why' => 'common'], ['group' => 'genre', 'label_he' => 'אימה', 'label_en' => 'Horror', 'words' => [], 'why' => 'dup']],
        ];
    }

    private function tagging(): void
    {
        $this->replies['You tag one online store'] = function (array $in): array {
            $ref = fn (string $title): string => collect($in['items'])->firstWhere('title', $title)['id'] ?? collect($in['items'])->first(fn (array $i): bool => str_starts_with($i['title'], $title))['id'];
            $items = [];
            foreach ($in['items'] as $item) {
                $items[$item['id']] = ['id' => $item['id'], 'tags' => [], 'propose' => []];
            }
            if (collect($in['items'])->contains('title', 'חטוף - אלי שרעבי')) {
                $items[$ref('חטוף - אלי שרעבי')]['tags'] = [
                    // the passage a little reworded ("של" added): it stands in the text all the same
                    ['group' => 'topic', 'tag' => 'hostages', 'source' => 'catalog', 'evidence' => 'שרד אלי שרעבי בשבי של החמאס', 'confidence' => 0.95, 'weight' => 1],
                    ['group' => 'genre', 'tag' => 'thriller', 'source' => 'knowledge', 'evidence' => 'מותחן', 'confidence' => 0.8, 'weight' => 0.5],
                    ['group' => 'genre', 'tag' => 'cyberpunk', 'source' => 'knowledge', 'evidence' => '', 'confidence' => 0.9, 'weight' => 0.9],
                ];
                $items[$ref('חטוף - אלי שרעבי')]['propose'] = [['group' => 'topic', 'label_he' => 'שבעה באוקטובר', 'label_en' => 'October 7', 'why' => 'the day he was taken'], ['group' => 'topic', 'label_he' => 'החטופים', 'label_en' => 'Hostages', 'why' => 'dup']];
            }
            $dracula = collect($in['items'])->first(fn (array $i): bool => str_starts_with($i['title'], 'דרקולה'));
            if ($dracula !== null) {
                $items[$dracula['id']]['tags'] = [
                    ['group' => 'genre', 'tag' => 'horror', 'source' => 'knowledge', 'evidence' => 'רומן אימה גותי קלאסי', 'confidence' => 0.9, 'weight' => 0.9],
                    ['group' => 'genre', 'tag' => 'fantasy', 'source' => 'knowledge', 'evidence' => 'ערפדים', 'confidence' => 0.7, 'weight' => 0.4],
                ];
            }
            if (collect($in['items'])->contains('title', 'חולצת פשתן לבנה')) {
                $items[$ref('חולצת פשתן לבנה')]['tags'] = [
                    ['group' => 'fabric', 'tag' => 'linen', 'source' => 'catalog', 'evidence' => 'מבד פשתן', 'confidence' => 0.95, 'weight' => 1],
                    ['group' => 'fabric', 'tag' => 'cotton', 'source' => 'knowledge', 'evidence' => 'shirts are often cotton', 'confidence' => 0.9, 'weight' => 0.9],
                    // what a thing is made of is never read from its picture
                    ['group' => 'fabric', 'tag' => 'cotton', 'source' => 'picture', 'evidence' => 'בד כותנה', 'confidence' => 0.9, 'weight' => 0.9],
                    ['group' => 'genre', 'tag' => 'thriller', 'source' => 'catalog', 'evidence' => 'חולצה', 'confidence' => 0.9, 'weight' => 0.9],
                ];
            }

            return ['items' => array_values($items)];
        };
        $this->replies['You check the tags'] = function (array $in): array {
            $wrong = [];
            foreach ($in['items'] as $item) {
                foreach ($item['tags'] as $tag) {
                    if (str_contains($tag['tag'], 'מתח')) {
                        $wrong[] = ['id' => $item['id'], 'ref' => $tag['ref'], 'why' => 'ספר עיון, לא מותחן'];
                    }
                }
            }

            return ['wrong' => $wrong];
        };
    }

    /** @return array<string, array<string, string>> external id => group:tag => status */
    private function tagsOf(): array
    {
        return app(TenantContext::class)->run($this->shop->id, function (): array {
            $ids = CatalogProduct::query()->pluck('external_id', 'id');
            $out = [];
            foreach (TaxonomyAssignment::query()->with('tag.group')->orderBy('id')->get() as $a) {
                $out[$ids[$a->subject_id]][$a->tag->group->key.':'.$a->tag->key] = $a->status;
            }
            ksort($out);

            return $out;
        });
    }

    /**
     * @param  list<string>  $categories
     * @param  list<array{name: string, values: list<string>}>  $attributes
     */
    private function product(string $id, string $title, string $description, array $categories, array $attributes = []): void
    {
        app(TenantContext::class)->run($this->shop->id, function () use ($id, $title, $description, $categories, $attributes): void {
            $product = CatalogProduct::query()->create([
                'shop_id' => $this->shop->id, 'external_id' => $id, 'type' => 'simple', 'status' => 'publish', 'title' => $title,
                'price' => '89.00', 'currency' => 'ILS', 'in_stock' => true, 'purchasable' => true, 'hash' => md5($title.$description),
                'payload' => ['title' => $title, 'short_description' => '', 'description' => '<p>'.$description.'</p>', 'attributes' => $attributes],
            ]);
            foreach ($categories as $name) {
                $this->categories[$name] ??= CatalogCategory::query()->create([
                    'shop_id' => $this->shop->id, 'external_id' => (string) (count($this->categories) + 1), 'name' => $name, 'path' => [$name], 'depth' => 0,
                    'product_count' => 1, 'url' => null, 'hash' => md5($name),
                ]);
            }
            $product->categories()->attach(array_map(fn (string $name): string => $this->categories[$name]->id, $categories));
        });
    }
}
