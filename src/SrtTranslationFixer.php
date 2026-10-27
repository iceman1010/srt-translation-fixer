<?php

declare(strict_types=1);

namespace SrtFixer;

use SrtValidator\ReadabilityChecker;
use SrtValidator\ReadabilityProfile;
use SrtValidator\SubtitleLoader;

/**
 * Repairs a translated subtitle file against the target language's
 * readability profile (the same table the validator uses - single
 * source of truth: measurement lives in the validator, repair policy
 * lives here).
 *
 * Ladder steps implemented (see README "The fix ladder"):
 *   2. TimeHarvester - re-time over-limit cues into available gaps
 *   4. LineWrapper   - re-break text to cpl/lines with script-aware
 *                      rules (wrapping runs FIRST: the harvester then
 *                      sees, and covers, the final character count)
 * Steps 1 (re-slot onto the source timeline) and 3 (merge dense
 * neighbours) were dropped after probing production data. The fixer
 * never modifies text, and cues it cannot repair are reported with
 * their character budget for a condensation pass upstream.
 */
final class SrtTranslationFixer
{
    private ?float $cpsOverride = null;

    /**
     * Override the profile's reading-speed limit (CLI: --max-cps).
     */
    public function setCpsOverride(?float $cps): void
    {
        if ($cps !== null && $cps <= 0) {
            throw new \InvalidArgumentException('cps override must be positive, got ' . $cps);
        }
        $this->cpsOverride = $cps;
    }

    /**
     * Fix a translation file. Pure computation: nothing is written; the
     * repaired cue blocks are returned in the envelope and can be
     * written with toFile().
     *
     * The computed repair is accepted only after the self-verification
     * gate (see VerificationGate) proves it harmless; a rejected repair
     * is not applied ('applied' => false, original blocks returned).
     *
     * @param string $translationFile the translated subtitle file (srt or vtt)
     * @param string|null $language target language code (BCP-47-ish);
     *        null/unknown falls back to the default profile, exactly as
     *        the validator resolves it
     * @param string|null $sourceFile the original subtitle file the
     *        translation was made from; when given, the gate also runs a
     *        full validator comparison (CLI: --source)
     * @return array{
     *     file: string,
     *     language: string|null,
     *     profile: array{cps: float, cpl: int, lines: int, cps_overridden: bool},
     *     captions: int,
     *     applied: bool,
     *     verification: array<string, mixed>,
     *     fixes: list<array<string, mixed>>,
     *     residual: list<array<string, mixed>>,
     *     blocks: list<array{start: float, end: float, lines: list<string>}>,
     *     summary: array{
     *         over_limit_before: int,
     *         over_limit_after: int,
     *         line_over_before: int,
     *         line_over_after: int,
     *         max_cps_before: float,
     *         max_cps_after: float,
     *         avg_cps_before: float,
     *         avg_cps_after: float,
     *         residual_chars_to_cut: int
     *     }
     * }
     * @throws \InvalidArgumentException when the file is missing or unparseable
     */
    public function fix(string $translationFile, ?string $language = null, ?string $sourceFile = null): array
    {
        if (!is_file($translationFile) || !is_readable($translationFile)) {
            throw new \InvalidArgumentException('subtitle file does not exist or is not readable: ' . $translationFile);
        }

        if ($sourceFile !== null && (!is_file($sourceFile) || !is_readable($sourceFile))) {
            throw new \InvalidArgumentException('source file does not exist or is not readable: ' . $sourceFile);
        }

        try {
            $blocks = SubtitleLoader::loadFile($translationFile)->getInternalFormat();
        } catch (\Throwable $e) {
            throw new \InvalidArgumentException(
                'could not parse the subtitle file: ' . $translationFile . ' (' . $e->getMessage() . ')',
                0,
                $e
            );
        }

        $profile = ReadabilityProfile::for($language);
        $cps = $this->cpsOverride ?? $profile['cps'];

        $checker = new ReadabilityChecker();
        $before = $checker->analyze($blocks, $cps, null, null, $language);

        // Wrap first, then harvest: the wrapper only changes line
        // breaks, which can shift the checker's character count by the
        // join spaces between lines - the harvester, running last,
        // then covers the FINAL count, so reading speed never worsens
        // (probed across all production dump pairs: zero regressions).
        $wrap = (new LineWrapper())->wrap($blocks, $profile['cpl'], $profile['lines']);
        $harvest = (new TimeHarvester())->harvest($wrap['blocks'], $cps);
        $reverted = $this->revertWrapsThatBrokeReadingSpeed(
            $blocks,
            $harvest['blocks'],
            $harvest['residual'],
            $cps
        );
        $harvestFixes = $harvest['fixes'];

        $verification = (new VerificationGate())->verify(
            $blocks,
            $reverted['blocks'],
            $sourceFile,
            $translationFile,
            $language
        );
        $applied = $verification['passed'];

        if ($applied) {
            $finalBlocks = $reverted['blocks'];
            $wrapFixes = array_values(array_filter(
                $wrap['fixes'],
                fn (array $fix): bool => !in_array((int) $fix['caption'], $reverted['captions'], true)
            ));
            $fixes = array_merge($wrapFixes, $harvestFixes);
            $residual = $this->mergeResidual($wrap['residual'], $reverted['residual']);
        } else {
            // Rejected: nothing is applied. The residual then describes
            // the unmodified input, so the condensation handoff stays
            // truthful. The wrapper's capacity residual was computed on
            // the input blocks and stays valid as-is.
            $finalBlocks = $blocks;
            $fixes = [];
            $residual = $this->mergeResidual($wrap['residual'], $this->residualFromProblems($before['problems'], $cps));
        }

        $after = $checker->analyze($finalBlocks, $cps, null, null, $language);

        // A cue can carry both problems (over cps AND over display
        // capacity); the condensation pass needs the stricter cut, and
        // only once per cue.
        $cutByCaption = [];
        foreach ($residual as $entry) {
            $cutByCaption[(int) $entry['caption']] = max($cutByCaption[(int) $entry['caption']] ?? 0, (int) $entry['chars_over']);
        }

        return [
            'file' => $translationFile,
            'language' => $language,
            'profile' => [
                'cps' => $cps,
                'cpl' => $profile['cpl'],
                'lines' => $profile['lines'],
                'cps_overridden' => $this->cpsOverride !== null,
            ],
            'captions' => count($blocks),
            'applied' => $applied,
            'verification' => $verification,
            'fixes' => $fixes,
            'residual' => $residual,
            'blocks' => $finalBlocks,
            'summary' => [
                'over_limit_before' => $before['problems_by_type']['reading_speed'] ?? 0,
                'over_limit_after' => $after['problems_by_type']['reading_speed'] ?? 0,
                'line_over_before' => ($before['problems_by_type']['line_length'] ?? 0) + ($before['problems_by_type']['line_count'] ?? 0),
                'line_over_after' => ($after['problems_by_type']['line_length'] ?? 0) + ($after['problems_by_type']['line_count'] ?? 0),
                'max_cps_before' => $before['max_cps'],
                'max_cps_after' => $after['max_cps'],
                'avg_cps_before' => $before['avg_cps'],
                'avg_cps_after' => $after['avg_cps'],
                'residual_chars_to_cut' => array_sum($cutByCaption),
            ],
        ];
    }

    /**
     * Reading speed outranks line shape: pacing feeds the validator's
     * verdict, line limits never do. A wrap can add a join-space
     * character to the checker's count (a CJK cue broken into more
     * lines), and for cues in locked slots - no gap to harvest - that
     * one character is the difference between compliant and residual.
     * For every such cue the wrap is reverted: the cue gets its
     * original (line-limit-violating) lines back and reverts to
     * reading-speed-compliant. Wraps that DECREASED the character
     * count never regress and are never touched.
     *
     * @param list<array{start: float, end: float, lines: list<string>}> $inputBlocks
     * @param list<array{start: float, end: float, lines: list<string>}> $blocks
     * @param list<array<string, mixed>> $residual
     * @return array{blocks: array<int, array{start: float, end: float, lines: list<string>}>, residual: list<array<string, mixed>>, captions: list<int>}
     */
    private function revertWrapsThatBrokeReadingSpeed(
        array $inputBlocks,
        array $blocks,
        array $residual,
        float $cps
    ): array {
        $reverted = [];
        $keptResidual = [];

        foreach ($residual as $entry) {
            if (($entry['type'] ?? '') !== 'over_reading_speed') {
                $keptResidual[] = $entry;
                continue;
            }

            $index = (int) $entry['caption'] - 1;
            $originalLines = array_map('strval', $inputBlocks[$index]['lines'] ?? []);
            $currentLines = array_map('strval', $blocks[$index]['lines'] ?? []);
            if ($originalLines === $currentLines) {
                $keptResidual[] = $entry; // unwrapped cue, honest residual
                continue;
            }

            $durationMs = (int) round(((float) $blocks[$index]['end'] - (float) $blocks[$index]['start']) * 1000);
            $originalChars = ReadabilityChecker::plainLength(implode(' ', $originalLines));
            if ($originalChars <= 0 || (int) ceil($originalChars * 1000 / $cps) > $durationMs) {
                $keptResidual[] = $entry; // over the limit even unwrapped
                continue;
            }

            $blocks[$index]['lines'] = $originalLines;
            $reverted[] = $index + 1;
        }

        return ['blocks' => $blocks, 'residual' => $keptResidual, 'captions' => $reverted];
    }

    /**
     * Combine wrapper and harvester residual lists, ordered by caption.
     * Entries stay separate per problem type - both budgets are
     * relevant to a condensation pass - but the summary counts each
     * caption once (see fix()).
     *
     * @param list<array<string, mixed>> $a
     * @param list<array<string, mixed>> $b
     * @return list<array<string, mixed>>
     */
    private function mergeResidual(array $a, array $b): array
    {
        $merged = array_merge($a, $b);
        usort($merged, function (array $x, array $y): int {
            return ($x['caption'] ?? 0) <=> ($y['caption'] ?? 0);
        });

        return $merged;
    }

    /**
     * Residual entries for a block list that was NOT repaired: mapped
     * from the readability analysis of the input, in the same shape the
     * TimeHarvester reports (the condensation handoff).
     *
     * @param list<array<string, mixed>> $problems
     * @return list<array<string, mixed>>
     */
    private function residualFromProblems(array $problems, float $cps): array
    {
        $residual = [];
        foreach ($problems as $problem) {
            $isCps = false;
            foreach ($problem['issues'] ?? [] as $issue) {
                if (($issue['type'] ?? null) === 'reading_speed') {
                    $isCps = true;
                    break;
                }
            }
            if (!$isCps) {
                continue;
            }

            $chars = (int) $problem['chars'];
            $duration = (float) $problem['duration_seconds'];
            $budget = (int) floor($cps * $duration);
            $residual[] = [
                'type' => 'over_reading_speed',
                'caption' => (int) $problem['caption'],
                'start' => (float) $problem['start_seconds'],
                'end' => (float) $problem['end_seconds'],
                'text' => (string) $problem['text'],
                'chars' => $chars,
                'duration' => round($duration, 3),
                'cps' => $problem['cps'],
                'char_budget' => $budget,
                'chars_over' => max(0, $chars - $budget),
            ];
        }

        return $residual;
    }

    /**
     * Write a fix() result envelope to a subtitle file. The original
     * file is re-loaded to inherit the library's format handling, then
     * its blocks are replaced with the repaired ones; the output format
     * follows the output file extension (.srt/.vtt).
     *
     * @param array<string, mixed> $result envelope returned by fix()
     * @return string the path written
     */
    public function toFile(array $result, string $outputFile): string
    {
        if (!isset($result['file'], $result['blocks']) || !is_array($result['blocks'])) {
            throw new \InvalidArgumentException('not a fixer result envelope (file/blocks missing)');
        }

        $subs = SubtitleLoader::loadFile($result['file']);
        $subs->setInternalFormat($result['blocks']);
        $subs->save($outputFile);

        return realpath($outputFile) ?: $outputFile;
    }
}
