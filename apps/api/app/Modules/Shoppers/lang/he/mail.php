<?php

// The email to the store's team when a shopper leaves details to be called back (CallbackReceivedMail).
return [
    'callback' => [
        'subject' => 'מישהו מבקש שתחזרו אליו · :shop',
        'title' => 'מישהו השאיר פרטים ומבקש שתחזרו אליו',
        'name' => 'שם',
        'channel' => ['phone' => 'טלפון', 'email' => 'מייל'],
        'question' => 'על מה',
        'page' => 'איפה',
        'at' => 'הושארו ב־:at',
        'open' => 'לכל הממתינים לכם',
        'why' => 'המייל הזה נשלח כי אתם בצוות החנות, והגולש הסכים שתחזרו אליו. אפשר לכבות אותו בהגדרות.',
    ],
];
