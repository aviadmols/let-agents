<?php

namespace App\Modules\SearchSubscriptions\Support;

use App\Core\Facades\Settings;
use App\Modules\SearchSubscriptions\Models\SearchSubscription;
use Illuminate\Support\Facades\URL;

/**
 * What Search Subscriptions knows of an address and a search, in code: the address's keyed hash (the same in every
 * store, so an erasure finds it everywhere), the search's key (two spellings of one search are one), whether a search
 * may be kept at all, and the signed links an email carries (one tap, no login).
 */
final class Subscribers
{
    /** The longest search kept, in characters. */
    public const QUERY_MAX = 120;

    /**
     * Offensive words no search to be followed may hold, as queryKey() reads them, beside the operator's own
     * (search_subscriptions.blocked_words). A search is shown in emails and in the store's panel.
     */
    private const OFFENSIVE = ['fuck', 'shit', 'cunt', 'nigger', 'nigga', 'faggot', 'whore', 'slut', 'motherfucker', 'bitch',
        'זונה', 'שרמוטה', 'כוסאמק', 'כוסאמא', 'כוס אמא', 'כוס אמק', 'בן זונה', 'מניאק', 'חרא', 'קוקסינל'];

    public static function normalEmail(string $email): string
    {
        return mb_strtolower(trim($email));
    }

    public static function validEmail(string $email): bool
    {
        $email = self::normalEmail($email);

        return mb_strlen($email) <= 200 && filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }

    /** A keyed hash of the address: never the address itself, and the same in every store. */
    public static function hash(string $email): string
    {
        return hash_hmac('sha256', self::normalEmail($email), (string) config('app.key'));
    }

    /** The search as code reads it: lower case, no quotes or marks, one space between words. */
    public static function queryKey(string $query): string
    {
        $key = mb_strtolower($query);
        $key = (string) preg_replace('/[׳״\'"`’‘“”]/u', '', $key);
        $key = (string) preg_replace('/[^\p{L}\p{N}]+/u', ' ', $key);

        return mb_substr(trim($key), 0, 200);
    }

    /**
     * Whether the words may be kept as a search: not empty, not too long, and no personal detail or markup in them
     * (an email, a phone, a card's digits, a tag): a search is shown in emails and in the store's panel.
     */
    public static function keepable(string $query): bool
    {
        $query = trim($query);
        if ($query === '' || mb_strlen($query) > self::QUERY_MAX || self::queryKey($query) === '') {
            return false;
        }

        return preg_match('/[<>{}]|@|\b\S+@\S+\.\S+|(?:\d[\s\-]?){7,}/u', $query) !== 1 && ! self::offensive($query);
    }

    /**
     * Whether the words hold an offensive word: a Hebrew one as a whole word, with the letters that join a word before
     * it (ה, ו, ב, ל, מ, ש, כ); a Latin one as a whole word or with a common ending ("fucking"), never inside another
     * word ("shitake" is a mushroom).
     */
    public static function offensive(string $query): bool
    {
        $key = ' '.self::queryKey($query).' ';
        $own = preg_split('/\s*,\s*/u', (string) Settings::get('search_subscriptions.blocked_words')) ?: [];
        foreach (array_merge(self::OFFENSIVE, $own) as $word) {
            $word = self::queryKey((string) $word);
            if ($word === '') {
                continue;
            }
            $pattern = preg_match('/\p{Hebrew}/u', $word) === 1
                ? '/ [והבלמשכ]{0,2}'.preg_quote($word, '/').' /u'
                : '/ '.preg_quote($word, '/').'(?:s|es|ing|in|ed|er|ers|y)? /u';
            if (preg_match($pattern, $key) === 1) {
                return true;
            }
        }

        return false;
    }

    /** The words cut for a title or a line: about $max characters, never in the middle of a word when it can help it. */
    public static function cut(string $text, int $max): string
    {
        $text = trim($text);
        if (mb_strlen($text) <= $max) {
            return $text;
        }
        $cut = mb_substr($text, 0, $max - 1);
        $atWord = (string) preg_replace('/\s+\S*$/u', '', $cut);

        return (mb_strlen($atWord) >= $max * .6 ? $atWord : $cut).'…';
    }

    public static function confirmUrl(SearchSubscription $subscription): string
    {
        return URL::temporarySignedRoute('search_subscriptions.confirm', now()->addDays(7), ['subscription' => $subscription->id]);
    }

    public static function stopUrl(SearchSubscription $subscription): string
    {
        return URL::signedRoute('search_subscriptions.stop', ['subscription' => $subscription->id]);
    }

    public static function keepUrl(SearchSubscription $subscription): string
    {
        return URL::signedRoute('search_subscriptions.keep', ['subscription' => $subscription->id]);
    }

    public static function stopAllUrl(string $shopId, string $emailHash): string
    {
        return URL::signedRoute('search_subscriptions.stop_all', ['shop' => $shopId, 'address' => $emailHash]);
    }

    /** The one-tap unsubscribe a mail client posts to (List-Unsubscribe-Post, RFC 8058): the same address, by POST. */
    public static function oneClickUrl(string $shopId, string $emailHash): string
    {
        return URL::signedRoute('search_subscriptions.one_click', ['shop' => $shopId, 'address' => $emailHash]);
    }

    public static function manageUrl(string $shopId, string $emailHash): string
    {
        return URL::signedRoute('search_subscriptions.manage', ['shop' => $shopId, 'address' => $emailHash]);
    }

    public static function openPixelUrl(string $sendId): string
    {
        return URL::signedRoute('search_subscriptions.opened', ['send' => $sendId]);
    }

    /** A link to the store through us, so a click is counted; signed, so it leads nowhere but where the email said. */
    public static function clickUrl(string $sendId, string $to): string
    {
        return URL::signedRoute('search_subscriptions.clicked', ['send' => $sendId, 'to' => $to]);
    }

    /** The store's own link with the campaign's tags, so its analytics count the visits and the revenue. */
    public static function tagged(string $url): string
    {
        if (! preg_match('#^https?://#i', $url)) {
            return $url;
        }
        $tags = 'utm_source=lets&utm_medium=email&utm_campaign=search_subscription';
        $hash = '';
        if (($at = strpos($url, '#')) !== false) {
            $hash = substr($url, $at);
            $url = substr($url, 0, $at);
        }

        return $url.(str_contains($url, '?') ? '&' : '?').$tags.$hash;
    }
}
