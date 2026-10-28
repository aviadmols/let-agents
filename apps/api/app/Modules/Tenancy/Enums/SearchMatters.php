<?php

namespace App\Modules\Tenancy\Enums;

use App\Core\Facades\Features;
use App\Core\Facades\Settings;
use App\Core\Features\FeatureRegistry;
use App\Core\Settings\SettingRegistry;
use App\Modules\Tenancy\Models\Shop;

/**
 * What matters in a shop's search, as a demo asks it (BuildDemoStore): the look (each product's picture is read, for
 * search by photo and similar items that look alike), the content (no picture is read, similar items of the same use),
 * or both. Chosen by the operator creating the shop and by the shop in Settings · General. The keys belong to other
 * modules (Search, Retrieval) and are named, as TrialLimits names them; one whose module is off is left out.
 */
enum SearchMatters: string
{
    case Look = 'look';
    case Content = 'content';
    case Both = 'both';

    /** The features a picture is read for. */
    public const PICTURE_FEATURES = ['retrieval.image_index', 'retrieval.image_captions', 'search.photos'];

    /** @return array<string, string> value => label, in the panel's language */
    public static function options(): array
    {
        $options = [];
        foreach (self::cases() as $case) {
            $options[$case->value] = __('tenancy::shops.matters.'.$case->value);
        }

        return $options;
    }

    /** As the shop said, else as its pictures and similar items are set now. */
    public static function of(Shop $shop): self
    {
        $said = self::tryFrom((string) (((array) ($shop->profile ?? []))['matters'] ?? ''));
        if ($said !== null) {
            return $said;
        }
        if (! app(FeatureRegistry::class)->has('retrieval.image_index') || ! Features::enabled('retrieval.image_index', $shop->id)) {
            return self::Content;
        }

        return app(SettingRegistry::class)->has('search.similar_by') && Settings::get('search.similar_by', $shop->id) === 'picture' ? self::Look : self::Both;
    }

    public function readsPictures(): bool
    {
        return $this !== self::Content;
    }

    /** Whether a trial holds the shop's pictures off now (TrialLimits): the choice waits for its end. */
    public static function picturesHeld(Shop $shop): bool
    {
        return $shop->onTrial() && array_intersect_key((array) $shop->trial_saved, array_flip(self::PICTURE_FEATURES)) !== [];
    }

    /**
     * As the shop's own settings: its pictures read or not, its similar items by look, by content or both, a photo
     * matched by look or by use. On a trial that holds pictures off, what the trial gives back at its end (EndTrial) is
     * the choice; a plan that holds them off holds them above this (PlanCeilings). Turned on, the picture scan starts by
     * itself within minutes (search:resume-pictures).
     */
    public function apply(Shop $shop): void
    {
        $settings = ['search.similar_by' => match ($this) {
            self::Look => 'picture', self::Content => 'meaning', self::Both => 'both'
        }, 'search.photo_match' => $this === self::Content ? 'function' : 'look'];
        foreach ($settings as $key => $value) {
            if (app(SettingRegistry::class)->has($key)) {
                Settings::set($key, $value, $shop->id);
            }
        }

        if (self::picturesHeld($shop)) {
            $saved = (array) $shop->trial_saved;
            foreach (self::PICTURE_FEATURES as $key) {
                if (isset($saved[$key])) {
                    $saved[$key] = ['feature', $this->readsPictures()];
                }
            }
            $shop->forceFill(['trial_saved' => $saved])->save();

            return;
        }

        foreach (self::PICTURE_FEATURES as $key) {
            if (app(FeatureRegistry::class)->has($key)) {
                Features::override($key, $this->readsPictures(), $shop->id);
            }
        }
    }
}
