<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use SrtFixer\SrtTranslationFixer;
use SrtValidator\SubtitleLoader;

/**
 * Integration tests: real .srt files through the orchestrator, mirroring
 * the validator's synthetic-fixture style. Self-contained.
 */
final class SrtTranslationFixerTest extends TestCase
{
    private $tmp;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/srt-fixer-test-' . uniqid();
        mkdir($this->tmp, 0700, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tmp . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->tmp);
    }

    public function testJaOverLimitCueIsFixedWithProfileNumbers(): void
    {
        $file = $this->writeSrt('dense.ja.srt', [
            [0.0, 2.0, 'twelve chars'],   // 6 cps at the ja profile (4 cps)
            [10.0, 12.0, 'ok'],
        ]);

        $fixer = new SrtTranslationFixer();
        $result = $fixer->fix($file, 'ja');

        $this->assertSame(4.0, $result['profile']['cps'], 'The ja profile drives the limit');
        $this->assertSame(13, $result['profile']['cpl']);
        $this->assertSame(2, $result['profile']['lines']);
        $this->assertFalse($result['profile']['cps_overridden']);
        $this->assertSame(2, $result['captions']);

        $this->assertCount(1, $result['fixes']);
        $this->assertSame([], $result['residual']);
        $this->assertSame(3.0, $result['blocks'][0]['end']);

        $this->assertSame(1, $result['summary']['over_limit_before']);
        $this->assertSame(0, $result['summary']['over_limit_after']);
        $this->assertSame(6.0, $result['summary']['max_cps_before']);
        $this->assertSame(4.0, $result['summary']['max_cps_after']);
    }

    public function testUnknownLanguageFallsBackToDefaultProfile(): void
    {
        $file = $this->writeSrt('dense.xx.srt', [
            [0.0, 2.0, 'twelve chars'],   // 6 cps - fine at the default 20 cps
            [10.0, 12.0, 'ok'],
        ]);

        $result = (new SrtTranslationFixer())->fix($file, 'xx');

        $this->assertSame(20.0, $result['profile']['cps']);
        $this->assertSame(42, $result['profile']['cpl']);
        $this->assertSame([], $result['fixes'], 'Nothing is over the default limit');
    }

    public function testCpsOverrideWinsOverProfile(): void
    {
        $file = $this->writeSrt('dense.ja.srt', [
            [0.0, 2.0, 'fourteen chars!'], // 15 chars: 7.5 cps, needed 2.5s at 6
            [10.0, 12.0, 'ok'],
        ]);

        $fixer = new SrtTranslationFixer();
        $fixer->setCpsOverride(6.0);
        $result = $fixer->fix($file, 'ja');

        $this->assertSame(6.0, $result['profile']['cps']);
        $this->assertTrue($result['profile']['cps_overridden']);
        $this->assertSame(2.5, $result['blocks'][0]['end']);
        $this->assertSame(13, $result['profile']['cpl'], 'The profile still supplies cpl/lines');
    }

    public function testResidualCuesCarryCharacterBudget(): void
    {
        // Zero gap after, cue #1 of the file: nothing can move.
        $file = $this->writeSrt('stuck.ja.srt', [
            [0.0, 2.0, 'twelve chars'],
            [2.0, 4.0, 'ok'],
        ]);

        $result = (new SrtTranslationFixer())->fix($file, 'ja');

        $this->assertTrue($result['applied'], 'Nothing harmful here - the repair is applied');
        $this->assertSame([], $result['fixes']);
        $this->assertCount(1, $result['residual']);
        $cue = $result['residual'][0];
        $this->assertSame('over_reading_speed', $cue['type']);
        $this->assertSame(12, $cue['chars']);
        $this->assertSame(8, $cue['char_budget'], 'floor(4 cps * 2s) = 8 chars');
        $this->assertSame(4, $cue['chars_over'], '12 - 8 = 4 chars to cut');
        $this->assertSame('twelve chars', $cue['text'], 'The text rides along for the condensation handoff');
        $this->assertSame(0.0, $cue['start']);
        $this->assertSame(2.0, $cue['end']);
        $this->assertSame(4, $result['summary']['residual_chars_to_cut']);
        $this->assertSame(1, $result['summary']['over_limit_after']);
    }

    public function testToFileWritesRoundTrippableSrt(): void
    {
        $file = $this->writeSrt('dense.ja.srt', [
            [0.0, 2.0, 'twelve chars'],
            [10.0, 12.0, 'ok'],
        ]);
        $out = $this->tmp . '/fixed.ja.srt';

        $fixer = new SrtTranslationFixer();
        $result = $fixer->fix($file, 'ja');
        $written = $fixer->toFile($result, $out);

        $this->assertSame($out, $written);
        $this->assertFileExists($out);

        $reloaded = SubtitleLoader::loadFile($out)->getInternalFormat();
        $this->assertSame(3.0, (float) $reloaded[0]['end'], 'The repaired timing survives the round trip');
        $this->assertSame(2, count($reloaded));

        // Fixing the fixed file must be a no-op (file-level idempotence).
        $second = $fixer->fix($out, 'ja');
        $this->assertSame([], $second['fixes']);
        $this->assertSame([], $second['residual']);
    }

    public function testSourceFileRunsFullValidatorComparison(): void
    {
        // 12 chars in a 1s cue at a 6 cps override: the repair extends the
        // end past the validator's 0.5s tolerance - sanctioned drift, the
        // gate must pass and report it.
        $source = $this->writeSrt('source.en.srt', [
            [0.0, 1.0, 'hello world'],
            [10.0, 12.0, 'second line'],
        ]);
        $file = $this->writeSrt('dense.en.srt', [
            [0.0, 1.0, 'twelve chars'],
            [10.0, 12.0, 'ok text'],
        ]);

        $fixer = new SrtTranslationFixer();
        $fixer->setCpsOverride(6.0);
        $result = $fixer->fix($file, 'en', $source);

        $this->assertTrue($result['applied']);
        $this->assertTrue($result['verification']['validator_compared']);
        $this->assertSame(2.0, $result['blocks'][0]['end']);
        $this->assertSame(0, $result['verification']['timestamp_mismatch_before']);
        $this->assertSame(1, $result['verification']['timestamp_mismatch_after']);
        $this->assertSame([], $result['verification']['guarded_regressions']);
    }

    public function testUnparseableSourceSkipsFullComparisonButApplies(): void
    {
        // Production case (#4): the source file itself is corrupted. The
        // repair is structural-only verified and still applied - refusing
        // to fix translations because their SOURCE is broken would block
        // exactly the messy files the fixer exists for.
        $file = $this->writeSrt('dense.ja.srt', [
            [0.0, 2.0, 'twelve chars'],
            [10.0, 12.0, 'ok'],
        ]);
        $empty = $this->tmp . '/empty.srt';
        file_put_contents($empty, '');

        $fixer = new SrtTranslationFixer();
        $result = $fixer->fix($file, 'ja', $empty);

        $this->assertTrue($result['applied']);
        $this->assertSame(3.0, $result['blocks'][0]['end'], 'The harvest ran normally');
        $this->assertFalse($result['verification']['validator_compared']);
        $this->assertStringContainsString('could not parse the source', (string) $result['verification']['validator_skipped_reason']);
    }

    public function testMissingFileRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new SrtTranslationFixer())->fix($this->tmp . '/nope.srt', 'ja');
    }

    public function testEmptyFileRejected(): void
    {
        // The subtitle library is deliberately lenient (junk lines become
        // cue text); only a converter-less/empty file makes it throw.
        $file = $this->tmp . '/empty.srt';
        file_put_contents($file, '');

        $this->expectException(\InvalidArgumentException::class);
        (new SrtTranslationFixer())->fix($file, 'de');
    }

    /**
     * @param list<array{0: float, 1: float, 2: string}> $captions
     */
    private function writeSrt(string $name, array $captions): string
    {
        $path = $this->tmp . '/' . $name;
        $blocks = [];
        foreach ($captions as $i => [$start, $end, $text]) {
            $blocks[] = ($i + 1) . "\n"
                . sprintf('%s --> %s', $this->tc($start), $this->tc($end))
                . "\n" . $text . "\n";
        }
        file_put_contents($path, implode("\n", $blocks));
        return $path;
    }

    private function tc(float $seconds): string
    {
        $ms = (int) round(($seconds - (int) $seconds) * 1000);
        return sprintf('%02d:%02d:%02d,%03d', 0, intdiv((int) $seconds, 60), (int) $seconds % 60, $ms);
    }
}
