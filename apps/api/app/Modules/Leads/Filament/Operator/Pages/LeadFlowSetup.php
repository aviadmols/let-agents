<?php

namespace App\Modules\Leads\Filament\Operator\Pages;

use App\Core\Tenancy\NeedsShopContext;
use App\Core\Tenancy\TenantContext;
use App\Modules\Leads\Enums\LeadGoal;
use App\Modules\Leads\Models\LeadFlow;
use BackedEnum;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use UnitEnum;

/**
 * What this shop wants from a reader, and what it will ask for it.
 *
 * Everything else in the platform is worked out from the site. This cannot be: whether a shop
 * wants a phone call booked or an address to send a guide to, what it offers in return, and how
 * much typing it is willing to put a reader through are decisions about the business, and a
 * system that guesses them guesses wrong in a way that costs the shop real people.
 *
 * So it is asked once, plainly, and then obeyed. Saving adds a version rather than editing one,
 * because a lead has to stay readable against the wording the person actually agreed to.
 */
class LeadFlowSetup extends Page implements HasForms
{
    use InteractsWithForms;
    use NeedsShopContext;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserPlus;

    protected static string|UnitEnum|null $navigationGroup = 'shoppers';

    protected static ?int $navigationSort = 40;

    protected static ?string $slug = 'leads/flow';

    protected string $view = 'leads::operator.flow';

    /** @var array<string, mixed> */
    public array $data = [];

    public static function getNavigationLabel(): string
    {
        return __('leads::flow.title');
    }

    public function getTitle(): string
    {
        return __('leads::flow.title');
    }

    public function getSubheading(): ?string
    {
        return __('leads::flow.help');
    }

    public function mount(): void
    {
        $flow = $this->current();

        $this->form->fill($flow === null ? $this->blank() : [
            'goal' => $flow->goal->value,
            'offer' => $flow->offer,
            'promise' => $flow->promise,
            'consent' => $flow->consent,
            'fields' => $flow->asked(),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Section::make(__('leads::flow.offer_section'))
                    ->description(__('leads::flow.offer_help'))
                    ->schema([
                        Select::make('goal')
                            ->label(__('leads::flow.goal'))
                            ->options(collect(LeadGoal::all())->mapWithKeys(fn (LeadGoal $goal): array => [
                                $goal->value => __('leads::flow.goals.'.$goal->value),
                            ])->all())
                            ->required(),
                        TextInput::make('offer')
                            ->label(__('leads::flow.offer'))
                            ->placeholder(__('leads::flow.offer_example'))
                            ->maxLength(200)
                            ->required(),
                        TextInput::make('promise')
                            ->label(__('leads::flow.promise'))
                            ->placeholder(__('leads::flow.promise_example'))
                            ->maxLength(120),
                    ]),

                Section::make(__('leads::flow.fields_section'))
                    ->description(__('leads::flow.fields_help'))
                    ->schema([
                        Repeater::make('fields')
                            ->label('')
                            ->addActionLabel(__('leads::flow.add_field'))
                            ->reorderable()
                            ->columns(4)
                            ->schema([
                                Select::make('type')
                                    ->label(__('leads::flow.field_type'))
                                    ->options(collect(LeadFlow::FIELD_TYPES)->mapWithKeys(fn (string $type): array => [
                                        $type => __('leads::flow.field_types.'.$type),
                                    ])->all())
                                    ->required(),
                                TextInput::make('key')
                                    ->label(__('leads::flow.field_key'))
                                    ->rule('alpha_dash')
                                    ->maxLength(30)
                                    ->required(),
                                TextInput::make('label')
                                    ->label(__('leads::flow.field_label'))
                                    ->maxLength(60)
                                    ->required(),
                                Toggle::make('required')
                                    ->label(__('leads::flow.field_required'))
                                    ->inline(false),
                            ]),
                    ]),

                Section::make(__('leads::flow.consent_section'))
                    ->description(__('leads::flow.consent_help'))
                    ->schema([
                        Textarea::make('consent')
                            ->label(__('leads::flow.consent'))
                            ->rows(3)
                            ->maxLength(600)
                            ->required(),
                    ]),
            ]);
    }

    public function save(): void
    {
        $shopId = app(TenantContext::class)->id();
        $state = $this->form->getState();

        if ($shopId === null) {
            return;
        }

        $flow = app(TenantContext::class)->run($shopId, function () use ($shopId, $state): LeadFlow {
            $version = (int) (LeadFlow::query()->where('shop_id', $shopId)->max('version') ?? 0) + 1;
            LeadFlow::query()->where('shop_id', $shopId)->update(['active' => false]);

            return LeadFlow::query()->create([
                'shop_id' => $shopId,
                'version' => $version,
                'goal' => $state['goal'],
                'offer' => trim((string) $state['offer']),
                'promise' => $state['promise'] === null ? null : trim((string) $state['promise']),
                'consent' => trim((string) $state['consent']),
                'fields' => array_values((array) ($state['fields'] ?? [])),
                'active' => true,
                'created_by' => Auth::id(),
            ]);
        });

        // A flow with no way to reach anybody collects typing and nothing else.
        if (! $flow->canReachAnyone()) {
            Notification::make()->warning()->title(__('leads::flow.no_contact_field'))->send();

            return;
        }

        Notification::make()->success()->title(__('leads::flow.saved', ['n' => $flow->version]))->send();
    }

    public function current(): ?LeadFlow
    {
        $shopId = app(TenantContext::class)->id();

        return $shopId === null ? null : app(TenantContext::class)->run($shopId, fn () => LeadFlow::inForce($shopId));
    }

    /** @return array<string, mixed> */
    private function blank(): array
    {
        return [
            'goal' => LeadGoal::Advice->value,
            'offer' => '',
            'promise' => '',
            'consent' => (string) __('leads::flow.consent_default'),
            'fields' => [
                ['type' => 'phone', 'key' => 'phone', 'label' => (string) __('leads::flow.field_types.phone'), 'required' => true],
                ['type' => 'name', 'key' => 'name', 'label' => (string) __('leads::flow.field_types.name'), 'required' => false],
            ],
        ];
    }
}
