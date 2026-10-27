<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * End-to-end tests for the bin/srt-fixer command, mirroring the
 * validator's CliTest. Self-contained: generates its own fixtures.
 */
final class CliTest extends TestCase
{
    private $bin;
    private $tmp;
    private $fixtures = [];

    protected function setUp(): void
    {
        $this->bin = __DIR__ . '/../bin/srt-fixer';
        $this->tmp = sys_get_temp_dir() . '/srt-fixer-cli-test-' . uniqid();
        mkdir($this->tmp, 0700, true);

        // Fixable: 12 chars in a 2s cue at the ja profile (4 cps) with
        // an 8s gap after -> extends to 3s, no residual.
        $this->fixtures['fixable'] = $this->tmp . '/fixable.ja.srt';
        file_put_contents($this->fixtures['fixable'], $this->srt([
            [0.0, 2.0, 'twelve chars'],
            [10.0, 12.0, 'ok'],
        ]));

        // Stuck: zero gap after, first cue of the file -> residual.
        $this->fixtures['stuck'] = $this->tmp . '/stuck.ja.srt';
        file_put_contents($this->fixtures['stuck'], $this->srt([
            [0.0, 2.0, 'twelve chars'],
            [2.0, 4.0, 'ok'],
        ]));

        $this->fixtures['broken'] = $this->tmp . '/broken.srt';
        file_put_contents($this->fixtures['broken'], "1\n00:00:01,000 --> 00:00:03,000\nHallo Welt\n\nBROKEN_LINE_WITHOUT_TIMESTAMP\nMehr Text\n\n");

        $this->fixtures['empty'] = $this->tmp . '/empty.srt';
        file_put_contents($this->fixtures['empty'], '');
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tmp . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->tmp);
    }

    public function testHelpExitsOk(): void
    {
        [$exit, $output] = $this->execute(['--help']);
        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Usage:', $output);
        $this->assertStringContainsString('--lang', $output);

        [$exitShort] = $this->execute(['-h']);
        $this->assertSame(0, $exitShort);
    }

    public function testVersionExitsOk(): void
    {
        [$exit, $output] = $this->execute(['--version']);
        $this->assertSame(0, $exit);
        $this->assertStringContainsString('srt-fixer', $output);

        [$exitShort, $outputShort] = $this->execute(['-V']);
        $this->assertSame(0, $exitShort);
        $this->assertStringContainsString('srt-fixer', $outputShort);
    }

    public function testMissingFileArgumentIsUsageError(): void
    {
        [$exit, $output] = $this->execute([]);
        $this->assertSame(2, $exit);
        $this->assertStringContainsString('expected exactly one subtitle file', $output);
    }

    public function testMissingLanguageIsUsageError(): void
    {
        [$exit, $output] = $this->execute([$this->fixtures['fixable']]);
        $this->assertSame(2, $exit);
        $this->assertStringContainsString('--lang', $output);
    }

    public function testUnknownOptionIsUsageError(): void
    {
        [$exit] = $this->execute(['--nonsense', $this->fixtures['fixable'], '-l', 'ja']);
        $this->assertSame(2, $exit);
    }

    public function testUnreadableFileIsInputError(): void
    {
        [$exit, $output] = $this->execute([$this->tmp . '/nope.srt', '-l', 'ja']);
        $this->assertSame(1, $exit);
        $this->assertStringContainsString('does not exist', $output);
    }

    public function testEmptyFileIsInputError(): void
    {
        // The subtitle library is lenient (junk becomes cue text); only a
        // converter-less/empty file fails to parse.
        [$exit, $output] = $this->execute([$this->fixtures['empty'], '-l', 'ja']);
        $this->assertSame(1, $exit);
        $this->assertStringContainsString('could not parse', $output);
    }

    public function testJunkLinesAreParsedLenientlyAndStillReTimed(): void
    {
        // Untimestamped junk lines merge into the previous cue's text;
        // the fixer re-wraps and re-times what parsed (format errors are
        // the validator's job, not the fixer's).
        [$exit, $output] = $this->execute([$this->fixtures['broken'], '-l', 'de']);

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('fixes (2):', $output, 'One rewrap + one retime on the same cue');
        $this->assertStringContainsString('rewrap', $output);
    }

    public function testDryRunFixesAndWritesNothing(): void
    {
        $out = $this->tmp . '/should-not-exist.srt';
        [$exit, $output] = $this->execute([$this->fixtures['fixable'], '-l', 'ja']);

        $this->assertSame(0, $exit, 'Fully fixed -> exit 0');
        $this->assertStringContainsString('fixes (1):', $output);
        $this->assertStringContainsString('dry run', $output);
        $this->assertStringContainsString('over reading speed:  1 -> 0', $output);
        $this->assertFileDoesNotExist($out, 'Without --out nothing is ever written');
        $this->assertStringNotContainsString('(budget', $output, 'No residual cues to report');
    }

    public function testOutWritesRepairedFile(): void
    {
        $out = $this->tmp . '/fixed.ja.srt';
        [$exit, $output] = $this->execute([$this->fixtures['fixable'], '--lang=ja', '--out=' . $out]);

        $this->assertSame(0, $exit);
        $this->assertFileExists($out);
        $this->assertStringContainsString($out, $output);

        $content = (string) file_get_contents($out);
        $this->assertStringContainsString('00:00:00,000 --> 00:00:03,000', $content, 'The extended end time is written');
        $this->assertSame(2, substr_count($content, ' --> '), 'Both cues survive');
    }

    public function testResidualCuesExitThreeWithBudget(): void
    {
        [$exit, $output] = $this->execute([$this->fixtures['stuck'], '-l', 'ja']);

        $this->assertSame(3, $exit, 'Residual over-limit cues -> exit 3');
        $this->assertStringContainsString('residual', $output);
        $this->assertStringContainsString('(cut 4+ to 8)', $output);
        $this->assertStringContainsString('twelve chars', $output, 'The text preview identifies the cue');
    }

    public function testMaxCpsOverrideChangesOutcome(): void
    {
        // 6 cps instead of the ja profile's 4: 12 chars in 2s are fine.
        [$exit, $output] = $this->execute([$this->fixtures['fixable'], '-l', 'ja', '--max-cps=6']);

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('fixes (0):', $output);
        $this->assertStringContainsString('6 (overridden) cps', $output);
    }

    public function testInvalidMaxCpsIsUsageError(): void
    {
        [$exit] = $this->execute([$this->fixtures['fixable'], '-l', 'ja', '--max-cps=abc']);
        $this->assertSame(2, $exit);
    }

    public function testJsonEnvelopeIsValid(): void
    {
        [$exit, $output] = $this->execute([$this->fixtures['stuck'], '-l', 'ja', '--json']);

        $this->assertSame(3, $exit);
        $envelope = json_decode($output, true);
        $this->assertIsArray($envelope);
        $this->assertArrayHasKey('fixes', $envelope);
        $this->assertArrayHasKey('residual', $envelope);
        $this->assertArrayHasKey('summary', $envelope);
        $this->assertArrayHasKey('profile', $envelope);
        $this->assertArrayHasKey('verification', $envelope);
        $this->assertTrue($envelope['applied']);
        $this->assertArrayNotHasKey('blocks', $envelope, 'The bulky block list is stripped from JSON');
        $this->assertSame(8, $envelope['residual'][0]['char_budget']);
        $this->assertSame('twelve chars', $envelope['residual'][0]['text'], 'JSON carries the condensation handoff');
    }

    public function testDryRunReportsStructuralVerification(): void
    {
        [$exit, $output] = $this->execute([$this->fixtures['fixable'], '-l', 'ja']);

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('verification: passed (structural', $output);
        $this->assertStringContainsString('--source', $output, 'The report points at the full gate');
    }

    public function testSourceRunsFullVerificationAndSanctionsDrift(): void
    {
        // Source with a 1s first cue: the repair extends the translation's
        // first cue to 2s - past the 0.5s tolerance, sanctioned.
        $source = $this->tmp . '/source.en.srt';
        file_put_contents($source, $this->srt([
            [0.0, 1.0, 'hello world'],
            [10.0, 12.0, 'second line'],
        ]));
        $translation = $this->tmp . '/dense.en.srt';
        file_put_contents($translation, $this->srt([
            [0.0, 1.0, 'twelve chars'],
            [10.0, 12.0, 'ok text'],
        ]));

        [$exit, $output] = $this->execute([$translation, '-l', 'en', '--max-cps=6', '--source=' . $source]);

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('verification: passed (validator', $output);
        $this->assertStringContainsString('0 -> 1 timestamp mismatches', $output);
    }

    public function testUnparseableSourceSkipsFullComparisonButWrites(): void
    {
        $out = $this->tmp . '/fixed.srt';
        $empty = $this->tmp . '/empty.srt';
        file_put_contents($empty, '');

        [$exit, $output] = $this->execute([$this->fixtures['fixable'], '-l', 'ja', '--source=' . $empty, '--out=' . $out]);

        $this->assertSame(0, $exit, 'Structural verification suffices');
        $this->assertStringContainsString('verification: passed (structural only', $output);
        $this->assertStringContainsString('could not parse the source', $output);
        $this->assertFileExists($out, 'The repair is applied and written');
    }

    /**
     * @param list<string> $args
     * @return array{0: int, 1: string}
     */
    private function execute(array $args): array
    {
        $cmd = 'php ' . escapeshellarg($this->bin);
        foreach ($args as $arg) {
            $cmd .= ' ' . escapeshellarg($arg);
        }
        exec($cmd . ' 2>&1', $outputLines, $exitCode);

        return [$exitCode, implode("\n", $outputLines)];
    }

    /**
     * @param list<array{0: float, 1: float, 2: string}> $captions
     */
    private function srt(array $captions): string
    {
        $blocks = [];
        foreach ($captions as $i => [$start, $end, $text]) {
            $ms = fn (float $s): string => sprintf(
                '%02d:%02d:%02d,%03d',
                0,
                intdiv((int) $s, 60),
                (int) $s % 60,
                (int) round(($s - (int) $s) * 1000)
            );
            $blocks[] = ($i + 1) . "\n" . sprintf('%s --> %s', $ms($start), $ms($end)) . "\n" . $text . "\n";
        }
        return implode("\n", $blocks);
    }
}
