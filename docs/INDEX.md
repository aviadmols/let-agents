# Let Agents — אינדקס המסמכים והסטטוס

> **עדכון:** 2026-10-06. **הכלל:** כל יחידת עבודה מעדכנת את השורה שלה כאן לפני שהיא נחשבת גמורה.
> **סטטוסים:** `planned` (בתכנון) · `partial` (חלק נבנה) · `built` (נבנה ונבדק) · `n/a` (לא רלוונטי לפלטפורמה).

## המסמכים

| מסמך | מה הוא עונה |
|---|---|
| [01-flows.md](01-flows.md) | **תרשימי הזרימה.** הוקטורים, ה‑RAG, ההתאמות, הבדיקה, המודול היומי, הדפדפן, הלוג, לוח הזמנים, והשאלות הפתוחות. גרסה מרונדרת עם סקיצה חיה: [claude.ai/artifact/U9Nyj1Cfii6HDPAHnWwPsB](https://claude.ai/artifact/U9Nyj1Cfii6HDPAHnWwPsB) |
| [02-system-design.md](02-system-design.md) | המודולים, הטבלאות, ה‑API, שתי הפלטפורמות, הרכיב, האנליטיקס, הפאנל, האבטחה, הבדיקות. |
| [03-models-and-budget.md](03-models-and-budget.md) | התפקידים, שתי המשפחות, מנופי הטוקנים, התקרות, הערכת עלות, מודלים עתידיים. |
| [04-widget-sketch.md](04-widget-sketch.md) | סקיצת הרכיב בעמוד: המצבים, הטקסטים, העיצוב שנלקח מהאתר, מובייל, נגישות. |
| [ADR/0001](ADR/0001-new-product-on-the-rega-kernel.md) | ריפו חדש על הקרנל של Rega. |
| [ADR/0002](ADR/0002-two-model-families.md) | שתי משפחות של מודלים, מוצלבות ונאכפות בקוד. |
| [ADR/0003](ADR/0003-images-visual-facts-then-vectors.md) | תמונות: עובדות לפי סכימה קודם, וקטור לתמונה כ‑driver. |
| [ADR/0004](ADR/0004-no-model-on-page-load-or-keystroke.md) | אין מודל בטעינת עמוד ובהקלדה. |
| [runbooks/bootstrap-laravel.md](runbooks/bootstrap-laravel.md) | הצעד הראשון של הבנייה. |
| [runbooks/deploy-railway.md](runbooks/deploy-railway.md) | השירותים והמשתנים ב‑Railway. |

## מודולי השרת

| מודול | סטטוס | הערות |
|---|---|---|
| Core, Tenancy, Runs, Ai, Admin | planned | מועתקים מ‑Rega (ADR 0001) |
| Notify | planned | מייל קודם |
| Connections | planned | WordPress קודם, Shopify אחריו |
| Catalog | planned | |
| Index | planned | טקסט + עובדות חזותיות. וקטור לתמונה כ‑driver כבוי |
| Search | planned | מילולי סלחן (קיים מגואטה) + וקטורי |
| Matching | planned | |
| Assistant | planned | |
| Analytics | planned | event-spec מ‑Rega כבסיס |
| Widget | planned | |
| Improvement | planned | |

## משטחים באתר

| משטח | WordPress | Shopify | הערות |
|---|---|---|---|
| חיבור ופיד | planned | planned | WP: תוסף. Shopify: custom app ואז OAuth |
| webhooks על שינוי | planned | planned | |
| החלפת תיבת החיפוש | planned | planned | שכבת JS, נפילה לחיפוש של הפלטפורמה |
| שורת התגיות בעמוד מוצר | planned | planned | לפי class |
| הרכיב בעמוד מאמר | planned | planned | |
| שאלות + ווצאפ | planned | planned | |
| הוספה לסל מהרכיב | planned | planned | Store API / cart.js |
| סיכומי הזמנות ל"נקנו יחד" | planned | planned | אופציונלי, אנונימי |

## מסכי הפאנל

| מסך | מפעיל | סוחר |
|---|---|---|
| בית | planned | planned |
| חיבור לאתר | planned | planned |
| האינדקס | planned | planned |
| חיפושים ומילים נרדפות | planned | planned |
| תגיות בעמוד + curation | planned | planned |
| שאלות | planned | planned |
| הצעות לאתר | planned | planned |
| עיצוב הרכיב | planned | planned |
| הדוחות היומיים | planned | planned |
| מודלים ותקציב | planned | n/a |
| לוג הסוכנים | planned | n/a |
| מפת המערכת | planned | n/a |
