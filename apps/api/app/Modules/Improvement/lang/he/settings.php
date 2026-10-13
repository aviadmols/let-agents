<?php

return [
    'analyst_provider' => [
        'label' => 'ספק המודל שמציע',
        'description' => 'anthropic כברירת מחדל. חייב להיות ממשפחה אחרת מהבודק, אחרת הבדיקה נעצרת.',
    ],
    'analyst_model' => [
        'label' => 'המודל שמציע',
        'description' => 'למשל claude-sonnet-5-5. רץ פעם ביום, רק כשיש משהו חדש.',
    ],
    'analyst_input_usd_per_million' => [
        'label' => 'מחיר קלט של המודל שמציע, למיליון טוקנים',
        'description' => 'לפי המחירון של הספק.',
    ],
    'analyst_output_usd_per_million' => [
        'label' => 'מחיר פלט של המודל שמציע, למיליון טוקנים',
        'description' => 'לפי המחירון של הספק. טוקני חשיבה נספרים כפלט.',
    ],
    'analyst_max_output_tokens' => [
        'label' => 'תקרת טוקנים לתשובת המודל שמציע',
        'description' => 'כולל חשיבה. נמוך מדי עלול להשאיר תשובה ריקה.',
    ],
    'auditor_provider' => [
        'label' => 'ספק המודל שבודק',
        'description' => 'openai כברירת מחדל. משפחה אחרת מהמודל שמציע, כדי שלא יטעו באותו אופן.',
    ],
    'auditor_model' => [
        'label' => 'המודל שבודק',
        'description' => 'למשל gpt-5.4-mini.',
    ],
    'auditor_input_usd_per_million' => [
        'label' => 'מחיר קלט של המודל שבודק, למיליון טוקנים',
        'description' => 'לפי המחירון של הספק.',
    ],
    'auditor_output_usd_per_million' => [
        'label' => 'מחיר פלט של המודל שבודק, למיליון טוקנים',
        'description' => 'לפי המחירון של הספק.',
    ],
    'auditor_max_output_tokens' => [
        'label' => 'תקרת טוקנים לתשובת המודל שבודק',
        'description' => 'כולל חשיבה.',
    ],
    'max_proposals' => [
        'label' => 'הצעות ביום לכל היותר',
        'description' => 'החזקות ביותר קודם.',
    ],
    'evidence_days' => [
        'label' => 'כמה ימים אחורה נבדקים',
        'description' => 'חיפושים ושאלות מהתקופה הזו.',
    ],
    'min_searches' => [
        'label' => 'חיפוש בלי תוצאות נספר מ־',
        'description' => 'כמה פעמים חיפוש צריך לא למצוא כלום כדי להיכנס לבדיקה.',
    ],
    'digest_email' => [
        'label' => 'כתובת לסיכום היומי',
        'description' => 'הסיכום נשלח לכאן כל בוקר. ריק: הסיכום נשמר בפאנל בלבד.',
    ],
    'answer_writer_provider' => [
        'label' => 'ספק המודל שכותב תשובות',
        'description' => 'openai או anthropic. ממשפחה אחרת מהבודק.',
    ],
    'answer_writer_model' => [
        'label' => 'המודל שכותב תשובות',
        'description' => 'למשל gpt-5.4-mini. רץ רק כשהצוות לוחץ.',
    ],
    'answer_writer_input_usd_per_million' => [
        'label' => 'מחיר קלט, כותב התשובות',
        'description' => 'דולר למיליון טוקנים.',
    ],
    'answer_writer_output_usd_per_million' => [
        'label' => 'מחיר פלט, כותב התשובות',
        'description' => 'דולר למיליון טוקנים.',
    ],
    'answer_writer_max_output_tokens' => [
        'label' => 'אורך תשובה מרבי, כותב התשובות',
        'description' => 'בטוקנים.',
    ],
    'answer_checker_provider' => [
        'label' => 'ספק המודל שבודק תשובות',
        'description' => 'openai או anthropic. ממשפחה אחרת מהכותב.',
    ],
    'answer_checker_model' => [
        'label' => 'המודל שבודק תשובות',
        'description' => 'למשל claude-haiku-4-5.',
    ],
    'answer_checker_input_usd_per_million' => [
        'label' => 'מחיר קלט, בודק התשובות',
        'description' => 'דולר למיליון טוקנים.',
    ],
    'answer_checker_output_usd_per_million' => [
        'label' => 'מחיר פלט, בודק התשובות',
        'description' => 'דולר למיליון טוקנים.',
    ],
    'answer_checker_max_output_tokens' => [
        'label' => 'אורך תשובה מרבי, בודק התשובות',
        'description' => 'בטוקנים.',
    ],
];
