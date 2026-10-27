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
 * Current ladder steps (see README "The fix ladder"):
 *   2. TimeHarvester - re-time over-limit cues into available gaps
 * Steps 1 (re-slot onto the source timeline), 3 (merge dense
 * neighbours) and 4 (re-wrap to cpl) are planned; the fixer never
 * modifies text, and cues it cannot repair are reported with their
 * character budget for a condensation pass upstream.
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
        $harvest = (new TimeHarvester())->harvest($blocks, $cps);

        $verification = (new VerificationGate())->verify(
            $blocks,
            $harvest['blocks'],
            $sourceFile,
            $translationFile,
            $language
        );
        $applied = $verification['passed'];

        if ($applied) {
            $finalBlocks = $harvest['blocks'];
            $fixes = $harvest['fixes'];
            $residual = $harvest['residual'];
        } else {
            // Rejected: nothing is applied. The residual then describes
            // the unmodified input, so the condensation handoff stays
            // truthful.
            $finalBlocks = $blocks;
            $fixes = [];
            $residual = $this->residualFromProblems($before['problems'], $cps);
        }

        $after = $checker->analyze($finalBlocks, $cps, null, null, $language);

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
                'max_cps_before' => $before['max_cps'],
                'max_cps_after' => $after['max_cps'],
                'avg_cps_before' => $before['avg_cps'],
                'avg_cps_after' => $after['avg_cps'],
                'residual_chars_to_cut' => array_sum(array_column($residual, 'chars_over')),
            ],
        ];
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
