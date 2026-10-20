<?php

namespace App\Modules\Assistant\Tests;

use App\Modules\Assistant\Support\ReviewWords;
use PHPUnit\Framework\TestCase;

/**
 * The shop manager reads what shoppers looked for and what the store could add, never that the
 * assistant invented a book or named a product the store does not have (Sela's report of 19.10).
 */
final class ReviewWordsTest extends TestCase
{
    public function test_the_shop_reads_no_sentence_about_the_assistants_mistakes(): void
    {
        $summary = 'עוזר החנות עשה עבודה טובה בעיקר בתשובות על ספרים ספציפיים כמו החטוף והמדינה שבדרך, וגם על מדיניות החנות. אך יש בעיות: הוא המציא שם של ספרים שאינם קיימים, סירב לענות על שאלות שהיה אפשר לעזור בהן, ולפעמים התבלבל בין ספרים שונים. קונים חיפשו בעיקר ספרים ספציפיים, טיפים על עשירות, וספרים על אירועים קרובים בעת.';

        $this->assertSame('עוזר החנות עשה עבודה טובה בעיקר בתשובות על ספרים ספציפיים כמו החטוף והמדינה שבדרך, וגם על מדיניות החנות. קונים חיפשו בעיקר ספרים ספציפיים, טיפים על עשירות, וספרים על אירועים קרובים בעת.', ReviewWords::forMerchant($summary));
        $this->assertNull(ReviewWords::forMerchant('הציע ספר לא קיים בחנות, שם המציא.'));
        $this->assertNull(ReviewWords::forMerchant('It recommended a book that does not exist.'));

        $this->assertSame(['להוסיף ספרי ילדים: קונים חיפשו כאלה שלא קיימים בחנות'], ReviewWords::improvements([
            'להוסיף ספרי ילדים: קונים חיפשו כאלה שלא קיימים בחנות',
            'לוודא שהעוזר לא ממציא שמות ספרים',
        ]), 'what shoppers looked for and the store lacks is the shop\'s to act on');
    }
}
