<?php

namespace App\Modules\Demo\Actions;

use App\Core\Facades\Features;
use App\Core\Facades\Settings;
use App\Modules\Demo\Mail\DemoMail;
use App\Modules\Demo\Models\DemoSite;
use App\Modules\Demo\Support\PublicSite;
use App\Modules\Tenancy\Enums\ShopStatus;
use App\Modules\Tenancy\Models\Shop;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Throwable;

/**
 * Someone asks to see Lets on a store: its address, their email, what the site is and what matters in its search.
 * Before anything is read or paid for: the address is a public site that answers, it is not a store already on
 * Lets, and the day's demos (demo.per_day) and this email's (demo.per_email_per_day) are not used up. Then a code
 * goes to the email; nothing is built until it comes back (VerifyDemo).
 */
final class StartDemo
{
    public const KINDS = ['store', 'content', 'both'];

    public const MATTERS = ['look', 'content', 'both'];

    /** The language of the store's site and its search, as whoever asks chooses it. */
    public const LANGUAGES = ['he', 'en'];

    /** The code is good this long, and this many times wrong. */
    public const CODE_MINUTES = 15;

    public const CODE_TRIES = 5;

    /**
     * @param  array{url?: mixed, email?: mixed, kind?: mixed, matters?: mixed, language?: mixed, consent?: mixed}  $input
     * @return array{token: string}|array{error: string}
     */
    public function handle(array $input, ?string $ip, string $locale): array
    {
        $email = mb_strtolower(trim((string) ($input['email'] ?? '')));
        $host = PublicSite::host((string) ($input['url'] ?? ''));

        $error = match (true) {
            ! Features::enabled('demo.enabled') => 'closed',
            $host === null => 'bad_url',
            filter_var($email, FILTER_VALIDATE_EMAIL) === false => 'bad_email',
            ! in_array($input['kind'] ?? null, self::KINDS, true) || ! in_array($input['matters'] ?? null, self::MATTERS, true)
                || ! in_array($input['language'] ?? null, self::LANGUAGES, true) => 'choose',
            default => self::siteProblem($host, $email),
        };

        if ($error !== null) {
            return ['error' => $error];
        }

        $code = (string) random_int(100000, 999999);
        $site = DemoSite::query()->create([
            'token' => Str::random(40),
            'url' => 'https://'.$host,
            'host' => $host,
            'email' => $email,
            'consent' => (bool) ($input['consent'] ?? false),
            'choices' => ['kind' => $input['kind'], 'matters' => $input['matters'], 'language' => $input['language']],
            'locale' => $locale,
            'code_hash' => Hash::make($code),
            'code_expires_at' => now()->addMinutes(self::CODE_MINUTES),
            'status' => DemoSite::PENDING,
            'ip_hash' => $ip === null ? null : hash('sha256', 'demo|'.$ip),
        ]);

        self::sendCode($site, $code);

        return ['token' => $site->token];
    }

    /**
     * What stops a demo of this site, before anything is read or paid for: no public address, a store already on
     * Lets, the day's demos used up, this email's (when it asks for a new demo, not again for the same one), a site
     * that does not answer, a site on neither Shopify nor WordPress (Lets could never connect it). Null when nothing does.
     */
    public static function siteProblem(string $host, ?string $email): ?string
    {
        return match (true) {
            ! PublicSite::public($host) => 'bad_url',
            Shop::query()->where('domain', $host)->where('status', '!=', ShopStatus::Demo->value)->exists() => 'already',
            DemoSite::query()->where('created_at', '>=', now()->startOfDay())->count() >= (int) Settings::get('demo.per_day') => 'busy',
            $email !== null && DemoSite::query()->where('email', $email)->where('created_at', '>=', now()->subDay())->count() >= (int) Settings::get('demo.per_email_per_day') => 'too_many',
            PublicSite::get($host, '/') === null => 'unreachable',
            ! PublicSite::supported($host) => 'not_supported',
            default => null,
        };
    }

    /** A new code for the same demo: the old one stops working. */
    public static function newCode(DemoSite $site): void
    {
        $code = (string) random_int(100000, 999999);
        $site->forceFill(['code_hash' => Hash::make($code), 'code_expires_at' => now()->addMinutes(self::CODE_MINUTES), 'code_tries' => 0])->save();
        self::sendCode($site, $code);
    }

    private static function sendCode(DemoSite $site, string $code): void
    {
        try {
            Mail::to($site->email)->locale(DemoSite::LOCALE)->send(new DemoMail('code', $site, ['code' => $code]));
        } catch (Throwable $e) {
            Log::warning('demo: the code could not be sent', ['demo' => $site->id, 'error' => $e->getMessage()]);
        }
    }
}
