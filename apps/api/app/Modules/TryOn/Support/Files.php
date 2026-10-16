<?php

namespace App\Modules\TryOn\Support;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Where Try-On kept its pictures: the disk named in config (`upsell.tryon_disk`, the Railway bucket
 * in production, the local disk elsewhere), one folder per shop and month. On a container's own disk
 * every picture was also kept in the database (try_on_files).
 *
 * Try-On no longer takes photos; what it kept is deleted on its keeping date (`try-on:prune`).
 */
final class Files
{
    private const TABLE = 'try_on_files';

    public static function disk(): Filesystem
    {
        return Storage::disk((string) (config('upsell.tryon_disk') ?: 'local'));
    }

    /** Deletes a picture from the disk and from the database copy. */
    public static function delete(?string $path): void
    {
        if ($path !== null) {
            self::disk()->delete($path);
            DB::table(self::TABLE)->where('path', $path)->delete();
        }
    }
}
