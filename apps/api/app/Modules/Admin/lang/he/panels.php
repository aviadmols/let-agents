<?php

return [
    'locale' => [
        'label' => 'שפה',
        'short' => [
            'he' => 'עב',
            'en' => 'EN',
        ],
        'switch_to' => 'החלפה ל־:language',
        'names' => [
            'he' => 'עברית',
            'en' => 'English',
        ],
    ],
    'switch' => [
        'operator' => 'פאנל המפעיל',
        'merchant' => 'תצוגת סוחר',
    ],
    'account' => [
        'viewing' => 'החנות שלכם',
        'as_operator' => 'אתם צופים בחנות :shop כמפעיל. כל מה שכאן שייך לחנות הזו בלבד.',
        'switch' => 'מעבר לחנות אחרת',
    ],
    'shop' => [
        'label' => 'חנות',
        'every' => 'כל החנויות',
    ],
    // Sidebar groups. Operator: overview, shops, content, discovery, shoppers, analytics, system.
    // Merchant: overview, analytics, search, on_page, shoppers, settings.
    'nav' => [
        'overview' => 'סקירה',
        'shops' => 'חנויות',
        'catalog' => 'קטלוג ותוכן',
        'scan' => 'סריקה ומידע מוצרים',
        'discovery' => 'חיפוש וגילוי',
        'shoppers' => 'גולשים',
        'analytics' => 'אנליטיקס',
        'system' => 'מערכת',
        'search' => 'חיפוש',
        'on_page' => 'הרכיב בעמוד',
        'settings' => 'הגדרות',
    ],
    'view_as_shop' => 'לראות כמו מנהל החנות',
    'top' => [
        'live' => 'פועל בחנות',
        'preview' => 'בתצוגה מקדימה',
        'as_operator' => 'צפייה כמפעיל · חזרה',
        'operator' => 'מפעיל המערכת',
        'open_store' => 'לפתוח את החנות',
        'theme' => 'מצב תצוגה',
        'logout' => 'יציאה',
        'themes' => [
            'light' => 'בהיר',
            'dark' => 'כהה',
            'system' => 'כמו המערכת',
        ],
        'brand' => 'הפאנל לחנות',
    ],
    // The shop's screens, top to bottom (MerchantNavigation::SECTIONS).
    'screens' => [
        'analytics' => 'אנליטיקס',
        'sessions' => 'סשנים',
        'timeline' => 'ציר זמן',
        'inbox' => 'הודעות ותובנות',
        'knowledge' => 'מרכז הידע',
        'scan' => 'סיכום סריקה',
        'settings' => 'הגדרות',
    ],
    'close' => 'סגירה',
    // A short hint the first time each screen opens (merchant-section).
    'hints' => [
        'analytics' => 'כל מה שהגולשים עשו עם Lets. "לכל הרשימה" פותח את הרשימה המלאה, עם חיפוש ומיון.',
        'sessions' => 'כל ביקור הוא רשימת פעולות. מסננים למעלה, ולוחצים על סשן כדי לראות מה הגולש עשה, לפי הסדר.',
        'timeline' => 'כל מה שקרה בחשבון: סריקות, מוצרים חדשים, תשובות ושינויים. לחיצה על שורה פותחת את הפרטים.',
        'inbox' => 'מה שמחכה לכם. תשובה שתאשרו כאן נכנסת לידע, וכל גולש הבא יקבל אותה.',
        'knowledge' => 'מה ש-Lets יודע על החנות. כל שורה היא תשובה אפשרית לגולש. משהו לא מדויק? מסירים, והוא לא ייקרא שוב.',
        'scan' => 'איך Lets הבין כל מוצר ודף. בוחרים מימין, ורואים תגיות, מפרט ומה התמונה גילתה.',
        'settings' => 'כל הגדרה בעמוד משלה. בוחרים מהתפריט, והגולשים רואים את השינוי בתוך כמה דקות.',
    ],
];
