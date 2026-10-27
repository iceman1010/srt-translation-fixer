<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use SrtFixer\TimeHarvester;

/**
 * Unit tests for the time harvester, in integer-millisecond precision.
 * Character counts are ASCII so plainLength() arithmetic is exact.
 */
final class TimeHarvesterTest extends TestCase
{
    /** 'twelve chars' = 12 chars -> 3.0s at 4 cps. */
    private const TWELVE = 'twelve chars';

    public function testOverLimitCueIsExtendedIntoGap(): void
    {
        // 12 chars need 3000ms; the cue has 2000ms and an 8s gap after.
        $result = (new TimeHarvester())->harvest([
            ['start' => 0.0, 'end' => 2.0, 'lines' => [self::TWELVE]],
            ['start' => 10.0, 'end' => 12.0, 'lines' => ['ok']],
        ], 4.0);

        $this->assertSame(3.0, $result['blocks'][0]['end'], 'The cue extends to exactly the needed duration');
        $this->assertSame(0.0, $result['blocks'][0]['start'], 'The in-time is untouched when the out-gap suffices');
        $this->assertSame([], $result['residual']);

        $this->assertCount(1, $result['fixes']);
        $fix = $result['fixes'][0];
        $this->assertSame('duration_extended', $fix['type']);
        $this->assertSame(1, $fix['caption']);
        $this->assertSame(2.0, $fix['end_before']);
        $this->assertSame(3.0, $fix['end_after']);
        $this->assertSame(1.0, $fix['out_extension']);
        $this->assertSame(0.0, $fix['in_pull']);
        $this->assertSame(6.0, $fix['cps_before']);
        $this->assertSame(4.0, $fix['cps_after'], 'After repair the cue is exactly at the limit');
    }

    public function testExtensionStopsAtMinimumGapBeforeNextCue(): void
    {
        // Needed 3000ms, available only until 3.000 - 0.084 = 2.916.
        $result = (new TimeHarvester())->harvest([
            ['start' => 0.0, 'end' => 2.0, 'lines' => [self::TWELVE]],
            ['start' => 3.0, 'end' => 4.0, 'lines' => ['ok']],
        ], 4.0);

        $this->assertSame(2.916, $result['blocks'][0]['end'], 'The 2-frame minimum gap is preserved');
        $this->assertCount(1, $result['fixes']);
        $this->assertCount(1, $result['residual'], 'Still over the limit -> reported');

        $cue = $result['residual'][0];
        $this->assertSame(1, $cue['caption']);
        $this->assertSame(12, $cue['chars']);
        $this->assertSame(2.916, $cue['duration']);
        $this->assertSame(4.1, $cue['cps'], '12 chars / 2.916s = 4.1 cps');
        $this->assertSame(11, $cue['char_budget'], 'floor(4 cps * 2.916s) = 11 chars');
    }

    public function testLongSilenceAllowsMoreThanTwoSeconds(): void
    {
        // 'twenty chars exactly' = 20 chars need 5s; a huge gap follows.
        // Extensions are need-driven and anchored to neighbours/duration,
        // not to a fixed per-run budget - so the cue grows to exactly 5s.
        $result = (new TimeHarvester())->harvest([
            ['start' => 0.0, 'end' => 2.0, 'lines' => ['twenty chars exactly']],
            ['start' => 60.0, 'end' => 62.0, 'lines' => ['ok']],
        ], 4.0);

        $this->assertSame(5.0, $result['blocks'][0]['end'], 'The cue grows to exactly the needed duration');
        $this->assertSame([], $result['residual']);
    }

    public function testMaxDurationSevenSecondsRespected(): void
    {
        // 40 chars need 10s; the cue is already 6.5s long.
        $result = (new TimeHarvester())->harvest([
            ['start' => 0.0, 'end' => 6.5, 'lines' => [str_repeat('a', 40)]],
        ], 4.0);

        $this->assertSame(7.0, $result['blocks'][0]['end'], 'The 7s duration ceiling caps the extension');
        $this->assertSame(0.0, $result['blocks'][0]['start']);
        $this->assertCount(1, $result['residual']);
        $this->assertSame(28, $result['residual'][0]['char_budget'], 'floor(4 * 7.0) = 28');
    }

    public function testCeilingBoundsExtensionPlusPullCombined(): void
    {
        // Regression (caught by the verification gate on production data,
        // 2026-10-27): 31 chars need 7.75s at 4 cps. The out-extension
        // honors start+7s, but the follow-up in-pull used to move the
        // start earlier WITHOUT re-checking the ceiling, producing cues
        // of 7.75s and more. The ceiling must bound the combined result.
        $blocks = [
            ['start' => 0.0, 'end' => 2.0, 'lines' => ['ok']],
            ['start' => 10.0, 'end' => 14.0, 'lines' => [str_repeat('a', 31)]],
            ['start' => 60.0, 'end' => 61.0, 'lines' => ['ok']],
        ];

        $result = (new TimeHarvester())->harvest($blocks, 4.0);
        $cue = $result['blocks'][1];

        $this->assertSame(17.0, $cue['end'], 'End extends to start + 7s');
        $this->assertSame(10.0, $cue['start'], 'The pull is floored at end - 7s: no room left');
        $this->assertSame(7.0, $cue['end'] - $cue['start']);
        $this->assertCount(1, $result['residual'], 'Still over the limit at 4.4 cps - reported');
    }

    public function testCeilingBoundsPullWhenNeighborCapsTheEnd(): void
    {
        // Same bug from the pull side: the neighbor caps the end below
        // start+7s, then the pull tries to recover the full needed time.
        // 31 chars need 7.75s at 4 cps.
        $result = (new TimeHarvester())->harvest([
            ['start' => 0.0, 'end' => 2.0, 'lines' => ['ok']],
            ['start' => 10.0, 'end' => 14.0, 'lines' => [str_repeat('a', 31)]],
            ['start' => 16.5, 'end' => 20.0, 'lines' => ['ok']],
        ], 4.0);
        $cue = $result['blocks'][1];

        $this->assertSame(16.416, $cue['end'], 'End capped by the next cue minus the min gap');
        $this->assertSame(9.416, $cue['start'], 'Pull floored at end - 7s (not at prev cue: 2.084)');
        $this->assertSame(7.0, $cue['end'] - $cue['start'], 'Combined duration is exactly the ceiling');
        $this->assertCount(1, $result['residual']);
    }

    public function testInTimePullLimitedAndGapAware(): void
    {
        // No room after (next starts 0.05s later); 0.1s gap before:
        // the pull floor is the previous cue's end plus the min gap
        // (4.9 + 0.084 = 4.984).
        $result = (new TimeHarvester())->harvest([
            ['start' => 4.0, 'end' => 4.9, 'lines' => ['ok']],
            ['start' => 5.0, 'end' => 7.0, 'lines' => [self::TWELVE]],
            ['start' => 7.05, 'end' => 8.0, 'lines' => ['ok']],
        ], 4.0);

        $this->assertSame(4.984, $result['blocks'][1]['start'], 'Pull stops at the previous cue plus the minimum gap');
        $this->assertSame(7.0, $result['blocks'][1]['end'], 'No out-extension was possible');
        $this->assertCount(1, $result['fixes']);
        $this->assertSame(0.016, $result['fixes'][0]['in_pull']);
        $this->assertCount(1, $result['residual']);
    }

    public function testNoRoomAtAllLeavesCueUntouchedButReported(): void
    {
        // Previous cue ends 0.04s before, next starts immediately: any
        // pull would violate the minimum gap, any extension overlaps.
        $result = (new TimeHarvester())->harvest([
            ['start' => 4.0, 'end' => 4.96, 'lines' => ['ok']],
            ['start' => 5.0, 'end' => 7.0, 'lines' => [self::TWELVE]],
            ['start' => 7.05, 'end' => 8.0, 'lines' => ['ok']],
        ], 4.0);

        $this->assertSame(5.0, $result['blocks'][1]['start']);
        $this->assertSame(7.0, $result['blocks'][1]['end']);
        $this->assertSame([], $result['fixes'], 'Nothing could move');
        $this->assertCount(1, $result['residual'], 'But the cue is still reported');
    }

    public function testCompliantCueUntouchedAndSecondRunIdempotent(): void
    {
        $blocks = [
            ['start' => 0.0, 'end' => 2.0, 'lines' => ['ok']],
            ['start' => 10.0, 'end' => 14.0, 'lines' => [self::TWELVE]],
        ];

        $first = (new TimeHarvester())->harvest($blocks, 4.0);
        $this->assertSame([], $first['fixes']);
        $this->assertSame([], $first['residual']);
        $this->assertSame($blocks, $first['blocks'], 'Compliant cues pass through byte-identical');

        // Repaired cues are also stable: harvesting the output again
        // must not change anything - including capped-out cues, whose
        // caps anchor to neighbours/duration so a second run cannot
        // grant a fresh extension budget (creep regression test).
        $repaired = (new TimeHarvester())->harvest([
            ['start' => 0.0, 'end' => 2.0, 'lines' => [self::TWELVE]],
            ['start' => 10.0, 'end' => 12.0, 'lines' => ['ok']],
        ], 4.0);
        $this->assertSame(3.0, $repaired['blocks'][0]['end']);

        $second = (new TimeHarvester())->harvest($repaired['blocks'], 4.0);
        $this->assertSame([], $second['fixes'], 'Idempotent: nothing moves twice');
        $this->assertSame([], $second['residual']);

        // And a cue capped by its neighbour's start stays capped.
        $capped = (new TimeHarvester())->harvest([
            ['start' => 0.0, 'end' => 2.0, 'lines' => [self::TWELVE]],
            ['start' => 3.0, 'end' => 4.0, 'lines' => ['ok']],
        ], 4.0);
        $this->assertSame(2.916, $capped['blocks'][0]['end']);
        $this->assertCount(1, $capped['residual']);

        $cappedAgain = (new TimeHarvester())->harvest($capped['blocks'], 4.0);
        $this->assertSame([], $cappedAgain['fixes'], 'A capped-out cue gains nothing on re-run');
        $this->assertSame(2.916, $cappedAgain['blocks'][0]['end']);
        $this->assertCount(1, $cappedAgain['residual']);
    }

    public function testShortAndEmptyCuesPassedThrough(): void
    {
        // 150ms duration: below the cps analysis floor - not this
        // ladder's defect class, and an empty cue has no reading speed.
        $blocks = [
            ['start' => 0.0, 'end' => 0.15, 'lines' => [self::TWELVE]],
            ['start' => 1.0, 'end' => 3.0, 'lines' => ['']],
        ];

        $result = (new TimeHarvester())->harvest($blocks, 4.0);

        $this->assertSame($blocks, $result['blocks']);
        $this->assertSame([], $result['fixes']);
        $this->assertSame([], $result['residual']);
    }

    public function testOverlappingNeighboursNeverWorsen(): void
    {
        // The next cue already starts 0.2s before this one ends: the
        // cap computation must not extend further (and never shorten).
        $result = (new TimeHarvester())->harvest([
            ['start' => 0.0, 'end' => 2.0, 'lines' => [self::TWELVE]],
            ['start' => 1.8, 'end' => 4.0, 'lines' => ['ok']],
        ], 4.0);

        $this->assertSame(2.0, $result['blocks'][0]['end'], 'No extension into an already-overlapping cue');
        $this->assertSame([], $result['fixes']);
        $this->assertCount(1, $result['residual']);
    }

    public function testMultiLineTextCountsAllCharacters(): void
    {
        // Two lines joined by a space: 6 + 1 + 6 = 13 chars -> 3.25s.
        $result = (new TimeHarvester())->harvest([
            ['start' => 0.0, 'end' => 3.0, 'lines' => ['twelve', 'chars!']],
            ['start' => 10.0, 'end' => 12.0, 'lines' => ['ok']],
        ], 4.0);

        $this->assertSame(3.25, $result['blocks'][0]['end']);
        $this->assertSame([], $result['residual']);
    }

    public function testNonPositiveCpsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new TimeHarvester())->harvest([], 0.0);
    }
}
