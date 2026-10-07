# פריסה ל־Railway

## איך זה בנוי

פרויקט Railway אחד, סביבת `production`. חמישה שירותים:

| שירות | מקור | הגדרות עיקריות |
|---|---|---|
| api | GitHub `aviadmols/Let Agents`, ענף `main` | Root directory `/`, Dockerfile `apps/api/Dockerfile`, watch paths `/apps/api/**` ו־`/plugins/woocommerce/**`, healthcheck `/up` עם 300 שניות, הפעלה מחדש בכישלון עד 5 פעמים, דומיין ציבורי על פורט 8080 |
| worker | אותו repo | אותן הגדרות בנייה, הפעלה מחדש תמיד |
| scheduler | אותו repo | אותן הגדרות בנייה, הפעלה מחדש תמיד |
| Postgres | image `pgvector/pgvector:pg17` | volume ב־`/var/lib/postgresql/data`, `PGDATA` בתת־תיקייה, בלי דומיין ציבורי |
| Redis | image `redis:7-alpine` | עם סיסמה, בלי volume ובלי דומיין ציבורי |

שלושת שירותי האפליקציה בונים את אותו `apps/api/Dockerfile`, מתיקיית השורש של הריפו. הבנייה מתחילה בשורש כדי שה־image יבנה גם את קובץ ה־zip של התוסף מתוך `plugins/woocommerce`, ויגיש אותו להורדה במסך "התוסף לחנויות". התפקיד נקבע במשתנה `APP_ROLE`: `web`, `worker` או `scheduler`.

בנייה מקומית של אותו image:

```sh
docker build -f apps/api/Dockerfile .
```

**למה בלי קובצי הגדרות בריפו.** Railway הוציא משימוש את `railway.json`, והמחליף שלו, `.railway/railway.ts`, מופעל רק בפקודה `railway config apply` ולא ב־push. שירות לא יכול להיות מנוהל גם כקוד וגם דרך הממשק. לכן ההגדרות מנוהלות בממשק של Railway או ב־API, ומתועדות כאן.

## משתנים

**Postgres**

```
POSTGRES_USER=let-agents
POSTGRES_PASSWORD=<אקראי>
POSTGRES_DB=let-agents
PGDATA=/var/lib/postgresql/data/pgdata
DATABASE_URL=postgresql://${{POSTGRES_USER}}:${{POSTGRES_PASSWORD}}@${{RAILWAY_PRIVATE_DOMAIN}}:5432/${{POSTGRES_DB}}
```

**Redis**

```
REDIS_PASSWORD=<אקראי>
REDIS_URL=redis://default:${{REDIS_PASSWORD}}@${{RAILWAY_PRIVATE_DOMAIN}}:6379
```

Start command:

```
/bin/sh -c 'exec redis-server --requirepass "$REDIS_PASSWORD"'
```

**api, worker, scheduler, משותפים**

```
APP_NAME=Let Agents
APP_ENV=production
APP_DEBUG=false
APP_KEY=base64:<אקראי, זהה בשלושת השירותים>
APP_URL=https://<הדומיין של api>
APP_LOCALE=he
APP_FALLBACK_LOCALE=en
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

`worker` מקבל `APP_ROLE=worker`, ו־`scheduler` מקבל `APP_ROLE=scheduler`.

## מה קורה בכל הפעלה של api

1. cache של config, routes, views ו־events.
2. migrations, עם עד עשרה ניסיונות, כי הרשת הפרטית לפעמים עונה אחרי כמה שניות.
3. יצירת המפעיל מ־`BOOTSTRAP_OPERATOR_*`. משתמש קיים רק מקבל הרשאת מפעיל. הסיסמה שלו לא מתאפסת, ולכן אפשר להשאיר את המשתנים.
4. `php artisan system:check`: מסד נתונים, migrations, pgvector, cache, Redis והגדרות production. התוצאה בשורות הראשונות של הלוג. כישלון לא עוצר את העלייה.
5. Octane על FrankenPHP בפורט 8080.

## כניסה ראשונה

המפעיל נוצר אוטומטית. הסיסמה נמצאת במשתנה `BOOTSTRAP_OPERATOR_PASSWORD` של שירות api. אחרי הכניסה הראשונה מומלץ להחליף סיסמה במסך המשתמשים, ואז אפשר למחוק את המשתנה.

## פריסה שוטפת

1. push ל־`main`.
2. **לחכות ש־CI יסתיים בירוק**, כולל Postgres. SQLite לבד לא מספיק: פעם אחת migration עבר ב־SQLite ונכשל ב־Postgres.
3. לפרוס את api, worker ו־scheduler על ה־commit הזה, מהממשק של Railway או מה־API עם `serviceInstanceDeployV2`.

השירותים חוברו ל־repo דרך טוקן פרויקט, ולכן push לא מפעיל פריסה אוטומטית. כדי שכל push יפרוס לבד, מחברים מחדש את ה־repo בהגדרות של כל שירות בממשק. גם אז כדאי להפעיל ב־Railway את האפשרות לחכות ל־CI.

פריסה שנכשלת לא מחליפה את הגרסה הרצה: Railway ממשיך להגיש את הפריסה הקודמת עד שבדיקת `/up` עוברת.

## Cloudflare

- DNS של הדומיין הציבורי בפרוקסי מול הדומיין של Railway.
- מהשלב שבו ה־widget וה־bank קיימים: cache rules ארוכים לנתיבים `/w/*` ו־`/bank/*`. יש גרסה בנתיב, ולכן אין צורך ב־purge.
- להימנע מהמילים upsell ו־assistant בשם הדומיין הציבורי, כי חוסמי פרסומות מסננים אותן.

## החזרה לאחור

בממשק של Railway: Deployments, בחירת הפריסה הקודמת, Redeploy. migration הרסנית דורשת migration הפוכה ולא rollback של image.

## Redis בלי volume

כרגע Redis משמש ל־cache, סשנים ותורים, ואין בו מידע שאסור לאבד. כשיהיו משימות רקע ארוכות, להוסיף volume ולהפעיל `--appendonly yes`.
