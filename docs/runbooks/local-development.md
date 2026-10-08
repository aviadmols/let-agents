# פיתוח מקומי

## דרישות

- PHP 8.4 עם pdo_sqlite, pdo_pgsql, intl, redis. במחשב הזה מגיע מ־Herd.
- Composer 2.
- Node 22 ומעלה, לבדיקות המפרטים.

מ־Git Bash, PHP של Herd לא נמצא ב־PATH. אפשר להשתמש בנתיב המלא, או לעבוד מ־PowerShell:

```sh
/c/Users/user/.config/herd/bin/php84/php.exe artisan module:list
```

## הקמה

```sh
cd apps/api
composer setup
php artisan admin:operator you@example.com --locale=he
php artisan serve
```

- פאנל המפעיל: http://localhost:8000/operator

סיסמה שאבדה (גם בפרודקשן, דרך `railway ssh --service api`): הסיסמה עוברת במשתנה סביבה, לא בשורת הפקודה.

```sh
LA_PW='the-new-password' php artisan admin:password you@example.com --password-env=LA_PW
```
- פאנל הסוחר: http://localhost:8000/merchant

מקומית עובדים על SQLite. Postgres עם pgvector רץ ב־CI, וב־staging וב־production.

## יצירת חנות לבדיקה

1. בפאנל המפעיל: חנויות, חנות חדשה.
2. בעמוד החנות: מפתחות API, הנפקת מפתח. המפתח מוצג פעם אחת.
3. בדיקת החיבור:

```sh
curl -H "X-Shop-Key: usk_..." http://localhost:8000/api/v1/shop
```

## לפני כל commit

```sh
cd apps/api && composer check
cd ../.. && npm test
```
