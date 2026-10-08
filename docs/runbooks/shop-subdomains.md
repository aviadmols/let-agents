# כתובת לכל חנות: {shop}.agents.lets.co.il

כל חנות מקבלת כתובת משלה: `gueta-avigdor.agents.lets.co.il/merchant`. מסך המפעיל נשאר ב־`agents.lets.co.il/operator`. הקוד מוכן: כל עוד `SHOP_DOMAIN` ריק, כל החנויות נשארות ב־`/merchant/{shop}` כמו היום.

## 1. DNS (אצל מי שמנהל את lets.co.il)

| סוג | שם | ערך |
|---|---|---|
| CNAME | `agents` | הכתובת ש־Railway נותן לדומיין המותאם (נראית כמו `xxxx.up.railway.app`) |
| CNAME | `*.agents` | אותה כתובת |

- ב־Cloudflare: DNS only (ענן אפור), כדי ש־Railway יוכל להנפיק תעודה.
- אם Railway מבקש רשומת `_acme-challenge`, מוסיפים אותה כפי שהוא כותב.

## 2. Railway, שירות api

1. Settings ← Networking ← Custom Domain: מוסיפים את `agents.lets.co.il`.
2. מוסיפים גם את `*.agents.lets.co.il`. דומיין עם כוכבית דורש תוכנית שתומכת בו. Railway מנפיק תעודה לכל תת־הדומיינים.
3. משתנים, ב־api, ב־worker וב־scheduler:
   - `SHOP_DOMAIN=agents.lets.co.il`
   - `SESSION_DOMAIN=.agents.lets.co.il`: התחברות אחת לכל הכתובות. ההתחברות הקודמת תתבטל פעם אחת, וצריך להתחבר שוב.
   - `APP_URL=https://agents.lets.co.il`
4. Deploy.

## 3. בדיקה

- `https://agents.lets.co.il/operator`: נכנסים כמפעיל. ברשימת החנויות, עמודת "כתובת החנות כאן" מציגה `gueta-avigdor.agents.lets.co.il`. לחיצה על שורה פותחת את החנות בכתובת שלה.
- `https://gueta-avigdor.agents.lets.co.il/merchant`: מנהל החנות רואה את החנות שלו. מפעיל רואה את הפס "את/ה צופה בחנות של…".

## הערות

- הכתובת היא ה־slug של החנות: אותיות קטנות באנגלית, ספרות ומקפים. שמות של הפלטפורמה (www, api, app, admin…) שמורים ואי אפשר להשתמש בהם.
- שינוי slug משנה את כתובת החנות. אין עדיין הפניה מהכתובת הישנה.
- התוסף בוורדפרס, הסקריפטים בחנות והקריאות מ־Shopify ממשיכים לעבוד מול `APP_URL`, ולא מול כתובת החנות.
