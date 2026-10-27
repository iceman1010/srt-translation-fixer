<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use SrtFixer\VerificationGate;

/**
 * Unit tests for the self-verification gate: structural invariants in
 * memory, and the validator comparison through real (tiny) files.
 */
final class VerificationGateTest extends TestCase
{
    private $tmp;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/srt-gate-test-' . uniqid();
        mkdir($this->tmp, 0700, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tmp . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->tmp);
    }

    public function testIdenticalBlocksPass(): void
    {
        $blocks = [
            ['start' => 0.0, 'end' => 2.0, 'lines' => ['ok']],
            ['start' => 3.0, 'end' => 4.0, 'lines' => ['fine']],
        ];

        $result = (new VerificationGate())->verify($blocks, $blocks, null, null, null);

        $this->assertTrue($result['passed']);
        $this->assertTrue($result['structure_ok']);
        $this->assertFalse($result['validator_compared'], 'No source given - structure only');
        $this->assertSame([], $result['reasons']);
    }

    public function testWidenedCueStillPasses(): void
    {
        // A legal TimeHarvester-shaped repair: end extended, start pulled.
        $input = [
            ['start' => 1.0, 'end' => 2.0, 'lines' => ['twelve chars']],
            ['start' => 10.0, 'end' => 12.0, 'lines' => ['ok']],
        ];
        $repaired = [
            ['start' => 0.9, 'end' => 3.0, 'lines' => ['twelve chars']],
            ['start' => 10.0, 'end' => 12.0, 'lines' => ['ok']],
        ];

        $result = (new VerificationGate())->verify($input, $repaired, null, null, null);

        $this->assertTrue($result['passed']);
    }

    public function testTextChangedIsRejected(): void
    {
        $input = [['start' => 0.0, 'end' => 2.0, 'lines' => ['original']]];
        $repaired = [['start' => 0.0, 'end' => 2.0, 'lines' => ['changed!']]];

        $result = (new VerificationGate())->verify($input, $repaired, null, null, null);

        $this->assertFalse($result['passed']);
        $this->assertStringContainsString('text changed', $result['reasons'][0]);
    }

    public function testRewrappedTextPassesTheGate(): void
    {
        // Re-wrapping is the LineWrapper's charter: a line break may
        // replace a space and vice versa, characters must not change.
        $gate = new VerificationGate();

        $latin = $gate->verify(
            [['start' => 0.0, 'end' => 2.0, 'lines' => ['hello world']]],
            [['start' => 0.0, 'end' => 2.0, 'lines' => ['hello', 'world']]],
            null,
            null,
            null
        );
        $this->assertTrue($latin['passed']);

        $cjk = $gate->verify(
            [['start' => 0.0, 'end' => 2.0, 'lines' => ['一二三四五']]],
            [['start' => 0.0, 'end' => 2.0, 'lines' => ['一二', '三四五']]],
            null,
            null,
            null
        );
        $this->assertTrue($cjk['passed']);
    }

    public function testMergedWordsAreRejectedByTheGate(): void
    {
        // A wrapper bug that merges two words changes the atom
        // sequence even though every character survived.
        $input = [['start' => 0.0, 'end' => 2.0, 'lines' => ['hello world']]];
        $repaired = [['start' => 0.0, 'end' => 2.0, 'lines' => ['helloworld']]];

        $result = (new VerificationGate())->verify($input, $repaired, null, null, null);

        $this->assertFalse($result['passed']);
        $this->assertStringContainsString('text changed', $result['reasons'][0]);
    }

    public function testCueCountChangeIsRejected(): void
    {
        $input = [['start' => 0.0, 'end' => 2.0, 'lines' => ['ok']]];
        $repaired = [
            ['start' => 0.0, 'end' => 2.0, 'lines' => ['ok']],
            ['start' => 3.0, 'end' => 4.0, 'lines' => ['ok']],
        ];

        $result = (new VerificationGate())->verify($input, $repaired, null, null, null);

        $this->assertFalse($result['passed']);
        $this->assertStringContainsString('cue count changed', $result['reasons'][0]);
    }

    public function testShortenedOrDelayedOrNegativeCuesRejected(): void
    {
        $gate = new VerificationGate();
        $input = [['start' => 1.0, 'end' => 3.0, 'lines' => ['ok']]];

        $shortened = $gate->verify($input, [['start' => 1.0, 'end' => 2.5, 'lines' => ['ok']]], null, null, null);
        $this->assertFalse($shortened['passed']);
        $this->assertStringContainsString('end moved earlier', implode(' ', $shortened['reasons']));

        $delayed = $gate->verify($input, [['start' => 1.1, 'end' => 3.0, 'lines' => ['ok']]], null, null, null);
        $this->assertFalse($delayed['passed']);
        $this->assertStringContainsString('start moved later', implode(' ', $delayed['reasons']));

        $negative = $gate->verify($input, [['start' => -0.5, 'end' => 3.0, 'lines' => ['ok']]], null, null, null);
        $this->assertFalse($negative['passed']);
        $this->assertStringContainsString('negative', implode(' ', $negative['reasons']));
    }

    public function testDurationCeilingEnforced(): void
    {
        $gate = new VerificationGate();

        // A repair creating a new ceiling violation is rejected...
        $input = [['start' => 0.0, 'end' => 6.5, 'lines' => ['ok']]];
        $repaired = [['start' => 0.0, 'end' => 8.0, 'lines' => ['ok']]];
        $result = $gate->verify($input, $repaired, null, null, null);
        $this->assertFalse($result['passed']);
        $this->assertStringContainsString('ceiling', implode(' ', $result['reasons']));

        // ...but a cue that already ran long in the input may stay long
        // (never-worsen semantics: the fixer never shortens cues).
        $longInput = [['start' => 0.0, 'end' => 8.0, 'lines' => ['ok']]];
        $kept = $gate->verify($longInput, $longInput, null, null, null);
        $this->assertTrue($kept['passed']);
    }

    public function testGrownOverlapRejectedButPreexistingOverlapTolerated(): void
    {
        $gate = new VerificationGate();

        // Input already overlaps by 0.2s; the repair must not deepen it.
        $input = [
            ['start' => 0.0, 'end' => 2.2, 'lines' => ['ok']],
            ['start' => 2.0, 'end' => 4.0, 'lines' => ['ok']],
        ];
        $grown = [
            ['start' => 0.0, 'end' => 2.6, 'lines' => ['ok']],
            ['start' => 2.0, 'end' => 4.0, 'lines' => ['ok']],
        ];
        $result = $gate->verify($input, $grown, null, null, null);
        $this->assertFalse($result['passed']);
        $this->assertStringContainsString('overlap with the next cue grew', $result['reasons'][0]);

        // Keeping the preexisting overlap as-is is fine.
        $kept = [
            ['start' => 0.0, 'end' => 2.2, 'lines' => ['ok']],
            ['start' => 2.0, 'end' => 4.0, 'lines' => ['ok']],
        ];
        $this->assertTrue($gate->verify($input, $kept, null, null, null)['passed']);
    }

    public function testAlignerClassificationsAreSanctioned(): void
    {
        // merged/split/extra/missing captions are derived from cue start
        // times inside the validator's 0.5s pairing tolerance, so a
        // sanctioned in-pull can legitimately reclassify a pairing
        // (dump #6: harvest-only grows merged_captions by 2). The gate
        // must sanction them - content defects stay guarded.
        $sanctioned = VerificationGate::SANCTIONED_TYPES;

        $this->assertContains('timestamp_mismatch', $sanctioned);
        $this->assertContains('reading_speed', $sanctioned);
        foreach (['merged_captions', 'split_captions', 'extra_caption', 'missing_caption'] as $type) {
            $this->assertContains($type, $sanctioned);
        }
        // Content the fixer never touches must stay OUTSIDE the list.
        foreach (['text_changed', 'empty_cue', 'format_error', 'source_corruption_inherited'] as $guarded) {
            $this->assertNotContains($guarded, $sanctioned);
        }
    }

    public function testValidatorComparisonPassesAndSanctionsTimingDrift(): void
    {
        // 12 chars in a 1s cue at a 6 cps limit: the repair extends the
        // end 0.2 -> 2.0s, past the validator's 0.5s tolerance - exactly
        // the sanctioned timing divergence the gate must tolerate.
        $source = $this->writeSrt('source.srt', [
            [0.0, 1.0, 'hello world'],
            [10.0, 12.0, 'second line'],
        ]);
        $translation = $this->writeSrt('translation.srt', [
            [0.0, 1.0, 'twelve chars'],
            [10.0, 12.0, 'ok text'],
        ]);

        $input = \SrtValidator\SubtitleLoader::loadFile($translation)->getInternalFormat();
        $repaired = [
            ['start' => 0.0, 'end' => 2.0, 'lines' => ['twelve chars']],
            ['start' => 10.0, 'end' => 12.0, 'lines' => ['ok text']],
        ];

        $result = (new VerificationGate())->verify($input, $repaired, $source, $translation, 'en');

        $this->assertTrue($result['passed'], 'Sanctioned drift must not fail the gate: ' . implode('; ', $result['reasons']));
        $this->assertTrue($result['validator_compared']);
        $this->assertSame(0, $result['timestamp_mismatch_before']);
        $this->assertSame(1, $result['timestamp_mismatch_after'], 'The 1s end extension is reported as sanctioned drift');
        $this->assertSame([], $result['guarded_regressions']);
    }

    public function testUnparseableSourceSkipsTheFullComparison(): void
    {
        // A source the validator cannot parse makes the comparison
        // impossible - but says nothing about the repair, which the
        // structural checks fully cover. Skip loudly, do not reject.
        $translation = $this->writeSrt('translation.srt', [
            [0.0, 1.0, 'twelve chars'],
            [10.0, 12.0, 'ok text'],
        ]);
        $empty = $this->tmp . '/empty.srt';
        file_put_contents($empty, '');

        $input = \SrtValidator\SubtitleLoader::loadFile($translation)->getInternalFormat();

        $result = (new VerificationGate())->verify($input, $input, $empty, $translation, 'en');

        $this->assertTrue($result['passed']);
        $this->assertFalse($result['validator_compared']);
        $this->assertStringContainsString('could not parse the source', (string) $result['validator_skipped_reason']);
    }

    /**
     * @param list<array{0: float, 1: float, 2: string}> $captions
     */
    private function writeSrt(string $name, array $captions): string
    {
        $path = $this->tmp . '/' . $name;
        $blocks = [];
        foreach ($captions as $i => [$start, $end, $text]) {
            $tc = fn (float $s): string => sprintf(
                '%02d:%02d:%02d,%03d',
                0,
                intdiv((int) $s, 60),
                (int) $s % 60,
                (int) round(($s - (int) $s) * 1000)
            );
            $blocks[] = ($i + 1) . "\n" . sprintf('%s --> %s', $tc($start), $tc($end)) . "\n" . $text . "\n";
        }
        file_put_contents($path, implode("\n", $blocks));

        return $path;
    }
}
