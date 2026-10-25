<?php

return [
    'stock' => 'בדיקת מלאי: :products מוצרים עברו מבמלאי לאזל או חזרה.',
    'refreshed' => 'רענון מחירים ומלאי: :updated מוצרים השתנו, :removed ירדו מהחנות.',
    'choices_read' => 'עמודי מוצר: נקראו :read, :changed השתנו; :with מוצרים מבקשים בחירה (אורך, מידה) לפני הוספה לסל.',
    'changes_synced' => 'שינויים מהחנות: :created מוצרים חדשים, :updated השתנו, :removed ירדו מהחנות.',
    'site_pages' => 'דפי האתר: :new חדשים, :changed השתנו, :removed ירדו מהאתר.',
    'site_pages_none' => 'דפי האתר: לחנות אין כתובת ציבורית לקרוא ממנה.',
    'synced' => 'נקראו :products {products:מוצר/מוצרים}: :created חדשים, :updated השתנו, :removed כבר לא מפורסמים. :categories {categories:קטגוריה/קטגוריות}, :content {content:מאמר/מאמרים}.',
    'synced_capped' => 'הקריאה נעצרה במגבלה של :limit מוצרים: נקראו :products, :created חדשים, :updated השתנו. שום מוצר לא סומן כהוסר. כדי לקרוא את השאר מעלים את המגבלה בהגדרות.',
    'no_connection' => 'לחנות הזו אין חיבור. מוסיפים אותו ב״חיבור חנויות״.',
    'pages_sampled' => 'נבדקו :read דפי מוצר, לפחות אחד מכל סוג (:kinds סוגים): גרסאות של מוצר שמקושרות כמוצרים נפרדים ב־:siblings, מנוי ב־:subscriptions, בחירות שמופיעות רק בדף: :fields.',
    // A storefront closed to visitors (StorefrontLock): what each reading of its pages did instead.
    'locked' => [
        'sample' => 'דפי המוצר: החנות סגורה למבקרים (בסיסמה, בתחזוקה או בדף ״בקרוב״), ולכן לא נקרא אף דף. המוצרים מגיעים מ־:platform, ודפיהם ייקראו כשהחנות תיפתח.',
        'site' => 'דפי האתר: החנות סגורה למבקרים (בסיסמה, בתחזוקה או בדף ״בקרוב״), ולכן הדפים מגיעים בינתיים מ־:platform. נוקו דפים שהציגו רק את דף הכניסה: :cleared.',
        'choices' => 'דפי המוצר: החנות סגורה למבקרים, ולכן הבחירות שבהם (אורך, מידה) ייקראו כשהחנות תיפתח.',
    ],
];
