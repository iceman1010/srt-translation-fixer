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
            $result = $fixer->fix($files[0], $lang);
        } catch (\InvalidArgumentException $e) {
            return self::fail($e->getMessage(), $json, self::EXIT_INPUT_ERROR);
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
     * @return array{files: list<string>, lang: string|null, max_cps: string|null, out: string|null, json: bool, help: bool, version: bool}|null
     */
    private static function parseOptions(array $args): ?array
    {
        $options = [
            'files' => [],
            'lang' => null,
            'max_cps' => null,
            'out' => null,
            'json' => false,
            'help' => false,
            'version' => false,
        ];

        $valueNames = ['--lang' => 'lang', '--out' => 'out', '--max-cps' => 'max_cps'];

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
            } elseif (preg_match('/^(--lang|--out|--max-cps)=(.*)$/', $arg, $m) === 1) {
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

        $fixes = $result['fixes'];
        $lines[] = sprintf('fixes (%d):', count($fixes));
        if ($fixes === []) {
            $lines[] = '  none - no cue needed re-timing';
        }
        foreach ($fixes as $fix) {
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
        $lines[] = sprintf('residual - retiming cannot save these, shorten the translation (%d):', count($residual));
        if ($residual === []) {
            $lines[] = '  none';
        }
        foreach ($residual as $cue) {
            $lines[] = sprintf(
                '  #%-5d %s  %d chars / %ss = %s cps  (budget: %d chars)',
                $cue['caption'],
                self::ts($result['blocks'][$cue['caption'] - 1]['start'] ?? 0.0),
                $cue['chars'],
                $cue['duration'],
                $cue['cps'],
                $cue['char_budget']
            );
        }
        $lines[] = '';

        $lines[] = 'summary:';
        $lines[] = sprintf('  over reading speed:  %d -> %d captions', $summary['over_limit_before'], $summary['over_limit_after']);
        $lines[] = sprintf('  max cps:             %s -> %s', $summary['max_cps_before'], $summary['max_cps_after']);
        $lines[] = sprintf('  avg cps:             %s -> %s', $summary['avg_cps_before'], $summary['avg_cps_after']);
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

    private static function usage(): string
    {
        return <<<TXT
Usage:
  srt-fixer <translation-file> --lang <code> [options]

Repairs reading-speed (cps) problems in a translated subtitle file: cues
that exceed the target language's profile limit are extended into the
surrounding gaps (out-time first, then the in-time if still needed),
never past the neighbouring cue. Text is never changed; cues that cannot
be saved by re-timing are reported with their character budget.

Options:
  -l, --lang <code>   Target language (BCP-47-ish), e.g. ja, de, zh-Hant
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
