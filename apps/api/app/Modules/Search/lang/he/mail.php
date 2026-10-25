<?php

return [
    'scan_check' => [
        'subject' => 'בדיקת החיפוש ב-:store: :failed מתוך :tried לא מצאו',
        'title' => 'בדיקת החיפוש ב-:store',
        'mode_apply' => 'מה שנכשל תוקן לחנות הזאת בלבד',
        'mode_dry' => 'מצב ניסיון: שום דבר לא נכתב, רק מה שהיה מתוקן',
        'summary' => ':tried חיפושים נבדקו, :failed לא הביאו את מה שצריך. עלות: $:cost מתוך תקרה של $:cap.',
        'failed_title' => 'חיפושים שלא הביאו את מה שצריך',
        'found' => ':n תוצאות',
        'after_ok' => 'אחרי התיקון: נמצא',
        'after_still' => 'אחרי התיקון: עדיין לא',
        'why' => [
            'kind' => 'סוג מוצר',
            'colour' => 'סוג וצבע',
            'audience' => 'סוג ולמי',
            'name' => 'שם מוצר',
            'latin' => 'שם לועזי בעברית',
            'shopper' => 'כמו של גולש',
        ],
        'mended_title' => 'מה תוקן',
        'would_title' => 'מה היה מתוקן',
        'tag' => 'תגית «:tag» על :n מוצרים שבתיאור שלהם כתוב כך',
        'outcome' => [
            'resolved' => 'יציג',
            'refused' => 'נדחה בבדיקה',
            'none' => 'לא נמצאה התאמה',
        ],
        'synonym' => 'מילה של החנות: «:word»',
        'questions_title' => 'שאלות לעוזר',
        'answer' => [
            'answered' => 'ענה',
            'not' => 'לא ענה',
        ],
        'button' => 'לפרטים המלאים',
    ],
];
