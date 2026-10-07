# הקמת אפליקציית Laravel — הצעד הראשון אחרי אישור התכנון

> **סטטוס:** עדיין לא בוצע. זה מה שיקרה ברגע שהתרשימים מאושרים.

## סביבה מקומית

PHP 8.4 ו‑Composer מגיעים מ‑Herd. ב‑PowerShell `php` ו‑`composer` זמינים. ב‑Git Bash: `/c/Users/user/.config/herd/bin/php84/php.exe`.
Node 24 מותקן. Docker לא מותקן; בדיקות ב‑Postgres רצות ב‑CI.

## שלב 1: הקרנל מ‑Rega ([ADR 0001](../ADR/0001-new-product-on-the-rega-kernel.md))

```powershell
# מתוך C:\Users\user\Desktop\Projects\Let Agents
$rega = "C:\Users\user\Desktop\Projects\UPSELL"
New-Item -ItemType Directory -Force apps\api, packages, plugins, extensions | Out-Null

# השלד של Laravel + Filament + Octane עם אותן גרסאות
Copy-Item "$rega\apps\api\composer.json" apps\api\
Copy-Item "$rega\apps\api\composer.lock" apps\api\
Copy-Item "$rega\apps\api\Dockerfile", "$rega\apps\api\docker" apps\api\ -Recurse
Copy-Item "$rega\apps\api\bootstrap", "$rega\apps\api\config", "$rega\apps\api\public", "$rega\apps\api\resources", "$rega\apps\api\routes", "$rega\apps\api\artisan", "$rega\apps\api\.env.example", "$rega\apps\api\phpunit.xml", "$rega\apps\api\pint.json" apps\api\ -Recurse

# הקרנל וארבעת המודולים המשותפים בלבד
Copy-Item "$rega\apps\api\app\Core" apps\api\app\Core -Recurse
foreach ($m in "Tenancy","Runs","Ai","Admin") { Copy-Item "$rega\apps\api\app\Modules\$m" "apps\api\app\Modules\$m" -Recurse }
Copy-Item "$rega\apps\api\app\Models", "$rega\apps\api\app\Providers" apps\api\app\ -Recurse
Copy-Item "$rega\apps\api\database" apps\api\database -Recurse
Copy-Item "$rega\apps\api\tests" apps\api\tests -Recurse
Copy-Item "$rega\packages\event-spec" packages\event-spec -Recurse
Copy-Item "$rega\.github" .github -Recurse
```

אחר כך שינוי שמות: `upsell` → `letagents`, `Rega` → `Let Agents`, `usk_` → `lak_`, `@upsell` → `@letagents`, `config/upsell.php` → `config/letagents.php`. לבדוק ש‑`Admin` לא מייבא ממודולים שלא הועתקו (Catalog, Widget): מה שמייבא נמחק או עובר למודול שלו.

```powershell
Set-Location apps\api
composer install
Copy-Item .env.example .env
php artisan key:generate
php artisan migrate
composer check          # pint, i18n:check, phpunit: חייב להיות ירוק לפני שממשיכים
```

## שלב 2: מודולים חדשים

```powershell
php artisan make:module Notify      --requires=Tenancy
php artisan make:module Connections --requires=Tenancy,Runs
php artisan make:module Catalog     --requires=Connections,Runs
php artisan make:module Index       --requires=Catalog,Ai,Runs
php artisan make:module Search      --requires=Index,Analytics
php artisan make:module Analytics   --requires=Tenancy
php artisan make:module Matching    --requires=Index,Analytics,Ai,Runs
php artisan make:module Assistant   --requires=Index,Ai,Runs,Analytics
php artisan make:module Widget      --requires=Search,Matching,Assistant,Analytics
php artisan make:module Improvement --requires=Search,Matching,Assistant,Analytics,Ai,Runs,Notify
```

סדר הבנייה לפי [INDEX.md](../INDEX.md): Connections (WordPress) → Catalog → Index → Search → Widget (חיפוש בלבד) → פריסה ראשונה לאתר → Matching → Widget (תגיות) → Assistant → Improvement → Shopify.

## שלב 3: ריפו ו‑Railway

```powershell
git init
git add .
git commit -m "Scaffold Let Agents on the Rega kernel"
# ריפו ב‑GitHub: aviadmols/let-agents (פרטי), ואז בממשק של Railway: פרויקט חדש מהריפו לפי runbooks/deploy-railway.md
```

## מה לא מעתיקים מ‑Rega

- Catalog, Enrichment, Retrieval, Widget, Assistant, Analytics, Knowledge, Shoppers, Leads, Connections: המודולים של המוצר נכתבים כאן לפי התכנון. קוד נקודתי (Chunker, VectorSearch, MatchCheck) מועתק קובץ‑קובץ כשמגיעים אליו.
- התוסף ל‑WooCommerce: התוסף החדש הוא גם חיפוש וגם פיד, ומבנהו שונה. הקוד של הפיד והחתימה משמש כהתחלה.
