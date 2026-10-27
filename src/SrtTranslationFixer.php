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
     * @param string $translationFile the translated subtitle file (srt or vtt)
     * @param string|null $language target language code (BCP-47-ish);
     *        null/unknown falls back to the default profile, exactly as
     *        the validator resolves it
     * @return array{
     *     file: string,
     *     language: string|null,
     *     profile: array{cps: float, cpl: int, lines: int, cps_overridden: bool},
     *     captions: int,
     *     fixes: list<array<string, mixed>>,
     *     residual: list<array<string, mixed>>,
     *     blocks: list<array{start: float, end: float, lines: list<string>}>,
     *     summary: array{
     *         over_limit_before: int,
     *         over_limit_after: int,
     *         max_cps_before: float,
     *         max_cps_after: float,
     *         avg_cps_before: float,
     *         avg_cps_after: float
     *     }
     * }
     * @throws \InvalidArgumentException when the file is missing or unparseable
     */
    public function fix(string $translationFile, ?string $language = null): array
    {
        if (!is_file($translationFile) || !is_readable($translationFile)) {
            throw new \InvalidArgumentException('subtitle file does not exist or is not readable: ' . $translationFile);
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
        $after = $checker->analyze($harvest['blocks'], $cps, null, null, $language);

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
            'fixes' => $harvest['fixes'],
            'residual' => $harvest['residual'],
            'blocks' => $harvest['blocks'],
            'summary' => [
                'over_limit_before' => $before['problems_by_type']['reading_speed'] ?? 0,
                'over_limit_after' => $after['problems_by_type']['reading_speed'] ?? 0,
                'max_cps_before' => $before['max_cps'],
                'max_cps_after' => $after['max_cps'],
                'avg_cps_before' => $before['avg_cps'],
                'avg_cps_after' => $after['avg_cps'],
            ],
        ];
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
