<?php

return [
    'input_selector' => [
        'label' => 'תיבות החיפוש באתר',
        'description' => 'בורר CSS של שדות החיפוש שהחיפוש של Let Agents מתחבר אליהם. אפשר כמה, מופרדים בפסיקים.',
    ],
    'results' => [
        'label' => 'איפה מוצגות כל התוצאות',
        'description' => 'בחלון של Let Agents מעל העמוד, או בדף תוצאות החיפוש של האתר, בסדר של Let Agents.',
        'options' => [
            'panel' => 'בחלון של Let Agents',
            'drawer' => 'במגירה בצד, עם שדה לחיפוש ולשאלות בתחתית',
            'page' => 'בדף החיפוש של האתר',
        ],
    ],
    'suggestions' => [
        'label' => 'מוצרים בהצעות',
        'description' => 'כמה מוצרים מוצגים מתחת לתיבה בזמן ההקלדה.',
    ],
    'results_per_group' => [
        'label' => 'תוצאות בכל קבוצה',
        'description' => 'כמה מוצרים, מדריכים או קטגוריות מוצגים בכל קבוצה בתוצאות המלאות.',
    ],
    'semantic_results' => [
        'label' => 'תוצאות לפי משמעות',
        'description' => 'כמה מסמכים לכל היותר נלקחים מהאינדקס לפי משמעות לפני המיזוג עם החיפוש לפי כתיב. 0 מכבה.',
    ],
    'semantic_min_similarity' => [
        'label' => 'דמיון מינימלי לפי משמעות',
        'description' => 'מתחת לזה תוצאה לפי משמעות לא נכנסת. תלוי במודל הווקטורים: ל־text-embedding-3-small בסביבות 0.3.',
    ],
    'picture_min_similarity' => [
        'label' => 'דמיון מינימלי בין מילים לתמונה',
        'description' => 'מתחת לזה מוצר לא נכנס לתוצאות לפי התמונה. תלוי במודל: ל־gemini-embedding-2 בסביבות 0.35.',
    ],
    'semantic_min_chars' => [
        'label' => 'אורך מינימלי לחיפוש לפי משמעות',
        'description' => 'שאילתה קצרה מזה מחופשת לפי כתיב בלבד.',
    ],
    'requests_per_minute' => [
        'label' => 'בקשות חיפוש לדקה',
        'description' => 'לכל כתובת IP ואתר. מגן מפני הצפה.',
    ],
    'keep_days' => [
        'label' => 'כמה זמן נשמרות סטטיסטיקות החיפוש',
        'description' => 'ספירות של חיפושים ולחיצות ישנות מזה נמחקות בלילה.',
    ],
    'photo_max_kb' => [
        'label' => 'גודל תמונה מקסימלי לחיפוש',
        'description' => 'תמונה גדולה מזה נדחית. הדפדפן מקטין תמונות לפני השליחה, כך שבדרך כלל זה לא קורה.',
    ],
    'photos_per_day' => [
        'label' => 'חיפושים לפי תמונה ביום',
        'description' => 'לכל חנות. מעבר לזה כפתור המצלמה עונה שהשירות לא זמין היום. 0 מכבה.',
    ],
    'photo_requests_per_minute' => [
        'label' => 'חיפושים לפי תמונה לדקה',
        'description' => 'לכל כתובת IP ואתר.',
    ],
    'photo_min_similarity' => [
        'label' => 'דמיון מינימלי לתמונה שהועלתה',
        'description' => 'מתחת לזה מוצר לא מוצג. תמונה של גולש שונה מתמונת מוצר ברקע ובתאורה, ולכן הסף נמוך מהדמיון בין מוצרים.',
    ],
    'resolve_provider' => [
        'label' => 'ספק המודל שמתאים',
        'description' => 'openai כברירת מחדל. חייב להיות ממשפחה אחרת מהבודק.',
    ],
    'resolve_model' => [
        'label' => 'המודל שמתאים',
        'description' => 'למשל gpt-5.4-mini.',
    ],
    'resolve_input_usd_per_million' => [
        'label' => 'מחיר קלט של המודל שמתאים, למיליון טוקנים',
        'description' => 'לפי המחירון של הספק.',
    ],
    'resolve_output_usd_per_million' => [
        'label' => 'מחיר פלט של המודל שמתאים, למיליון טוקנים',
        'description' => 'לפי המחירון של הספק.',
    ],
    'resolve_max_output_tokens' => [
        'label' => 'תקרת טוקנים לתשובת המודל שמתאים',
        'description' => 'כולל חשיבה.',
    ],
    'check_provider' => [
        'label' => 'ספק המודל שבודק',
        'description' => 'anthropic כברירת מחדל. משפחה אחרת מהמודל שמתאים.',
    ],
    'check_model' => [
        'label' => 'המודל שבודק',
        'description' => 'למשל claude-haiku-4-5.',
    ],
    'check_input_usd_per_million' => [
        'label' => 'מחיר קלט של המודל שבודק, למיליון טוקנים',
        'description' => 'לפי המחירון של הספק.',
    ],
    'check_output_usd_per_million' => [
        'label' => 'מחיר פלט של המודל שבודק, למיליון טוקנים',
        'description' => 'לפי המחירון של הספק.',
    ],
    'check_max_output_tokens' => [
        'label' => 'תקרת טוקנים לתשובת המודל שבודק',
        'description' => 'כולל חשיבה.',
    ],
    'resolve_per_run' => [
        'label' => 'חיפושים לפתרון בלילה אחד',
        'description' => 'הנפוצים ביותר קודם. השאר מחכים ללילה הבא.',
    ],
    'resolve_min_searches' => [
        'label' => 'חיפוש נפתר מ־',
        'description' => 'כמה פעמים חיפוש צריך לא למצוא כלום כדי להישלח לפתרון.',
    ],
    'resolve_candidates' => [
        'label' => 'כמה מועמדים המודל רואה',
        'description' => 'הקרובים ביותר לפי משמעות, מוצרים ומדריכים.',
    ],
    'resolve_days' => [
        'label' => 'כמה ימים אחורה נספרים',
        'description' => 'חיפושים ריקים מהתקופה הזו.',
    ],
    'tags_provider' => [
        'label' => 'ספק המודל שכותב תגיות',
        'description' => 'openai כברירת מחדל. הבודק הוא המודל שבודק את פתרון החיפושים, ממשפחה אחרת.',
    ],
    'tags_model' => [
        'label' => 'המודל שכותב תגיות',
        'description' => 'למשל gpt-5.4-mini.',
    ],
    'tags_input_usd_per_million' => [
        'label' => 'מחיר קלט של כותב התגיות, למיליון טוקנים',
        'description' => 'לפי המחירון של הספק.',
    ],
    'tags_output_usd_per_million' => [
        'label' => 'מחיר פלט של כותב התגיות, למיליון טוקנים',
        'description' => 'לפי המחירון של הספק.',
    ],
    'tags_max_output_tokens' => [
        'label' => 'תקרת טוקנים לתשובת כותב התגיות',
        'description' => 'לקבוצת דפים אחת, כולל חשיבה.',
    ],
    'tags_per_run' => [
        'label' => 'דפים לכתיבת תגיות בלילה אחד',
        'description' => 'דפים חדשים או שהמילים שלהם השתנו. השאר מחכים ללילה הבא.',
    ],
    'tags_per_page' => [
        'label' => 'תגיות לדף',
        'description' => 'לכל היותר.',
    ],
    'tags_batch' => [
        'label' => 'דפים בקריאה אחת למודל',
        'description' => 'יותר דפים בקריאה, פחות טוקנים על ההוראות.',
    ],
    'tags_min_results' => [
        'label' => 'תגית נשארת מ־',
        'description' => 'כמה תוצאות החיפוש צריך למצוא לתגית, חוץ מהדף עצמו.',
    ],
    'answers_in_index' => [
        'label' => 'תשובות בתיבת החיפוש',
        'description' => 'כמה תשובות שגולשים כבר קיבלו נכנסות לחיפוש, הנשאלות ביותר קודם. 0 כדי לא להציג תשובות.',
    ],
    'drawer_side' => [
        'label' => 'מאיזה צד נפתחת המגירה',
        'description' => 'התחלה היא ימין באתר בעברית ושמאל באתר באנגלית. בנייד המגירה תופסת את כל המסך.',
        'options' => ['start' => 'מצד ההתחלה (ימין בעברית)', 'end' => 'מצד הסוף (שמאל בעברית)'],
    ],
];
