<?php

namespace App\Modules\TryOn\Support;

use App\Modules\TryOn\Models\TryOnJob;
use App\Modules\TryOn\Models\TryOnPhoto;
use App\Modules\TryOn\Models\TryOnStep;
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

    /**
     * Deletes a photo's pictures: the photo, every picture made from it, and the pictures its steps kept. The
     * rows stay, without paths, so the log still says what happened.
     */
    public static function forgetPhoto(TryOnPhoto $photo): void
    {
        foreach (TryOnStep::query()->where('photo_id', $photo->id)->whereNotNull('images')->get() as $step) {
            foreach ((array) $step->images as $path) {
                self::delete($path);
            }
            $step->update(['images' => null]);
        }
        foreach (TryOnJob::query()->where('photo_id', $photo->id)->whereNotNull('result_path')->get() as $job) {
            self::delete($job->result_path);
            $job->update(['result_path' => null]);
        }
        self::delete($photo->path);
        $photo->update(['path' => null]);
    }
}
