<?php

namespace App\Modules\Enrichment\Tasks;

use App\Modules\Enrichment\Enums\TaskType;

final class TaskRegistry
{
    public function for(TaskType $type): AgentTask
    {
        return app(match ($type) {
            TaskType::ProductExtraction => ProductExtractionTask::class,
            TaskType::FactReview => FactReviewTask::class,
            TaskType::ContentMapping => ContentMappingTask::class,
            TaskType::ProductHighlights => ProductHighlightsTask::class,
            TaskType::ProductSummary => ProductSummaryTask::class,
            TaskType::SectionQuestions => SectionQuestionsTask::class,
        });
    }
}
