<?php

namespace App\Modules\Runs\Filament\Operator\Pages;

use App\Core\Facades\Settings;
use App\Modules\Runs\Models\Run;
use App\Modules\Runs\Models\RunCall;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;
use Livewire\Attributes\Url;
use UnitEnum;

/**
 * One step of the pipeline opened: its latest runs, and for the run opened, what it was given,
 * what it wrote and every model call it made, each with its prompt, what was sent and what came
 * back. Without a step, the latest calls of the whole platform, those outside any run too (a
 * shopper's question, a Try-On photo). Prompts are kept runs.call_log_days. The operator's only.
 *
 * Only the call opened carries its texts, so a run with hundreds of calls stays light.
 */
final class ProcessLog extends Page
{
    public const RUNS = 30;

    public const CALLS = 200;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChatBubbleLeftRight;

    protected static string|UnitEnum|null $navigationGroup = 'overview';

    protected static ?int $navigationSort = 32;

    protected static ?string $slug = 'pipeline/process';

    protected string $view = 'runs::operator.process';

    #[Url]
    public string $step = '';

    #[Url]
    public string $run = '';

    #[Url]
    public ?int $call = null;

    public static function getNavigationLabel(): string
    {
        return __('runs::process.nav');
    }

    public function getTitle(): string
    {
        return $this->step === '' ? __('runs::process.nav') : PipelineFlow::name($this->step);
    }

    public function getSubheading(): ?string
    {
        return __('runs::process.help', ['days' => (int) Settings::get('runs.call_log_days')]);
    }

    public function openRun(string $id): void
    {
        $this->run = $this->run === $id ? '' : $id;
        $this->call = null;
    }

    public function openCall(int $id): void
    {
        $this->call = $this->call === $id ? null : $id;
    }

    /** The step's latest runs, with how many calls each still has in the log. @return Collection<int, Run> */
    public function runs(): Collection
    {
        if ($this->step === '') {
            return collect();
        }

        return Run::query()
            ->with('shop:id,name')
            ->withCount('calls')
            ->where('action', $this->step)
            ->latest('started_at')
            ->limit(self::RUNS)
            ->get();
    }

    /** The run opened, when it is one of this step's (or any run when opened from a call list). */
    public function openedRun(): ?Run
    {
        return $this->run === '' ? null : Run::query()->with('shop:id,name')->find($this->run);
    }

    /**
     * The calls shown, without their texts: the opened run's, in the order made, or else the
     * latest of the step, or of the whole platform.
     *
     * @return Collection<int, RunCall>
     */
    public function calls(): Collection
    {
        $columns = ['id', 'run_id', 'shop_id', 'agent', 'action', 'kind', 'provider', 'model', 'ok', 'input_tokens', 'output_tokens', 'duration_ms', 'created_at'];

        if ($this->run !== '') {
            return RunCall::query()->select($columns)->where('run_id', $this->run)->orderBy('id')->limit(self::CALLS)->get();
        }

        if ($this->step !== '') {
            return collect();
        }

        return RunCall::query()->select($columns)->with('shop:id,name')->latest('id')->limit(self::CALLS)->get();
    }

    /** The call opened, with its texts. */
    public function openedCall(): ?RunCall
    {
        return $this->call === null ? null : RunCall::query()->find($this->call);
    }

    /** A reply stored as JSON, as it was; anything else as itself. */
    public static function readable(?string $text): ?string
    {
        if ($text === null || $text === '') {
            return $text;
        }

        $data = json_decode($text, true);

        return is_array($data) ? (string) json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) : $text;
    }

    /** @param array<mixed>|null $data */
    public static function json(?array $data): ?string
    {
        return $data === null || $data === [] ? null : (string) json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    }
}
