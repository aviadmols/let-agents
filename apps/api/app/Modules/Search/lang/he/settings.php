<?php

return array (
  'input_selector' => 
  array (
    'label' => 'תיבות החיפוש באתר',
    'description' => 'בורר CSS של שדות החיפוש שהחיפוש של Let Agents מתחבר אליהם. אפשר כמה, מופרדים בפסיקים.',
  ),
  'results' => 
  array (
    'label' => 'איפה מוצגות כל התוצאות',
    'description' => 'בחלון של Let Agents מעל העמוד, או בדף תוצאות החיפוש של האתר, בסדר של Let Agents.',
    'options' => 
    array (
      'panel' => 'בחלון של Let Agents',
      'drawer' => 'במגירה בצד, עם שדה לחיפוש ולשאלות בתחתית',
      'page' => 'בדף החיפוש של האתר',
    ),
  ),
  'suggestions' => 
  array (
    'label' => 'מוצרים בהצעות',
    'description' => 'כמה מוצרים מוצגים מתחת לתיבה בזמן ההקלדה.',
  ),
  'results_per_group' => 
  array (
    'label' => 'תוצאות בכל קבוצה',
    'description' => 'כמה מוצרים, מדריכים או קטגוריות מוצגים בכל קבוצה בתוצאות המלאות.',
  ),
  'semantic_results' => 
  array (
    'label' => 'תוצאות לפי משמעות',
    'description' => 'כמה מסמכים לכל היותר נלקחים מהאינדקס לפי משמעות לפני המיזוג עם החיפוש לפי כתיב. 0 מכבה.',
  ),
  'semantic_min_similarity' => 
  array (
    'label' => 'דמיון מינימלי לפי משמעות',
    'description' => 'מתחת לזה תוצאה לפי משמעות לא נכנסת. תלוי במודל הווקטורים: ל־text-embedding-3-small בסביבות 0.3.',
  ),
  'picture_min_similarity' => 
  array (
    'label' => 'דמיון מינימלי בין מילים לתמונה',
    'description' => 'מתחת לזה מוצר לא נכנס לתוצאות לפי התמונה. תלוי במודל: ל־gemini-embedding-2 בסביבות 0.35.',
  ),
  'semantic_min_chars' => 
  array (
    'label' => 'אורך מינימלי לחיפוש לפי משמעות',
    'description' => 'שאילתה קצרה מזה מחופשת לפי כתיב בלבד.',
  ),
  'requests_per_minute' => 
  array (
    'label' => 'בקשות חיפוש לדקה',
    'description' => 'לכל כתובת IP ואתר. מגן מפני הצפה.',
  ),
  'keep_days' => 
  array (
    'label' => 'כמה זמן נשמרות סטטיסטיקות החיפוש',
    'description' => 'ספירות של חיפושים ולחיצות ישנות מזה נמחקות בלילה.',
  ),
  'photo_max_kb' => 
  array (
    'label' => 'גודל תמונה מקסימלי לחיפוש',
    'description' => 'תמונה גדולה מזה נדחית. הדפדפן מקטין תמונות לפני השליחה, כך שבדרך כלל זה לא קורה.',
  ),
  'photos_per_day' => 
  array (
    'label' => 'חיפושים לפי תמונה ביום',
    'description' => 'לכל חנות. מעבר לזה כפתור המצלמה עונה שהשירות לא זמין היום. 0 מכבה.',
  ),
  'photo_requests_per_minute' => 
  array (
    'label' => 'חיפושים לפי תמונה לדקה',
    'description' => 'לכל כתובת IP ואתר.',
  ),
  'photo_relative_gap' => 
  array (
    'label' => 'מרחק מקסימלי מההתאמה הטובה ביותר בתמונה',
    'description' => 'מוצג רק מה שקרוב לתוצאה הראשונה (למשל 0.06 = עד 6 נקודות פחות ממנה). ציוני הדמיון של תמונות צפופים, ולכן סף קבוע לבד מכניס גם מוצרים רחוקים.',
  ),
  'photo_in_stock_only' => 
  array (
    'label' => 'בחיפוש לפי תמונה, רק מה שבמלאי',
    'description' => 'מוצרים שאזלו לא מוצגים, אלא אם אין במלאי שום דבר דומה.',
  ),
  'photo_min_similarity' => 
  array (
    'label' => 'דמיון מינימלי לתמונה שהועלתה',
    'description' => 'מתחת לזה מוצר לא מוצג. תמונה של גולש שונה מתמונת מוצר ברקע ובתאורה, ולכן הסף נמוך מהדמיון בין מוצרים.',
  ),
  'resolve_provider' => 
  array (
    'label' => 'ספק המודל שמתאים',
    'description' => 'openai כברירת מחדל. חייב להיות ממשפחה אחרת מהבודק.',
  ),
  'resolve_model' => 
  array (
    'label' => 'המודל שמתאים',
    'description' => 'למשל gpt-5.4-mini.',
  ),
  'resolve_input_usd_per_million' => 
  array (
    'label' => 'מחיר קלט של המודל שמתאים, למיליון טוקנים',
    'description' => 'לפי המחירון של הספק.',
  ),
  'resolve_output_usd_per_million' => 
  array (
    'label' => 'מחיר פלט של המודל שמתאים, למיליון טוקנים',
    'description' => 'לפי המחירון של הספק.',
  ),
  'resolve_max_output_tokens' => 
  array (
    'label' => 'תקרת טוקנים לתשובת המודל שמתאים',
    'description' => 'כולל חשיבה.',
  ),
  'check_provider' => 
  array (
    'label' => 'ספק המודל שבודק',
    'description' => 'anthropic כברירת מחדל. משפחה אחרת מהמודל שמתאים.',
  ),
  'check_model' => 
  array (
    'label' => 'המודל שבודק',
    'description' => 'למשל claude-haiku-4-5.',
  ),
  'check_input_usd_per_million' => 
  array (
    'label' => 'מחיר קלט של המודל שבודק, למיליון טוקנים',
    'description' => 'לפי המחירון של הספק.',
  ),
  'check_output_usd_per_million' => 
  array (
    'label' => 'מחיר פלט של המודל שבודק, למיליון טוקנים',
    'description' => 'לפי המחירון של הספק.',
  ),
  'check_max_output_tokens' => 
  array (
    'label' => 'תקרת טוקנים לתשובת המודל שבודק',
    'description' => 'כולל חשיבה.',
  ),
  'resolve_per_run' => 
  array (
    'label' => 'חיפושים לפתרון בלילה אחד',
    'description' => 'הנפוצים ביותר קודם. השאר מחכים ללילה הבא.',
  ),
  'resolve_min_searches' => 
  array (
    'label' => 'חיפוש נפתר מ־',
    'description' => 'כמה פעמים חיפוש צריך לא למצוא כלום כדי להישלח לפתרון.',
  ),
  'resolve_candidates' => 
  array (
    'label' => 'כמה מועמדים המודל רואה',
    'description' => 'הקרובים ביותר לפי משמעות, מוצרים ומדריכים.',
  ),
  'resolve_days' => 
  array (
    'label' => 'כמה ימים אחורה נספרים',
    'description' => 'חיפושים ריקים מהתקופה הזו.',
  ),
  'tags_provider' => 
  array (
    'label' => 'ספק המודל שכותב תגיות',
    'description' => 'openai כברירת מחדל. הבודק הוא המודל שבודק את פתרון החיפושים, ממשפחה אחרת.',
  ),
  'tags_model' => 
  array (
    'label' => 'המודל שכותב תגיות',
    'description' => 'למשל gpt-5.4-mini.',
  ),
  'tags_input_usd_per_million' => 
  array (
    'label' => 'מחיר קלט של כותב התגיות, למיליון טוקנים',
    'description' => 'לפי המחירון של הספק.',
  ),
  'tags_output_usd_per_million' => 
  array (
    'label' => 'מחיר פלט של כותב התגיות, למיליון טוקנים',
    'description' => 'לפי המחירון של הספק.',
  ),
  'tags_max_output_tokens' => 
  array (
    'label' => 'תקרת טוקנים לתשובת כותב התגיות',
    'description' => 'לקבוצת דפים אחת, כולל חשיבה.',
  ),
  'tags_per_run' => 
  array (
    'label' => 'דפים לכתיבת תגיות בלילה אחד',
    'description' => 'דפים חדשים או שהמילים שלהם השתנו. השאר מחכים ללילה הבא.',
  ),
  'tags_per_page' => 
  array (
    'label' => 'תגיות לדף',
    'description' => 'לכל היותר.',
  ),
  'tags_batch' => 
  array (
    'label' => 'דפים בקריאה אחת למודל',
    'description' => 'יותר דפים בקריאה, פחות טוקנים על ההוראות.',
  ),
  'tags_min_results' => 
  array (
    'label' => 'תגית נשארת מ־',
    'description' => 'כמה תוצאות החיפוש צריך למצוא לתגית, חוץ מהדף עצמו.',
  ),
  'answers_in_index' => 
  array (
    'label' => 'תשובות בתיבת החיפוש',
    'description' => 'כמה תשובות שגולשים כבר קיבלו נכנסות לחיפוש, הנשאלות ביותר קודם. 0 כדי לא להציג תשובות.',
  ),
  'drawer_side' => 
  array (
    'label' => 'מאיזה צד נפתחת המגירה',
    'description' => 'התחלה היא ימין באתר בעברית ושמאל באתר באנגלית. בנייד המגירה תופסת את כל המסך.',
    'options' => 
    array (
      'start' => 'מצד ההתחלה (ימין בעברית)',
      'end' => 'מצד הסוף (שמאל בעברית)',
    ),
  ),
  'photo_tags_provider' => 
  array (
    'label' => 'ספק לקריאת תמונות',
    'description' => 'anthropic או openai.',
  ),
  'photo_tags_model' => 
  array (
    'label' => 'מודל לקריאת תמונות',
    'description' => 'מודל שרואה תמונות.',
  ),
  'photo_tags_input_usd_per_million' => 
  array (
    'label' => 'מחיר קלט (דולר למיליון)',
    'description' => 'לחישוב העלות ביומן.',
  ),
  'photo_tags_output_usd_per_million' => 
  array (
    'label' => 'מחיר פלט (דולר למיליון)',
    'description' => 'לחישוב העלות ביומן.',
  ),
  'photo_tags_max_output_tokens' => 
  array (
    'label' => 'אורך תשובה מקסימלי',
    'description' => 'בטוקנים.',
  ),
  'photo_sure_min' => 
  array (
    'label' => 'ביטחון מינימלי בקריאת תמונה',
    'description' => 'הקורא אומר כמה הוא בטוח (0 עד 1). מתחת לזה התמונה מקבלת מבט שני ממודל חזק יותר.',
  ),
  'photo_second_provider' => 
  array (
    'label' => 'ספק למבט שני על תמונה',
    'description' => 'anthropic או openai.',
  ),
  'photo_second_model' => 
  array (
    'label' => 'מודל למבט שני על תמונה',
    'description' => 'מודל חזק שרואה תמונות. נקרא רק כשיש ספק: לא נמצא מקום בחנות, ביטחון נמוך, או שתמונות המוצרים הקרובות אומרות אחרת.',
  ),
  'photo_second_input_usd_per_million' => 
  array (
    'label' => 'מחיר קלט למבט שני (דולר למיליון)',
    'description' => 'לחישוב העלות ביומן.',
  ),
  'photo_second_output_usd_per_million' => 
  array (
    'label' => 'מחיר פלט למבט שני (דולר למיליון)',
    'description' => 'לחישוב העלות ביומן.',
  ),
  'photo_second_max_output_tokens' => 
  array (
    'label' => 'אורך תשובה מקסימלי למבט שני',
    'description' => 'בטוקנים.',
  ),
  'photo_keep_days' => 
  array (
    'label' => 'כמה זמן נשמרות תמונות שחיפשו בהן',
    'description' => 'עותק מוקטן של כל תמונה שהועלתה לחיפוש נשמר לבדיקה ונמחק אחרי זה. תמונה שסומנה נכון או לא נכון נשארת, כחלק מסט הבדיקה.',
  ),
  'photo_same_similarity' => 
  array (
    'label' => 'דמיון שנחשב לאותה תמונה',
    'description' => 'תמונה שהועלתה ודומה לתמונת מוצר לפחות בזה (0 עד 1) היא התמונה של המוצר עצמו: הוא מוצג ראשון, לא משנה מה זוהה ואילו תגיות נבחרו.',
  ),
  'drawer_columns' => 
  array (
    'label' => 'כמה מוצרים בשורה במגירה',
    'description' => 'בחלון הצדדי של תוצאות החיפוש: שני מוצרים בכל שורה, או מוצר אחד גדול.',
    'options' => 
    array (
      2 => 'שני מוצרים בשורה',
      1 => 'מוצר אחד בשורה',
    ),
  ),
);
