<?php

return [
    'singular' => 'ספק AI',
    'plural' => 'מפתחות AI',
    'roles' => [
        'openai' => 'כותב את כל הטקסט שהגולשים רואים ועונה בצ׳אט.',
        'anthropic' => 'מנתח: חילוץ מאפיינים, סיווג, ביקורת ותכנון.',
        'gemini' => 'וקטורים לתמונות המוצרים, כדי למצוא מוצרים שנראים דומה ולחפש בתמונות לפי מילים.',
    ],
    'statuses' => [
        'untested' => 'לא נבדק',
        'connected' => 'תקין',
        'failed' => 'נכשל',
    ],
    'sections' => [
        'key' => 'מפתח API',
        'key_help' => 'המפתח נשמר מוצפן ומשמש את כל החנויות. הבדיקה מושכת את רשימת המודלים, והיא לא עולה טוקנים.',
        'status' => 'מצב',
        'models' => 'מודלים זמינים',
        'models_help' => 'הרשימה כפי שהספק מחזיר אותה למפתח הזה, החדשים ראשונים. מכאן בוחרים מודל לכל תפקיד.',
    ],
    'fields' => [
        'provider' => 'ספק',
        'api_key' => 'מפתח API',
        'api_key_keep' => 'ריק ישאיר את המפתח הקיים.',
        'status' => 'מצב',
        'key_hint' => 'מפתח',
        'last_checked_at' => 'בדיקה אחרונה',
        'never' => 'לא נבדק',
        'last_error' => 'שגיאה אחרונה',
        'model_count' => 'מודלים',
    ],
    'errors' => [
        'invalid_key' => 'מפתח לא תקין',
        'permission_denied' => 'אין הרשאה',
        'rate_limited' => 'הגבלת קצב',
        'provider_error' => 'שגיאת ספק',
        'unreachable' => 'אין חיבור',
    ],
    'actions' => [
        'test' => 'בדיקת מפתח',
    ],
    'notifications' => [
        'connected' => 'המפתח תקין',
        'failed' => 'בדיקת המפתח נכשלה',
        'view_run' => 'לפרטי הפעולה',
    ],
    'empty' => [
        'heading' => 'עוד לא הוזנו מפתחות',
        'description' => 'הוסיפו מפתח של OpenAI לכתיבה ומפתח של Anthropic לניתוח.',
    ],
];
