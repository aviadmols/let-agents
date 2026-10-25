<?php

use App\Modules\Enrichment\Enums\TaskType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * As the English walk through the panel found (2026-10-25): a task file's line read "3 שאלות שכל קטע בעמוד עונה
 * עליהן: requests ready" for an English reader, because the task's name was written into the line in the
 * language the job ran in. A run now keeps the name's key (Run::summary reads it in the reader's language);
 * the lines written before are given the key too, from the name in either language.
 */
return new class extends Migration
{
    private const KEYS = ['enrichment::runs.task_file_created', 'enrichment::runs.nothing_to_do'];

    public function up(): void
    {
        $keys = [];

        foreach (TaskType::cases() as $type) {
            foreach (['he', 'en'] as $locale) {
                $keys[(string) __('enrichment::enrichment.tasks.'.$type->value, [], $locale)] = 'enrichment::enrichment.tasks.'.$type->value;
            }
        }

        DB::table('runs')->whereIn('summary_key', self::KEYS)->orderBy('id')->select(['id', 'summary_params'])
            ->chunk(500, function ($rows) use ($keys): void {
                foreach ($rows as $row) {
                    $params = json_decode((string) $row->summary_params, true);

                    if (! is_array($params) || ! isset($keys[(string) ($params['task'] ?? '')])) {
                        continue;
                    }

                    $params['task'] = $keys[(string) $params['task']];
                    DB::table('runs')->where('id', $row->id)->update(['summary_params' => json_encode($params, JSON_UNESCAPED_UNICODE)]);
                }
            });
    }

    public function down(): void
    {
        // Nothing to undo: a key reads as the name it stood for.
    }
};
