<?php

namespace App\Modules\Widget\Filament\Merchant\Pages;

use App\Core\Facades\Settings;
use App\Core\Filament\EditsShopSettings;
use App\Core\Localization\Locales;
use App\Modules\Tenancy\Models\Shop;
use App\Modules\Widget\Support\AnsweringZone;
use App\Modules\Widget\Support\PageCache;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/**
 * Settings · WhatsApp, as the designer's panel lays it out: the shop's number, the sentence, the button
 * and the message the shopper sends, WhatsApp when search finds nothing, and what happens outside the
 * answering hours. It cannot be on without a number. A wording left as the usual one is not saved, so
 * the shop keeps following the usual wording. The product pages carry it, so their cache is forgotten.
 */
class WhatsappSettings extends Page
{
    use EditsShopSettings;

    /** The words the shop may rewrite, each falling back to the widget's own (widget::bank.contact.*). */
    public const TEXTS = ['title', 'button', 'message'];

    public static string $sideGroup = 'team';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChatBubbleLeftRight;

    protected static string|UnitEnum|null $navigationGroup = 'settings';

    protected static ?int $navigationSort = 70;

    protected static ?string $slug = 'settings/whatsapp';

    protected string $view = 'widget::merchant.settings.whatsapp';

    public static function getNavigationLabel(): string
    {
        return __('widget::shop_settings.whatsapp.title');
    }

    public function getTitle(): string
    {
        return __('admin::shop_settings.title');
    }

    public function getSubheading(): ?string
    {
        return __('admin::shop_settings.subheading');
    }

    protected function fields(): array
    {
        return [
            'whatsapp' => 'feature:widget.whatsapp',
            'search_whatsapp' => 'feature:assistant.search_whatsapp',
            'when_offline' => 'setting:widget.whatsapp_when_offline',
        ];
    }

    protected function loadMore(): void
    {
        $shop = $this->shopId();

        // The shop's own number only, never one saved for every shop.
        $this->data['number'] = (string) (Settings::overrideFor('widget.whatsapp_number', $shop) ?? '');

        foreach (self::TEXTS as $name) {
            $own = trim((string) Settings::get("widget.whatsapp_{$name}", $shop));
            $this->data[$name] = $own === '' || in_array($own, $this->usual($name, every: true), true) ? $this->usual($name) : $own;
        }
    }

    protected function checkMore(): void
    {
        $number = self::international((string) ($this->data['number'] ?? ''));

        if ($number !== '' && (strlen($number) < 8 || strlen($number) > 15)) {
            $this->problems['number'] = __('widget::shop_settings.whatsapp.bad_number');
        } elseif ($number === '' && ($this->data['whatsapp'] ?? false)) {
            $this->problems['number'] = __('widget::shop_settings.whatsapp.needs_number');
        }

        foreach (self::TEXTS as $name) {
            $max = $name === 'message' ? 300 : ($name === 'title' ? 120 : 60);
            if (mb_strlen($this->text($name)) > $max) {
                $this->problems[$name] = __('widget::shop_settings.whatsapp.too_long');
            }
        }
    }

    protected function saveMore(): void
    {
        $shop = $this->shopId();
        $number = self::international((string) ($this->data['number'] ?? ''));

        $number === '' ? Settings::clear('widget.whatsapp_number', $shop) : Settings::set('widget.whatsapp_number', $number, $shop);
        $this->data['number'] = $number;

        foreach (self::TEXTS as $name) {
            $text = $this->text($name);
            // The usual wording (in any language), or nothing, follows the usual wording.
            $usual = $text === '' || in_array($text, $this->usual($name, every: true), true);
            $usual ? Settings::clear("widget.whatsapp_{$name}", $shop) : Settings::set("widget.whatsapp_{$name}", $text, $shop);
            $this->data[$name] = $usual ? $this->usual($name) : $text;
        }
    }

    protected function saved(): void
    {
        PageCache::forgetShop($this->shopId());
    }

    /**
     * Digits only, the way WhatsApp links need them: "050-123 4567" is an Israeli mobile, so its leading
     * 0 becomes 972; "+44 …" and "0044 …" keep their country.
     */
    public static function international(string $number): string
    {
        $digits = (string) preg_replace('/\D/', '', $number);

        if (str_starts_with($digits, '00')) {
            return substr($digits, 2);
        }

        if (! str_starts_with(trim($number), '+') && preg_match('/^0\d{8,9}$/', $digits) === 1) {
            return '972'.substr($digits, 1);
        }

        return $digits;
    }

    /** An example number where the shop's clock runs: an Israeli mobile in Israel, an international one elsewhere. */
    public function numberExample(): string
    {
        return Shop::israeli(AnsweringZone::for($this->shopId())) ? '050-0000000' : '+1 555 0100';
    }

    private function text(string $name): string
    {
        return (string) preg_replace('/^\s+|\s+$/u', '', (string) ($this->data[$name] ?? ''));
    }

    /**
     * The usual wording in the shop's language, what its shoppers read (or in every language, to tell it
     * from the shop's own): one line, as the field shows it, the usual message breaking before the page's address.
     *
     * @return ($every is true ? list<string> : string)
     */
    private function usual(string $name, bool $every = false): string|array
    {
        $line = fn (string $locale): string => (string) preg_replace('/\s*\R\s*/u', ' ', (string) __("widget::bank.contact.{$name}", [], $locale));

        if ($every) {
            return array_map($line, Locales::supported());
        }

        $shop = Filament::getTenant();

        return $line(Locales::forShop($shop instanceof Shop ? (string) $shop->content_locale : null));
    }
}
