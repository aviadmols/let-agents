<?php

namespace App\Modules\Search\Support;

/**
 * What one search did, stage by stage, for the search check screen (SearchCatalog::explain()): what code read from
 * the words, how each record was found, and why each that was found was left out. Filled only when a check asks;
 * a shopper's search carries none.
 */
final class SearchTrace
{
    /** @var array<string, mixed> what code read from the words: a price, words left out, actions, filters, a question */
    public array $read = [];

    /** @var array<string, list<array<string, mixed>>> record id => each way it was found, in order: ['by' => words|meaning|picture|…] */
    public array $found = [];

    /** @var array<string, string> record id => why it was left out, the first reason only */
    public array $left = [];

    /** @var array<string, array<string, mixed>> stage => what it decided (the meaning's floor, a subject too wide…) */
    public array $steps = [];

    /** @param array<string, mixed> $detail */
    public function found(string $id, string $by, array $detail = []): void
    {
        $this->found[$id][] = ['by' => $by] + $detail;
    }

    /**
     * The records of $before that $after no longer holds, left out for $why.
     *
     * @param  list<string>  $before
     * @param  list<string>  $after
     */
    public function dropped(array $before, array $after, string $why): void
    {
        foreach (array_diff($before, $after) as $id) {
            $this->left[$id] ??= $why;
        }
    }

    public function left(string $id, string $why): void
    {
        $this->left[$id] ??= $why;
    }

    /** @param array<string, mixed> $detail */
    public function step(string $step, array $detail = []): void
    {
        $this->steps[$step] = $detail + ($this->steps[$step] ?? []);
    }
}
