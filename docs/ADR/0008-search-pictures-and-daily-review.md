# ADR 0008: חיפוש באתר, וקטורים לתמונות, ובדיקה יומית עם שתי משפחות של מודלים

תאריך: 6 באוקטובר 2026
סטטוס: התקבל ומומש. המודולים Search ו־Improvement, הרחבות ב־Retrieval וב־Ai, ותוסף 0.5.0.

## הקשר

אביעד ביקש מערכת שעובדת עם Shopify ו־WordPress: RAG של כל התוכן, חיפוש לפי משמעות, תגיות "תרצו לראות גם", ניתוח תמונות שמתאים גם לאופנה, שאלות עם ווצאפ כגיבוי, אנליטיקס, ובדיקה יומית שמציעה מה להוסיף לאתר, כש־OpenAI ו־Claude בודקים זה את זה. התכנון נכתב קודם כפרויקט נפרד ("Let Agents"). בבדיקה התברר שכשני שלישים ממנו כבר קיימים ב־Let Agents, ולכן הוא נבנה כאן.

## החלטות

**חיפוש (מודול Search).**
- המנוע לפי כתיב הוא זה שנמדד בחנות הפיילוט, כמו שהוא: שגיאות כתיב, יחיד ורבים, כתיב מלא וחסר, מקלדת שנשארה באנגלית. הוא רץ פעמיים, בדפדפן (הצעות בלי בקשה להקשה) ובשרת (תוצאות מלאות). בדיקה מריצה את שניהם על אותו קובץ ודורשת אותם מזהים באותו סדר.
- תוצאות מלאות מוסיפות חיפוש לפי משמעות מהאינדקס של Retrieval. ניסוח חדש עולה וקטור אחד, נשמר, ולא עולה שוב. רשומה שמכילה את כל המילים כמו שהוקלדו תמיד למעלה; השאר מתמזגים בדירוג הדדי (RRF).
- מחיר ומלאי מגיעים חיים מהחנות, אף פעם מהאינדקס.
- כל חיפוש נספר פעם אחת ללשונית, עם מספר התוצאות; כל לחיצה נספרת עם התוצאה. בלי שום פרט על הגולש.
- מילים נרדפות נכנסות לאינדקס בזמן הבנייה (קרש = עץ מוסיף "קרש" לכל רשומה שיש בה "עץ"), כך שאין חוק בזמן חיפוש.
- התוסף טוען את הסקריפט בכל עמוד כשהחנות מפעילה (כבוי כברירת מחדל), ומסדר את דף התוצאות של WordPress לפי Let Agents. כש־Let Agents לא עונה, החיפוש של האתר עובד כמו קודם.

**תמונות (Retrieval + Ai).**
- המודל: Gemini Embedding 2 של Google. במחירים בתשלום הוא הזול מבין הטובים (כ־0.00012 $ לתמונה), ומכניס תמונות ומילים לאותו מרחב. Voyage ו־Cohere נבדקו ויקרים יותר. הוא driver, כך שהחלפה היא הגדרה.
- וקטור לתמונה הראשית של כל מוצר. הכתובת היא הגרסה: תמונה נשלחת פעם אחת, ושוב רק כשהחנות מעלה חדשה.
- מה שזה מאפשר בלי קריאה למודל: מקור מועמדים "נראה דומה" להתאמות (וחלופה יכולה להיות דומה במראה גם בלי מילים משותפות), ורשימה שלישית בחיפוש ("חולצת פסים" מוצאת חולצות פסים שבשם שלהן זה לא כתוב).
- כבוי כברירת מחדל (`retrieval.image_index`). טביעות האצבע הישנות של ההתאמות לא משתנות, כך שאף חנות לא נשאלת שוב בגלל זה.

**בדיקה יומית (מודול Improvement).**
- הקוד אוסף: חיפושים שלא מצאו כלום, חיפושים שאף אחד לא לחץ עליהם, ושאלות שהאתר לא ענה עליהן. רשימות באורך קבוע, כך שחנות גדולה וקטנה עולות אותו דבר.
- פריט שכבר נשלח לבדיקה לא נשלח שוב, אלא אם הוא לפחות הוכפל. יום בלי משהו חדש לא שואל מודל.
- מודל אחד מציע (Claude Sonnet 5.5 כברירת מחדל) שלושה סוגים: מילה נרדפת, שאלה שכדאי לענות עליה באתר, ומשהו שמחפשים ואין. כל הצעה מצביעה על הראיות שלה.
- הקוד דוחה הצעה בלי ראיות, מילה נרדפת שלא מצביעה על מילה שהחנות משתמשת בה, והצעה שכבר הוצעה.
- מודל ממשפחה אחרת בודק (OpenAI gpt-5.4-mini כברירת מחדל). **אותה משפחה בשני הצדדים עוצרת את הבדיקה לפני כל קריאה.** הצעה בלי תשובה מהבודק נחשבת דחויה.
- מילה נרדפת ששניהם קיבלו נכנסת לחיפוש רק אם החנות הרשתה (`improvement.auto_synonyms`, כבוי). כל השאר מחכה לצוות במסך "הצעות לאתר".
- הסיכום היומי נכתב בקוד, נשמר, ונשלח במייל כשהחנות נתנה כתובת.

## השלכות

- נדרש מפתח Gemini בפאנל רק כשמדליקים תמונות. הבדיקה היומית צריכה מפתח OpenAI ומפתח Anthropic.
- העוזר לשאלות (Assistant) עדיין בודק את תשובות OpenAI עם מודל של OpenAI. זה לא עומד בכלל של שתי משפחות, ונשאר כך עד החלטה של אביעד, כי שינוי שלו משנה התנהגות בפרודקשן.
- Shopify עדיין לא נבנה. המודולים החדשים לא תלויים בפלטפורמה: הם קוראים מהקטלוג, ו־Shopify יצטרך StoreFeed משלו ו־Theme App Extension שטוען את אותם שני סקריפטים.

## Addendum (2026-10-07): resolving empty searches and the tag bank

- **Searches that found nothing are resolved at night (04:00), never live.** A query that came back
  empty `search.resolve_min_searches` times gets its nearest products and guides by meaning; one
  family matches (default OpenAI), another checks (default Anthropic). Accepted matches are shown
  first for that exact query from the next request on; an accepted synonym joins the index at the
  next build. The team can take a resolution back and restore it from the searches screen.
- **The on-page module has a third view, `widget.layout = tags`.** "תרצו לראות גם" over a row of
  tags. Tags are written per page at night (03:30) by one family, kept only when the store's own
  search fills them, and checked by the other family against what they show. Widget reads them
  through `Search\Contracts\PageTags`, which searches each tag when the bank is built, so results
  follow stock. Tags the team takes off stay off.

## Addendum (2026-10-07): questions in the search box and in the module

- **A question typed in the search box is answered from the whole site, only on Enter.** While
  typing, code only: the search index now holds answers shoppers already got (page answers and
  site answers, `search.answers_in_index`), so a matching question shows its answer at once.
  On Enter, `POST /api/v1/search/{site}/ask` runs `Assistant\Actions\AnswerSiteQuestion`: saved
  answer first; else the nearest passages by meaning (`Retrieval\Contracts\Passages`); none near
  enough means "not found" with no writing model; else OpenAI writes from the passages and cites
  them, code refuses uncited answers, prices, contact details and numbers not in the cited text,
  and Claude checks every fact is in the cited passages. Site answers have neither `product_id`
  nor `content_id` and carry `sources` (the pages, with links). "Not found" is retried after
  `assistant.site_retry_days`. No answer: the shop's WhatsApp, with the question filled in.
- **The on-page module has a search-and-ask field** (`widget.find_field`, default on). Typing
  filters the circles or tags and shows the page's saved answers, products and guides already in
  the bank; no request is made. Enter sends a question to the existing question box (same limits
  and handover to the shop) and plain words to the store's search.
- **Search by photo is off by default and the system operator turns it on per site** from the
  searches screen. The shop's own screen never shows the switch.

## Addendum (2026-10-08): the assistant picks from the results, WhatsApp on no match, a daily score

- **A question in the search box carries the products the search showed** (`products`, at most
  12). AnswerSiteQuestion (prompt v2) writes a short answer from the site's passages and the
  products' own descriptions, and picks at most three of those products, each with why. Code
  keeps only refs it gave; the checker of the other family (`picks_fit`) drops picks that do not
  fit and refuses an answer the pages do not hold. Nothing left is `no_match`.
- **No exact match offers the shop's WhatsApp** (`assistant.search_whatsapp`, the message in
  `assistant.search_whatsapp_message`, both in the shop's own settings, group "search").
- **Every question asked is logged** in `assistant_search_asks`: what was shown, the outcome, the
  picks, whether WhatsApp was offered and clicked, and which picks the shopper opened (events
  `ask_whatsapp` and `ask_pick`, accepted only for that question's own picks).
- **Every morning at 05:15, ReviewSearchAsks** (another family than the answering model, checked
  in code) scores each of yesterday's questions 1–5 and writes the shop manager a summary, up to
  five improvements and a score out of 100 (`assistant_ask_reviews`). A quiet day asks no model;
  a day is reviewed once. The report and the questions show on the questions screen; costs and
  the "review now" button only on the operator's.
