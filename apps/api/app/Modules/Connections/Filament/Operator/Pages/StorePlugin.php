<?php

namespace App\Modules\Connections\Filament\Operator\Pages;

use App\Modules\Connections\Support\PluginPackage;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Infolists\Components\TextEntry;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontFamily;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/**
 * Where the operator downloads the WooCommerce plugin and reads how to install it.
 */
final class StorePlugin extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowDownTray;

    protected static string|UnitEnum|null $navigationGroup = 'shops';

    protected static ?int $navigationSort = 40;

    protected static ?string $slug = 'store-plugin';

    public static function getNavigationLabel(): string
    {
        return __('connections::plugin.title');
    }

    public function getTitle(): string
    {
        return __('connections::plugin.title');
    }

    public function getSubheading(): ?string
    {
        return __('connections::plugin.subheading');
    }

    protected function getHeaderActions(): array
    {
        $package = PluginPackage::latest();

        return [
            Action::make('download')
                ->label($package === null ? __('connections::plugin.download') : __('connections::plugin.download_version', ['version' => $package->version]))
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->url(route('connections.plugin.download'))
                ->disabled($package === null),
        ];
    }

    public function content(Schema $schema): Schema
    {
        $package = PluginPackage::latest();

        return $schema->components([
            Section::make(__('connections::plugin.sections.file'))
                ->columns(3)
                ->schema($package === null
                    ? [
                        TextEntry::make('missing')
                            ->label(__('connections::plugin.sections.file'))
                            ->hiddenLabel()
                            ->state(__('connections::plugin.missing'))
                            ->color('danger')
                            ->columnSpanFull(),
                    ]
                    : [
                        TextEntry::make('filename')->label(__('connections::plugin.fields.file'))->state($package->filename)->fontFamily(FontFamily::Mono)->extraAttributes(['dir' => 'ltr']),
                        TextEntry::make('version')->label(__('connections::plugin.fields.version'))->state($package->version),
                        TextEntry::make('size')->label(__('connections::plugin.fields.size'))->state(__('connections::plugin.fields.kilobytes', ['size' => $package->kilobytes()])),
                    ]),
            Section::make(__('connections::plugin.sections.install'))
                ->schema([
                    TextEntry::make('steps')
                        ->label(__('connections::plugin.sections.install'))
                        ->hiddenLabel()
                        ->state([
                            __('connections::plugin.steps.upload'),
                            __('connections::plugin.steps.token'),
                            __('connections::plugin.steps.copy'),
                            __('connections::plugin.steps.connect'),
                        ])
                        ->listWithLineBreaks()
                        ->bulleted(),
                ]),
            Section::make(__('connections::plugin.sections.access'))
                ->schema([
                    TextEntry::make('access')
                        ->label(__('connections::plugin.sections.access'))
                        ->hiddenLabel()
                        ->state([
                            __('connections::plugin.access.reads'),
                            __('connections::plugin.access.never'),
                            __('connections::plugin.access.revoke'),
                        ])
                        ->listWithLineBreaks()
                        ->bulleted(),
                ]),
        ]);
    }
}
