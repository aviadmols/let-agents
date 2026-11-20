<?php

namespace App\Modules\Assistant\Support;

/**
 * The store speaks as "we", never as one man or one woman (answer_site: "יש לנו", "נשמח"): a writer that slips into the
 * first person singular ("אם תרצו, אני יכול לפרט", Rocks 9.11) is put back into the store's plural by code, after the
 * model and before the answer is shown or kept. Only the forms that cannot mean anything else: "אוכל" alone may be food,
 * so it is read only before a verb ("אוכל לעזור"); "נשלח", "נראה" or "נמצא" would read as another word, so "אשלח",
 * "אראה" and "אמצא" are left as written. And the shopper is one person of no fixed gender (SHOPPER below).
 */
final class StoreVoice
{
    /** The verbs "אוכל" may stand before as "I can": before anything else it may be food ("אוכל לכלבים"). */
    private const CAN_DO = 'לעזור|לפרט|לבדוק|להציע|להמליץ|למצוא|למיין|לכוון|להסביר|לענות|להראות|להשוות';

    /**
     * pattern => the store's words; a word is a word only between non-letters, and may carry "ו" ("ואעזור" is
     * "ונעזור"), kept as $1
     */
    private const FORMS = [
        '/(?<!\p{L})(ו|ש|כש)?אני\s+(?:יכול|יכולה)(?!\p{L})/u' => '$1נוכל',
        '/(?<!\p{L})(ו|ש|כש)?אוכל(?=\s+(?:'.self::CAN_DO.')(?!\p{L}))/u' => '$1נוכל',
        '/(?<!\p{L})(ו?)(?:אני\s+)?אשמח(?!\p{L})/u' => '$1נשמח',
        // what the store would do, said as one woman or man ("הייתי מתחילה מטבעות הווינטג'", Rocks)
        '/(?<!\p{L})(ו?)הייתי\s+(?:מתחיל|מתחילה)(?!\p{L})/u' => '$1היינו מתחילים',
        '/(?<!\p{L})(ו?)הייתי\s+(?:ממליץ|ממליצה)(?!\p{L})/u' => '$1היינו ממליצים',
        '/(?<!\p{L})(ו?)הייתי\s+(?:בוחר|בוחרת)(?!\p{L})/u' => '$1היינו בוחרים',
        '/(?<!\p{L})(ו?)הייתי\s+(?:הולך|הולכת)(?!\p{L})/u' => '$1היינו הולכים',
        '/(?<!\p{L})(ו|ש|כש)?אני\s+(?:ממליץ|ממליצה)(?!\p{L})/u' => '$1אנחנו ממליצים',
        '/(?<!\p{L})(ו|ש|כש)?אני\s+(?:מציע|מציעה)(?!\p{L})/u' => '$1אנחנו מציעים',
        '/(?<!\p{L})(ו|ש|כש)?אני\s+(?:כאן|פה)(?!\p{L})/u' => '$1אנחנו כאן',
        '/(?<!\p{L})(ו|ש|כש)?אעזור(?!\p{L})/u' => '$1נעזור',
        '/(?<!\p{L})(ו|ש|כש)?אבדוק(?!\p{L})/u' => '$1נבדוק',
        '/(?<!\p{L})(ו|ש|כש)?אפרט(?!\p{L})/u' => '$1נפרט',
        '/(?<!\p{L})(ו|ש|כש)?אמליץ(?!\p{L})/u' => '$1נמליץ',
        '/(?<!\p{L})(ו|ש|כש)?אציע(?!\p{L})/u' => '$1נציע',
        '/(?<!\p{L})(ו|ש|כש)?אסביר(?!\p{L})/u' => '$1נסביר',
        '/(?<!\p{L})(ו|ש|כש)?אמיין(?!\p{L})/u' => '$1נמיין',
        '/(?<!\p{L})(ו|ש|כש)?אכוון(?!\p{L})/u' => '$1נכוון',
    ];

    /**
     * Where a word that may be "you" or "she" ("תוכל", "תרצה") is the shopper's: at a sentence's start, after a comma, or
     * after a question word or a place ("מה תרצה לדעת", "אצלנו תוכל למצוא"). After a noun it is the noun's: "הכלה תרצה".
     */
    private const ADDRESSED = '(^|[.,:;!?\n]\s*|(?<!\p{L})(?:מה|איזה|איזו|אילו|האם|אולי|גם|כאן|פה|אצלנו|באתר|בחנות)\s+)';

    /**
     * The shopper, addressed as one person in forms that read the same for a man and a woman (answer_site: "חיפשת", "לך",
     * "אפשר"): a writer's slip into one gender ("איזה אורך היית מעדיפה?", Rocks 20.11; "אם תרצי", "תוכלי למצוא") is said
     * again in the neutral form. Only "you" forms: the same verbs about a product or a person named ("הטבעת צריכה", "הכלה
     * תרצה") are left as written. $1 keeps what stood before ("ו", "ש", "כש", or the place it was read from).
     */
    private const SHOPPER = [
        '/(?<!\p{L})(ו|ש|כש)?היית\s+(?:מעדיף|מעדיפה)(?!\p{L})/u' => '$1עדיף לך',
        '/(?<!\p{L})(ו|ש|כש)?היית\s+(?:מחפש|מחפשת)(?!\p{L})/u' => '$1חיפשת',
        // "אם תרצי," alone is an offer ("אם מתאים לך, נוכל…"); before what is wanted, it is that ("אם רצית משהו מוחשי יותר")
        '/(?<!\p{L})(ו|ש|כש)?אם\s+(?:תרצה|תרצי|(?:אתה|את)\s+רוצה)(?=\s*(?:[,.:;!?\n]|$))/u' => '$1אם מתאים לך',
        '/(?<!\p{L})(ו|ש|כש)?אם\s+(?:תרצה|תרצי|(?:אתה|את)\s+רוצה)(?!\p{L})/u' => '$1אם רצית',
        '/(?<!\p{L})(ו|ש|כש)?אם\s+(?:(?:אתה|את)\s+)?(?:מעוניין|מעוניינת)(?!\p{L})/u' => '$1אם יש לך עניין',
        '/(?<!\p{L})(ו|ש|כש)?(?:אתה|את)\s+(?:מחפש|מחפשת)(?!\p{L})/u' => '$1חיפשת',
        '/(?<!\p{L})(ו|ש|כש)?(?:אתה|את)\s+(?:מעדיף|מעדיפה)(?!\p{L})/u' => '$1עדיף לך',
        '/(?<!\p{L})(ו|ש|כש)?(?:אתה|את)\s+(?:צריך|צריכה)(?!\p{L})/u' => '$1צריך',
        '/(?<!\p{L})(ו|ש|כש)?(?:אתה|את)\s+(?:משתמש|משתמשת)(?!\p{L})/u' => '$1משתמשים',
        '/(?<!\p{L})(ו|ש|כש)?(?:אתה|את)\s+רוצה(?!\p{L})/u' => '$1מתאים לך',
        // a woman's only: never "she" ("תוכלי", "תרצי")
        '/(?<!\p{L})(ו|ש|כש)?תוכלי(?!\p{L})/u' => '$1אפשר',
        '/(?<!\p{L})(ו|ש|כש)?תרצי(?!\p{L})/u' => '$1רצית',
        // a man's, or "she": only where it is the shopper's
        '/'.self::ADDRESSED.'(ו?)תוכל(?!\p{L})/u' => '$1$2אפשר',
        '/'.self::ADDRESSED.'(ו?)תרצה(?!\p{L})/u' => '$1$2רצית',
        // a question opened with the shopper's state ("מחפשת משהו מסוים?")
        '/(^|[.!?\n]\s*)(?:מחפש|מחפשת)(?!\p{L})/u' => '$1חיפשת',
        '/(^|[.!?\n]\s*)(?:מעדיף|מעדיפה)(?!\p{L})/u' => '$1עדיף לך',
        '/(^|[.!?\n]\s*)(?:מעוניין|מעוניינת)(?!\p{L})/u' => '$1יש לך עניין',
    ];

    public static function plural(string $text): string
    {
        if ($text === '' || ! preg_match('/[\x{0590}-\x{05FF}]/u', $text)) {
            return $text;
        }

        $forms = self::FORMS + self::SHOPPER;

        return (string) preg_replace(array_keys($forms), array_values($forms), $text);
    }
}
