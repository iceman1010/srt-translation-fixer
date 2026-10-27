<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use SrtFixer\LineWrapper;

/**
 * Unit tests for the cpl/lines re-wrapper: script-aware breaking,
 * kinsoku, glue rules, idempotence and the capacity residual.
 */
final class LineWrapperTest extends TestCase
{
    public function testJaLineOverCplIsRewrappedBalanced(): void
    {
        $result = (new LineWrapper())->wrap(
            [['start' => 0.0, 'end' => 5.0, 'lines' => ['ここは『ザ・クラブ』ですか？']]],
            13,
            2
        );

        $lines = $result['blocks'][0]['lines'];
        $this->assertCount(2, $lines);
        foreach ($lines as $line) {
            $this->assertLessThanOrEqual(13, mb_strlen($line));
        }
        // Balanced: the split minimizes the longer line (7/7 here).
        $this->assertSame([7, 7], array_map('mb_strlen', $lines));
        $this->assertNotEmpty($result['fixes']);
        $this->assertSame('rewrapped', $result['fixes'][0]['type']);
    }

    public function testKinsokuCloserNeverStartsALine(): void
    {
        // 13 chars, cpl 12: the balanced 7/6 split would strand the 。at
        // the start of line 2 - kinsoku must move the break after it.
        $result = (new LineWrapper())->wrap(
            [['start' => 0.0, 'end' => 5.0, 'lines' => ['一二三四五六。七八九十百千']]],
            12,
            2
        );

        $lines = $result['blocks'][0]['lines'];
        $this->assertCount(2, $lines);
        foreach ($lines as $line) {
            $this->assertStringStartsNotWith('。', $line);
            $this->assertLessThanOrEqual(12, mb_strlen($line));
        }
        $this->assertSame('一二三四五六。', $lines[0]);
        $this->assertSame('七八九十百千', $lines[1]);
    }

    public function testKinsokuOpenerNeverEndsALine(): void
    {
        // 12 chars, cpl 10: the perfectly balanced 6/6 split would end
        // line 1 with 『 - kinsoku forbids it, so the break moves before
        // the opener instead (5/7).
        $result = (new LineWrapper())->wrap(
            [['start' => 0.0, 'end' => 5.0, 'lines' => ['一二三四五『六七八九十一']]],
            10,
            2
        );

        $lines = $result['blocks'][0]['lines'];
        $this->assertCount(2, $lines);
        $this->assertStringEndsNotWith('『', $lines[0]);
        $this->assertSame('一二三四五', $lines[0]);
        $this->assertSame('『六七八九十一', $lines[1]);
    }

    public function testLatinBreaksOnlyAtWordBoundaries(): void
    {
        $result = (new LineWrapper())->wrap(
            [['start' => 0.0, 'end' => 5.0, 'lines' => ['the quick brown fox jumps over the lazy dog again']]],
            25,
            2
        );

        $lines = $result['blocks'][0]['lines'];
        $this->assertCount(2, $lines);
        foreach ($lines as $line) {
            $this->assertLessThanOrEqual(25, mb_strlen($line));
            $this->assertStringStartsNotWith(' ', $line);
            $this->assertStringEndsNotWith(' ', $line);
        }
        // Every line is a subsequence of whole words.
        $this->assertSame('the quick brown fox jumps', $lines[0]);
        $this->assertSame('over the lazy dog again', $lines[1]);
    }

    public function testLineCountViolationIsCollapsed(): void
    {
        $result = (new LineWrapper())->wrap(
            [['start' => 0.0, 'end' => 5.0, 'lines' => ['one', 'two', 'three']]],
            13,
            2
        );

        $this->assertSame(['one two three'], $result['blocks'][0]['lines']);
    }

    public function testCjkCollapseInventsNoSpaces(): void
    {
        // A line break between CJK characters never displayed a space;
        // collapsing the lines must not introduce one.
        $result = (new LineWrapper())->wrap(
            [['start' => 0.0, 'end' => 5.0, 'lines' => ['一', '二', '三']]],
            13,
            2
        );

        $this->assertSame(['一二三'], $result['blocks'][0]['lines']);
    }

    public function testCrossScriptLineBreakGluesASpace(): void
    {
        // A break between two Latin words collapses with a space...
        $result = (new LineWrapper())->wrap(
            [['start' => 0.0, 'end' => 5.0, 'lines' => ['hello', 'big', 'world']]],
            15,
            2
        );
        $this->assertSame(['hello big world'], $result['blocks'][0]['lines']);
    }

    public function testCompliantCuesPassThroughUntouched(): void
    {
        $blocks = [
            ['start' => 0.0, 'end' => 5.0, 'lines' => ['短いテキスト', '二行目です']],
            ['start' => 6.0, 'end' => 9.0, 'lines' => ['a short line']],
        ];

        $result = (new LineWrapper())->wrap($blocks, 13, 2);

        $this->assertSame($blocks, $result['blocks']);
        $this->assertSame([], $result['fixes']);
        $this->assertSame([], $result['residual']);
    }

    public function testWrapIsIdempotent(): void
    {
        $blocks = [
            ['start' => 0.0, 'end' => 5.0, 'lines' => ['ここは『ザ・クラブ』ですか？']],
            ['start' => 6.0, 'end' => 9.0, 'lines' => ['一二三四五六七八九十百千。']],
        ];

        $once = (new LineWrapper())->wrap($blocks, 13, 2);
        $twice = (new LineWrapper())->wrap($once['blocks'], 13, 2);

        $this->assertSame($once['blocks'], $twice['blocks']);
        $this->assertSame([], $twice['fixes'], 'A second run finds nothing to do');
        $this->assertSame([], $twice['residual']);
    }

    public function testCapacityFailureIsUntouchedAndReported(): void
    {
        // 29 chars cannot fit 13 x 2: the only cure is a shorter text.
        $blocks = [['start' => 0.0, 'end' => 5.0, 'lines' => ['メニューにあるお料理の一つについて、', '質問があるのですが。']]];

        $result = (new LineWrapper())->wrap($blocks, 13, 2);

        $this->assertSame($blocks, $result['blocks'], 'Unwrappable cues keep their authored lines');
        $this->assertSame([], $result['fixes']);
        $this->assertCount(1, $result['residual']);
        $entry = $result['residual'][0];
        $this->assertSame('over_line_capacity', $entry['type']);
        $this->assertSame(29, $entry['chars']);
        $this->assertSame(26, $entry['char_budget'], '13 cpl x 2 lines');
        $this->assertSame(3, $entry['chars_over']);
    }

    public function testMarkupCuesAreSkippedSafely(): void
    {
        // Moving a break inside a tag pair would corrupt it - skip.
        // (The visible text must still violate cpl, measured the way
        // the validator measures: tags stripped.)
        $blocks = [['start' => 0.0, 'end' => 5.0, 'lines' => ['<i>これはとても長いテキストです</i>', 'second']]];

        $result = (new LineWrapper())->wrap($blocks, 13, 2);

        $this->assertSame($blocks, $result['blocks']);
        $this->assertSame([], $result['fixes']);
        $this->assertNotSame([], $result['residual'], 'The cue is still reported as not re-wrappable');
    }

    public function testWordGranularityFailureReportsTheSplitExcess(): void
    {
        // 80 chars fit the 42x2=84 budget, but the long "—you're"
        // atom straddles every candidate break: the closest split is
        // 36/43 - one char over cpl. The residual must demand that
        // char, not "cut 0+".
        $blocks = [[
            'start' => 0.0,
            'end' => 5.0,
            'lines' => [
                'been a line of ronin for generations',
                '—you’re just jealous, aren’t you? Thanks',
                'to',
            ],
        ]];

        $result = (new LineWrapper())->wrap($blocks, 42, 2);

        $this->assertSame($blocks, $result['blocks'], 'Genuinely infeasible: no word-boundary split fits');
        $this->assertSame([], $result['fixes']);
        $entry = $result['residual'][0];
        $this->assertSame(80, $entry['chars']);
        $this->assertSame(84, $entry['char_budget']);
        $this->assertSame(1, $entry['chars_over'], 'The best split (36/43) is one char over cpl');
    }

    public function testUnbreakableSingleWordIsReported(): void
    {
        $blocks = [['start' => 0.0, 'end' => 5.0, 'lines' => ['supercalifragilisticexpialidociousandmorewords']]];

        $result = (new LineWrapper())->wrap($blocks, 42, 2);

        $this->assertSame($blocks, $result['blocks']);
        $this->assertSame([], $result['fixes']);
        $this->assertCount(1, $result['residual']);
        $this->assertSame('over_line_capacity', $result['residual'][0]['type']);
    }

    public function testAtomFingerprintSanctionsBreaksButNotWordMerges(): void
    {
        foreach ([['abc def'], ['abc', 'def'], ['abc ', 'def']] as $lines) {
            $this->assertSame(LineWrapper::atomFingerprint(['abc def']), LineWrapper::atomFingerprint($lines));
        }

        $this->assertNotSame(
            LineWrapper::atomFingerprint(['abc def']),
            LineWrapper::atomFingerprint(['abcdef']),
            'Merging two words changes the atom sequence'
        );
        $this->assertNotSame(
            LineWrapper::atomFingerprint(['abc def']),
            LineWrapper::atomFingerprint(['abc de f']),
            'Splitting a word changes the atom sequence'
        );
        $this->assertNotSame(
            LineWrapper::atomFingerprint(['abc def']),
            LineWrapper::atomFingerprint(['abc fed']),
            'Reordering characters changes the atom sequence'
        );
    }

    public function testAtomFingerprintSanctionsCjkRewrapping(): void
    {
        // CJK breaks never displayed a space, so the fingerprint of a
        // re-broken cue equals the original.
        $this->assertSame(
            LineWrapper::atomFingerprint(['一二三四']),
            LineWrapper::atomFingerprint(['一二', '三四'])
        );
        $this->assertSame(
            LineWrapper::atomFingerprint(['一二三']),
            LineWrapper::atomFingerprint(['一', '二', '三'])
        );
    }

    public function testInvalidLimitsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new LineWrapper())->wrap([['start' => 0.0, 'end' => 1.0, 'lines' => ['ok']]], 0, 2);
    }
}
