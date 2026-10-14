<?php

namespace App\Modules\Runs\Support;

/**
 * The runs open right now, innermost last, so a model call made inside one knows whose it is.
 * RunRecorder pushes when a run starts and pops when it ends, also when the work throws. Bound
 * scoped, so under Octane a request never inherits another's run.
 */
final class RunStack
{
    /** @var list<array{id: string, shop_id: string|null, agent: string, action: string}> */
    private array $runs = [];

    public function push(string $id, ?string $shopId, string $agent, string $action): void
    {
        $this->runs[] = ['id' => $id, 'shop_id' => $shopId, 'agent' => $agent, 'action' => $action];
    }

    public function pop(): void
    {
        array_pop($this->runs);
    }

    /** @return array{id: string, shop_id: string|null, agent: string, action: string}|null */
    public function current(): ?array
    {
        return $this->runs === [] ? null : $this->runs[array_key_last($this->runs)];
    }
}
