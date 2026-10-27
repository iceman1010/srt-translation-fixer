<?php

declare(strict_types=1);

namespace SrtFixer;

use SrtValidator\ReadabilityChecker;

/**
 * Ladder step 2: harvest reading time for cues that exceed the target
 * language's reading-speed (cps) limit.
 *
 * For every over-limit cue, extend the out-time into the following gap
 * first, then pull the in-time into the preceding gap - in both cases
 * exactly as far as NEEDED to reach the limit, never further. Every cap
 * anchors to something that cannot move backwards between runs:
 *
 *   - the neighbouring cues (a 2-frame minimum gap always remains)
 *   - the absolute 7s maximum duration
 *   - time 0
 *
 * Because extensions are need-driven and anchored this way, harvesting
 * is idempotent by construction: running it over its own output moves
 * nothing (verified against real files). Deliberately NOT capped is how
 * far into a silence a cue may grow: a fixed +2s budget relative to the
 * input times would re-anchor on every run and let repeated pipeline
 * executions creep timings further and further from the speech - the
 * need-driven bound makes lingering longer than necessary impossible
 * anyway.
 *
 * All arithmetic runs in integer milliseconds so results are exact.
 * Cues that still exceed the limit after capping are reported (not
 * "fixed") with their character budget: the only remaining cure for
 * those is a shorter translation, which a mechanical fixer must not
 * invent.
 *
 * Text is never modified. Cues too short for a defined cps (< 0.2s,
 * matching the validator's analysis floor) and empty cues are passed
 * through untouched - they belong to other defect classes.
 */
final class TimeHarvester
{
    /** Minimum gap left between cues, in ms (2 frames at 23.976fps). */
    public const MIN_GAP_MS = 84;

    /** Maximum total cue duration, in ms. */
    public const MAX_DURATION_MS = 7000;

    /** Below this duration the cps is undefined (validator analysis floor). */
    private const MIN_CPS_DURATION_MS = 200;

    /**
     * @param list<array{start: float, end: float, lines: list<string>}> $blocks
     * @param float $cps target reading speed from the language profile
     * @return array{
     *     blocks: list<array{start: float, end: float, lines: list<string>}>,
     *     fixes: list<array{type: string, caption: int, start_before: float, end_before: float, start_after: float, end_after: float, out_extension: float, in_pull: float, cps_before: float, cps_after: float}>,
     *     residual: list<array{type: string, caption: int, chars: int, duration: float, cps: float, char_budget: int}>
     * }
     */
    public function harvest(array $blocks, float $cps): array
    {
        if ($cps <= 0) {
            throw new \InvalidArgumentException('cps limit must be positive, got ' . $cps);
        }

        $result = [];
        $fixes = [];
        $residual = [];
        $prevEndMs = null; // previous cue's end AFTER repair
        $count = count($blocks);

        foreach ($blocks as $index => $block) {
            $lines = array_map('strval', $block['lines'] ?? []);
            $startMs = self::toMs((float) $block['start']);
            $endMs = self::toMs((float) $block['end']);
            $originalStartMs = $startMs;
            $originalEndMs = $endMs;

            $nextStartMs = $index + 1 < $count
                ? self::toMs((float) $blocks[$index + 1]['start'])
                : null;

            $chars = ReadabilityChecker::plainLength(implode(' ', $lines));
            $neededMs = $chars > 0 && ($endMs - $startMs) >= self::MIN_CPS_DURATION_MS
                ? (int) ceil($chars * 1000 / $cps)
                : 0;

            if ($neededMs > 0 && ($endMs - $startMs) < $neededMs) {
                // 1) out-time extension into the following gap. The cap
                //    anchors to the next cue's start (never crossed) and
                //    the absolute duration ceiling - never to this cue's
                //    own times, which would re-anchor on every run.
                $capMs = $startMs + self::MAX_DURATION_MS;
                if ($nextStartMs !== null) {
                    $capMs = min($capMs, $nextStartMs - self::MIN_GAP_MS);
                }
                $endMs = max($endMs, min($startMs + $neededMs, $capMs));

                // 2) in-time pull into the preceding gap, as far as
                //    still needed, never past the previous cue (plus the
                //    minimum gap) and never below 0.
                if ($endMs - $startMs < $neededMs) {
                    $floorMs = 0;
                    if ($prevEndMs !== null) {
                        $floorMs = max($floorMs, $prevEndMs + self::MIN_GAP_MS);
                    }
                    $startMs = min($startMs, max($endMs - $neededMs, $floorMs));
                }

                $finalDurationMs = $endMs - $startMs;
                if ($finalDurationMs < $neededMs) {
                    $residual[] = [
                        'type' => 'over_reading_speed',
                        'caption' => $index + 1,
                        'chars' => $chars,
                        'duration' => round($finalDurationMs / 1000, 3),
                        'cps' => round($chars * 1000 / $finalDurationMs, 1),
                        'char_budget' => (int) floor($cps * $finalDurationMs / 1000),
                    ];
                }

                if ($startMs !== $originalStartMs || $endMs !== $originalEndMs) {
                    $fixes[] = [
                        'type' => 'duration_extended',
                        'caption' => $index + 1,
                        'start_before' => self::toSeconds($originalStartMs),
                        'end_before' => self::toSeconds($originalEndMs),
                        'start_after' => self::toSeconds($startMs),
                        'end_after' => self::toSeconds($endMs),
                        'out_extension' => round(($endMs - $originalEndMs) / 1000, 3),
                        'in_pull' => round(($originalStartMs - $startMs) / 1000, 3),
                        'cps_before' => round($chars * 1000 / ($originalEndMs - $originalStartMs), 1),
                        'cps_after' => round($chars * 1000 / ($endMs - $startMs), 1),
                    ];
                }
            }

            $result[] = [
                'start' => self::toSeconds($startMs),
                'end' => self::toSeconds($endMs),
                'lines' => $lines,
            ];
            $prevEndMs = $endMs;
        }

        return ['blocks' => $result, 'fixes' => $fixes, 'residual' => $residual];
    }

    private static function toMs(float $seconds): int
    {
        return (int) round($seconds * 1000);
    }

    private static function toSeconds(int $ms): float
    {
        return round($ms / 1000, 3);
    }
}
