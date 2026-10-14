<?php

return [
    'monthly_cap_usd' => [
        'label' => 'תקציב Try-On חודשי לחנות',
        'description' => 'כל בדיקת תמונה וכל מדידה נספרות. כשהוא נגמר, המדידה מחכה לחודש הבא. נפרד מתקציב ה־AI של החנות.',
    ],
    'job_cap_usd' => [
        'label' => 'המקסימום שמדידה אחת יכולה לעלות',
        'description' => 'כולל הניסיונות החוזרים. מדידה שתעלה יותר נעצרת ולא מוצגת.',
    ],
    'jobs_per_visitor_per_day' => [
        'label' => 'מדידות ללקוח ביום',
        'description' => 'לפי דפדפן.',
    ],
    'retries' => [
        'label' => 'ניסיונות חוזרים כשהבודק דוחה',
        'description' => 'עם התיקון של הבודק בפרומפט.',
    ],
    'retention_days' => [
        'label' => 'כמה ימים נשמרות התמונות',
        'description' => 'אחר כך נמחקות כל לילה. היומן שומר מה קרה וכמה עלה.',
    ],
    'similar_count' => [
        'label' => 'מוצרים דומים בחלון המדידה',
        'description' => 'מאותו סוג, מוכנים למדידה על אותה תמונה.',
    ],
    'pass_similarity' => [
        'label' => 'כמה המוצר צריך להיראות דומה כדי להיות מוצג (1 עד 5)',
        'description' => 'הציון של הבודק למוצר בתמונה.',
    ],
    'photo_max_kb' => [
        'label' => 'הגודל המקסימלי של תמונה שלקוח מעלה',
        'description' => 'היא מוקטנת לפני כל דבר אחר.',
    ],
    'generator_provider' => [
        'label' => 'הספק שמייצר את התמונות',
        'description' => 'המפתח שלו נשמר תחת ספקי AI.',
    ],
    'model_apparel' => [
        'label' => 'מודל התמונות לביגוד',
        'description' => 'מודל של Replicate, למשל google/nano-banana.',
    ],
    'model_jewelry' => [
        'label' => 'מודל התמונות לתכשיטים',
        'description' => 'מודל של Replicate, למשל google/nano-banana.',
    ],
    'model_furniture' => [
        'label' => 'מודל התמונות לרהיטים',
        'description' => 'מודל של Replicate, למשל google/nano-banana.',
    ],
    'generation_usd_per_image' => [
        'label' => 'מחיר של תמונה אחת',
        'description' => 'לפי המחירון של הספק למודל שנבחר. לאמת.',
    ],
    'gate_provider' => [
        'label' => 'הספק של בדיקת התמונה',
        'description' => 'מסתכל על התמונה של הלקוח ואומר מה רואים בה. הקוד מחליט.',
    ],
    'gate_model' => [
        'label' => 'המודל של בדיקת התמונה',
        'description' => 'מסתכל על התמונה של הלקוח ואומר מה רואים בה. הקוד מחליט.',
    ],
    'gate_input_usd_per_million' => [
        'label' => 'מחיר קלט של בדיקת התמונה, למיליון טוקנים',
        'description' => 'לפי המחירון של הספק.',
    ],
    'gate_output_usd_per_million' => [
        'label' => 'מחיר פלט של בדיקת התמונה, למיליון טוקנים',
        'description' => 'לפי המחירון של הספק.',
    ],
    'writer_provider' => [
        'label' => 'הספק של כותב הפרומפט',
        'description' => 'מדייק את התבנית של הצוות למוצר, לתמונה ולתשובות הלקוח. משפחה אחרת מזו של הבודק.',
    ],
    'writer_model' => [
        'label' => 'המודל של כותב הפרומפט',
        'description' => 'מדייק את התבנית של הצוות למוצר, לתמונה ולתשובות הלקוח. משפחה אחרת מזו של הבודק.',
    ],
    'writer_input_usd_per_million' => [
        'label' => 'מחיר קלט של כותב הפרומפט, למיליון טוקנים',
        'description' => 'לפי המחירון של הספק.',
    ],
    'writer_output_usd_per_million' => [
        'label' => 'מחיר פלט של כותב הפרומפט, למיליון טוקנים',
        'description' => 'לפי המחירון של הספק.',
    ],
    'checker_provider' => [
        'label' => 'הספק של בודק התמונה שנוצרה',
        'description' => 'מסתכל על המוצר, על התמונה ועל התוצאה זו לצד זו. משפחה אחרת מזו של הכותב.',
    ],
    'checker_model' => [
        'label' => 'המודל של בודק התמונה שנוצרה',
        'description' => 'מסתכל על המוצר, על התמונה ועל התוצאה זו לצד זו. משפחה אחרת מזו של הכותב.',
    ],
    'checker_input_usd_per_million' => [
        'label' => 'מחיר קלט של בודק התמונה שנוצרה, למיליון טוקנים',
        'description' => 'לפי המחירון של הספק.',
    ],
    'checker_output_usd_per_million' => [
        'label' => 'מחיר פלט של בודק התמונה שנוצרה, למיליון טוקנים',
        'description' => 'לפי המחירון של הספק.',
    ],
    'anchor_selector' => [
        'label' => 'איפה כפתור המדידה מופיע בעמוד המוצר',
        'description' => 'בורר CSS של אלמנט בעמוד המוצר, למשל .product-form או #product-actions. ריק: ליד טופס ההוספה לסל. חנות שהוסיפה את בלוק Let Agents לעמוד המוצר מקבלת את הכפתור בתוך הבלוק, אלא אם נכתב כאן בורר.',
    ],
    'anchor_position' => [
        'label' => 'ביחס לאלמנט',
        'description' => 'איפה הכפתור ביחס לאלמנט שבבורר.',
        'options' => [
            'after' => 'אחריו',
            'before' => 'לפניו',
            'start' => 'בתוכו, בהתחלה',
            'end' => 'בתוכו, בסוף',
        ],
    ],
];
