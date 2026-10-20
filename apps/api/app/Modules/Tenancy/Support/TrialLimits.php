<?php

namespace App\Modules\Tenancy\Support;

use App\Core\Facades\Features;
use App\Core\Facades\Settings;
use App\Core\Features\FeatureRegistry;
use App\Core\Settings\SettingRegistry;

/**
 * What a trial limits, as the shop's own settings: how many products are read, how many questions
 * a day the assistant answers, and (unless tenancy.trial_pictures is on) no reading of pictures
 * and no search by photo. The keys belong to other modules and are named, not imported; one whose
 * module is off is left out.
 */
final class TrialLimits
{
    /**
     * The shop's settings while on trial, key => [kind, value].
     *
     * @return array<string, array{0: 'setting'|'feature', 1: int|bool}>
     */
    public static function all(): array
    {
        $limits = [
            'catalog.max_products' => ['setting', (int) Settings::get('tenancy.trial_max_products')],
            'assistant.questions_per_shop_per_day' => ['setting', (int) Settings::get('tenancy.trial_questions_per_day')],
        ];

        if (! Features::enabled('tenancy.trial_pictures')) {
            foreach (['retrieval.image_index', 'retrieval.image_captions', 'search.photos'] as $key) {
                $limits[$key] = ['feature', false];
            }
        }

        $settings = app(SettingRegistry::class);
        $features = app(FeatureRegistry::class);

        return array_filter($limits, fn (array $limit, string $key): bool => $limit[0] === 'setting' ? $settings->has($key) : $features->has($key), ARRAY_FILTER_USE_BOTH);
    }

    /**
     * The trial in words, for the operator's screens: products, questions a day, pictures, days.
     *
     * @return array{products: string, questions: string, pictures: string, days: int}
     */
    public static function words(): array
    {
        return [
            'products' => number_format((int) Settings::get('tenancy.trial_max_products')),
            'questions' => number_format((int) Settings::get('tenancy.trial_questions_per_day')),
            'pictures' => __(Features::enabled('tenancy.trial_pictures') ? 'tenancy::shops.trial.pictures_on' : 'tenancy::shops.trial.pictures_off'),
            'days' => (int) Settings::get('tenancy.trial_days'),
        ];
    }
}
