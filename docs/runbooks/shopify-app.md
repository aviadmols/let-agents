# אפליקציית Shopify: הקמה, התקנה ומנוי

החנות מתקינה את האפליקציה, ומיד הופכת לחנות אצלנו. אין טופס ואין תוסף. אחרי ההתקנה הסוחר מאשר מנוי של $49 לחודש, שנגבה בחשבונית של Shopify. בלי מנוי פעיל החנות מושהית: החיפוש לא מופיע בחנות, ושום נתון לא נמחק.

## 1. האפליקציה ב־Shopify Partners

1. https://partners.shopify.com ← Apps ← Create app ← Create app manually. שם: Let Agents.
2. מעתיקים את ה־Client ID ואת ה־Client secret.
3. בקובץ `extensions/shopify/shopify.app.toml` מחליפים את `client_id` ב־Client ID.
4. מהתיקייה `extensions/shopify`:
   ```sh
   shopify app config link      # בוחרים את האפליקציה שנוצרה
   shopify app deploy           # כתובות, הרשאות, webhooks והרחבת התבנית
   ```
5. Distribution: לבדיקות בוחרים Custom distribution לחנות אחת. בשביל כל החנויות בוחרים Public (App Store). כך או כך, החיוב עובר דרך ה־Billing API של האפליקציה, ולא דרך Managed pricing.
6. בפרטי האפליקציה ב־Partners, תחת API access, מבקשים Protected customer data access. זה נדרש בגלל ה־webhooks של פרטיות ובגלל המייל שנשמר בסלים נטושים.

## 2. משתנים ב־Railway (api, worker, scheduler)

| משתנה | ערך |
|---|---|
| `SHOPIFY_API_KEY` | Client ID |
| `SHOPIFY_API_SECRET` | Client secret |
| `SHOPIFY_API_VERSION` | `2026-07` (ברירת המחדל) |
| `SHOPIFY_APP_HANDLE` | ה־handle של האפליקציה, `let-agents` |
| `APP_URL` | `https://agents.lets.co.il`: הכתובת שבקובץ ה־toml |

הכתובות שהאפליקציה משתמשת בהן, כולן תחת `APP_URL`:

| כתובת | למה |
|---|---|
| `/shopify/app` | Application URL: פתיחת האפליקציה מהניהול של Shopify |
| `/shopify/callback` | חזרה מאישור ההתקנה |
| `/shopify/billing/return` | חזרה מאישור המנוי |
| `/api/v1/shopify/webhooks` | הסרת האפליקציה, שינוי מנוי ושלושת ה־webhooks של פרטיות |

## 3. התקנה ובדיקה על חנות פיתוח

1. ב־Partners: Stores ← Add store ← Development store.
2. באפליקציה: Test your app ← בוחרים את החנות. או פותחים `https://agents.lets.co.il/shopify/install?shop=xxx.myshopify.com`.
3. מאשרים את ההרשאות. נפתח עמוד אישור המנוי של $49. בחנות פיתוח הוא תמיד חיוב בדיקה, ואף אחד לא מחויב.
4. אחרי האישור נכנסים לפאנל החנות אצלנו, מחוברים כבעל החנות.
5. בפאנל המפעיל, ב"חנויות", מופיעה שורה עם התגית Shopify והתוכנית "פעילה". הקטלוג נסנכרן ברקע, ב־worker.
6. בחנות: Online Store ← Themes ← Customize ← App embeds ← מדליקים את Let Agents search ← Save. החיפוש מופיע בתיבת החיפוש של התבנית.

## 4. מה קורה אחרי ההתקנה

- **הסוחר מבטל את המנוי** (webhook `app_subscriptions/update`): החנות מושהית. כשהסוחר פותח שוב את האפליקציה, הוא מתבקש לאשר מחדש.
- **הסוחר מסיר את האפליקציה** (`app/uninstalled`): הגישה נמחקת והחנות מושהית. אחרי 48 שעות מגיע `shop/redact`, והחנות וכל הנתונים שלה נמחקים.
- **בקשת פרטיות של קונה** (`customers/redact`): הסלים הנטושים עם המייל שלו נמחקים. זה המקום היחיד שבו נשמר מייל של קונה.
- **הטוקן:** טוקן אופליין שפג תוקפו מתחדש אוטומטית עם ה־refresh token. אם גם הוא פג, הסנכרון נכשל עם "צריך להתקין שוב".

## 5. מחיר וניסיון

בפאנל המפעיל ← הגדרות ← Shopify: שם התוכנית, המחיר (ברירת מחדל $49) וימי הניסיון (ברירת מחדל 0). שינוי חל רק על מנויים חדשים.
