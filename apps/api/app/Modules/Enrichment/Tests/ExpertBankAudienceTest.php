<?php

namespace App\Modules\Enrichment\Tests;

use App\Modules\Enrichment\Scanning\ExpertBankGate;
use PHPUnit\Framework\TestCase;

/**
 * Who a product is for stands with one value (expert_bank v3): a store that tags only "mens" pieces has a filter "for men"
 * (Jean Rachel, 19.11); every other attribute still needs two values, and "audience" still needs its products.
 */
final class ExpertBankAudienceTest extends TestCase
{
    /** @return array{0: array<string, mixed>, 1: array<string, mixed>} a bank of one-value audience and one-value craft, measured */
    private static function bank(int $menProducts): array
    {
        $bank = ['types' => [], 'attributes' => [
            ['id' => 'attribute:audience', 'key' => 'audience', 'label' => 'For', 'multiple' => false, 'types' => [], 'values' => [['id' => 'value:audience.men', 'key' => 'men', 'label' => 'Men', 'words' => ['mens']]]],
            ['id' => 'attribute:craft', 'key' => 'craft', 'label' => 'Craft', 'multiple' => false, 'types' => [], 'values' => [['id' => 'value:craft.hammered', 'key' => 'hammered', 'label' => 'Hammered', 'words' => ['hammered']]]],
        ]];
        $measured = ['types' => [], 'attributes' => [
            'audience' => ['of' => 100, 'share' => 0.4, 'values' => ['men' => ['products' => $menProducts]]],
            'craft' => ['of' => 100, 'share' => 0.4, 'values' => ['hammered' => ['products' => 40]]],
        ]];

        return [$bank, $measured];
    }

    public function test_audience_stands_with_one_value_and_another_attribute_does_not(): void
    {
        [$bank, $measured] = self::bank(40);
        [$kept, $removed] = ExpertBankGate::thin($bank, $measured, 2, 0.1);

        $this->assertSame(['audience'], array_column($kept['attributes'], 'key'));
        $this->assertSame('too_few_values', array_column($removed, null, 'id')['attribute:craft']['why']);
    }

    public function test_audience_still_needs_its_products(): void
    {
        [$bank, $measured] = self::bank(1);
        [$kept] = ExpertBankGate::thin($bank, $measured, 2, 0.1);

        $this->assertSame([], array_column($kept['attributes'], 'key'));
    }
}
