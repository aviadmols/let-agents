<?php

namespace App\Modules\Widget\Filament\Operator\Pages;

use App\Core\Facades\Settings;
use App\Core\Tenancy\TenantContext;
use App\Modules\Widget\Support\OpeningHours as Week;
use BackedEnum;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Carbon;
use UnitEnum;

/**
 * When the store team answers, a day at a time.
 *
 * The hours used to be three text fields inside the long settings list, and nobody could tell
 * which days "the week" covered or what an empty Friday meant. Here every day has its own row
 * with a switch and two times, the screen says whether the team is answering right now, and the
 * timezone it is all measured in sits above it.
 */
class OpeningHours extends Page implements HasForms
{
    use InteractsWithForms;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClock;

    protected static string|UnitEnum|null $navigationGroup = 'shoppers';

    protected static ?int $navigationSort = 60;

    protected static ?string $slug = 'opening-hours';

    protected string $view = 'widget::operator.opening-hours';

    /** @var array<string, mixed> */
    public array $data = [];

    public static function getNavigationLabel(): string
    {
        return __('widget::ui.hours.title');
    }

    public function getTitle(): string
    {
        return __('widget::ui.hours.title');
    }

    public function getSubheading(): ?string
    {
        return __('widget::ui.hours.help');
    }

    /** The shop these hours belong to: the one the operator picked, or none while across shops. */
    public function shopId(): ?string
    {
        return app(TenantContext::class)->id();
    }

    public function mount(): void
    {
        $shop = $this->shopId();
        $state = ['timezone' => $shop === null ? 'Asia/Jerusalem' : (string) Settings::get('widget.whatsapp_timezone', $shop)];

        foreach (Week::DAYS as $day) {
            $value = $shop === null ? '' : (string) Settings::get("widget.hours_{$day}", $shop);
            $ends = Week::split($value);

            $state["open_{$day}"] = $ends['from'] !== null;
            $state["from_{$day}"] = $ends['from'] ?? '09:00';
            $state["until_{$day}"] = $ends['until'] ?? '18:00';
        }

        $this->form->fill($state);
    }

    public function form(Schema $schema): Schema
    {
        $rows = [];

        foreach (Week::DAYS as $day) {
            $rows[] = Grid::make(['default' => 3])->schema([
                Toggle::make("open_{$day}")
                    ->label(__("widget::ui.hours.days.{$day}"))
                    ->inline(false)
                    ->live(),
                TextInput::make("from_{$day}")
                    ->label(__('widget::ui.hours.from'))
                    ->type('time')
                    ->visible(fn (callable $get): bool => (bool) $get("open_{$day}")),
                TextInput::make("until_{$day}")
                    ->label(__('widget::ui.hours.until'))
                    ->type('time')
                    ->visible(fn (callable $get): bool => (bool) $get("open_{$day}")),
            ]);
        }

        return $schema
            ->statePath('data')
            ->components([
                Section::make(__('widget::ui.hours.timezone_section'))->schema([
                    Select::make('timezone')
                        ->label(__('widget::settings.whatsapp_timezone.label'))
                        ->options(array_combine(timezone_identifiers_list(), timezone_identifiers_list()))
                        ->searchable()
                        ->required(),
                ]),
                Section::make(__('widget::ui.hours.week'))
                    ->description(__('widget::ui.hours.week_help'))
                    ->schema($rows),
            ]);
    }

    /** Whether the team is answering at this moment, for the line at the top of the screen. */
    public function openNow(): ?bool
    {
        $shop = $this->shopId();

        if ($shop === null) {
            return null;
        }

        $week = Week::fromDays(array_map(
            fn (string $day): string => (string) Settings::get("widget.hours_{$day}", $shop),
            Week::DAYS,
        ));

        return $week->alwaysClosed()
            ? null
            : $week->openAt(Carbon::now()->toDateTimeImmutable(), (string) Settings::get('widget.whatsapp_timezone', $shop));
    }

    public function save(): void
    {
        $shop = $this->shopId();

        if ($shop === null) {
            Notification::make()->warning()->title(__('widget::ui.hours.pick_a_shop'))->send();

            return;
        }

        Settings::set('widget.whatsapp_timezone', (string) ($this->data['timezone'] ?? 'Asia/Jerusalem'), $shop);

        foreach (Week::DAYS as $day) {
            $value = ($this->data["open_{$day}"] ?? false)
                ? Week::compose($this->data["from_{$day}"] ?? null, $this->data["until_{$day}"] ?? null)
                : '';

            Settings::set("widget.hours_{$day}", $value, $shop);
        }

        Notification::make()->success()->title(__('widget::ui.hours.saved'))->send();

        $this->mount();
    }
}
