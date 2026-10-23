<?php

namespace App\Modules\Enrichment\Filament\Merchant\Pages;

use App\Core\Localization\Money;
use App\Modules\Admin\Contracts\ReadsContentAgain;
use App\Modules\Catalog\Models\CatalogContent;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Catalog\Models\CatalogProductChoice;
use App\Modules\Enrichment\Actions\ApplyTeamValue;
use App\Modules\Enrichment\Contracts\ProductMeaning;
use App\Modules\Enrichment\Contracts\RereadsPages;
use App\Modules\Enrichment\Enums\FactKind;
use App\Modules\Enrichment\Enums\FactOrigin;
use App\Modules\Enrichment\Enums\FactStatus;
use App\Modules\Enrichment\Models\EnrichmentContentProduct;
use App\Modules\Enrichment\Models\EnrichmentFact;
use App\Modules\Enrichment\Scanning\RequiredGaps;
use App\Modules\Retrieval\Contracts\RefreshesVectors;
use App\Modules\Retrieval\Enums\ChunkSource;
use App\Modules\Retrieval\Models\RetrievalChunk;
use App\Modules\Retrieval\Models\RetrievalImage;
use App\Modules\Runs\Contracts\ScansShops;
use App\Modules\Runs\Enums\RunStatus;
use App\Modules\Runs\Models\Run;
use App\Modules\Tenancy\Models\Shop;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Livewire\Attributes\Url;
use UnitEnum;

/**
 * The scan summary, as the designer's panel lays it out: how Lets understood each product, and what
 * was read from each page and post, all fixable from here. Products: the numbers, a list (search,
 * missing details, picture not read, edited by hand) and the chosen product: its tags with where
 * each came from, the spec that was built with what is missing, and what the picture showed.
 * Pages and posts: what was read from each and the products it names. Code only; edits are the team's.
 */
final class ScanSummary extends Page
{
    private const PAGE = 40;

    /** Variants listed for the open product. */
    private const VARIANTS = 50;

    /** Fact kinds shown as tags; the spec shows choices, numbers and yes/no details. */
    public const TAG_KINDS = [FactKind::Type, FactKind::Category, FactKind::Brand, FactKind::Tag, FactKind::Use];

    private const SPEC_KINDS = [FactKind::Spec, FactKind::Choice, FactKind::Flag];

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentMagnifyingGlass;

    protected static string|UnitEnum|null $navigationGroup = 'scan';

    protected static ?int $navigationSort = 5;

    protected static ?string $slug = 'scan-summary';

    protected string $view = 'enrichment::merchant.scan-summary';

    /** products or pages */
    #[Url]
    public string $tab = 'products';

    #[Url(as: 'find')]
    public string $search = '';

    /** all, missing, image, manual (products); all, pages, posts, unread (pages) */
    #[Url]
    public string $filter = 'all';

    /** The product or page open beside the list. */
    #[Url]
    public ?string $open = null;

    public int $shown = self::PAGE;

    public string $newTag = '';

    /** A detail being filled or changed: its attribute key, and the value. */
    public ?string $editing = null;

    public string $editValue = '';

    public static function getNavigationLabel(): string
    {
        return __('enrichment::scan.title');
    }

    public function getTitle(): string
    {
        return __('enrichment::scan.title');
    }

    public function getSubheading(): ?string
    {
        return __('enrichment::scan.subheading');
    }

    public function getHeader(): ?View
    {
        return view('enrichment::merchant.scan-head', ['title' => $this->getTitle(), 'subheading' => $this->getSubheading()]);
    }

    public function show(string $tab): void
    {
        $this->tab = $tab === 'pages' ? 'pages' : 'products';
        $this->filter = 'all';
        $this->search = '';
        $this->open = null;
        $this->shown = self::PAGE;
    }

    public function pick(string $filter): void
    {
        $this->filter = $filter;
        $this->shown = self::PAGE;
    }

    public function select(string $id): void
    {
        $this->open = $id;
        $this->editing = null;
        $this->newTag = '';
    }

    public function more(): void
    {
        $this->shown += self::PAGE;
    }

    public function updatedSearch(): void
    {
        $this->shown = self::PAGE;
    }

    /** "Last scan 12 hours ago": the store's last successful reading. */
    public function lastScan(): ?string
    {
        $at = Run::query()->forShop($this->shop()->id)->where('action', 'catalog.sync')->where('status', RunStatus::Succeeded)->latest('started_at')->value('finished_at');

        return $at === null ? null : Carbon::parse($at)->locale(app()->getLocale())->diffForHumans();
    }

    /** @return array{products: int, total: int, images: int, waiting: int, unreadable: int, tags: int, per: float, missing: int, pages: int, posts: int} */
    public function numbers(): array
    {
        return $this->numbersCache ??= $this->count();
    }

    /** @var array<string, int|float>|null */
    private ?array $numbersCache = null;

    /** @return array{products: int, total: int, images: int, waiting: int, unreadable: int, tags: int, per: float, missing: int, pages: int, posts: int} */
    private function count(): array
    {
        $total = CatalogProduct::query()->active()->count();
        $read = EnrichmentFact::query()->current()->whereNotNull('product_id')->distinct()->count('product_id');
        $images = RetrievalImage::query();
        $tags = EnrichmentFact::query()->current()->where('status', FactStatus::Approved)->whereNotNull('product_id')->whereIn('kind', self::TAG_KINDS)->count();

        return [
            'products' => min($read, $total),
            'total' => $total,
            'images' => (clone $images)->whereNotNull('caption')->count(),
            'waiting' => (clone $images)->whereNull('captioned_at')->whereNull('error')->count(),
            'unreadable' => (clone $images)->where(fn (Builder $q) => $q->whereNotNull('error')->orWhere(fn (Builder $q) => $q->whereNotNull('captioned_at')->whereNull('caption')))->count(),
            'tags' => $tags,
            'per' => $read > 0 ? round($tags / $read, 1) : 0.0,
            'missing' => count($this->gaps()),
            'pages' => CatalogContent::query()->active()->where('type', CatalogContent::PAGE)->count(),
            'posts' => CatalogContent::query()->active()->where('type', CatalogContent::POST)->count(),
        ];
    }

    /** @return array{rows: list<array<string, mixed>>, more: bool} the products of the list, as the design shows them */
    public function products(): array
    {
        $query = CatalogProduct::query()->active()->orderBy('title');

        if (($words = trim($this->search)) !== '') {
            $query->where(fn (Builder $q) => $q->where('title', 'like', '%'.$words.'%')->orWhere('sku', 'like', '%'.$words.'%')
                ->orWhereIn('id', EnrichmentFact::query()->current()->whereNotNull('product_id')->where('value_text', 'like', '%'.$words.'%')->select('product_id')));
        }

        match ($this->filter) {
            'missing' => $query->whereIn('id', array_keys($this->gaps()) ?: ['-']),
            // The pictures that could not be read: none loaded, or read and nothing could be said of them.
            'image' => $query->whereIn('id', RetrievalImage::query()->where(fn (Builder $q) => $q->whereNotNull('error')->orWhere(fn (Builder $q) => $q->whereNotNull('captioned_at')->whereNull('caption')))->select('product_id')),
            'manual' => $query->whereIn('id', EnrichmentFact::query()->where('origin', FactOrigin::Person)->whereNotNull('product_id')->select('product_id')),
            default => null,
        };

        $products = $query->limit($this->shown + 1)->get(['id', 'title', 'image_url', 'sku']);
        $ids = $products->pluck('id')->all();
        $tags = EnrichmentFact::query()->current()->where('status', FactStatus::Approved)->whereIn('product_id', $ids)->whereIn('kind', self::TAG_KINDS)
            ->selectRaw('product_id, count(*) as n')->groupBy('product_id')->pluck('n', 'product_id');
        $manual = EnrichmentFact::query()->whereIn('product_id', $ids)->where('origin', FactOrigin::Person)->distinct()->pluck('product_id')->flip();
        $images = RetrievalImage::query()->whereIn('product_id', $ids)->get(['product_id', 'caption', 'error'])->keyBy('product_id');
        $gaps = $this->gaps();

        $rows = $products->take($this->shown)->map(function (CatalogProduct $p) use ($tags, $manual, $images, $gaps): array {
            $image = $images[$p->id] ?? null;
            $imageRead = $image !== null && $image->caption !== null;
            $missing = $gaps[$p->id] ?? [];

            return [
                'id' => (string) $p->id,
                'title' => (string) $p->title,
                'thumb' => $p->image_url,
                'flag' => $image !== null && ! $imageRead ? 'bad' : ($missing !== [] ? 'warn' : 'good'),
                'line' => implode(' · ', array_filter([
                    trans_choice('enrichment::scan.tags_n', (int) ($tags[$p->id] ?? 0), ['n' => (int) ($tags[$p->id] ?? 0)]),
                    $missing !== [] ? __('enrichment::scan.missing_one', ['what' => $missing[0]['label']]) : null,
                    $missing === [] && isset($manual[$p->id]) ? __('enrichment::scan.manual') : null,
                    $missing === [] && ! isset($manual[$p->id]) && $image !== null ? __($imageRead ? 'enrichment::scan.image_read_short' : 'enrichment::scan.image_unread') : null,
                ])),
            ];
        })->values()->all();

        return ['rows' => $rows, 'more' => $products->count() > $this->shown];
    }

    /**
     * The product open beside the list: its tags with their source, its spec with what is missing,
     * and its picture's reading.
     *
     * @return array<string, mixed>|null
     */
    public function product(): ?array
    {
        $product = $this->open === null ? null : CatalogProduct::query()->active()->find($this->open);
        $product ??= $this->tab === 'products' ? CatalogProduct::query()->active()->orderBy('title')->first() : null;

        if ($product === null) {
            return null;
        }

        $facts = EnrichmentFact::query()->current()->where('product_id', $product->id)->whereIn('status', [FactStatus::Approved, FactStatus::NotApplicable])
            ->with('vocabulary')->orderBy('kind')->orderBy('id')->get();
        $spec = [];

        foreach ($facts->whereIn('kind', self::SPEC_KINDS) as $fact) {
            $label = trim($fact->keyLabel());
            $spec[$fact->key] ??= ['key' => $fact->key, 'label' => $label, 'values' => [], 'vocabulary' => $fact->vocabulary_id, 'manual' => false, 'missing' => false];
            $spec[$fact->key]['values'][] = $fact->status === FactStatus::NotApplicable ? __('enrichment::scan.not_applicable') : trim($fact->valueLabel());
            $spec[$fact->key]['manual'] = $spec[$fact->key]['manual'] || $fact->origin === FactOrigin::Person;
        }

        foreach ($this->gaps()[$product->id] ?? [] as $gap) {
            $spec[$gap['key']] ??= ['key' => $gap['key'], 'label' => $gap['label'], 'values' => [], 'vocabulary' => $gap['vocabulary'], 'manual' => false, 'missing' => true];
        }

        $payload = (array) $product->payload;
        $image = RetrievalImage::query()->where('product_id', $product->id)->first();

        return [
            'id' => (string) $product->id,
            'external_id' => (string) $product->external_id,
            'title' => (string) $product->title,
            'url' => $product->url,
            'thumb' => $product->image_url,
            'sku' => $product->sku,
            'price' => $product->price,
            'in_stock' => (bool) $product->in_stock,
            'variants' => (int) $product->variations_count ?: count((array) ($payload['variations'] ?? [])),
            'tags' => $facts->whereIn('kind', self::TAG_KINDS)->map(fn (EnrichmentFact $f): array => [
                'id' => (string) $f->id,
                'label' => trim($f->valueLabel()),
                'source' => __('enrichment::scan.sources.'.self::source($f)),
            ])->filter(fn (array $t): bool => $t['label'] !== '')->unique('label')->values()->all(),
            'spec' => array_values(array_map(fn (array $s): array => [...$s, 'values' => implode(', ', array_unique(array_filter($s['values'])))], $spec)),
            'image' => $image === null ? null : [
                'url' => $image->image_url,
                'caption' => $image->caption,
                'words' => array_values(array_slice((array) $image->caption_words, 0, 12)),
                'read_at' => $image->captioned_at?->timezone('Asia/Jerusalem')->format('j.n'),
                'state' => $image->error !== null ? 'error' : ($image->caption !== null ? 'read' : ($image->captioned_at !== null ? 'unread' : 'waiting')),
            ],
            'summary' => self::summary((string) $product->id),
            'measurements' => $product->measurements(),
            'options' => array_values(array_map(fn (array $a): array => ['name' => (string) $a['name'], 'values' => implode(', ', $a['values'])], $product->storeAttributes())),
            'variant_rows' => self::variants($product),
            // The size its own page says (a ring's "מידה נוכחית: 54"), read from the page; null until it is.
            'page_size' => (fn (?CatalogProductChoice $c): ?array => $c?->size === null ? null : ['size' => rtrim(rtrim(number_format((float) $c->size, 2, '.', ''), '0'), '.'), 'label' => (string) ($c->size_label ?: '')])(
                CatalogProductChoice::query()->where('product_id', $product->id)->first(['size', 'size_label'])),
            'store_text' => mb_strlen(trim($product->shortDescription().' '.$product->description())),
            'indexed' => self::indexed(ChunkSource::Product, (string) $product->id),
            'meaning' => self::meaning('product', (string) $product->id),
        ];
    }

    /**
     * The team's word on a tag by meaning: kept (one the check took out comes back) or taken out. It holds when the
     * product is tagged again; the search reads it in the next index.
     */
    public function decideMeaning(string $assignmentId, bool $keep): void
    {
        if (! app()->bound(ProductMeaning::class)) {
            return;
        }

        app(ProductMeaning::class)->decide($assignmentId, $keep);
        Notification::make()->success()->title(__($keep ? 'enrichment::scan.meaning_kept' : 'enrichment::scan.meaning_dropped'))->send();
    }

    /** A tag the team does not want: set aside, and not read back into the product. */
    public function removeTag(string $factId): void
    {
        EnrichmentFact::query()->whereKey($factId)->whereNotNull('product_id')->update([
            'status' => FactStatus::Rejected, 'status_reason' => 'team', 'decided_by' => auth()->id(), 'decided_at' => now(), 'updated_at' => now(),
        ]);
        Notification::make()->success()->title(__('enrichment::scan.tag_removed'))->send();
    }

    /** A tag the team adds to the open product. */
    public function addTag(): void
    {
        $label = trim(mb_substr($this->newTag, 0, 80));
        $product = $this->open === null ? $this->product()['id'] ?? null : $this->open;

        if ($label === '' || $product === null) {
            return;
        }

        EnrichmentFact::query()->create([
            'product_id' => $product, 'kind' => FactKind::Tag, 'key' => 'team', 'value_text' => $label, 'quote' => $label,
            'origin' => FactOrigin::Person, 'status' => FactStatus::Approved, 'input_hash' => hash('sha256', 'team|'.$product.'|'.$label),
            'model' => 'team', 'evidence' => ['source' => 'team'], 'decided_by' => auth()->id(), 'decided_at' => now(),
        ]);
        $this->newTag = '';
        Notification::make()->success()->title(__('enrichment::scan.tag_added', ['tag' => $label]))->send();
    }

    public function edit(string $key): void
    {
        $this->editing = $key;
        $this->editValue = '';
    }

    /** A detail of the spec, filled or changed by the team: checked against the vocabulary first. */
    public function saveDetail(string $vocabularyId): void
    {
        $product = $this->product();

        if ($product === null || $this->editing === null || trim($this->editValue) === '') {
            return;
        }

        $saved = app(ApplyTeamValue::class)->set($product['id'], $vocabularyId, $this->editing, trim($this->editValue));

        if (! $saved['ok']) {
            Notification::make()->warning()->title(__('enrichment::missing.problems.'.$saved['problem']))->send();

            return;
        }

        Cache::forget($this->gapsKey());
        $this->editing = null;
        $this->editValue = '';
        Notification::make()->success()->title(__('enrichment::scan.detail_saved'))->send();
    }

    /** "Scan changes and new products": the night's reading, now; once in a while. */
    public function scanChanges(): void
    {
        $scans = app(ScansShops::class);

        if ($scans->scanChanges($this->shop())) {
            Notification::make()->success()->title(__('enrichment::scan.scan_started'))->body(__('enrichment::scan.scan_started_body'))->send();
        } else {
            Notification::make()->warning()->title(__('enrichment::scan.scan_too_soon', ['hours' => $scans->againAfterHours()]))->send();
        }
    }

    public function canScanChanges(): bool
    {
        return app(ScansShops::class)->canScan($this->shop());
    }

    /**
     * The open product or page scanned again: the code readers run on it now (no model), and what
     * needs a model (its picture, what a post teaches) is read first at the next reading.
     */
    public function rescan(): void
    {
        $item = $this->tab === 'products' ? $this->product() : $this->openItem();

        if ($item === null) {
            return;
        }

        $done = app(RereadsPages::class)->reread((string) $this->shop()->id, $this->tab === 'products' ? 'product' : 'content', $item['external_id']);

        if ($this->tab === 'products') {
            RetrievalImage::query()->where('product_id', $item['id'])->update(['caption_key' => null]);
        } elseif (app()->bound(ReadsContentAgain::class)) {
            app(ReadsContentAgain::class)->readAgain((string) $this->shop()->id, $item['id']);
        }

        // What it was read into reaches the search in minutes, not tonight.
        app(RefreshesVectors::class)->soon((string) $this->shop()->id);
        Cache::forget($this->gapsKey());
        Notification::make()->success()->title(__('enrichment::scan.rescanned', ['n' => $done['written']]))->body(__('enrichment::scan.rescanned_body'))->send();
    }

    /** The picture read again at the next scan; "the description is wrong" also takes it off now. */
    public function readImageAgain(bool $wrong = false): void
    {
        $product = $this->product();

        if ($product === null) {
            return;
        }

        RetrievalImage::query()->where('product_id', $product['id'])->update(array_filter([
            'caption_key' => null,
            'caption' => $wrong ? null : false,
            'caption_words' => $wrong ? null : false,
        ], fn ($v): bool => $v !== false));
        Notification::make()->success()->title(__($wrong ? 'enrichment::scan.image_wrong_done' : 'enrichment::scan.image_again_done'))->send();
    }

    /** @return array{rows: list<array<string, mixed>>, more: bool} pages and posts, as the design lists them */
    public function items(): array
    {
        $query = CatalogContent::query()->active()->whereIn('type', [CatalogContent::PAGE, CatalogContent::POST])->orderBy('type')->orderBy('title');

        if (($words = trim($this->search)) !== '') {
            $query->where('title', 'like', '%'.$words.'%');
        }

        $read = $this->postReadings();

        match ($this->filter) {
            'pages' => $query->where('type', CatalogContent::PAGE),
            'posts' => $query->where('type', CatalogContent::POST),
            'unread' => $query->whereNotIn('id', array_keys($read) ?: ['-']),
            default => null,
        };

        $contents = $query->limit($this->shown + 1)->get(['id', 'type', 'title', 'image_url']);
        $products = EnrichmentContentProduct::query()->whereIn('content_id', $contents->pluck('id'))->selectRaw('content_id, count(*) as n')->groupBy('content_id')->pluck('n', 'content_id');

        return [
            'rows' => $contents->take($this->shown)->map(fn (CatalogContent $c): array => [
                'id' => (string) $c->id,
                'title' => (string) $c->title,
                'thumb' => $c->image_url ?? null,
                'flag' => isset($read[(string) $c->id]) ? 'good' : 'warn',
                'line' => implode(' · ', array_filter([
                    __('enrichment::scan.kinds.'.$c->type),
                    isset($read[(string) $c->id]) ? trans_choice('enrichment::scan.facts_n', count($read[(string) $c->id]['facts'] ?? []), ['n' => count($read[(string) $c->id]['facts'] ?? [])]) : __('enrichment::scan.not_read'),
                    ($products[$c->id] ?? 0) > 0 ? trans_choice('enrichment::scan.products_n', (int) $products[$c->id], ['n' => (int) $products[$c->id]]) : null,
                ])),
            ])->values()->all(),
            'more' => $contents->count() > $this->shown,
        ];
    }

    /** @return array<string, mixed>|null the page or post open beside the list */
    public function openItem(): ?array
    {
        $content = $this->open === null ? null : CatalogContent::query()->active()->whereIn('type', [CatalogContent::PAGE, CatalogContent::POST])->find($this->open);
        $content ??= CatalogContent::query()->active()->whereIn('type', [CatalogContent::PAGE, CatalogContent::POST])->orderBy('type')->orderBy('title')->first();

        if ($content === null) {
            return null;
        }

        $read = $this->postReadings()[(string) $content->id] ?? null;
        $linked = EnrichmentContentProduct::query()->where('content_id', $content->id)->orderBy('rank')->with('product:id,title,image_url,external_id')->limit(20)->get();
        $named = array_values(array_unique(array_map('strval', (array) ($content->product_external_ids ?? []))));
        $known = CatalogProduct::query()->whereIn('external_id', $named)->pluck('external_id')->map(fn ($id): string => (string) $id)->all();

        return [
            'id' => (string) $content->id,
            'external_id' => (string) $content->external_id,
            'type' => (string) $content->type,
            'title' => (string) $content->title,
            'url' => $content->url,
            'thumb' => $content->image_url ?? null,
            'published' => $content->source_updated_at?->timezone('Asia/Jerusalem')->format('j.n'),
            'read_at' => isset($read['read_at']) ? Carbon::parse($read['read_at'])->timezone('Asia/Jerusalem')->format('j.n H:i') : null,
            'words' => count(preg_split('/\s+/u', trim(strip_tags((string) $content->body))) ?: []),
            'facts' => array_values((array) ($read['facts'] ?? [])),
            'products' => $linked->filter(fn (EnrichmentContentProduct $l): bool => $l->product !== null)->map(fn (EnrichmentContentProduct $l): array => [
                'title' => (string) $l->product->title, 'thumb' => $l->product->image_url, 'mentioned' => (bool) ($l->reasons['mentioned'] ?? false),
            ])->values()->all(),
            'unfound' => count(array_diff($named, $known)),
            'indexed' => self::indexed(ChunkSource::Content, (string) $content->id),
            'meaning' => self::meaning('content', (string) $content->id),
        ];
    }

    /**
     * What it was tagged with by meaning, each tag with what it rests on; empty where nothing was.
     *
     * @return list<array{group: string, label: string, weight: float, source: string, evidence: string|null, explicit: bool, status: string, note: string|null}>
     */
    private static function meaning(string $type, string $id): array
    {
        return app()->bound(ProductMeaning::class) ? app(ProductMeaning::class)->of($type, $id, app()->getLocale()) : [];
    }

    /**
     * The short description written for search, and whether it is in the search yet (approved) or
     * waiting for its check. Null when none was written.
     *
     * @return array{text: string, state: string}|null
     */
    private static function summary(string $productId): ?array
    {
        $fact = EnrichmentFact::query()->current()->where('product_id', $productId)->where('kind', FactKind::Summary)
            ->whereNotIn('status', [FactStatus::Rejected])->latest('id')->first(['value_text', 'status']);

        return $fact === null ? null : [
            'text' => (string) $fact->value_text,
            'state' => $fact->status === FactStatus::Approved ? 'in_search' : 'checking',
        ];
    }

    /**
     * The product's variants as the search reads them: what each is (its size, colour and other
     * options), its code, price and stock. The first VARIANTS; the count says how many there are.
     *
     * @return array{rows: list<array{label: string, size: string|null, colour: string|null, sku: string|null, price: string|null, in_stock: bool}>, total: int}
     */
    private static function variants(CatalogProduct $product): array
    {
        $variants = $product->variants()->limit(self::VARIANTS)->get();

        return [
            'rows' => $variants->map(fn ($v): array => [
                'label' => trim((string) $v->title) !== '' ? (string) $v->title : implode(' · ', array_filter([$v->color_label, $v->size_label, ...array_values((array) $v->options)])),
                'size' => $v->size_label,
                'colour' => $v->color_label,
                'sku' => $v->sku,
                'price' => $v->price,
                'in_stock' => (bool) $v->in_stock,
            ])->all(),
            'total' => $variants->count() < self::VARIANTS ? $variants->count() : $product->variants()->count(),
        ];
    }

    /**
     * What the search knows of a product or post: the very text its meaning vectors were made from,
     * piece by piece, and when. No model and no cost, only the text and how much of it there is.
     *
     * @return array{pieces: list<string>, chars: int, at: string|null, waiting: int}
     */
    private static function indexed(ChunkSource $source, string $id): array
    {
        $chunks = RetrievalChunk::query()->where('source', $source->value)->where('source_id', $id)
            ->orderBy('position')->get(['text', 'embedded_at']);
        $at = $chunks->max('embedded_at');

        return [
            'pieces' => $chunks->pluck('text')->map(fn ($t): string => (string) $t)->all(),
            'chars' => (int) $chunks->sum(fn (RetrievalChunk $c): int => mb_strlen((string) $c->text)),
            'at' => $at === null ? null : Carbon::parse($at)->timezone('Asia/Jerusalem')->format('j.n'),
            'waiting' => $chunks->whereNull('embedded_at')->count(),
        ];
    }

    /**
     * Each product's missing required details, from the shop's vocabularies; kept a few minutes.
     *
     * @return array<string, list<array{key: string, label: string, vocabulary: string}>> by product id
     */
    private function gaps(): array
    {
        return Cache::remember($this->gapsKey(), 600, function (): array {
            $by = [];

            foreach (RequiredGaps::of(locale: app()->getLocale()) as $group) {
                foreach ($group['missing'] as $product) {
                    $by[(string) $product['id']][] = ['key' => (string) $group['attribute'], 'label' => (string) $group['attribute_label'], 'vocabulary' => (string) $group['vocabulary_id']];
                }
            }

            return $by;
        });
    }

    private function gapsKey(): string
    {
        return 'enrichment:scan-gaps:'.$this->shop()->id.':'.app()->getLocale();
    }

    /** @return array<string, array<string, mixed>> what the reader learned from each page and post, by content id */
    private function postReadings(): array
    {
        $row = CatalogContent::query()->where('type', CatalogContent::KNOWLEDGE)->where('external_id', CatalogContent::OWN_PREFIX.'knowledge-posts')->first(['terms']);

        return (array) ($row?->terms['read'] ?? []);
    }

    private static function source(EnrichmentFact $fact): string
    {
        if ($fact->origin === FactOrigin::Person) {
            return 'team';
        }

        $source = (string) ($fact->evidence['source'] ?? '');

        return match (true) {
            $fact->kind === FactKind::Category || $source === 'category' => 'category',
            $source === 'variant' => 'variant',
            in_array($source, ['field', 'attribute', 'attributes'], true) => 'field',
            in_array($source, ['title', 'description', 'image', 'implied'], true) => $source,
            default => 'reading',
        };
    }

    /** A product's or an option's price, in the shop's own currency. */
    public function price(mixed $amount): ?string
    {
        return $amount === null || $amount === '' ? null : Money::price((float) $amount, (string) $this->shop()->currency);
    }

    private function shop(): Shop
    {
        /** @var Shop $shop */
        $shop = Filament::getTenant();

        return $shop;
    }
}
