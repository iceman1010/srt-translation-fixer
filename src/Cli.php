<?php

namespace SrtFixer;

/**
 * Command-line front end for the subtitle translation fixer.
 *
 * Expects exactly one positional argument (the translated subtitle
 * file) and the target language (--lang - in the pipeline the language
 * is always known, since it is the language the file was translated
 * into). Prints a human-readable report of the applied repairs and the
 * residual cues that only a shorter translation can save. Without
 * --out nothing is written (dry run).
 */
final class Cli
{
    public const EXIT_OK = 0;
    public const EXIT_INPUT_ERROR = 1;
    public const EXIT_USAGE = 2;
    /** Fixes applied, but cues remain over the limit - they need condensation upstream. */
    public const EXIT_RESIDUAL = 3;
    /** The self-verification gate rejected the repair; nothing was applied or written. */
    public const EXIT_REJECTED = 4;

    private const REPORT_WIDTH = 66;

    public static function run(array $argv): int
    {
        $options = self::parseOptions(array_slice($argv, 1));

        if ($options === null) {
            return self::fail('unknown option or missing option value', in_array('--json', array_slice($argv, 1), true), self::EXIT_USAGE, true);
        }

        $json = $options['json'];

        if ($options['help']) {
            echo self::usage();
            return self::EXIT_OK;
        }

        if ($options['version']) {
            echo self::versionString() . "\n";
            return self::EXIT_OK;
        }

        $files = $options['files'];

        if (count($files) !== 1) {
            return self::fail(sprintf('expected exactly one subtitle file, got %d', count($files)), $json, self::EXIT_USAGE, true);
        }

        $lang = $options['lang'];
        if ($lang === null || trim($lang) === '') {
            return self::fail('the target language is required -- it is the language the file was translated into (e.g. --lang ja)', $json, self::EXIT_USAGE, true);
        }

        if ($options['max_cps'] !== null && !is_numeric($options['max_cps'])) {
            return self::fail('--max-cps expects a number, got ' . $options['max_cps'], $json, self::EXIT_USAGE, true);
        }

        $fixer = new SrtTranslationFixer();

        try {
            if ($options['max_cps'] !== null) {
                $fixer->setCpsOverride((float) $options['max_cps']);
            }
            $result = $fixer->fix($files[0], $lang, $options['source']);
        } catch (\InvalidArgumentException $e) {
            return self::fail($e->getMessage(), $json, self::EXIT_INPUT_ERROR);
        }

        if (!$result['applied']) {
            // A rejected repair is never written; the rendered envelope
            // (report or json) carries the verification reasons.
            echo $json
                ? self::renderJson($result, null)
                : self::renderReport($result, null);

            return self::EXIT_REJECTED;
        }

        if ($options['out'] !== null) {
            try {
                $fixer->toFile($result, $options['out']);
            } catch (\Throwable $e) {
                return self::fail('could not write the fixed file: ' . $e->getMessage(), $json, self::EXIT_INPUT_ERROR);
            }
        }

        echo $json
            ? self::renderJson($result, $options['out'])
            : self::renderReport($result, $options['out']);

        return $result['residual'] === [] ? self::EXIT_OK : self::EXIT_RESIDUAL;
    }

    /**
     * @return array{files: list<string>, lang: string|null, max_cps: string|null, out: string|null, source: string|null, json: bool, help: bool, version: bool}|null
     */
    private static function parseOptions(array $args): ?array
    {
        $options = [
            'files' => [],
            'lang' => null,
            'max_cps' => null,
            'out' => null,
            'source' => null,
            'json' => false,
            'help' => false,
            'version' => false,
        ];

        $valueNames = ['--lang' => 'lang', '--out' => 'out', '--max-cps' => 'max_cps', '--source' => 'source'];

        for ($i = 0, $n = count($args); $i < $n; $i++) {
            $arg = $args[$i];

            if ($arg === '-h' || $arg === '--help') {
                $options['help'] = true;
            } elseif ($arg === '-V' || $arg === '--version') {
                $options['version'] = true;
            } elseif ($arg === '--json') {
                $options['json'] = true;
            } elseif ($arg === '-l') {
                if (++$i >= $n) {
                    return null;
                }
                $options['lang'] = $args[$i];
            } elseif (isset($valueNames[$arg])) {
                if (++$i >= $n) {
                    return null;
                }
                $options[$valueNames[$arg]] = $args[$i];
            } elseif (preg_match('/^(--lang|--out|--max-cps|--source)=(.*)$/', $arg, $m) === 1) {
                $options[$valueNames[$m[1]]] = $m[2];
            } elseif ($arg !== '' && $arg[0] === '-') {
                return null;
            } else {
                $options['files'][] = $arg;
            }
        }

        return $options;
    }

    private static function renderReport(array $result, ?string $out): string
    {
        $profile = $result['profile'];
        $summary = $result['summary'];

        $lines = [];
        $lines[] = self::versionString();
        $lines[] = '';
        $lines[] = '  file:      ' . $result['file'];
        $lines[] = sprintf(
            '  language:  %s (profile: %s%s cps / %d cpl / %d lines)',
            $result['language'],
            $profile['cps'],
            $profile['cps_overridden'] ? ' (overridden)' : '',
            $profile['cpl'],
            $profile['lines']
        );
        $lines[] = sprintf('  captions:  %d', $result['captions']);
        $lines[] = '';

        if (!$result['applied']) {
            $lines[] = 'verification: REJECTED - nothing was applied, nothing written:';
            foreach ($result['verification']['reasons'] ?? ['unknown reason'] as $reason) {
                $lines[] = '  - ' . $reason;
            }
            $lines[] = '';
            $lines[] = sprintf('residual - over-limit cues of the unmodified input (%d):', count($result['residual']));
            foreach ($result['residual'] as $cue) {
                if (($cue['type'] ?? '') === 'over_line_capacity') {
                    $lines[] = sprintf(
                        '  #%-5d %s  %d chars > %d display budget  (cut %d+)  %s',
                        $cue['caption'],
                        self::ts($cue['start']),
                        $cue['chars'],
                        $cue['char_budget'],
                        $cue['chars_over'],
                        self::preview($cue['text'])
                    );
                    continue;
                }
                $lines[] = sprintf(
                    '  #%-5d %s  %d chars / %ss = %s cps  (budget: %d chars)',
                    $cue['caption'],
                    self::ts($cue['start']),
                    $cue['chars'],
                    $cue['duration'],
                    $cue['cps'],
                    $cue['char_budget']
                );
            }
            $lines[] = '';

            return implode("\n", $lines);
        }

        $verification = $result['verification'];
        if ($verification['validator_compared']) {
            $line = sprintf(
                'verification: passed (validator: %d guarded defects unchanged, %d -> %d timestamp mismatches are sanctioned timing repair',
                array_sum($verification['guarded_defects'] ?? []),
                $verification['timestamp_mismatch_before'],
                $verification['timestamp_mismatch_after']
            );
            // Aligner classifications derived from moved start times -
            // reported so a consumer sees the re-pairing, never silent.
            foreach ($verification['sanctioned_deltas'] ?? [] as $type => $delta) {
                if (in_array($type, ['timestamp_mismatch', 'reading_speed'], true)) {
                    continue; // already covered by the numbers above / the summary
                }
                if ($type === 'reading_speed_newly_counted') {
                    $line .= sprintf(
                        ', reading_speed defects %d -> %d (every newly counted cue improved - validator bookkeeping, not damage)',
                        $delta['before'],
                        $delta['after']
                    );
                    continue;
                }
                $line .= sprintf(', %s %d -> %d (sanctioned re-pairing)', $type, $delta['before'], $delta['after']);
            }
            $lines[] = $line . ')';
        } elseif ($verification['validator_skipped_reason'] !== null) {
            $lines[] = 'verification: passed (structural only - ' . $verification['validator_skipped_reason'] . ')';
        } else {
            $lines[] = 'verification: passed (structural; pass --source <original> for a full validator comparison)';
        }
        $lines[] = '';

        $fixes = $result['fixes'];
        $lines[] = sprintf('fixes (%d):', count($fixes));
        if ($fixes === []) {
            $lines[] = '  none - no cue needed re-timing or re-wrapping';
        }
        foreach ($fixes as $fix) {
            if (($fix['type'] ?? '') === 'rewrapped') {
                $lines[] = sprintf(
                    '  #%-5d %s  rewrap  %d -> %d lines, %d -> %d max cpl',
                    $fix['caption'],
                    self::ts($result['blocks'][$fix['caption'] - 1]['start'] ?? 0.0),
                    count($fix['lines_before']),
                    count($fix['lines_after']),
                    $fix['max_line_before'],
                    $fix['max_line_after']
                );
                continue;
            }
            $move = [];
            if ($fix['out_extension'] > 0.0) {
                $move[] = sprintf('end +%ss', rtrim(rtrim(number_format($fix['out_extension'], 3), '0'), '.'));
            }
            if ($fix['in_pull'] > 0.0) {
                $move[] = sprintf('start -%ss', rtrim(rtrim(number_format($fix['in_pull'], 3), '0'), '.'));
            }
            $lines[] = sprintf(
                '  #%-5d %s  %s  %s -> %s cps',
                $fix['caption'],
                self::ts($fix['start_after']),
                implode(', ', $move) ?: '(no change)',
                $fix['cps_before'],
                $fix['cps_after']
            );
        }
        $lines[] = '';

        $residual = $result['residual'];
        $lines[] = sprintf('residual - mechanical repair cannot save these, shorten the translation (%d):', count($residual));
        if ($residual === []) {
            $lines[] = '  none';
        }
        foreach ($residual as $cue) {
            if (($cue['type'] ?? '') === 'over_line_capacity') {
                $lines[] = sprintf(
                    '  #%-5d %s  %d chars > %d display budget  (cut %d+)  %s',
                    $cue['caption'],
                    self::ts($cue['start']),
                    $cue['chars'],
                    $cue['char_budget'],
                    $cue['chars_over'],
                    self::preview($cue['text'])
                );
                continue;
            }
            $lines[] = sprintf(
                '  #%-5d %s  %d chars / %ss = %s cps  (cut %d+ to %d)  %s',
                $cue['caption'],
                self::ts($cue['start']),
                $cue['chars'],
                $cue['duration'],
                $cue['cps'],
                $cue['chars_over'],
                $cue['char_budget'],
                self::preview($cue['text'])
            );
        }
        $lines[] = '';

        $cutCues = count(array_unique(array_column($residual, 'caption')));
        $lines[] = 'summary:';
        $lines[] = sprintf('  over reading speed:  %d -> %d captions', $summary['over_limit_before'], $summary['over_limit_after']);
        $lines[] = sprintf('  over line limits:    %d -> %d captions', $summary['line_over_before'], $summary['line_over_after']);
        $lines[] = sprintf('  max cps:             %s -> %s', $summary['max_cps_before'], $summary['max_cps_after']);
        $lines[] = sprintf('  avg cps:             %s -> %s', $summary['avg_cps_before'], $summary['avg_cps_after']);
        $lines[] = sprintf('  chars to cut:        %d across %d cues', $summary['residual_chars_to_cut'], $cutCues);
        $retimed = false;
        foreach ($result['fixes'] as $fix) {
            if (($fix['type'] ?? '') !== 'rewrapped') {
                $retimed = true; // any non-wrap fix is a timing repair
                break;
            }
        }
        if ($retimed) {
            // Timing repair is intentional drift from the source
            // timeline; re-validating the fixed file with default
            // thresholds trips the validator's timestamp_drift gate.
            $lines[] = '  re-validate:         with the source only via --max-drift-ratio (timings were deliberately repaired)';
        }
        $lines[] = '  output:              ' . ($out !== null ? $out : 'dry run - pass --out to write the fixed file');
        $lines[] = '';

        return implode("\n", $lines);
    }

    private static function renderJson(array $result, ?string $out): string
    {
        unset($result['blocks']); // bulky; cues are fully described by fixes/residual
        $result['output'] = $out;

        return json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
    }

    /** @param list<float>|float $seconds */
    private static function ts($seconds): string
    {
        $ms = (int) round(((float) $seconds) * 1000);
        $s = intdiv($ms, 1000);
        $m = intdiv($s, 60);
        $h = intdiv($m, 60);

        return sprintf('%d:%02d:%02d.%03d', $h, $m % 60, $s % 60, $ms % 1000);
    }

    /** Short single-line text preview for the human report. */
    private static function preview(string $text, int $maxChars = 24): string
    {
        $text = str_replace(["\r", "\n"], ' ', $text);
        $length = mb_strlen($text);
        if ($length <= $maxChars) {
            return $text;
        }

        return rtrim(mb_substr($text, 0, $maxChars - 1)) . '…';
    }

    private static function usage(): string
    {
        return <<<TXT
Usage:
  srt-fixer <translation-file> --lang <code> [options]

Repairs reading-speed (cps) and line-limit problems in a translated
subtitle file: cues that exceed the target language's profile limits are
re-wrapped to the cpl/lines limits with script-aware rules (word
boundaries for Latin scripts, kinsoku for CJK) and extended into the
surrounding gaps (out-time first, then the in-time if still needed),
never past the neighbouring cue. Text is never changed; cues that cannot
be saved mechanically are reported with their character budget. Every
repair is accepted only after a self-verification gate proves it harmless
(structural checks always; a full validator comparison with --source).

Options:
  -l, --lang <code>   Target language (BCP-47-ish), e.g. ja, de, zh-Hant
      --source <file> The original subtitle file; enables the full validator
                      comparison in the self-verification gate
      --max-cps <F>   Override the profile's reading-speed limit
      --out <file>    Write the fixed subtitle file (without it: dry run)
      --json          Print the machine-readable result envelope
  -h, --help          Show this help
  -V, --version       Show the version

Exit codes:
  0  fixed (or nothing to fix); no residual over-limit cues
  1  input error (unreadable or unparseable file)
  2  usage error
  3  fixes applied, but some cues still exceed the limit - they need a
     shorter translation (reported with their character budget)
  4  the self-verification gate rejected the repair - nothing was
     applied or written (the report lists the reasons)

TXT;
    }

    private static function versionString(): string
    {
        $version = defined('SRT_FIXER_VERSION') ? SRT_FIXER_VERSION : 'dev';

        return 'srt-fixer ' . $version;
    }

    private static function fail(string $message, bool $json, int $exitCode, bool $showUsage = false): int
    {
        if ($json) {
            echo json_encode(['error' => $message], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
        } else {
            fwrite(STDERR, 'error: ' . $message . "\n");
            if ($showUsage) {
                fwrite(STDERR, "\n" . self::usage());
            }
        }

        return $exitCode;
    }
}
