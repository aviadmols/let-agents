<?php

namespace App\Modules\Admin\Livewire;

use App\Modules\Admin\Models\User;
use App\Modules\Admin\Support\Today;
use App\Modules\Tenancy\Models\Shop;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Today's "Right now in your store": the latest searches, photo searches and questions as they happen, each with what
 * the shopper got. Its own component, so the page around it is not drawn again: the browser asks for it every few
 * seconds unless the owner paused it, points at it or opened a row. Opening a row shows everything the search log keeps
 * about that search (the address, the device, the visit, the page), as the owner asked (8.11). A search box narrows it.
 * Read through Today (TodaySearches), inside the shop the person may open, checked on every request.
 */
final class TodayFeed extends Component
{
    public const NAME = 'admin-today-feed';

    /** How many rows show. */
    private const SHOWN = 6;

    #[Locked]
    public string $shopId = '';

    public string $search = '';

    /** The row whose details are open, or ''. */
    public string $open = '';

    public function mount(string $shopId): void
    {
        $this->shopId = $shopId;
        $this->shop();
    }

    public function toggle(string $id): void
    {
        $this->open = $this->open === $id ? '' : $id;
    }

    public function updatedSearch(): void
    {
        $this->open = '';
    }

    public function render(): View
    {
        return view('admin::merchant.today-feed', [
            'rows' => app(Today::class)->feed($this->shop(), $this->search, self::SHOWN),
        ]);
    }

    /** The shop, when the person signed in may open it; otherwise nothing is shown to them. */
    private function shop(): Shop
    {
        $user = Auth::user();
        $shop = Shop::query()->find($this->shopId);
        abort_unless($shop instanceof Shop && $user instanceof User && ($user->is_operator || $user->shops()->whereKey($shop->getKey())->exists()), 403);

        return $shop;
    }
}
