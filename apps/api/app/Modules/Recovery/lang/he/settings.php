<?php

return [
    'wait_minutes' => [
        'label' => 'אחרי כמה זמן סל נחשב נטוש',
        'description' => 'בדקות. רק אז נכתב הדוח.',
    ],
    'keep_days' => [
        'label' => 'כמה זמן לשמור סלים',
        'description' => 'בימים. אחר כך הסל והמייל נמחקים.',
    ],
    'reports_per_run' => [
        'label' => 'דוחות בריצה',
        'description' => 'כמה סלים לכל היותר בכל ריצה שעתית.',
    ],
    'popup_title' => [
        'label' => 'כותרת הפופאפ',
        'description' => 'ריק: הכותרת ברירת המחדל.',
    ],
    'popup_text' => [
        'label' => 'טקסט הפופאפ',
        'description' => 'ריק: הטקסט ברירת המחדל.',
    ],
    'popup_button' => [
        'label' => 'כפתור המשך',
        'description' => 'ריק: הכפתור ברירת המחדל.',
    ],
    'popup_skip' => [
        'label' => 'קישור דילוג',
        'description' => 'ריק: הקישור ברירת המחדל.',
    ],
    'popup_consent' => [
        'label' => 'נוסח ההסכמה',
        'description' => 'מה הגולש מאשר כשהוא משאיר מייל. ריק: נוסח ברירת המחדל.',
    ],
    'writer_provider' => [
        'label' => 'ספק כותב הדוח',
        'description' => 'anthropic או openai.',
    ],
    'writer_model' => [
        'label' => 'מודל כותב הדוח',
        'description' => 'מודל חזק; הקלט קצר.',
    ],
    'writer_input_usd_per_million' => [
        'label' => 'מחיר קלט לכותב (דולר למיליון)',
        'description' => 'לחישוב העלות.',
    ],
    'writer_output_usd_per_million' => [
        'label' => 'מחיר פלט לכותב (דולר למיליון)',
        'description' => 'לחישוב העלות.',
    ],
    'writer_max_output_tokens' => [
        'label' => 'אורך תשובה לכותב',
        'description' => 'בטוקנים.',
    ],
    'checker_provider' => [
        'label' => 'ספק הבודק',
        'description' => 'ממשפחה אחרת מהכותב.',
    ],
    'checker_model' => [
        'label' => 'מודל הבודק',
        'description' => 'מודל קטן וזול.',
    ],
    'checker_input_usd_per_million' => [
        'label' => 'מחיר קלט לבודק (דולר למיליון)',
        'description' => 'לחישוב העלות.',
    ],
    'checker_output_usd_per_million' => [
        'label' => 'מחיר פלט לבודק (דולר למיליון)',
        'description' => 'לחישוב העלות.',
    ],
    'checker_max_output_tokens' => [
        'label' => 'אורך תשובה לבודק',
        'description' => 'בטוקנים.',
    ],
];
