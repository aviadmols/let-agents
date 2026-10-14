<?php

namespace App\Modules\TryOn\Actions;

use App\Core\Facades\Features;
use App\Core\Facades\Settings;
use App\Core\Tenancy\TenantContext;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Enrichment\Enums\FactKind;
use App\Modules\Enrichment\Enums\FactStatus;
use App\Modules\Enrichment\Models\EnrichmentFact;
use App\Modules\TryOn\Jobs\RunTryOnJob;
use App\Modules\TryOn\Models\TryOnJob;
use App\Modules\TryOn\Models\TryOnPhoto;
use App\Modules\TryOn\Support\Files;
use App\Modules\TryOn\Support\TryOnClasses;

/**
 * A shopper asks to see a product on their photo. Code checks everything it can before anything is
 * spent: the shop turned Try-On on, the product is one that can be tried (the catalog scan marked it),
 * the photo was accepted and shows what this product needs, the shopper's daily tries and the shop's
 * month are not used up. Whatever the photo leaves open (which finger, which hand, where in the room)
 * is asked first: without the answers, nothing is made. The same try again is the one already made.
 * Inside the shop's tenant.
 */
final class StartTryOn
{
    // checker v2: a fault only when a shopper would see it at a glance; when unsure, none.
    // writer v2: "Edit the first image", nothing added; checker v3: gentle on the product, strict that it is the shopper's own photo.
    // template v2 (jewelry): worn, not pasted on; checker v4: judges that too (realistic).
    // writer v4, template v3, checker v5: the real size against the body (the product picture is a
    // close-up), the light of the spot it sits in; the checker judges the size again (proportions_ok).
    public const PROMPT_VERSIONS = ['writer' => 4, 'checker' => 5, 'template' => 3];

    public function __construct(private readonly TenantContext $tenant) {}

    /**
     * @param  array<string, mixed>  $answers
     * @param  bool  $test  the store team's test, with its preview key: the flag is not asked
     * @param  string|null  $testClass  for the test, the class to use on a product the scan did not mark
     *                                  (none: the class its name says, TryOnClasses::guess)
     * @param  array{title: string, image: string}|null  $page  for the test, the page's own product when the
     *                                                          catalog has not read it yet (checked by the controller)
     * @return array{job: TryOnJob|null, problem: string|null, questions: list<array<string, mixed>>}
     */
    public function handle(string $visitorHash, string $photoId, string $productExternalId, array $answers = [], bool $test = false, ?string $testClass = null, ?array $page = null): array
    {
        $shopId = $this->tenant->require();
        $none = ['job' => null, 'problem' => null, 'questions' => []];

        // The store team's test runs while it is off for shoppers (TryOnController checks the key).
        if (Features::enabled('try_on.paused') || (! $test && ! Features::enabled('try_on.enabled', $shopId))) {
            return ['problem' => 'off'] + $none;
        }

        $photo = TryOnPhoto::query()->whereKey($photoId)->where('visitor_hash', $visitorHash)->first();

        if ($photo === null || $photo->status !== TryOnPhoto::OK || $photo->path === null) {
            return ['problem' => 'unknown_photo'] + $none;
        }

        $product = CatalogProduct::query()->whereNull('removed_at')->where('external_id', $productExternalId)->first();
        // Only the team's test reaches a product the catalog has not read, through the page's own name and picture.
        $page = $product === null && $test && $page !== null ? ['external_id' => $productExternalId] + $page : null;
        $class = self::testedClass($product, $test, $testClass, $page['title'] ?? null);

        if (($product === null && $page === null) || $class === null) {
            return ['problem' => 'not_tryable'] + $none;
        }

        if (! TryOnClasses::fits($class, (array) $photo->gate)) {
            return ['problem' => 'wrong_subject'] + $none;
        }

        $questions = TryOnClasses::questions($class, (array) $photo->gate);
        $answered = TryOnClasses::answered($questions, $answers);

        if ($answered === null) {
            return ['questions' => $questions] + $none;
        }

        $group = (string) TryOnClasses::group($class);
        $fingerprint = hash('sha256', implode('|', [$photo->photo_hash, $product?->id ?? 'page:'.$productExternalId, (string) ($product?->image_url ?? $page['image']), $class, json_encode($answered), (string) Settings::get('try_on.model_'.$group), json_encode(self::PROMPT_VERSIONS)]));
        $same = TryOnJob::query()->where('visitor_hash', $visitorHash)->where('fingerprint', $fingerprint)->whereIn('status', [TryOnJob::QUEUED, TryOnJob::WORKING, TryOnJob::READY])->latest()->first();

        // The same try again is the one already made or on its way; one stuck past its minutes is ended
        // instead, and a new one starts, so asking again never waits on it forever.
        if ($same !== null && ! $same->endIfStuck()) {
            return ['job' => $same] + $none;
        }

        // The team's test is not a shopper's day: no daily count. The shop's month still is (below).
        if (! $test && TryOnJob::query()->where('visitor_hash', $visitorHash)->where('created_at', '>=', now()->startOfDay())->count() >= (int) Settings::get('try_on.jobs_per_visitor_per_day', $shopId)) {
            return ['problem' => 'daily_limit'] + $none;
        }

        $spent = (float) TryOnJob::query()->where('created_at', '>=', now()->startOfMonth())->sum('cost_usd') + (float) TryOnPhoto::query()->where('created_at', '>=', now()->startOfMonth())->sum('cost_usd');

        if ($spent >= (float) Settings::get('try_on.monthly_cap_usd', $shopId)) {
            return ['problem' => 'busy'] + $none;
        }

        $job = TryOnJob::query()->create([
            'shop_id' => $shopId, 'photo_id' => $photo->id, 'product_id' => $product?->id, 'page_product' => $page, 'visitor_hash' => $visitorHash,
            'tryon_class' => $class, 'group' => $group, 'answers' => $answered === [] ? null : $answered, 'fingerprint' => $fingerprint,
            'status' => TryOnJob::QUEUED,
        ]);

        // On the worker: it finds the photo on the bucket, or without one in the database (Files).
        RunTryOnJob::dispatch($shopId, $job->id);

        return ['job' => $job->refresh()] + $none;
    }

    /**
     * The class a try uses: the scan's for shoppers; in the team's test, the scan's, else the class the
     * team named, else the class the product's name says. Null when none.
     */
    public static function testedClass(?CatalogProduct $product, bool $test, ?string $testClass, ?string $pageTitle = null): ?string
    {
        $marked = $product === null ? null : self::classOf($product->id);

        if ($marked !== null || ! $test) {
            return $marked;
        }

        if ($testClass !== null && TryOnClasses::group($testClass) !== null) {
            return $testClass;
        }

        return TryOnClasses::guess((string) ($product?->title ?? $pageTitle ?? ''));
    }

    /** The product's try-on class, as the catalog scan marked it, or null when it is not one. */
    public static function classOf(string $productId): ?string
    {
        $class = EnrichmentFact::query()->where('product_id', $productId)->where('kind', FactKind::TryOn)->where('key', 'class')
            ->where('status', FactStatus::Approved)->latest('id')->value('value_text');

        return is_string($class) && TryOnClasses::group($class) !== null ? $class : null;
    }
}
