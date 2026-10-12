<?php

namespace ImageCaptionBenchmark;

/**
 * The pages and sheets a person reads to choose a flow: the build report (totals, costs, whole meta
 * objects side by side), a sheet per product for scoring by hand, and one page per query.
 */
final class Report
{
    /** label, and whether more is better (null: neither) */
    private const METRICS = [
        'products' => ['מוצרים', null],
        'answered_pct' => ['קיבלו קריאה של התמונה (%)', true],
        'fallback_used' => ['נעזרו במודל הגיבוי', false],
        'schema_clean_pct' => ['מבנה תקין בלי תיקון (%)', true],
        'spec_leaks' => ['פרטים שתמונה לא יכולה לתת (מחיר, מידה, קארט, קוד)', false],
        'type_agrees_pct' => ['סוג המוצר מתאים לקטלוג (%)', true],
        'color_agrees_pct' => ['הצבע מתאים לקטלוג (%)', true],
        'material_agrees_pct' => ['החומר מתאים לקטלוג (%)', true],
        'stone_agrees_pct' => ['האבן מתאימה לקטלוג (%)', true],
        'conflicts' => ['סתירות לקטלוג (המצאות)', false],
        'missing' => ['פרטים מהקטלוג שהתמונה החמיצה', false],
        'unverified' => ['פרטים שהקטלוג לא מזכיר (לבדיקה בעין)', null],
        'avg_new_terms' => ['מילים חדשות לחיפוש, בממוצע למוצר', true],
        'avg_ms' => ['זמן ממוצע לתמונה (ms)', false],
        'avg_output_tokens' => ['טוקני יציאה בממוצע', false],
        'reading_cost_usd' => ['עלות קריאת התמונות ($)', false],
        'shared_cost_usd' => ['עלות משותפת: וקטור התמונה והטקסט ($)', null],
        'total_cost_usd' => ['עלות כוללת של המאגר ($)', false],
        'per_1000_products_usd' => ['לכל 1,000 מוצרים ($)', false],
    ];

    private const MARKS = ['match' => '✓', 'partial' => '≈', 'wrong' => '✗', 'missed' => '–', 'unknown' => '·', 'unverified' => '?', 'failed' => '!'];

    /**
     * @param  array<string, list<array<string, mixed>>>  $flowConfigs
     * @param  array<string, Store>  $stores
     * @param  array<string, list<array<string, mixed>>>  $rows
     * @param  array<string, array<string, mixed>>  $summaries
     * @param  list<array{catalog: array<string, mixed>, flows: array<string, array{meta: array<string, mixed>, evaluation: array<string, mixed>}>}>  $products
     */
    public static function build(string $dir, string $shop, array $flowConfigs, array $stores, array $rows, array $summaries, array $products, int $examples, int $skipped): void
    {
        $names = array_keys($summaries);
        $html = self::head('השוואת מאגרי מידע')
            .'<h1>השוואת מאגרי מידע: '.self::e(implode(' מול ', $names)).'</h1>'
            .'<p class="muted">'.self::e($shop).' · '.count($products).' מוצרים'.($skipped > 0 ? ' · '.$skipped.' דולגו (תמונה לא נטענה)' : '').' · פרומפט '.MetaBuilder::PROMPT_VERSION.' · '.gmdate('Y-m-d H:i').' UTC</p>';

        $html .= '<h2>הזרימות</h2><ul>';
        foreach ($names as $name) {
            $html .= '<li><b>'.self::e($name).'</b>: '.self::e(implode(' ← גיבוי: ', array_map(fn (array $s): string => $s['model'], $flowConfigs[$name]))).'</li>';
        }
        $html .= '</ul>';

        $html .= '<h2>סיכום</h2><table><tr><th></th>';
        foreach ($names as $name) {
            $html .= '<th>'.self::e($name).'</th>';
        }
        $html .= '</tr>';
        foreach (self::METRICS as $key => [$label, $more]) {
            $values = array_map(fn (string $n) => $summaries[$n][$key], $names);
            $numbers = array_filter($values, 'is_numeric');
            $best = $more === null || count(array_unique($numbers)) < 2 ? null : ($more ? max($numbers) : min($numbers));
            $html .= '<tr><td>'.self::e($label).'</td>';
            foreach ($values as $value) {
                $html .= '<td class="'.($best !== null && $value == $best ? 'best' : '').'">'.self::e($value === null ? '—' : (string) $value).'</td>';
            }
            $html .= '</tr>';
        }
        $html .= '</table><p class="muted">"מתאים לקטלוג" נבדק רק במוצרים שהקטלוג שלהם אומר את הפרט. סתירה: חומר, אבן או סוג שהקטלוג אומר אחרת. צבע שהקטלוג לא מזכיר נספר כ"לבדיקה בעין", לא כטעות.</p>';

        $html .= '<h2>עלות לפי שלב</h2><div class="cols">';
        foreach ($names as $name) {
            $html .= '<div><h3>'.self::e($name).'</h3><table><tr><th>שלב</th><th>מודל</th><th>קריאות</th><th>נכשלו</th><th>טוקנים נכנסים</th><th>טוקנים יוצאים</th><th>$</th></tr>';
            foreach ($stores[$name]->costs() as $c) {
                $html .= '<tr><td>'.self::e($c['step']).'</td><td>'.self::e($c['model']).'</td><td>'.$c['calls'].'</td><td>'.$c['failed'].'</td><td>'.$c['input_tokens'].'</td><td>'.$c['output_tokens'].'</td><td>'.$c['cost_usd'].'</td></tr>';
            }
            $html .= '</table></div>';
        }
        $html .= '</div>';

        $html .= '<h2>דוגמאות: מה ייכנס לאינדקס</h2>';
        foreach (array_slice($products, 0, $examples) as $product) {
            $html .= self::example($product, $names);
        }

        $html .= '<h2>כל המוצרים</h2><p class="muted">✓ מתאים · ≈ חלקי · ✗ שגוי · – החמיץ · ? הקטלוג לא אומר · · אין מידע · ! נכשל. בכל תא: סוג / צבע / חומר / אבן.</p><table><tr><th>מוצר</th>';
        foreach ($names as $name) {
            $html .= '<th>'.self::e($name).'</th>';
        }
        $html .= '</tr>';
        foreach ($products as $product) {
            $html .= '<tr><td><a href="'.self::e($product['catalog']['source']['url']).'">'.self::e(mb_substr($product['catalog']['text']['title'], 0, 70)).'</a></td>';
            foreach ($names as $name) {
                $ev = $product['flows'][$name]['evaluation'];
                $html .= '<td>'.implode(' ', array_map(fn (string $f): string => self::MARKS[$ev[$f]] ?? '·', ['type', 'color', 'material', 'stone']))
                    .($ev['conflicts'] !== [] ? ' <span class="bad">'.self::e(implode(', ', $ev['conflicts'])).'</span>' : '')
                    .($ev['fallback'] ? ' <span class="tag">גיבוי</span>' : '')
                    .' <span class="muted">$'.number_format($ev['cost_usd'], 5).'</span></td>';
            }
            $html .= '</tr>';
        }
        $html .= '</table></body></html>';

        file_put_contents($dir.'/report.html', $html);
        self::sheets($dir, $names, $products);
    }

    /**
     * @param  array<string, array<string, mixed>>  $answers
     */
    public static function query(string $dir, string $raw, ?string $image, array $parsed, array $answers, ?string $note, float $cost): string
    {
        $html = self::head('חיפוש: '.$raw)
            .'<h1>'.self::e($raw !== '' ? $raw : 'חיפוש לפי תמונה').'</h1>'
            .'<p class="muted">מילים: "'.self::e($parsed['words']).'"'
            .($parsed['min'] !== null ? ' · מ־'.self::e((string) $parsed['min']) : '').($parsed['max'] !== null ? ' · עד '.self::e((string) $parsed['max']) : '')
            .($parsed['filters'] !== [] ? ' · מסננים: '.self::e(json_encode($parsed['filters'], JSON_UNESCAPED_UNICODE)) : '')
            .($parsed['size'] !== null ? ' · מידה '.self::e($parsed['size']) : '')
            .($image !== null ? ' · תמונה: '.self::e($image) : '')
            .' · עלות הווקטור של החיפוש $'.number_format($cost, 6).'</p>'
            .($note !== null ? '<p class="bad">'.self::e($note).'</p>' : '')
            .'<div class="cols">';

        foreach ($answers as $name => $answer) {
            $total = $answer['summary']['total_cost_usd'] ?? null;
            $html .= '<div><h2>'.self::e($name).'</h2><p class="muted">'.self::e(implode(' ← ', array_column((array) $answer['flow'], 'model')))
                .($total !== null ? ' · עלות המאגר $'.self::e((string) $total) : '').' · '.$answer['candidates'].' עברו את המסננים'
                .($answer['relaxed'] ? ' · <span class="bad">אף מוצר לא עבר את כל המסננים: הוסרו (המחיר נשאר)</span>' : '').'</p>';
            foreach ($answer['results'] as $i => $r) {
                $sources = [];
                foreach (['product_type', 'color', 'material', 'stone'] as $field) {
                    if (($r['filters'][$field] ?? []) !== []) {
                        $from = $r['filters'][$field.'_source'] ?? '';
                        $sources[] = '<span class="'.($from === 'image' ? 'tag' : '').'">'.self::e($field.': '.implode(', ', $r['filters'][$field]).($from === 'image' ? ' (מהתמונה)' : '')).'</span>';
                    }
                }
                $html .= '<div class="card"><img src="'.self::e($r['image_url']).'" loading="lazy" alt=""><div><b>'.($i + 1).'.</b> <a href="'.self::e($r['url']).'">'.self::e($r['title']).'</a>'
                    .'<div>₪'.self::e((string) $r['price']).' · ציון '.self::e((string) $r['score']).'</div>'
                    .'<div class="muted">'.self::e(implode(' · ', array_map(fn (string $k, ?float $v): string => $k.' '.($v ?? '—'), array_keys($r['parts']), $r['parts']))).'</div>'
                    .'<div class="small">'.implode(' ', $sources).'</div></div></div>';
            }
            if ($answer['results'] === []) {
                $html .= '<p class="muted">אין תוצאות.</p>';
            }
            $html .= '</div>';
        }

        $html .= '</div></body></html>';
        $folder = $dir.'/queries';
        if (! is_dir($folder)) {
            mkdir($folder, 0777, true);
        }
        $file = $folder.'/'.gmdate('His').'-'.substr(md5($raw.$image), 0, 6).'.html';
        file_put_contents($file, $html);

        return $file;
    }

    private static function example(array $product, array $names): string
    {
        $catalog = $product['catalog'];
        $custom = $catalog['attributes']['custom'];
        $facts = array_filter([
            'סוג' => $catalog['identity']['product_type_normalized'],
            'צבע' => implode(', ', $catalog['attributes']['color']),
            'חומר' => implode(', ', $catalog['attributes']['material']),
            'אבן' => implode(', ', $custom['stones'] ?? []),
            'קארט' => $custom['karat'] ?? null,
            'משקל' => $catalog['physical']['weight'] !== null ? $catalog['physical']['weight'].' g' : null,
            'מחיר' => $catalog['commerce']['price'] !== null ? '₪'.$catalog['commerce']['price'] : null,
        ]);

        $html = '<section class="example"><div class="product"><img src="'.self::e($catalog['source']['image_url']).'" loading="lazy" alt=""><div><h3><a href="'.self::e($catalog['source']['url']).'">'.self::e($catalog['text']['title']).'</a></h3>'
            .'<p class="muted">מהקטלוג: '.self::e(implode(' · ', array_map(fn (string $k, $v): string => $k.' '.$v, array_keys($facts), $facts))).'</p></div></div><div class="cols">';

        foreach ($names as $name) {
            $meta = $product['flows'][$name]['meta'];
            $ev = $product['flows'][$name]['evaluation'];
            $image = $meta['image_analysis'];
            $f = $meta['search']['filterable_fields'];
            $html .= '<div><h4>'.self::e($name).($image !== null ? ' · '.self::e($image['model']) : ' · <span class="bad">לא נקרא</span>').'</h4>';

            if ($image !== null) {
                $html .= '<p>'.self::e($image['caption']).'</p><table>';
                foreach (['product_type' => ['סוג', 'type'], 'color' => ['צבע', 'color'], 'material' => ['חומר', 'material'], 'stone' => ['אבן', 'stone'], 'shape' => ['צורה', 'shape'], 'style' => ['סגנון', 'style'], 'pattern' => ['דוגמה', 'pattern']] as $field => [$label, $group]) {
                    $from = $f[$field.'_source'] ?? null;
                    $values = array_map(fn (string $v): string => Vocabulary::hebrew($group, $v).' · '.$v, $f[$field]);
                    $html .= '<tr><td>'.$label.'</td><td>'.self::e(implode(', ', $values)).($from === 'image' ? ' <span class="tag">מהתמונה</span>' : '').'</td></tr>';
                }
                $html .= '<tr><td>מילים</td><td>'.self::e(implode(', ', $image['visual_keywords'])).'</td></tr>'
                    .'<tr><td>חיפושים</td><td>'.self::e(implode(' | ', $image['image_search_terms'])).'</td></tr></table>';
            }

            $flags = [];
            foreach (['conflicts' => 'סתירות', 'missing' => 'חסר', 'unverified' => 'לבדיקה'] as $key => $label) {
                if ($ev[$key] !== []) {
                    $flags[] = $label.': '.implode(', ', $ev[$key]);
                }
            }
            if ($ev['issues'] !== []) {
                $flags[] = 'תיקוני מבנה: '.implode(', ', $ev['issues']);
            }
            $html .= '<p class="small '.($ev['conflicts'] !== [] ? 'bad' : 'muted').'">'.self::e(implode(' · ', $flags) ?: 'אין הערות').' · $'.number_format($ev['cost_usd'], 5).' · '.round($ev['ms'] / 1000, 1).'s</p>'
                .'<details><summary>אובייקט המטא המלא</summary><pre dir="ltr">'.self::e((string) json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)).'</pre></details></div>';
        }

        return $html.'</div></section>';
    }

    private static function sheets(string $dir, array $names, array $products): void
    {
        $metrics = fopen($dir.'/metrics.csv', 'w');
        $review = fopen($dir.'/review.csv', 'w');
        fwrite($metrics, "\xEF\xBB\xBF");
        fwrite($review, "\xEF\xBB\xBF");
        fputcsv($metrics, ['flow', 'product_id', 'title', 'answered', 'fallback', 'type', 'color', 'material', 'stone', 'conflicts', 'missing', 'unverified', 'new_terms', 'schema_issues', 'spec_leaks', 'input_tokens', 'output_tokens', 'reading_cost_usd', 'cost_usd', 'ms']);
        fputcsv($review, ['flow', 'product_id', 'title', 'url', 'image_url', 'model', 'caption', 'type', 'colors', 'materials', 'stones', 'shapes', 'keywords', 'search_terms', 'type_correct_1_0', 'colors_correct_1_0', 'material_correct_1_0', 'invented_something_1_0', 'keywords_useful_1_5', 'notes']);

        foreach ($products as $product) {
            $catalog = $product['catalog'];
            foreach ($names as $name) {
                $ev = $product['flows'][$name]['evaluation'];
                $image = $product['flows'][$name]['meta']['image_analysis'];
                fputcsv($metrics, [$name, $catalog['identity']['product_id'], $catalog['text']['title'], (int) $ev['answered'], (int) $ev['fallback'], $ev['type'], $ev['color'], $ev['material'], $ev['stone'],
                    implode(' ', $ev['conflicts']), implode(' ', $ev['missing']), implode(' ', $ev['unverified']), $ev['new_terms'], $ev['schema_issues'], $ev['spec_leaks'],
                    $ev['input_tokens'], $ev['output_tokens'], round($ev['reading_cost_usd'], 6), round($ev['cost_usd'], 6), $ev['ms']]);
                fputcsv($review, [$name, $catalog['identity']['product_id'], $catalog['text']['title'], $catalog['source']['url'], $catalog['source']['image_url'], $image['model'] ?? '', $image['caption'] ?? '',
                    $image['product_type_visible'] ?? '', implode(', ', $image['visible_colors'] ?? []), implode(', ', $image['visible_materials'] ?? []), implode(', ', $image['visible_stones'] ?? []),
                    implode(', ', $image['visible_shapes'] ?? []), implode(', ', $image['visual_keywords'] ?? []), implode(' | ', $image['image_search_terms'] ?? []), '', '', '', '', '', '']);
            }
        }

        fclose($metrics);
        fclose($review);
    }

    private static function head(string $title): string
    {
        return '<!doctype html><html lang="he" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>'.self::e($title).'</title><style>'
            .':root{--bg:#fff;--fg:#1d1d1f;--muted:#6e6e73;--line:#e5e5ea;--best:#e8f5e9;--bad:#c62828;--tag:#fff3e0}'
            .'@media (prefers-color-scheme:dark){:root{--bg:#161618;--fg:#f2f2f7;--muted:#a1a1a6;--line:#3a3a3c;--best:#1e3a24;--bad:#ff6b6b;--tag:#3d2e14}}'
            .'body{background:var(--bg);color:var(--fg);font:14px/1.5 system-ui,-apple-system,"Segoe UI",Arial,sans-serif;margin:0 auto;padding:16px;max-width:1500px}'
            .'a{color:inherit}table{border-collapse:collapse;width:100%;margin:8px 0}td,th{border-bottom:1px solid var(--line);padding:6px 8px;text-align:start;vertical-align:top}'
            .'.best{background:var(--best);font-weight:600}.muted{color:var(--muted)}.bad{color:var(--bad)}.small{font-size:12px}.tag{background:var(--tag);border-radius:4px;padding:0 4px;font-size:12px}'
            .'.cols{display:grid;grid-template-columns:repeat(auto-fit,minmax(320px,1fr));gap:16px}.example{border-top:2px solid var(--line);padding:16px 0}'
            .'.product{display:flex;gap:16px;align-items:flex-start}.product img{width:140px;height:140px;object-fit:contain;border-radius:7px;border:1px solid var(--line)}'
            .'.card{display:flex;gap:12px;padding:8px 0;border-bottom:1px solid var(--line)}.card img{width:84px;height:84px;object-fit:contain;border-radius:7px;border:1px solid var(--line);flex:none}'
            .'pre{white-space:pre-wrap;word-break:break-word;font-size:11px;max-height:520px;overflow:auto;background:rgba(127,127,127,.08);padding:8px;border-radius:6px}'
            .'@media (max-width:640px){.product{flex-direction:column}.product img{width:100%;height:auto}}'
            .'</style></head><body>';
    }

    private static function e(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
