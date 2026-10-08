# Let Agents ל־Shopify

אפליקציית Shopify של Let Agents: קובץ ההגדרות של האפליקציה (`shopify.app.toml`) והרחבת תבנית (Theme App Extension)
אחת, `let-agents-search`, שמוסיפה את החיפוש של Let Agents לכל עמודי החנות כ־app embed.

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
4. בודקים בחנות: לוחצים על שדה החיפוש ומקלידים. ההצעות של Let Agents מחליפות את ההצעות של התבנית.

כיבוי ה־embed מחזיר את החיפוש של התבנית כמו שהיה.

## מה ה־embed מדפיס

`window.LetAgentsSearchContext` עם `platform: "shopify"`, ואחריו `{api}/search/let-agents-search.js` עם `defer`.
מחירים ומלאי נקראים מ־`{root}products/{handle}.js`, והוספה לסל נעשית ב־`{root}cart/add.js`, בלי טוקן.
