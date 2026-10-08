<?php

return [
    'answer_model' => [
        'label' => 'מודל שכותב את התשובות',
        'description' => 'מזהה מודל של OpenAI מהרשימה שבמפתח, למשל gpt-5.4-mini.',
    ],
    'scope_model' => [
        'label' => 'מודל שבודק שהשאלה על המוצר',
        'description' => 'מודל קטן וזול. שאלה שלא על המוצר לא מגיעה למודל הכותב.',
    ],
    'reasoning_effort' => [
        'label' => 'כמה המודל חושב לפני שעונה',
        'description' => 'יותר חשיבה נותנת תשובות זהירות יותר, אבל איטיות ויקרות יותר.',
        'options' => [
            'model_default' => 'ברירת המחדל של המודל',
            'minimal' => 'מינימלי',
            'low' => 'נמוך',
            'medium' => 'בינוני',
        ],
    ],
    'answer_input_usd_per_million' => [
        'label' => 'מחיר קלט של המודל הכותב, למיליון טוקנים',
        'description' => 'לפי המחירון של OpenAI. משמש להערכה לפני כל קריאה ולרישום העלות. עדיף להעריך גבוה.',
    ],
    'answer_output_usd_per_million' => [
        'label' => 'מחיר פלט של המודל הכותב, למיליון טוקנים',
        'description' => 'לפי המחירון של OpenAI. טוקני חשיבה נספרים כפלט.',
    ],
    'scope_input_usd_per_million' => [
        'label' => 'מחיר קלט של המודל הבודק, למיליון טוקנים',
        'description' => 'לפי המחירון של OpenAI.',
    ],
    'scope_output_usd_per_million' => [
        'label' => 'מחיר פלט של המודל הבודק, למיליון טוקנים',
        'description' => 'לפי המחירון של OpenAI.',
    ],
    'answer_max_output_tokens' => [
        'label' => 'תקרת טוקנים לתשובה',
        'description' => 'כולל טוקני חשיבה. נמוך מדי עלול להשאיר את התשובה ריקה.',
    ],
    'max_question_chars' => [
        'label' => 'אורך שאלה מקסימלי',
        'description' => 'שאלה ארוכה יותר נחתכת.',
    ],
    'max_text_chars' => [
        'label' => 'אורך תיאור המוצר שנשלח עם השאלה',
        'description' => 'התשובה נכתבת מהעובדות שנבדקו, מהנקודות החשובות ומהתיאור עד האורך הזה.',
    ],
    'questions_per_visitor_per_day' => [
        'label' => 'שאלות חדשות לגולש ביום',
        'description' => 'שאלה שכבר נענתה לא נספרת.',
    ],
    'questions_per_address_per_day' => [
        'label' => 'שאלות חדשות מכתובת רשת אחת ביום',
        'description' => 'עוצר רובוט שמחליף זהות בכל שאלה. משרד או בית עם כמה אנשים חולקים כתובת אחת.',
    ],
    'questions_per_shop_per_day' => [
        'label' => 'שאלות חדשות לחנות ביום',
        'description' => 'מעבר לזה גולשים מקבלים הפניה לצוות החנות עד מחר.',
    ],
    'asks_per_minute' => [
        'label' => 'בקשות שאלה בדקה, לכל כתובת',
        'description' => 'הגנה מהצפה, גם לשאלות שנענות מהזיכרון.',
    ],
    'suggested_questions' => [
        'label' => 'שאלות מוצעות בחלונית',
        'description' => 'קודם השאלות שנשאלו הכי הרבה על המוצר, ואז שאלות כלליות.',
    ],
    'site_check_provider' => [
        'label' => 'ספק המודל שבודק תשובות מהחיפוש',
        'description' => 'anthropic כברירת מחדל. חייב להיות ממשפחה אחרת מהמודל שכותב.',
    ],
    'site_check_model' => [
        'label' => 'המודל שבודק תשובות מהחיפוש',
        'description' => 'למשל claude-haiku-4-5.',
    ],
    'site_check_input_usd_per_million' => [
        'label' => 'מחיר קלט של הבודק, למיליון טוקנים',
        'description' => 'לפי המחירון של הספק.',
    ],
    'site_check_output_usd_per_million' => [
        'label' => 'מחיר פלט של הבודק, למיליון טוקנים',
        'description' => 'לפי המחירון של הספק.',
    ],
    'site_passages' => [
        'label' => 'קטעים מהאתר לכל שאלה',
        'description' => 'הקרובים ביותר לפי משמעות. יותר קטעים, יותר טוקנים.',
    ],
    'site_min_similarity' => [
        'label' => 'קרבה מינימלית של קטע לשאלה',
        'description' => 'מתחת לזה הקטע לא נשלח, ואם אין אף קטע לא נשאל אף מודל כותב.',
    ],
    'site_retry_days' => [
        'label' => '"לא נמצא" נשאל שוב אחרי',
        'description' => 'ימים. כך דף שנוסף לאתר בינתיים ייכנס לתשובה.',
    ],
    'answer_provider' => [
        'label' => 'ספק המודל שעונה',
        'description' => 'openai כברירת מחדל.',
    ],
    'scope_provider' => [
        'label' => 'ספק המודל הקטן שבודק',
        'description' => 'בודק שהשאלה על הדף ושהתשובה נשענת עליו. כדאי ממשפחה אחרת מהמודל שעונה.',
    ],
    'search_whatsapp_message' => [
        'label' => 'ההודעה שנפתחת בוואטסאפ מהחיפוש',
        'description' => 'ריק להודעה הרגילה. :question תוחלף בשאלה של הגולש.',
    ],
    'review_provider' => [
        'label' => 'ספק המודל שבודק את השאלות בחיפוש',
        'description' => 'משפחה אחרת מהמודל שעונה.',
    ],
    'review_model' => [
        'label' => 'המודל שבודק את השאלות בחיפוש',
        'description' => 'למשל claude-haiku-4-5.',
    ],
    'review_input_usd_per_million' => [
        'label' => 'מחיר קלט של הבודק היומי, למיליון טוקנים',
        'description' => 'לפי המחירון של הספק.',
    ],
    'review_output_usd_per_million' => [
        'label' => 'מחיר פלט של הבודק היומי, למיליון טוקנים',
        'description' => 'לפי המחירון של הספק.',
    ],
    'review_max_output_tokens' => [
        'label' => 'תקרת טוקנים לדוח היומי',
        'description' => 'כולל חשיבה.',
    ],
    'review_max_asks' => [
        'label' => 'כמה שאלות נבדקות ביום',
        'description' => 'הראשונות של אותו יום.',
    ],
];
