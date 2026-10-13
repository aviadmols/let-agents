<?php

return [
    'cap_reached' => 'הקריאה למודל נחסמה: החודש הוצאו :spent דולר, ההערכה לקריאה הזו :estimate דולר, והתקרה החודשית היא :cap דולר.',
    'shop_cap_reached' => 'הקריאה למודל נחסמה: החנות הוציאה החודש :spent דולר, ההערכה לקריאה הזו :estimate דולר, והמכסה החודשית שלה היא :cap דולר.',

    // The strip at the top of every merchant screen. A share, never an amount.
    'strip' => [
        'title' => 'ניצול המערכת החודש',
        'share' => ':percent% מהמכסה החודשית',
        'renews' => 'מתחדש ב־:date',
        'date_format' => 'j בF',
        'near' => 'קרוב לסוף המכסה',
        'used_up' => 'המכסה החודשית נוצלה. האתר ממשיך לענות לגולשים; סריקות הלילה וההצעות ממתינות לחודש הבא.',
        'used_up_all' => 'המכסה החודשית נוצלה. האתר עונה רק ממה שכבר שמור עד החודש הבא.',
        'set_by' => 'המכסה נקבעת על ידי Let\'s. להגדלה, פנו אלינו.',
    ],

    // The same strip for the operator: amounts.
    'operator_strip' => [
        'shop' => ':shop החודש',
        'spent' => '$:spent מתוך $:cap (:percent%)',
        'every' => 'כל החנויות החודש',
        'every_spent' => '$:spent מתוך $:caps במכסות החנויות · תקרה כוללת $:global',
        'edit' => 'מכסות החנויות',
    ],

    // Operator screen: every shop's allowance.
    'screen' => [
        'title' => 'מכסות החנויות',
        'subheading' => 'כמה כל חנות הוציאה על מודלים החודש מתוך המכסה שלה. ברירת המחדל :default דולר לחודש; מנהל החנות רואה רק אחוז.',
        'columns' => [
            'shop' => 'חנות',
            'used' => 'ניצול החודש',
            'spent' => 'הוצאה',
            'cap' => 'מכסה',
            'calls' => 'קריאות',
            'top' => 'הכי יקר',
            'state' => 'מצב',
        ],
        'own' => 'מותאם',
        'state' => [
            'ok' => 'תקין',
            'near' => 'קרוב למכסה',
            'over' => 'המכסה נוצלה',
            'idle' => 'בלי קריאות',
        ],
        'edit' => 'מכסה',
        'form' => [
            'title' => 'מכסה חודשית · :shop',
            'cap' => 'מכסה חודשית (דולר)',
            'stops' => 'בסוף המכסה לעצור גם את מה שהגולשים מבקשים',
            'reset' => 'לחזור לברירת המחדל ($:default)',
            'save' => 'לשמור',
            'cancel' => 'ביטול',
            'saved' => 'המכסה של :shop נשמרה: $:cap לחודש.',
            'cleared' => 'המכסה של :shop חזרה לברירת המחדל.',
            'note' => 'מנהל החנות יראה רק את אחוז הניצול. השינוי חל מיד.',
        ],
        'totals' => 'סך הכל: $:spent מתוך $:caps · :calls קריאות',
        'none' => 'אין חנויות עדיין.',
    ],
];
