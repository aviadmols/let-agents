# עוזר קנייה חכם לחנויות

רכיב JavaScript שיושב באתרי חנויות ומציג לגולש את ההצעה הכי רלוונטית: השוואה, מוצר משלים, מיקום בקטגוריה, שאלות נפוצות, מדריך או צ׳אט. ההצעות מוכנות מראש, נלמדות מהגלישה, ונפרדות לגמרי בין חנות לחנות.

התוכנית המלאה נמצאת ב־[docs/WORK-PLAN.md](docs/WORK-PLAN.md).

## מבנה

| תיקייה | מה יש בה |
|---|---|
| `apps/api` | Laravel 13: ליבה, מודולים, שני פאנלי ניהול ב־Filament 5, API, תורים |
| `plugins/woocommerce` | התוסף לחנות: טוקן גישה ו־API לקריאה בלבד של הקטלוג והתוכן |
| `packages/event-spec` | JSON Schema של האירועים שהרכיב שולח |
| `docs/runbooks/deploy-railway.md` | איך השירותים ב־Railway מוגדרים ואיך פורסים |
| `docs/ADR` | החלטות ארכיטקטורה |
| `docs/runbooks` | הוראות עבודה: פיתוח מקומי, פריסה, מודול חדש |
| `docs/plan` | התוכנית המקורית של Let Agents (לפני הבנייה), עם ההיסטוריה שלה |

בהמשך יתווספו `apps/widget` (הרכיב בדפדפן), `plugins/woocommerce` (התוסף) ו־`packages/feed-spec` (מפרט הפיד).

## התחלה מהירה

```sh
cd apps/api
composer setup
php artisan admin:operator you@example.com
php artisan serve
```

פאנל המפעיל נמצא ב־`/operator`, ופאנל הסוחר ב־`/merchant`. שניהם בעברית ובאנגלית.

## בדיקות

```sh
cd apps/api && composer check     # Pint, בדיקת תרגומים, PHPUnit
npm test                          # מפרטי JSON
```

## מצב נוכחי

שלב 0 מתוך התוכנית: ליבה מודולרית, חנויות ומפתחות API, דגלים והגדרות עם תקרות, שני פאנלים דו־לשוניים, מפרט אירועים, CI ותצורת Railway.
