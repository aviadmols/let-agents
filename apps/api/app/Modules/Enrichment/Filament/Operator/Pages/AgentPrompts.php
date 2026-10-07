<?php

namespace App\Modules\Enrichment\Filament\Operator\Pages;

use App\Modules\Enrichment\Enums\TaskType;
use App\Modules\Enrichment\Prompts\PromptLibrary;
use BackedEnum;
use Filament\Infolists\Components\TextEntry;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontFamily;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/**
 * The instructions each agent receives, as released. Read-only: a prompt changes through a new
 * versioned file, so every batch can say exactly what it sent.
 */
final class AgentPrompts extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static string|UnitEnum|null $navigationGroup = 'system';

    protected static ?int $navigationSort = 20;

    protected static ?string $slug = 'enrichment/prompts';

    public static function getNavigationLabel(): string
    {
        return __('enrichment::ui.prompts.title');
    }

    public function getTitle(): string
    {
        return __('enrichment::ui.prompts.title');
    }

    public function getSubheading(): ?string
    {
        return __('enrichment::ui.prompts.subheading');
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components(array_map(
            fn (TaskType $task): Section => Section::make($task->label())
                ->description(__('enrichment::ui.prompts.meta', [
                    'version' => PromptLibrary::version($task),
                    'model' => $task->suggestedModel(),
                ]))
                ->collapsible()
                ->collapsed($task !== TaskType::ProductExtraction)
                ->schema([
                    TextEntry::make("prompt_{$task->value}")
                        ->hiddenLabel()
                        ->label($task->label())
                        ->state(PromptLibrary::template($task))
                        ->fontFamily(FontFamily::Mono)
                        ->extraAttributes(['dir' => 'ltr', 'style' => 'white-space: pre-wrap']),
                ]),
            TaskType::cases(),
        ));
    }
}
