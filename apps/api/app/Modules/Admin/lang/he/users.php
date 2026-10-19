<?php

return [
    'singular' => 'משתמש',
    'plural' => 'משתמשים',
    'sections' => [
        'account' => 'חשבון',
        'access' => 'הרשאות',
    ],
    'fields' => [
        'name' => 'שם',
        'email' => 'אימייל',
        'password' => 'סיסמה',
        'password_keep' => 'ריק ישאיר את הסיסמה הנוכחית.',
        'locale' => 'שפת ממשק',
        'locale_auto' => 'אוטומטי, לפי הדפדפן',
        'is_operator' => 'מפעיל מערכת',
        'is_operator_help' => 'גישה מלאה לכל החנויות, לעלויות, למודלים ולהגדרות.',
        'shops' => 'חנויות',
        'shops_help' => 'החנויות שהמשתמש רואה בפאנל הסוחר.',
    ],
    'roles' => [
        'owner' => 'בעלים',
        'member' => 'צוות',
        'viewer' => 'צפייה',
    ],
    // What each role may do, as the users page and the invitation say it.
    'role_text' => [
        'owner' => 'הכל, כולל מנוי ומשתמשים',
        'member' => 'עונה לשאלות ופניות, עורך ידע ותגיות',
        'viewer' => 'רואה אנליטיקס ודוחות בלבד',
    ],
];
