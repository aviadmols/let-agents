<?php

namespace App\Modules\Admin\Actions;

use App\Core\Settings\OverrideScope;
use App\Core\Tenancy\BelongsToTenant;
use App\Core\Tenancy\TenantContext;
use App\Modules\Admin\Models\User;
use App\Modules\Tenancy\Models\Shop;
use BackedEnum;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Throwable;
use ZipArchive;

/**
 * Everything a shop holds, for its owner to keep: one ZIP with a CSV per table (Excel opens them, Hebrew included),
 * by module, and a README in Hebrew and English. The shop, its people, its own settings, and every table of every
 * module whose rows belong to a shop (the models that use BelongsToTenant, found on disk, so a new module's table
 * joins by itself). What shoppers left with the store is theirs too (leads, saved lists, the details they gave),
 * read as written, not encrypted.
 *
 * Left out: credentials (tokens, keys, passwords), one-way codes (hashes), vectors and copies of pictures, the
 * search's own index (rebuilt from the catalogue), the raw stream of page events, and how the system worked (the
 * model, its cost, its prompt).
 */
final class ExportShopData
{
    /** Tables of credentials, of the system's own copies and of the raw event stream. */
    private const LEFT_OUT_TABLES = ['shop_api_keys', 'store_connections', 'search_indexes', 'analytics_events', 'analytics_visit_steps', 'sms_accounts'];

    /** Every table whose name starts so: vectors and what was made from them. */
    private const LEFT_OUT_PREFIXES = ['retrieval_'];

    /** Columns that are secrets, one-way codes, vectors, pictures' bytes, or how the system worked. */
    private const LEFT_OUT_COLUMNS = '/(^|_)(token|secret|password|hash|embedding|vector|bytes|model|cost|usd|tokens|prompt|prompt_version|fingerprint)$|^(photo|thumb|images|shop_id)$/';

    private const ROWS_AT_ONCE = 500;

    public function __construct(private readonly TenantContext $tenant) {}

    /** The ZIP's path, in the system's temporary folder; the caller sends it and deletes it. */
    public function handle(Shop $shop): string
    {
        $zipPath = (string) tempnam(sys_get_temp_dir(), 'lets-export-');
        $zip = new ZipArchive;

        if ($zip->open($zipPath, ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('The export could not be written.');
        }

        $files = [];
        $add = function (string $name, iterable $rows) use ($zip, &$files): void {
            $files[] = $file = $this->csv($rows);
            $zip->addFile($file, $name);
        };

        // inside the shop, so every model's rows are this shop's alone, read as the files are written
        $this->tenant->run($shop->id, function () use ($shop, $add): void {
            $add('shop.csv', [$this->shopRow($shop)]);
            $add('team.csv', $this->team($shop));
            $add('settings.csv', $this->settings($shop));

            foreach ($this->tenantModels() as $module => $models) {
                foreach ($models as $class) {
                    $table = (new $class)->getTable();
                    if ($this->leftOut($table)) {
                        continue;
                    }
                    $add($module.'/'.$table.'.csv', $this->rows($class, $table));
                }
            }
        });

        $zip->addFromString('README.txt', (string) __('admin::privacy.export.readme', ['shop' => $shop->name, 'date' => now()->toDateString()]));
        $zip->close();

        foreach ($files as $file) {
            @unlink($file);
        }

        Log::info('A shop\'s data was exported', ['shop' => $shop->id]);

        return $zipPath;
    }

    /** @return array<string, mixed> */
    private function shopRow(Shop $shop): array
    {
        return collect(['id', 'name', 'domain', 'platform', 'status', 'plan', 'content_locale', 'currency', 'timezone', 'created_at', 'cancelled_at', 'plan_ends_at', 'deletion_due_at'])
            ->mapWithKeys(fn (string $column): array => [$column => $this->plain($shop->getAttributeValue($column))])->all();
    }

    /** @return iterable<array<string, mixed>> */
    private function team(Shop $shop): iterable
    {
        foreach ($shop->belongsToMany(User::class, 'shop_user')->withPivot('role', 'created_at')->orderBy('users.id')->get() as $user) {
            yield ['name' => $user->name, 'email' => $user->email, 'role' => $user->pivot->role, 'joined_at' => $this->plain($user->pivot->created_at), 'last_login_at' => $this->plain($user->last_login_at)];
        }
    }

    /** The shop's own settings and switches, where it differs from what it inherits. @return iterable<array<string, mixed>> */
    private function settings(Shop $shop): iterable
    {
        foreach (['setting_overrides' => 'setting', 'feature_overrides' => 'feature'] as $table => $kind) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            foreach (DB::table($table)->where('scope', OverrideScope::for($shop->id))->orderBy('key')->get() as $row) {
                $values = (array) $row;
                yield ['kind' => $kind, 'key' => $values['key'] ?? '', 'value' => $this->plain($values['value'] ?? $values['enabled'] ?? null)];
            }
        }
    }

    /**
     * @param  class-string<Model>  $class
     * @return iterable<array<string, mixed>>
     */
    private function rows(string $class, string $table): iterable
    {
        $columns = array_values(array_filter(Schema::getColumnListing($table), fn (string $c): bool => preg_match(self::LEFT_OUT_COLUMNS, $c) !== 1));

        yield array_combine($columns, $columns);

        foreach ($class::query()->orderBy((new $class)->getKeyName())->lazy(self::ROWS_AT_ONCE) as $model) {
            $row = [];
            foreach ($columns as $column) {
                try {
                    $row[$column] = $this->plain($model->getAttributeValue($column));
                } catch (Throwable) {
                    $row[$column] = '';
                }
            }
            yield $row;
        }
    }

    /** One CSV in the temporary folder, UTF-8 with the mark Excel reads Hebrew by. The first row names the columns. */
    private function csv(iterable $rows): string
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'lets-csv-');
        $out = fopen($path, 'wb');
        fwrite($out, "\xEF\xBB\xBF");
        $header = false;

        foreach ($rows as $row) {
            if (! $header) {
                $keys = array_keys($row);
                // a table's rows come after a row of its columns; the fixed files name theirs from the first row
                if (array_values($row) !== $keys) {
                    fputcsv($out, $keys, escape: '');
                }
                $header = true;
            }
            fputcsv($out, array_values($row), escape: '');
        }

        fclose($out);

        return $path;
    }

    private function plain(mixed $value): string
    {
        return match (true) {
            $value === null => '',
            $value instanceof BackedEnum => (string) $value->value,
            $value instanceof DateTimeInterface => $value->format('Y-m-d H:i:s'),
            is_bool($value) => $value ? '1' : '0',
            is_array($value), is_object($value) => (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            default => (string) $value,
        };
    }

    private function leftOut(string $table): bool
    {
        if (in_array($table, self::LEFT_OUT_TABLES, true)) {
            return true;
        }

        foreach (self::LEFT_OUT_PREFIXES as $prefix) {
            if (str_starts_with($table, $prefix)) {
                return true;
            }
        }

        return ! Schema::hasTable($table);
    }

    /** @return array<string, list<class-string<Model>>> module => its models whose rows belong to a shop */
    private function tenantModels(): array
    {
        $found = [];

        foreach (glob(app_path('Modules/*/Models/*.php')) ?: [] as $file) {
            $module = basename(dirname($file, 2));
            $class = 'App\\Modules\\'.$module.'\\Models\\'.basename($file, '.php');

            if (class_exists($class) && is_subclass_of($class, Model::class) && ! (new \ReflectionClass($class))->isAbstract()
                && in_array(BelongsToTenant::class, class_uses_recursive($class), true)) {
                $found[$module][] = $class;
            }
        }

        ksort($found);

        return array_map(function (array $classes): array {
            sort($classes);

            return $classes;
        }, $found);
    }
}
