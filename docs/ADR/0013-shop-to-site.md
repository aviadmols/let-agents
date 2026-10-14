# ADR 0013: הדייר נקרא `Site`, לא `Shop`

תאריך: 14 באוקטובר 2026
סטטוס: נדחה. השם נבחר, אבל הביצוע המכני נעצר אחרי שנמצאה התנגשות (ראו "מה נמצא"). עד שנסגרת ההתנגשות, הקוד נשאר `Shop`, וקוד חדש נכתב בשם הזה.

## הקשר

הדייר (חנות Shopify או אתר WordPress/WooCommerce אחד) נקרא בקוד `Shop`: טבלת `shops`, עמודה `shop_id` בכל טבלה של חנות, `BelongsToTenant` עם `shop_id`, המפתח `X-Shop-Key`. הכתובות של הרכיב כבר משתמשות ב‑`{site}`. השם `Shop` מתנגש בקריאה עם `Shopify`, `Shopper` ו"חנות" כמוצר, ואתרי WordPress אינם חנויות אלא אתרים. ההחלטה: `Site`.

## מה נמצא בבדיקה לפני הביצוע

ב‑UPSELL המילה `site` כבר תפוסה, והיא **לא** הדייר:

- `StoreConnection::forSite($siteKey)`, הנתיב `{site}` ב‑`routes/api.php` של Widget, Search, Analytics, Assistant, Shortlist, Shoppers ו‑Leads, `$site` בבקרים, `site_key` ב‑`StoreConnection`, `SiteKeys`, `StorefrontSite`. זה **המפתח הציבורי של החנות**, מחרוזת, לא מודל.
- `AnswerSiteQuestion`, `assistant.site_*`: "שאלה על האתר כולו", מול שאלה על מוצר.
- 15 קובצי בדיקה ו‑`ContextEvalCommand`, `ShopSearches` מחזיקים גם `$site` וגם `$shop` באותו תחום.

החלפה עיוורת `Shop` ← `Site` מאחדת את שני המושגים לשם אחד, ויוצרת `forSite` כפול, `$site` שהוא לפעמים מפתח ולפעמים מודל, ותוצאה שנראית תקינה בבדיקות (שמוחלפות באותה החלפה) ושוברת את הרכיב. בנוסף, JS של הרכיב קורא מהשרת שדות בשם `shop` ו‑`shopName` ו‑`shopCurrency`, ו‑`SettingScope::Shop` ו‑`ValueSource::Shop` הם ערכי הצהרה במניפסטים.

**לפני שמבצעים:** לשנות קודם את מושג המפתח הציבורי ל‑`site_key` / `siteKey` (`forSiteKey`, `$siteKey`, הנתיב נשאר `{site}` כי הוא משטח ציבורי), ורק אז להחליף `Shop` ל‑`Site`. שני commits נפרדים.

## החלטה

1. **שם:** `Shop` ← `Site`, `shop_id` ← `site_id`, `shops` ← `sites`, `shop_api_keys` ← `site_api_keys`, `shop_user` ← `site_user`, scope `shop` של הגדרות ← `site`, `TenantContext` ו‑`BelongsToTenant` עוברים ל‑`site`.
2. **לא משתנה:** `Shopify`, `Shopper*` (`shoppers`, `shopper_visitors`, ...), `shopify_installs`, מילים בטקסט של גולשים, "חנות" בעברית (המונח למשתמש), `shop` בתוך שמות של דומיינים או URL שלא שלנו.
3. **משטח ציבורי, תאימות לאחור:** התוסף ל‑WooCommerce שכבר מותקן אצל חנויות שולח `X-Shop-Key`; השרת מקבל גם `X-Site-Key` וגם `X-Shop-Key` (ו‑`Authorization: Bearer`), והמפתחות הקיימים (`usk_`) נשארים תקפים. אטריבוטי `data-shop-banner` / `data-shop-bar` ברכיב נשארים כשם נוסף. הסרה רק אחרי שכל התוספים עודכנו.
4. **מיגרציה אחת, הפיכה:** שינוי שם טבלאות ועמודות (`Schema::rename`, `renameColumn`), שמות אינדקסים ו‑foreign keys נשארים תקינים. מיגרציה נפרדת מתכנון הקוד כדי שאפשר יהיה לחזור.
5. **ביצוע:** commit אחד ייעודי, בלי שינוי התנהגות, לפני שמודולים חדשים נכתבים, ואחריו `composer check` מלא ב‑SQLite ו‑CI ב‑Postgres. פריסה: להריץ מיגרציה ופריסה יחד (api, worker, scheduler) כי הקוד הישן והחדש לא מדברים עם אותן טבלאות.

## למה

- שם אחד שמתאים לשתי הפלטפורמות והולך עם `{site}` שכבר בכתובות.
- לעשות את זה לפני `TryOn` ו‑`Taxonomy` כדי שהקוד החדש ייכתב ישר בשם הנכון, ולא יעבור שינוי כפול.

## השלכות

- שינוי רחב: כ‑1,260 קבצים מזכירים `shop` (כולל Shopify ו‑Shopper, שלא משתנים), 48 מיגרציות עם `shop_id`, 12 שימושים ב‑`X-Shop-Key`. מבוצע בסקריפט עם רשימת החרגות ומאומת בבדיקות.
- מסמכים וה‑CLAUDE.md מתעדכנים באותו commit.
- בדיקות שיוצרות טבלאות משלהן ויש בהן `shop_id` מתעדכנות באותו commit.
