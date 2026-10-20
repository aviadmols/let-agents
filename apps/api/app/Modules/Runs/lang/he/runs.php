<?php

return [
    'singular' => 'פעולה',
    'plural' => 'פעולות סוכנים',
    'statuses' => [
        'running' => 'רץ',
        'succeeded' => 'הצליח',
        'failed' => 'נכשל',
    ],
    'triggers' => [
        'manual' => 'ידני',
        'schedule' => 'מתוזמן',
        'webhook' => 'עדכון מהחנות',
        'system' => 'שלב בתהליך',
    ],
    'fields' => [
        'started_at' => 'התחיל',
        'finished_at' => 'הסתיים',
        'status' => 'מצב',
        'agent' => 'סוכן',
        'action' => 'פעולה',
        'shop' => 'חנות',
        'system' => 'מערכת',
        'summary' => 'תוצאה',
        'duration' => 'משך',
        'tokens' => 'טוקנים (קלט / פלט)',
        'tokens_detail' => 'קלט :input, פלט :output, מה־cache :cached',
        'cost' => 'עלות',
        'trigger' => 'הופעל על ידי',
        'user' => 'משתמש',
        'error' => 'שגיאה',
        'provider' => 'ספק',
        'model' => 'מודל',
        'input' => 'קלט',
        'output' => 'פלט',
    ],
    'sections' => [
        'overview' => 'פרטים',
        'result' => 'תוצאה',
        'usage' => 'שימוש במודל',
        'data' => 'קלט ופלט',
    ],
    'duration' => [
        'ms' => ':value מ״ש',
        'seconds' => ':value שנ׳',
    ],
    'summaries' => [
        'done' => 'הושלם.',
        'unexpected_error' => 'הפעולה נכשלה בשגיאה לא צפויה. הפרטים בשדה השגיאה.',
    ],
    'empty' => [
        'heading' => 'עוד אין פעולות',
        'description' => 'כל פעולה של סוכן, כמו בדיקת חיבור או קריאה למודל, תופיע כאן בזמן אמת.',
    ],
];
