<?php

namespace App\Modules\Assistant\Support;

/**
 * The store speaks as "we", never as one man or one woman (answer_site: "יש לנו", "נשמח"): a writer that slips into the
 * first person singular ("אם תרצו, אני יכול לפרט", Rocks 9.11) is put back into the store's plural by code, after the
 * model and before the answer is shown or kept. Only the forms that cannot mean anything else: "אוכל" alone may be food,
 * so it is read only before a verb ("אוכל לעזור"); "נשלח", "נראה" or "נמצא" would read as another word, so "אשלח",
 * "אראה" and "אמצא" are left as written.
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
        '/(?<!\p{L})(ו?)אני\s+(?:יכול|יכולה)(?!\p{L})/u' => '$1נוכל',
        '/(?<!\p{L})(ו?)אוכל(?=\s+(?:'.self::CAN_DO.')(?!\p{L}))/u' => '$1נוכל',
        '/(?<!\p{L})(ו?)(?:אני\s+)?אשמח(?!\p{L})/u' => '$1נשמח',
        '/(?<!\p{L})(ו?)אני\s+(?:ממליץ|ממליצה)(?!\p{L})/u' => '$1אנחנו ממליצים',
        '/(?<!\p{L})(ו?)אני\s+(?:מציע|מציעה)(?!\p{L})/u' => '$1אנחנו מציעים',
        '/(?<!\p{L})(ו?)אני\s+(?:כאן|פה)(?!\p{L})/u' => '$1אנחנו כאן',
        '/(?<!\p{L})(ו?)אעזור(?!\p{L})/u' => '$1נעזור',
        '/(?<!\p{L})(ו?)אבדוק(?!\p{L})/u' => '$1נבדוק',
        '/(?<!\p{L})(ו?)אפרט(?!\p{L})/u' => '$1נפרט',
        '/(?<!\p{L})(ו?)אמליץ(?!\p{L})/u' => '$1נמליץ',
        '/(?<!\p{L})(ו?)אציע(?!\p{L})/u' => '$1נציע',
        '/(?<!\p{L})(ו?)אסביר(?!\p{L})/u' => '$1נסביר',
        '/(?<!\p{L})(ו?)אמיין(?!\p{L})/u' => '$1נמיין',
        '/(?<!\p{L})(ו?)אכוון(?!\p{L})/u' => '$1נכוון',
    ];

    public static function plural(string $text): string
    {
        if ($text === '' || ! preg_match('/[\x{0590}-\x{05FF}]/u', $text)) {
            return $text;
        }

        return (string) preg_replace(array_keys(self::FORMS), array_values(self::FORMS), $text);
    }
}
