<?php

// An invitation to a shop's panel: the email, and the page its link opens (InvitationController).
return [
    'mail' => [
        'subject' => 'הזמנה ל-:shop ב-Lets',
        'title' => 'הוזמנתם ל-:shop',
        'by' => ':name הזמין אתכם לפאנל של :shop ב-Lets.',
        'by_team' => 'הוזמנתם לפאנל של :shop ב-Lets.',
        'as' => 'התפקיד שלכם: :role (:what).',
        'button' => 'לקבוע סיסמה ולהיכנס',
        'expires' => 'הקישור בתוקף עד :date. אם הוא פג, בקשו מבעל החשבון לשלוח שוב.',
    ],
    'page' => [
        'title' => 'הצטרפות ל-:shop',
        'lead' => 'נכנסים בתור :role: :what. בוחרים שם וסיסמה, וזהו.',
        'email' => 'מייל',
        'name' => 'שם',
        'password' => 'סיסמה',
        'password_again' => 'שוב, לאימות',
        'submit' => 'לקבוע סיסמה ולהיכנס',
        'name_short' => 'השם קצר מדי.',
        'password_short' => 'הסיסמה צריכה לפחות :min תווים.',
        'password_mismatch' => 'הסיסמאות לא זהות.',
        'joined_title' => 'נוספתם ל-:shop',
        'joined' => 'החשבון שלכם כבר קיים, ועכשיו יש לו גישה גם לחנות הזו בתור :role. נכנסים עם הסיסמה הרגילה.',
        'sign_in' => 'לכניסה',
        'closed_title' => 'הקישור הזה לא פעיל',
        'closed' => [
            'gone' => 'הקישור לא נמצא. אולי נשלחה הזמנה חדשה יותר: חפשו אותה במייל.',
            'used' => 'ההזמנה הזו כבר נוצלה. נכנסים עם המייל והסיסמה.',
            'expired' => 'תוקף ההזמנה פג. בקשו מבעל החשבון לשלוח אותה שוב.',
        ],
    ],
];
