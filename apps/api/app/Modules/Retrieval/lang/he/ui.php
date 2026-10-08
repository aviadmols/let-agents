<?php

return [
    'scans' => [
        'title' => 'יומן סריקות',
        'subheading' => 'כל סנכרון קטלוג וכל סריקת תמונות של החנות, מה כל אחד עשה, וכל תמונה: נסרקה, ממתינה או לא נקראה ולמה.',
        'runs' => 'סריקות',
        'runs_about' => '40 האחרונות, מהחדשה לישנה. סריקה שרצה מתעדכנת לבד.',
        'no_runs' => 'עוד לא רצה סריקה לחנות הזאת.',
        'pictures' => 'תמונות המוצרים',
        'pictures_about' => 'נסרקו :scanned מתוך :total · ממתינות :pending · לא נקראו :failed',
        'no_pictures' => 'אין תמונות שמתאימות.',
        'search' => 'חיפוש לפי שם מוצר או מספר',
        'stopped' => 'נעצרה: :reason',
        'hosts' => 'התמונות נמצאות ב:',
        'cols' => [
            'when' => 'מתי',
            'what' => 'מה',
            'status' => 'מצב',
            'result' => 'תוצאה',
            'took' => 'משך',
            'picture' => 'תמונה',
            'product' => 'מוצר',
            'state' => 'מצב',
            'scanned_at' => 'נסרקה',
        ],
        'kinds' => [
            'catalog_syncer' => 'סנכרון קטלוג',
            'retrieval_indexer' => 'אינדקס טקסט',
            'retrieval_image_indexer' => 'סריקת תמונות',
        ],
        'trigger' => [
            'manual' => 'לחיצה במערכת',
            'schedule' => 'ריצת לילה',
            'webhook' => 'שינוי בחנות',
            'system' => 'אוטומטי',
        ],
        'status' => [
            'running' => 'רצה',
            'succeeded' => 'הצליחה',
            'failed' => 'נכשלה',
            'cut_off' => 'נקטעה',
        ],
        'filter' => [
            'all' => 'הכל',
            'scanned' => 'נסרקו',
            'pending' => 'ממתינות',
            'failed' => 'לא נקראו',
        ],
        'state' => [
            'scanned' => 'נסרקה',
            'pending' => 'ממתינה',
            'failed' => 'לא נקראה',
        ],
        'reasons' => [
            'unreachable' => 'הכתובת לא עונה',
            'not_image' => 'לא קובץ תמונה',
            'too_big' => 'קובץ גדול מדי',
            'other' => 'שגיאה אחרת',
        ],
    ],
];
