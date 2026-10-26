<?php

namespace App\Modules\Search\Filament\Merchant\Pages;

use App\Core\Facades\Settings;
use App\Modules\Search\Actions\ChooseWordFamily;
use App\Modules\Search\Models\SearchFamilyChoice;
use App\Modules\Search\Support\HebrewSearch;
use App\Modules\Search\Support\LoadedIndex;
use App\Modules\Search\Support\OpenWords;
use App\Modules\Search\Support\StoreFamilies;
use App\Modules\Search\Support\StoreWords;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Livewire\Attributes\Url;
use UnitEnum;

/**
 * Word families, in the knowledge centre: words shoppers mean as one, so a search for one finds what says another.
 * Those found in the store's words wait for the team (they count from the start): approved, fixed (a word out, a word
 * in) or called wrong. The team's own, kept as they are. And the words shoppers searched lately that no family holds,
 * with the store's words they may belong with: joined to a family, or set aside. Each change builds the store's search
 * again (ChooseWordFamily). Code only.
 */
final class WordFamilies extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedLanguage;

    protected static string|UnitEnum|null $navigationGroup = 'knowledge';

    protected static ?int $navigationSort = 20;

    protected static ?string $slug = 'word-families';

    /** The knowledge centre's line is about answers; this screen says its own. */
    public static ?string $sectionHint = null;

    protected string $view = 'search::merchant.families';

    /** waiting, approved or open */
    #[Url]
    public string $tab = 'waiting';

    public string $newFamily = '';

    /** @var array<string, string> a family's ref => the words being added to it */
    public array $adding = [];

    /** @var array<int, string> an open word's row => the family chosen for it */
    public array $joining = [];

    /** @var list<array{word: string, searches: int, empty: int, near: list<string>}>|null */
    private ?array $open = null;

    public static function canAccess(): bool
    {
        return Filament::getTenant() !== null;
    }

    public static function getNavigationLabel(): string
    {
        return __('search::families.nav');
    }

    public function getTitle(): string
    {
        return __('search::families.title');
    }

    public function getSubheading(): ?string
    {
        return __('search::families.subheading');
    }

    public function show(string $tab): void
    {
        $this->tab = in_array($tab, ['waiting', 'approved', 'open'], true) ? $tab : 'waiting';
    }

    /** @return list<array{ref: string, words: list<string>}> */
    public function waitingRows(): array
    {
        return array_map(fn (array $words): array => ['ref' => 'w-'.StoreFamilies::key($words), 'words' => $words], StoreFamilies::waiting());
    }

    /** @return list<array{ref: string, words: list<string>}> */
    public function approvedRows(): array
    {
        return array_map(fn (array $f): array => ['ref' => 'a-'.$f['id'], 'words' => $f['words']], StoreFamilies::approved());
    }

    /** @return list<array{word: string, searches: int, empty: int, near: list<string>}> */
    public function openRows(): array
    {
        if ($this->open === null) {
            $shopId = $this->shopId();
            $index = LoadedIndex::for($shopId);
            $this->open = OpenWords::of(StoreFamilies::all(), $index === null ? [] : StoreWords::of($shopId, $index)[0],
                (int) Settings::get('search.families_open_days', $shopId), (int) Settings::get('search.families_open_shown', $shopId));
        }

        return $this->open;
    }

    public function openDays(): int
    {
        return (int) Settings::get('search.families_open_days', $this->shopId());
    }

    /** @return array<string, string> every family a searched word may join: its ref => its words */
    public function familyOptions(): array
    {
        $out = [];
        foreach ([...$this->approvedRows(), ...$this->waitingRows()] as $row) {
            $out[$row['ref']] = implode(' / ', array_map(fn (string $w): string => HebrewSearch::withFinals($w), $row['words']));
        }

        return $out;
    }

    public function approve(string $ref): void
    {
        $this->save($ref, fn (array $words): array => $words);
    }

    /** Not one family: never offered again as one. */
    public function reject(string $ref): void
    {
        [$words, $id] = $this->family($ref);
        if ($words !== []) {
            app(ChooseWordFamily::class)->handle($this->shopId(), SearchFamilyChoice::REJECTED, $words, $id);
            $this->done('rejected');
        }
    }

    public function dropWord(string $ref, string $word): void
    {
        $this->save($ref, fn (array $words): array => array_values(array_filter($words, fn (string $w): bool => HebrewSearch::normalize($w) !== HebrewSearch::normalize($word))));
    }

    public function addWord(string $ref): void
    {
        $more = self::split($this->adding[$ref] ?? '');
        unset($this->adding[$ref]);
        if ($more !== []) {
            $this->save($ref, fn (array $words): array => [...$words, ...$more]);
        }
    }

    public function create(): void
    {
        $words = self::split($this->newFamily);
        if (count($words) < 2) {
            Notification::make()->warning()->title(__('search::families.too_few'))->send();

            return;
        }
        app(ChooseWordFamily::class)->handle($this->shopId(), SearchFamilyChoice::APPROVED, $words);
        $this->newFamily = '';
        $this->done('saved');
    }

    /** A searched word joins the family chosen beside it. */
    public function join(string $word, int $row): void
    {
        $ref = $this->joining[$row] ?? '';
        unset($this->joining[$row]);
        if ($ref !== '') {
            $this->save($ref, fn (array $words): array => [...$words, $word]);
        }
    }

    /** A searched word with one of the store's words: into that word's family when it has one, else a new family. */
    public function pair(string $word, string $near): void
    {
        $key = HebrewSearch::normalize($near);
        foreach ([...$this->approvedRows(), ...$this->waitingRows()] as $row) {
            if (in_array($key, array_map(fn (string $w): string => HebrewSearch::normalize($w), $row['words']), true)) {
                $this->save($row['ref'], fn (array $words): array => [...$words, $word]);

                return;
            }
        }
        app(ChooseWordFamily::class)->handle($this->shopId(), SearchFamilyChoice::APPROVED, [$near, $word]);
        $this->done('saved');
    }

    public function hide(string $word): void
    {
        app(ChooseWordFamily::class)->handle($this->shopId(), SearchFamilyChoice::HIDDEN, [$word]);
        $this->done('hidden');
    }

    /** A family changed by the team is the team's from then on. */
    private function save(string $ref, \Closure $change): void
    {
        [$words, $id] = $this->family($ref);
        if ($words === []) {
            return;
        }
        $saved = app(ChooseWordFamily::class)->handle($this->shopId(), SearchFamilyChoice::APPROVED, $change($words), $id);
        $this->done($saved === null ? 'emptied' : 'saved');
    }

    /** @return array{0: list<string>, 1: string|null} a family's words, and its id when it is the team's */
    private function family(string $ref): array
    {
        if (str_starts_with($ref, 'a-')) {
            foreach (StoreFamilies::approved() as $family) {
                if ('a-'.$family['id'] === $ref) {
                    return [$family['words'], $family['id']];
                }
            }
        }
        foreach (StoreFamilies::waiting() as $words) {
            if ('w-'.StoreFamilies::key($words) === $ref) {
                return [$words, null];
            }
        }

        return [[], null];
    }

    /** @return list<string> words written apart by commas or lines ("קפוצון, hoodie") */
    private static function split(string $text): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/[,،\n]+/u', mb_substr($text, 0, 400)) ?: [])));
    }

    private function done(string $what): void
    {
        $this->open = null;
        Notification::make()->success()->title(__('search::families.done.'.$what))->body(__('search::families.done.updating'))->send();
    }

    private function shopId(): string
    {
        return (string) Filament::getTenant()?->getKey();
    }
}
