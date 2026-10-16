# Let Agents ל־Shopify

אפליקציית Shopify של Let Agents: קובץ ההגדרות של האפליקציה (`shopify.app.toml`) ושתי הרחבות:

- `let-agents-search` — הרחבת תבנית (Theme App Extension) עם app embed אחד, שטוען לכל עמודי החנות את
  Lets Search (המגירה): `{api}/search/lets-search.js`. זה הסקריפט היחיד של Let Agents בחנות. החיפוש, "על המוצר"
  בדפי מוצר, תשובות לשאלות, רשימת המשאלות וקישורים משותפים (`?la_q=`, `?la_list=`, `#wishlist`) — כולם בו.
  ה־block הישן לדף מוצר ולפוסט (`page-widget`, שטען את `let-agents.js`) הוסר; אחרי deploy הוא מפסיק להופיע בתבניות שהיה בהן.
- `let-agents-pixel` — Web Pixel שמדווח ב־`checkout_completed` את ההזמנה (בלי פרטי הקונה), כדי שהרכישות ייספרו.

ב־`shopify.app.toml` מוגדר גם app proxy (`/apps/letagents`), שדרכו המגירה שומרת את רשימת המשאלות של לקוח מחובר.

ההתקנה, החיוב וה־webhooks רצים ב־`apps/api` (מודול `Shopify`). בהתקנה האפליקציה כותבת לחנות שני metafields
(namespace `let_agents`): `site_key` ו־`api`. ה־embed קורא אותם, וכל עוד אחד מהם חסר הוא לא מדפיס כלום.

## פריסה

צריך Node 22+ וחשבון Partner עם גישה לאפליקציה.

```sh
cd extensions/shopify
npm install                       # Shopify CLI, from package.json
npx shopify app config link       # choose the "Let Agents" app; fills client_id in shopify.app.toml
npx shopify app deploy            # pushes the app config and the theme extension as a new version
```

`client_id` בקובץ הוא ה־Client ID של אפליקציית Let Agents (ציבורי, לא סוד). ה־Client secret נמצא רק ב־Railway (`SHOPIFY_API_SECRET`).
הכתובות בקובץ הן של production, ו־`automatically_update_urls_on_dev = false` מונע מ־`shopify app dev` לדרוס אותן.

## הפעלה בחנות

1. מתקינים את האפליקציה בחנות ומחכים שההתקנה תסתיים (ה־metafields נכתבים בה).
2. Shopify admin > Online Store > Themes > Customize.
3. בסרגל השמאלי: App embeds (סמל הקוביות) > מדליקים את **Let Agents search** > Save.
4. בודקים בחנות: לוחצים על שדה החיפוש או על סמל החיפוש ומקלידים; בדף מוצר המגירה מתחילה מהמוצר.

אין block להוסיף לדף המוצר: המגירה מזהה דף מוצר בעצמה ומוסיפה שורת חיפוש מתחת לטופס ההוספה לסל.

כיבוי ה־embed מחזיר את החיפוש של התבנית כמו שהיה.

## מה ה־embed מדפיס

`window.LetAgentsSearchContext` עם `platform: "shopify"`, `site`, `api`, `locale`, `root`, `searchUrl`, `cartUrl`, `currency`,
`shopCurrency`, `moneyFormat` ו־`loggedIn`, ובתבנית מוצר או מאמר גם `page: {type: "product" | "content", id}`.
אחריו `{api}/search/lets-search.js` עם `defer`. הקובץ מביא איתו את העיצוב ואת מנוע החיפוש; אין צורך בשום דבר אחר בעמוד.
במאמר ש־Let Agents כתב ה־embed מדפיס גם את השאלות שלו כ־FAQPage (JSON-LD), מתוך `article.metafields.let_agents.faq`.
מחירים ומלאי נקראים מ־`{root}products/{handle}.js`, והוספה לסל נעשית ב־`{root}cart/add.js`, בלי טוקן.
