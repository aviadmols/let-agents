<?php

namespace App\Core\Filament;

use App\Core\Facades\Features;
use App\Core\Facades\Settings;
use App\Core\Settings\InvalidSettingValue;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * A settings page of the shop's panel: its fields bound to features and settings (each a module's, by
 * key, so no module imports another), loaded for the shop in the address and saved as the shop's own
 * value only where it differs from what the shop would inherit: a value set back to the default follows
 * the default again. Everything is checked before anything is written, and written together.
 *
 * A page lists its fields in fields(): 'name' => 'feature:search.photos' or 'setting:search.image_fit'.
 * Fields of its own (a shop column, a list) it loads in loadMore() and saves in saveMore().
 */
trait EditsShopSettings
{
    /** @var array<string, mixed> the form, by field */
    public array $data = [];

    /** @var array<string, string> what was wrong, by field */
    public array $problems = [];

    /** @return array<string, string> field => 'feature:<key>' or 'setting:<key>' */
    abstract protected function fields(): array;

    public function mount(): void
    {
        $shop = $this->shopId();

        foreach ($this->fields() as $field => $source) {
            [$kind, $key] = explode(':', $source, 2);
            $this->data[$field] = $kind === 'feature' ? Features::enabled($key, $shop) : Settings::get($key, $shop);
        }

        $this->loadMore();
    }

    public function save(): void
    {
        $shop = $this->shopId();
        $this->problems = [];
        $this->checkMore();

        // Every value checked first: one wrong value saves nothing.
        foreach ($this->fields() as $field => $source) {
            [$kind, $key] = explode(':', $source, 2);

            if ($kind === 'setting') {
                try {
                    Settings::normalize($key, $this->data[$field] ?? null);
                } catch (InvalidSettingValue $e) {
                    $this->problems[$field] = $e->getMessage();
                }
            }
        }

        if ($this->problems !== []) {
            Notification::make()->warning()->title(__('admin::shop_settings.problems'))->send();

            return;
        }

        DB::transaction(function () use ($shop): void {
            foreach ($this->fields() as $field => $source) {
                [$kind, $key] = explode(':', $source, 2);
                $value = $this->data[$field] ?? null;

                if ($kind === 'feature') {
                    // The shop's own word only where it differs from what it would inherit.
                    Features::clearOverride($key, $shop);
                    if (Features::enabled($key, $shop) !== (bool) $value) {
                        Features::override($key, (bool) $value, $shop);
                    }

                    continue;
                }

                Settings::clear($key, $shop);
                if (Settings::normalize($key, $value) !== Settings::get($key, $shop)) {
                    Settings::set($key, $value, $shop);
                }
            }

            $this->saveMore();
        });

        $this->saved();
        Notification::make()->success()->title(__('admin::shop_settings.saved'))->send();
    }

    /** Fields of the page's own, beyond features and settings. */
    protected function loadMore(): void {}

    /** Check the page's own fields; add to $this->problems what is wrong. */
    protected function checkMore(): void {}

    /** Save the page's own fields, inside the same transaction. */
    protected function saveMore(): void {}

    /** After everything was saved (a cache to forget, a job to start). */
    protected function saved(): void {}

    protected function shopId(): string
    {
        $tenant = Filament::getTenant();

        return $tenant instanceof Model ? (string) $tenant->getKey() : '';
    }

    /** Whether this field was wrong at the last save. */
    public function problem(string $field): ?string
    {
        return $this->problems[$field] ?? null;
    }
}
