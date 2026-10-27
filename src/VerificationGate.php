<?php

declare(strict_types=1);

namespace SrtFixer;

use SrtValidator\ReadabilityChecker;
use SrtValidator\SrtTranslationValidator;
use SrtValidator\SubtitleLoader;

/**
 * Ladder step 6: the self-verification gate. After the repair chain has
 * computed new blocks, the gate proves that the repair did not damage
 * anything before the result may be applied or written:
 *
 *  1. Structural invariants (always checked, in memory): the fixer only
 *     re-times cues, so cue count and text must be identical, cues may
 *     only get wider (never shorter, never delayed, never negative, never
 *     past the duration ceiling) and neighbouring cues may never overlap
 *     more than they already did.
 *
 *  2. Validator comparison (when a --source file is given): the validator
 *     runs on (source, input) and on (source, repaired) and the defect
 *     counts are compared per type. Timing divergence from the source is
 *     the fixer's sanctioned domain - moving cues away from the source
 *     timeline to buy reading time is exactly what TimeHarvester does -
 *     so `timestamp_mismatch` deltas are reported but never fail the
 *     gate. `reading_speed` is judged per cue, not by count: the
 *     validator only counts over-limit captions whose aligned source
 *     caption is not itself overloaded (inheritance exemption), and
 *     re-timing legitimately re-pairs the alignment - a growing count
 *     is rejected only when a newly counted caption's cps actually rose
 *     above its input cps (corpus soak 2026-10-27: ai1/44575, 44576,
 *     44586, 44593 all "grew" while every newly counted cue improved
 *     3-5x). The aligner's
 *     classifications (merged/split/extra/missing captions) are derived
 *     from the very start times the fixer is sanctioned to move, so
 *     their deltas are likewise reported (sanctioned_deltas), not
 *     rejected. Every other defect type must not appear or grow: those
 *     cover content the fixer never touches (text, cue count, scripts,
 *     format), so any delta means a component bug and the whole repair
 *     is rejected.
 *
 * A failed gate rejects the repair entirely (the original blocks are
 * returned unchanged) - partial application would hide the bug that the
 * gate exists to catch.
 */
final class VerificationGate
{
    /**
     * Defect types the fixer is allowed to change by design:
     * timestamp_mismatch/reading_speed (timing is the fixer's domain)
     * and the aligner's classifications - merged/split/extra/missing
     * captions are derived from cue start times within the validator's
     * 0.5s pairing tolerance, so a sanctioned in-pull can legitimately
     * reclassify a pairing (proven on dump #6: harvest-only, no
     * wrapper, grows merged_captions by 2). All four are warning-tier
     * and never fail a verdict on their own. Their deltas are REPORTED
     * (sanctioned_deltas), never silently ignored; every other defect
     * type is guarded and may not appear or grow.
     */
    public const SANCTIONED_TYPES = [
        'timestamp_mismatch',
        'reading_speed',
        'merged_captions',
        'split_captions',
        'extra_caption',
        'missing_caption',
    ];

    /** Float comparison slack, in seconds. */
    private const EPSILON = 0.002;

    /**
     * Float comparison slack for per-cue cps comparison, in chars per
     * second. Text is fingerprint-identical and durations only grow, so
     * float noise cannot raise a cue's cps; the one real mechanism is a
     * wrap join space (>= 1 char / duration). The slack only absorbs
     * rounding, never a join space.
     */
    private const CPS_EPSILON = 0.05;

    /** Maximum reasons kept in the result when a check fails loudly. */
    private const MAX_REASONS = 5;

    /**
     * @param list<array{start: float, end: float, lines: list<string>}> $inputBlocks
     * @param list<array{start: float, end: float, lines: list<string>}> $repairedBlocks
     * @param string|null $sourceFile original subtitle file; when null the
     *        validator comparison is skipped (structure only)
     * @param string|null $language target language code as passed to fix()
     * @return array{
     *     passed: bool,
     *     reasons: list<string>,
     *     structure_ok: bool,
     *     validator_compared: bool,
     *     validator_skipped_reason: string|null,
     *     guarded_defects: array<string, int>|null,
     *     guarded_regressions: array<string, int>,
     *     reading_speed_before: int|null,
     *     reading_speed_after: int|null,
     *     timestamp_mismatch_before: int|null,
     *     timestamp_mismatch_after: int|null,
     *     sanctioned_deltas: array<string, array{before: int, after: int}>
     * }
     */
    public function verify(
        array $inputBlocks,
        array $repairedBlocks,
        ?string $sourceFile,
        ?string $translationFile,
        ?string $language
    ): array {
        $reasons = $this->structuralDefects($inputBlocks, $repairedBlocks);
        $structureOk = $reasons === [];

        $guarded = null;
        $regressions = [];
        $readingBefore = null;
        $readingAfter = null;
        $tsBefore = null;
        $tsAfter = null;
        $sanctionedDeltas = [];

        if ($sourceFile !== null && $translationFile !== null && $structureOk) {
            $comparison = $this->compareWithValidator($sourceFile, $translationFile, $inputBlocks, $repairedBlocks, $language);
            if ($comparison['skipped']) {
                // A source the validator cannot parse makes the full
                // comparison impossible, but says nothing about the
                // repair: the structural checks above already verify
                // everything the fixer changes (timings only). Skip
                // loudly instead of rejecting.
                $skipReason = $comparison['skip_reason'];
            } else {
                $guarded = $comparison['guarded'];
                $regressions = $comparison['regressions'];
                $readingBefore = $comparison['reading_speed_before'];
                $readingAfter = $comparison['reading_speed_after'];
                $tsBefore = $comparison['timestamp_mismatch_before'];
                $tsAfter = $comparison['timestamp_mismatch_after'];
                $sanctionedDeltas = $comparison['sanctioned_deltas'];
                foreach ($comparison['reasons'] as $reason) {
                    $reasons[] = $reason;
                }
            }
        }

        return [
            'passed' => $reasons === [],
            'reasons' => $reasons,
            'structure_ok' => $structureOk,
            'validator_compared' => $guarded !== null,
            'validator_skipped_reason' => $skipReason ?? null,
            'guarded_defects' => $guarded,
            'guarded_regressions' => $regressions,
            'reading_speed_before' => $readingBefore,
            'reading_speed_after' => $readingAfter,
            'timestamp_mismatch_before' => $tsBefore,
            'timestamp_mismatch_after' => $tsAfter,
            'sanctioned_deltas' => $sanctionedDeltas,
        ];
    }

    /**
     * @param list<array{start: float, end: float, lines: list<string>}> $input
     * @param list<array{start: float, end: float, lines: list<string>}> $repaired
     * @return list<string>
     */
    private function structuralDefects(array $input, array $repaired): array
    {
        $defects = [];
        $extra = 0;
        $add = function (string $reason) use (&$defects, &$extra): void {
            if (count($defects) < self::MAX_REASONS) {
                $defects[] = $reason;
            } else {
                $extra++;
            }
        };

        if (count($input) !== count($repaired)) {
            $add(sprintf('cue count changed: %d -> %d', count($input), count($repaired)));
            return $this->finishReasons($defects, $extra);
        }

        $count = count($input);
        for ($i = 0; $i < $count; $i++) {
            $n = $i + 1;
            $in = $input[$i];
            $rep = $repaired[$i];

            // Text identity up to re-wrapping: the atom fingerprint
            // sanctions moving line breaks (the LineWrapper's charter)
            // but rejects any character change or word merge/split.
            if (LineWrapper::atomFingerprint($in['lines'] ?? []) !== LineWrapper::atomFingerprint($rep['lines'] ?? [])) {
                $add(sprintf('cue #%d: text changed', $n));
            }

            $inStart = (float) $in['start'];
            $inEnd = (float) $in['end'];
            $repStart = (float) $rep['start'];
            $repEnd = (float) $rep['end'];

            if ($repEnd < $inEnd - self::EPSILON) {
                $add(sprintf('cue #%d: end moved earlier (%.3f -> %.3f)', $n, $inEnd, $repEnd));
            }
            if ($repStart > $inStart + self::EPSILON) {
                $add(sprintf('cue #%d: start moved later (%.3f -> %.3f)', $n, $inStart, $repStart));
            }
            if ($repStart < 0) {
                $add(sprintf('cue #%d: start is negative (%.3f)', $n, $repStart));
            }
            if ($repEnd - $repStart < $inEnd - $inStart - self::EPSILON) {
                $add(sprintf('cue #%d: duration shortened', $n));
            }
            // Never-worsen semantics: repairs may not create NEW ceiling
            // violations, but cues that already ran long in the input stay
            // long (shortening an end would break the never-earlier rule).
            $allowedDuration = max(
                $inEnd - $inStart,
                TimeHarvester::MAX_DURATION_MS / 1000
            );
            if ($repEnd - $repStart > $allowedDuration + self::EPSILON) {
                $add(sprintf('cue #%d: duration exceeds the %d ms ceiling', $n, TimeHarvester::MAX_DURATION_MS));
            }

            if ($i + 1 < $count) {
                $overlapIn = max(0.0, $inEnd - (float) $input[$i + 1]['start']);
                $overlapRep = max(0.0, $repEnd - (float) $repaired[$i + 1]['start']);
                if ($overlapRep > $overlapIn + self::EPSILON) {
                    $add(sprintf('cue #%d: overlap with the next cue grew (%.3f -> %.3f)', $n, $overlapIn, $overlapRep));
                }
            }
        }

        return $this->finishReasons($defects, $extra);
    }

    /**
     * @param string $sourceFile
     * @param string $translationFile
     * @param list<array{start: float, end: float, lines: list<string>}> $inputBlocks
     * @param list<array{start: float, end: float, lines: list<string>}> $repairedBlocks
     * @param string|null $language
     * @return array{guarded: array<string, int>, regressions: array<string, int>, reasons: list<string>, reading_speed_before: int, reading_speed_after: int, timestamp_mismatch_before: int, timestamp_mismatch_after: int, sanctioned_deltas: array<string, array{before: int, after: int}>, skipped: bool, skip_reason: string|null}
     */
    private function compareWithValidator(string $sourceFile, string $translationFile, array $inputBlocks, array $repairedBlocks, ?string $language): array
    {
        // Both sides go through the same lenient loader round-trip: the
        // fixer inevitably normalizes when it writes, so validating the
        // input file as-is against a normalized output would compare
        // apples to oranges (a format-broken input bails out of the
        // validator early; the rewritten output does not). Normalizing
        // both makes the normalization cancel out, leaving only the
        // fixer's own changes in the delta.
        $extension = strtolower(pathinfo($translationFile, PATHINFO_EXTENSION));
        $suffix = $extension === 'vtt' ? '.vtt' : '.srt';
        $tempInput = tempnam(sys_get_temp_dir(), 'srtfix-gate-in-') . $suffix;
        $tempOutput = tempnam(sys_get_temp_dir(), 'srtfix-gate-out-') . $suffix;

        try {
            $subs = SubtitleLoader::loadFile($translationFile);
            $subs->save($tempInput);
            $subs->setInternalFormat($repairedBlocks);
            $subs->save($tempOutput);

            // Not inside a PHAR, so the validator can build its own
            // language detector (glob() works on real paths).
            $validator = new SrtTranslationValidator();
            $before = $validator->validate($sourceFile, $tempInput, $language ?? '');
            $after = $validator->validate($sourceFile, $tempOutput, $language ?? '');
        } finally {
            @unlink($tempInput);
            @unlink($tempOutput);
        }

        $reasons = [];
        foreach ([$before, $after] as $index => $result) {
            foreach ($result['defects'] as $defect) {
                if (($defect['type'] ?? '') === 'source_parse_failed') {
                    return [
                        'guarded' => [],
                        'regressions' => [],
                        'reasons' => [],
                        'reading_speed_before' => 0,
                        'reading_speed_after' => 0,
                        'timestamp_mismatch_before' => 0,
                        'timestamp_mismatch_after' => 0,
                        'sanctioned_deltas' => [],
                        'skipped' => true,
                        'skip_reason' => 'the validator could not parse the source file, so the full comparison is unavailable (structural checks still ran)',
                    ];
                }
            }
        }

        $beforeCounts = $this->defectCounts($before);
        $afterCounts = $this->defectCounts($after);

        $regressions = [];
        foreach ($afterCounts as $type => $count) {
            $delta = $count - ($beforeCounts[$type] ?? 0);
            if ($delta > 0 && !in_array($type, self::SANCTIONED_TYPES, true)) {
                $regressions[$type] = $delta;
            }
        }
        if ($regressions !== []) {
            foreach ($regressions as $type => $delta) {
                $reasons[] = sprintf('validator defect "%s" grew by %d', $type, $delta);
            }
        }

        // Sanctioned deltas are reported, never silent: a consumer must
        // be able to see that the repair reclassified pairings.
        $sanctionedDeltas = [];
        foreach (self::SANCTIONED_TYPES as $type) {
            $countBefore = $beforeCounts[$type] ?? 0;
            $countAfter = $afterCounts[$type] ?? 0;
            if ($countBefore !== $countAfter) {
                $sanctionedDeltas[$type] = ['before' => $countBefore, 'after' => $countAfter];
            }
        }

        $readingBefore = $beforeCounts['reading_speed'] ?? 0;
        $readingAfter = $afterCounts['reading_speed'] ?? 0;
        if ($readingAfter > $readingBefore) {
            // A grown count is a regression only when a cue's reading
            // speed actually worsened. The count also grows when the
            // repair's timing changes re-pair a caption onto a less
            // overloaded source cue, dropping the validator's
            // inheritance exemption for a cue the repair just made
            // EASIER to read - bookkeeping, not damage.
            [$worsened, $improved] = $this->readingSpeedRegressions($before, $after, $inputBlocks);
            foreach ($worsened as $n => $cps) {
                $reasons[] = sprintf(
                    'cue #%d: reading speed worsened (%.1f -> %.1f cps)',
                    $n,
                    $cps[0],
                    $cps[1]
                );
            }
            if ($worsened === [] && $improved > 0) {
                $sanctionedDeltas['reading_speed_newly_counted'] = [
                    'before' => $readingBefore,
                    'after' => $readingAfter,
                ];
            }
        }

        return [
            'guarded' => array_diff_key(
                $afterCounts,
                array_fill_keys(self::SANCTIONED_TYPES, 0)
            ),
            'regressions' => $regressions,
            'reasons' => $reasons,
            'reading_speed_before' => $beforeCounts['reading_speed'] ?? 0,
            'reading_speed_after' => $readingAfter,
            'timestamp_mismatch_before' => $beforeCounts['timestamp_mismatch'] ?? 0,
            'timestamp_mismatch_after' => $afterCounts['timestamp_mismatch'] ?? 0,
            'sanctioned_deltas' => $sanctionedDeltas,
            'skipped' => false,
            'skip_reason' => null,
        ];
    }

    /**
     * Per-cue never-worsen examination of a grown reading_speed count.
     *
     * The validator counts a reading_speed defect only for captions
     * that exceed the profile limit AND whose aligned source caption is
     * not itself overloaded (inheritance exemption). Timing repairs
     * legitimately change the alignment pairing, so a caption can start
     * counting while its own reading speed got better. A regression
     * exists only where a newly counted caption's cps rose above its
     * own cps in the input blocks.
     *
     * @param array<string, mixed> $beforeResult validator result on (source, input)
     * @param array<string, mixed> $afterResult validator result on (source, repaired)
     * @param list<array{start: float, end: float, lines: list<string>}> $inputBlocks
     * @return array{0: array<int, array{0: float, 1: float}>, 1: int}
     *         0: worsened captions (caption number => [cps before, cps after])
     *         1: count of newly counted captions that did NOT worsen
     */
    private function readingSpeedRegressions(array $beforeResult, array $afterResult, array $inputBlocks): array
    {
        $counted = static function (array $result): array {
            $out = [];
            foreach ($result['defects'] as $defect) {
                if (($defect['type'] ?? '') === 'reading_speed') {
                    $out[(int) ($defect['caption_number'] ?? 0)] = (float) ($defect['cps'] ?? 0.0);
                }
            }
            return $out;
        };
        $beforeDefects = $counted($beforeResult);
        $afterDefects = $counted($afterResult);

        $worsened = [];
        $improved = 0;
        foreach ($afterDefects as $n => $cpsAfter) {
            if (isset($beforeDefects[$n])) {
                continue; // already counted before: not newly counted
            }

            $block = $inputBlocks[$n - 1] ?? null; // caption_number is 1-based
            if ($block === null) {
                // Cannot verify the caption: fail closed.
                $worsened[$n] = [0.0, $cpsAfter];
                continue;
            }

            $chars = ReadabilityChecker::plainLength(implode(' ', array_map('strval', $block['lines'])));
            $duration = (float) $block['end'] - (float) $block['start'];
            // A zero-duration input caption is unreadable by definition;
            // anything the repair did with it is an improvement.
            $cpsBefore = $duration > 0 ? $chars / $duration : INF;

            if ($cpsAfter > $cpsBefore + self::CPS_EPSILON) {
                $worsened[$n] = [$cpsBefore, $cpsAfter];
            } else {
                $improved++;
            }
        }

        return [$worsened, $improved];
    }

    /** @return array<string, int> */
    private function defectCounts(array $result): array
    {
        $counts = [];
        foreach ($result['defects'] as $defect) {
            $type = (string) ($defect['type'] ?? 'unknown');
            $counts[$type] = ($counts[$type] ?? 0) + 1;
        }

        return $counts;
    }

    /**
     * @param list<string> $defects
     * @return list<string>
     */
    private function finishReasons(array $defects, int $extra): array
    {
        if ($extra > 0) {
            $defects[] = sprintf('... and %d more', $extra);
        }

        return $defects;
    }
}
