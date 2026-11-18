<?php

declare(strict_types=1);

namespace App\Service\AudioLibrary\Inventory;

/** Merge of exact, strictly strcmp-sorted relative paths; no storage certification. */
final class AudioInventoryPathComparator
{
    /**
     * Each input is traversed once with one current path per stream. Validation
     * is progressive, including the remaining tail after the other stream ends.
     * No path, sort key or difference list is accumulated or normalized.
     *
     * The consumer receives {type: missing|unexpected, relative_path: string}.
     * Only strict false interrupts, counting the difference already delivered.
     * No iterator is advanced after refusal. Iterator and consumer exceptions
     * propagate unchanged without a result or secondary draining operation.
     * The caller owns suspended iterables and their resource lifetimes.
     *
     * '/' separates components, as in scanner output. Literal backslashes inside
     * POSIX names remain bytes, not alternative separators. Recognizable Windows
     * rooted/drive forms are rejected. Historical names need no naming convention
     * or extension check. Structural validation grants no filesystem permission.
     *
     * paths_equal describes only the fully consumed input streams: no claim about
     * their provenance, physical presence, sizes, contents, scope or generation.
     *
     * @param iterable<string> $expectedPaths
     * @param iterable<string> $observedPaths
     * @param \Closure(array{type:'missing'|'unexpected', relative_path:string}):mixed $consumeDifference
     * @return array{status:'completed'|'interrupted', matched_count:int, missing_count:int, unexpected_count:int, paths_equal:bool}
     */
    public function compare(iterable $expectedPaths, iterable $observedPaths, \Closure $consumeDifference): array
    {
        $expected = $this->validatedPaths($expectedPaths);
        $observed = $this->validatedPaths($observedPaths);
        $matched = $missing = $unexpected = 0;

        while (true) {
            $hasExpected = $expected->valid();
            $hasObserved = $observed->valid();
            if (!$hasExpected && !$hasObserved) {
                return $this->result('completed', $matched, $missing, $unexpected);
            }
            $comparison = !$hasObserved ? -1 : (!$hasExpected ? 1 : strcmp($expected->current(), $observed->current()));
            if ($comparison === 0) {
                ++$matched;
                $expected->next();
                $observed->next();
                continue;
            }

            if ($comparison < 0) {
                ++$missing;
                $difference = ['type' => 'missing', 'relative_path' => $expected->current()];
            } else {
                ++$unexpected;
                $difference = ['type' => 'unexpected', 'relative_path' => $observed->current()];
            }
            if ($consumeDifference($difference) === false) {
                return $this->result('interrupted', $matched, $missing, $unexpected);
            }
            if ($comparison < 0) {
                $expected->next();
            } else {
                $observed->next();
            }
        }
    }

    private function validatedPaths(iterable $paths): \Generator
    {
        $previous = null;
        foreach ($paths as $path) {
            if (!is_string($path) || $path === '' || str_contains($path, "\0")
                || str_starts_with($path, '/') || str_starts_with($path, '\\')
                || preg_match('/^[A-Za-z]:/', $path) === 1) {
                throw new \UnexpectedValueException('Expected a structurally valid relative path string.');
            }
            foreach (explode('/', $path) as $component) {
                if ($component === '' || $component === '.' || $component === '..') {
                    throw new \UnexpectedValueException('Relative paths cannot contain empty or traversal components.');
                }
            }
            if ($previous !== null && strcmp($previous, $path) >= 0) {
                throw new \UnexpectedValueException('Input paths must be strictly strcmp-sorted without duplicates.');
            }
            $previous = $path;
            yield $path;
        }
    }

    private function result(string $status, int $matched, int $missing, int $unexpected): array
    {
        return [
            'status' => $status,
            'matched_count' => $matched,
            'missing_count' => $missing,
            'unexpected_count' => $unexpected,
            'paths_equal' => $status === 'completed' && $missing === 0 && $unexpected === 0,
        ];
    }
}
