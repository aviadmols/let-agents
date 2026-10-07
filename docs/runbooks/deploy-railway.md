# פריסה ל‑Railway

> **סטטוס:** תכנון. הפרויקט ב‑Railway עדיין לא נוצר. המבנה זהה לזה של Rega, שרץ בפרודקשן מאז ספטמבר 2026.

## השירותים

פרויקט Railway אחד (`let-agents`), סביבת `production`. חמישה שירותים:

| שירות | מקור | הגדרות עיקריות |
|---|---|---|
| api | GitHub `aviadmols/let-agents`, ענף `main` | Root directory `/`, Dockerfile `apps/api/Dockerfile`, watch paths `/apps/api/**`, `/plugins/**`, `/packages/**`, healthcheck `/up` עם 300 שניות, הפעלה מחדש בכישלון עד 5 פעמים, דומיין ציבורי על פורט 8080 |
| worker | אותו repo | אותן הגדרות בנייה, הפעלה מחדש תמיד, `APP_ROLE=worker` |
| scheduler | אותו repo | אותן הגדרות בנייה, הפעלה מחדש תמיד, `APP_ROLE=scheduler` |
| Postgres | image `pgvector/pgvector:pg17` | volume ב‑`/var/lib/postgresql/data`, `PGDATA` בתת‑תיקייה, בלי דומיין ציבורי |
| Redis | image `redis:7-alpine` | עם סיסמה, בלי דומיין ציבורי. volume + `--appendonly yes` כש‑Batch מתחיל לרוץ (המעקב אחרי batch פתוח חייב לשרוד הפעלה מחדש) |

שלושת שירותי האפליקציה בונים את אותו image מתיקיית השורש, כדי שה‑image יארוז גם את התוסף ל‑WordPress ויגיש אותו להורדה מהפאנל. התפקיד נקבע ב‑`APP_ROLE`.

**בלי קובצי הגדרות בריפו.** Railway הוציא משימוש את `railway.json`, והמחליף (`.railway/railway.ts`) מופעל רק ב‑`railway config apply`. ההגדרות מנוהלות בממשק או ב‑API ומתועדות כאן.

## משתנים

**Postgres**

```
POSTGRES_USER=letagents
POSTGRES_PASSWORD=<אקראי>
POSTGRES_DB=letagents
PGDATA=/var/lib/postgresql/data/pgdata
DATABASE_URL=postgresql://${{POSTGRES_USER}}:${{POSTGRES_PASSWORD}}@${{RAILWAY_PRIVATE_DOMAIN}}:5432/${{POSTGRES_DB}}
```

**Redis**

```
REDIS_PASSWORD=<אקראי>
REDIS_URL=redis://default:${{REDIS_PASSWORD}}@${{RAILWAY_PRIVATE_DOMAIN}}:6379
```

Start command: `/bin/sh -c 'exec redis-server --requirepass "$REDIS_PASSWORD" --appendonly yes'`

**api, worker, scheduler, משותפים**

```
APP_NAME="Let Agents"
APP_ENV=production
APP_DEBUG=false
APP_KEY=base64:<אקראי, זהה בשלושה>
APP_URL=https://<הדומיין של api>
APP_LOCALE=he
APP_FALLBACK_LOCALE=en
APP_TIMEZONE=Asia/Jerusalem
LOG_CHANNEL=stderr
LOG_LEVEL=info
DB_CONNECTION=pgsql
DB_URL=${{Postgres.DATABASE_URL}}
REDIS_CLIENT=phpredis
REDIS_URL=${{Redis.REDIS_URL}}
CACHE_STORE=redis
QUEUE_CONNECTION=redis
SESSION_DRIVER=redis
SESSION_SECURE_COOKIE=true
```

**api בלבד**

```
APP_ROLE=web
PORT=8080
RUN_MIGRATIONS=true
BOOTSTRAP_OPERATOR_EMAIL=<אימייל המפעיל>
BOOTSTRAP_OPERATOR_PASSWORD=<סיסמה>
BOOTSTRAP_OPERATOR_LOCALE=he
```

מפתחות הספקים (OpenAI, Anthropic, Voyage) **לא** במשתני סביבה: הם נשמרים מוצפנים בפאנל המפעיל, לכל אתר או גלובלית. ככה החלפת מפתח לא דורשת פריסה.

## מה קורה בכל הפעלה של api

1. cache של config, routes, views, events.
2. migrations עם עד עשרה ניסיונות (הרשת הפרטית עונה אחרי כמה שניות). כולל `CREATE EXTENSION IF NOT EXISTS vector`.
3. יצירת המפעיל מ‑`BOOTSTRAP_OPERATOR_*`. משתמש קיים רק מקבל הרשאה.
4. `php artisan system:check`: מסד נתונים, migrations, pgvector, cache, Redis, הגדרות production.
5. Octane על FrankenPHP בפורט 8080.

## פריסה שוטפת

1. push ל‑`main`.
2. לחכות ל‑CI ירוק, כולל Postgres. SQLite לבד לא מספיק.
3. לפרוס api, worker, scheduler על אותו commit, מהממשק או מה‑API (`serviceInstanceDeployV2`).

פריסה שנכשלת לא מחליפה את הרצה: Railway מגיש את הקודמת עד ש‑`/up` עובר.

## Cloudflare

- DNS של הדומיין הציבורי בפרוקסי מול הדומיין של Railway.
- cache rules ארוכים ל‑`/w/*`. גרסה בנתיב, אין purge.
- להימנע מהמילים `search`, `assistant`, `ai` בשם הדומיין של הרכיב: חוסמי פרסומות מסננים אותן. `/w/` ו‑`agents.js` בסדר.

## החזרה לאחור

Deployments → הפריסה הקודמת → Redeploy. migration הרסנית דורשת migration הפוכה.
