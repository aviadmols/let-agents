<?php

return [
    'embedding_provider' => [
        'label' => 'ספק מודל הווקטורים',
        'description' => 'openai היום. ספק חדש הוא driver במודול ה־AI ושם הספק כאן.',
    ],
    'embedding_model' => [
        'label' => 'מודל הווקטורים',
        'description' => 'למשל text-embedding-3-small. החלפת מודל בונה את כל האינדקס מחדש, כי וקטורים של מודלים שונים לא משווים זה לזה.',
    ],
    'embedding_dimensions' => [
        'label' => 'מספר ממדים',
        'description' => '0 משאיר את ברירת המחדל של המודל. מודלים שתומכים בזה יכולים להחזיר וקטור קצר יותר וזול יותר לאחסון.',
    ],
    'embedding_usd_per_million' => [
        'label' => 'מחיר מודל הווקטורים, למיליון טוקנים',
        'description' => 'לפי המחירון של הספק. משמש להערכה לפני כל קריאה ולרישום העלות.',
    ],
    'chunk_chars' => [
        'label' => 'אורך קטע',
        'description' => 'כמה תווים לכל היותר בקטע אחד, כולל הכותרת שבראשו. שינוי חותך מחדש את הכול.',
    ],
    'max_chunks_per_run' => [
        'label' => 'קטעים חדשים בריצה אחת',
        'description' => 'כמה קטעים לכל היותר נשלחים למודל בריצה אחת. מה שנשאר ממתין לריצה הבאה.',
    ],
    'purchase_window_days' => [
        'label' => 'כמה זמן אחורה ההזמנות נספרות',
        'description' => 'להתאמות ולמסמכי הרכישות. כולל את הזמנות העבר שהתוסף שלח.',
    ],
    'match_provider' => [
        'label' => 'ספק מודל ההתאמות',
        'description' => 'openai או anthropic, לפי ה־driver שיש במודול ה־AI ולפי המפתח השמור בפאנל.',
    ],
    'match_model' => [
        'label' => 'מודל ההתאמות',
        'description' => 'מזהה המודל אצל הספק, למשל gpt-5.4-mini.',
    ],
    'match_reasoning_effort' => [
        'label' => 'כמה המודל חושב לפני שבוחר',
        'description' => 'יותר חשיבה נותנת בחירות זהירות יותר, אבל איטיות ויקרות יותר. לא כל ספק תומך בזה.',
        'options' => [
            'model_default' => 'ברירת המחדל של המודל',
            'minimal' => 'מינימלי',
            'low' => 'נמוך',
            'medium' => 'בינוני',
        ],
    ],
    'match_input_usd_per_million' => [
        'label' => 'מחיר קלט של מודל ההתאמות, למיליון טוקנים',
        'description' => 'לפי המחירון של הספק. עדיף להעריך גבוה.',
    ],
    'match_output_usd_per_million' => [
        'label' => 'מחיר פלט של מודל ההתאמות, למיליון טוקנים',
        'description' => 'לפי המחירון של הספק. טוקני חשיבה נספרים כפלט.',
    ],
    'match_max_output_tokens' => [
        'label' => 'תקרת טוקנים לתשובה',
        'description' => 'כולל טוקני חשיבה. נמוך מדי עלול להשאיר את התשובה ריקה.',
    ],
    'match_products_per_run' => [
        'label' => 'מוצרים בריצת התאמה אחת',
        'description' => 'הנמכרים ביותר קודם. מוצר שלא השתנה מאז הפעם הקודמת לא נשאל שוב ולא נספר.',
    ],
    'match_candidates' => [
        'label' => 'כמה מועמדים המודל רואה',
        'description' => 'מכל המקורות יחד, לסירוגין, כדי שאף מקור לא ידחק את האחרים.',
    ],
    'match_max_share_of_cap' => [
        'label' => 'חלק מתקרת ה־AI החודשית להתאמות',
        'description' => 'ההתאמות נעצרות כשההוצאה החודשית מגיעה לחלק הזה מהתקרה, כדי שלעוזר לגולשים תמיד יישאר תקציב. 0.5 הוא חצי.',
    ],
    'complements_per_product' => [
        'label' => 'משלימים לכל מוצר',
        'description' => 'כמה בחירות של משלימים הקוד מקבל לכל היותר למוצר.',
    ],
    'alternatives_per_product' => [
        'label' => 'חלופות לכל מוצר',
        'description' => 'כמה בחירות של חלופות הקוד מקבל לכל היותר למוצר. 0 מכבה חלופות מהמודל.',
    ],
    'min_alternative_similarity' => [
        'label' => 'דמיון מינימלי לחלופה',
        'description' => 'חלופה שהמודל בחר נדחית אם הטקסט שלה רחוק מדי במשמעות, בין 0 ל־1. משלים לא צריך להיות דומה.',
    ],
    'image_provider' => [
        'label' => 'ספק הווקטורים לתמונות',
        'description' => 'gemini היום. ספק שמכניס תמונות ומילים לאותו מרחב הוא driver במודול ה־AI.',
    ],
    'image_model' => [
        'label' => 'מודל הווקטורים לתמונות',
        'description' => 'למשל gemini-embedding-2. החלפה בונה מחדש את כל וקטורי התמונות.',
    ],
    'image_dimensions' => [
        'label' => 'ממדים לווקטור תמונה',
        'description' => '768 מספיק לדמיון חזותי וזול לאחסון. 0 משאיר את ברירת המחדל של המודל.',
    ],
    'image_usd_per_image' => [
        'label' => 'מחיר לתמונה',
        'description' => 'לפי המחירון של הספק. משמש להערכה לפני כל קריאה ולרישום העלות.',
    ],
    'image_text_usd_per_million' => [
        'label' => 'מחיר מילים לחיפוש בתמונות, למיליון טוקנים',
        'description' => 'לפי המחירון של הספק.',
    ],
    'max_images_per_run' => [
        'label' => 'תמונות חדשות בריצה אחת',
        'description' => 'מה שנשאר ממתין ללילה הבא.',
    ],
    'image_max_kb' => [
        'label' => 'גודל תמונה מקסימלי',
        'description' => 'תמונה גדולה מזה מדולגת ומסומנת.',
    ],
    'min_look_alike' => [
        'label' => 'דמיון חזותי מינימלי',
        'description' => 'מתחת לזה מוצר לא מוצע כנראה דומה.',
    ],
    'images_per_part' => [
        'label' => 'תמונות בכל חלק של סריקה',
        'description' => 'סריקה גדולה רצה בחלקים קטנים: עדכון או נפילה של השרת מאבדים חלק אחד לכל היותר, והסריקה ממשיכה לבד.',
    ],
    'caption_provider' => [
        'label' => 'ספק לתיאור תמונות',
        'description' => 'anthropic או openai.',
    ],
    'caption_model' => [
        'label' => 'מודל לתיאור תמונות',
        'description' => 'מודל שרואה תמונות.',
    ],
    'caption_input_usd_per_million' => [
        'label' => 'מחיר קלט לתיאור (דולר למיליון)',
        'description' => 'לחישוב העלות.',
    ],
    'caption_output_usd_per_million' => [
        'label' => 'מחיר פלט לתיאור (דולר למיליון)',
        'description' => 'לחישוב העלות.',
    ],
    'caption_max_output_tokens' => [
        'label' => 'אורך תיאור מקסימלי',
        'description' => 'בטוקנים.',
    ],
    'content_weight' => [
        'label' => 'משקל התוכן מול המראה',
        'description' => '0: רק מראה. 1: רק תוכן. 0.5: חצי־חצי.',
    ],
];
