<?php

namespace App\Modules\Enrichment\Tests;

use App\Modules\Enrichment\Actions\ImportVocabulary;
use App\Modules\Enrichment\Actions\ProposeSchema;
use App\Modules\Enrichment\Console\CompleteLooksCommand;
use Tests\TestCase;

/**
 * Rocks: its vocabulary kept two stone colours of the template's thirteen (no title said "red"; they
 * say "ruby"), so a red stone its picture shows had no red to be found by. A look a picture may tell
 * keeps its values; a vocabulary that lost them gets them back from its template.
 */
final class CompleteLooksTest extends TestCase
{
    public function test_a_look_the_sample_text_does_not_say_is_kept_and_a_lost_one_comes_back(): void
    {
        $template = (array) ImportVocabulary::template('jewelry');
        $colours = array_values(array_filter($template['attributes'], fn (array $a): bool => $a['key'] === 'stone_color'))[0];
        $karat = array_values(array_filter($template['attributes'], fn (array $a): bool => $a['key'] === 'gold_karat'))[0];
        $pruned = ['attributes' => [
            ['values' => array_values(array_filter($colours['values'], fn (array $v): bool => in_array($v['key'], ['blue', 'pink'], true)))] + $colours,
            ['values' => [$karat['values'][0]]] + $karat,
        ], 'image' => ['filter_confidence' => ['stone_color' => 0.75]]];

        [$completed, $added] = CompleteLooksCommand::withLooks($pruned, $template);

        $this->assertContains('stone_color.red', $added);
        $this->assertCount(count($colours['values']), $completed['attributes'][0]['values']);
        $this->assertCount(1, $completed['attributes'][1]['values'], 'a karat is a number a picture never tells: not put back');
        $this->assertSame(0.7, $completed['image']['filter_confidence']['stone_color']);

        // And a new vocabulary keeps them: no look is dropped for words the sample does not say.
        $coverage = ['attributes' => ['stone_color' => ['share' => 0.3, 'values' => ['blue' => 0.2, 'red' => 0.0]], 'gold_karat' => ['share' => 0.5, 'values' => ['k14' => 0.5, 'k9' => 0.0]]], 'product_types' => []];
        $drops = array_column(ProposeSchema::unread($coverage, ['attributes' => [$colours, $karat]]), 'key');
        $this->assertNotContains('stone_color.red', $drops);
        $this->assertContains('gold_karat.k9', $drops, 'what no text says of a karat still goes');
    }
}
