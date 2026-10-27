<?php

declare(strict_types=1);

namespace SrtFixer;

use SrtValidator\ReadabilityChecker;

/**
 * Ladder step 4: re-wrap cue text to the target language's cpl/lines
 * display limits (the same table the validator resolves).
 *
 * Only cues that VIOLATE the limits are touched (any line over cpl, or
 * more lines than the profile allows): authored cues that already
 * comply are passed through untouched, which makes the step idempotent
 * by construction - re-running it over its own output finds nothing to
 * do. Need-driven, exactly like TimeHarvester.
 *
 * The text itself is never modified: only the line-break placement
 * changes. Script-aware breaking:
 *   - every CJK character is its own atom and may start a new line,
 *     subject to kinsoku (characters that may not start / may not end
 *     a line are kept with their neighbour);
 *   - every other non-space run (Latin word, number, entity, markup
 *     run) is atomic - a line never breaks inside it;
 *   - spaces are break opportunities; a break consumes the space.
 * Lines are filled balanced: the split minimizes the longer resulting
 * line (professional two-line look), preferring the top line shorter.
 *
 * Widths are measured with the validator's ReadabilityChecker (tags
 * stripped, entities decoded) so the wrapper judges exactly what the
 * oracle judges - but the emitted lines preserve the original raw
 * text. Cues whose raw text contains markup are skipped entirely:
 * moving a break inside a tag pair would corrupt it, and production
 * data shows zero compliant-shape loss from this rule.
 *
 * A cue whose text cannot fit cpl x lines at all (capacity failure) is
 * left untouched and reported: like the reading-speed residual, the
 * only cure is a shorter translation upstream.
 */
final class LineWrapper
{
    /**
     * Characters that may not START a line (kinsoku): closers, small
     * kana, the prolonged-sound mark and friends attach to the
     * preceding character.
     */
    private const NO_LINE_START = '、。，．・：；？！ー〜」』）］｝〉》’”…‥゛゜ゃゅょっぁぃぅぇぉゎャュョッァィゥェォヮﾞﾟ';

    /** Characters that may not END a line: openers attach to the next. */
    private const NO_LINE_END = '（［｛「『〈《“‘';

    /**
     * @param list<array{start: float, end: float, lines: list<string>}> $blocks
     * @param int $cpl max characters per line from the language profile
     * @param int $maxLines max lines per cue from the language profile
     * @return array{
     *     blocks: list<array{start: float, end: float, lines: list<string>}>,
     *     fixes: list<array{type: string, caption: int, lines_before: list<string>, lines_after: list<string>, max_line_before: int, max_line_after: int}>,
     *     residual: list<array{type: string, caption: int, start: float, end: float, text: string, chars: int, char_budget: int, chars_over: int}>
     * }
     */
    public function wrap(array $blocks, int $cpl, int $maxLines): array
    {
        if ($cpl <= 0 || $maxLines <= 0) {
            throw new \InvalidArgumentException('cpl and lines limits must be positive, got ' . $cpl . '/' . $maxLines);
        }

        $result = [];
        $fixes = [];
        $residual = [];

        foreach ($blocks as $index => $block) {
            $lines = array_map('strval', $block['lines'] ?? []);

            $maxLine = self::maxLineLength($lines);
            if ($maxLine <= $cpl && count($lines) <= $maxLines) {
                $result[] = ['start' => (float) $block['start'], 'end' => (float) $block['end'], 'lines' => $lines];
                continue;
            }

            // Markup: a moved break could split a tag pair. Production
            // data shows this never blocks a repairable cue anyway.
            if (self::containsMarkup($lines)) {
                $residual[] = $this->capacityResidual($index, $block, $lines, $cpl, $maxLines, false);
                $result[] = ['start' => (float) $block['start'], 'end' => (float) $block['end'], 'lines' => $lines];
                continue;
            }

            $wrapped = self::wrapCue($lines, $cpl, $maxLines);
            if ($wrapped === null) {
                $residual[] = $this->capacityResidual($index, $block, $lines, $cpl, $maxLines, true);
                $result[] = ['start' => (float) $block['start'], 'end' => (float) $block['end'], 'lines' => $lines];
                continue;
            }

            $fixes[] = [
                'type' => 'rewrapped',
                'caption' => $index + 1,
                'lines_before' => $lines,
                'lines_after' => $wrapped,
                'max_line_before' => $maxLine,
                'max_line_after' => self::maxLineLength($wrapped),
            ];
            $result[] = ['start' => (float) $block['start'], 'end' => (float) $block['end'], 'lines' => $wrapped];
        }

        return ['blocks' => $result, 'fixes' => $fixes, 'residual' => $residual];
    }

    /**
     * Identity of cue text up to line-break placement: the sequence of
     * text atoms (each CJK character alone, every other non-space run
     * as one word). A break may replace a space and vice versa, so
     * whitespace never appears in the fingerprint - but merging or
     * splitting a word changes the atom sequence, and so does touching
     * any character. The verification gate compares these fingerprints
     * to prove "text unchanged up to re-wrapping".
     *
     * @param list<string> $lines
     */
    public static function atomFingerprint(array $lines): string
    {
        $flat = trim(implode("\n", array_map('strval', $lines)));
        preg_match_all(
            '/(\p{Han}|\p{Hiragana}|\p{Katakana}|\p{Hangul})|([^\s\p{Han}\p{Hiragana}\p{Katakana}\p{Hangul}]+)/u',
            $flat,
            $matches,
            PREG_SET_ORDER
        );
        $atoms = [];
        foreach ($matches as $match) {
            $atoms[] = $match[0];
        }

        return implode("\x1f", $atoms);
    }

    /**
     * Best partition of the cue text into at most $maxLines lines of at
     * most $cpl characters, or null when the text cannot fit.
     *
     * @param list<string> $lines
     * @return list<string>|null
     */
    private static function wrapCue(array $lines, int $cpl, int $maxLines): ?array
    {
        $atoms = self::atoms($lines);
        $n = count($atoms);
        if ($n === 0) {
            return null;
        }

        // Fewer lines than the cue already has and everything fits: the
        // natural collapse (also the cure for line-count violations).
        if (self::width($atoms, 0, $n) <= $cpl) {
            return [self::render($atoms, 0, $n)];
        }

        $split = self::bestSplit($atoms, 0, $n, $cpl);
        if ($split === null) {
            return null;
        }

        $out = [self::render($atoms, 0, $split)];

        // Profiles currently ship lines=2, but keep it general: keep
        // splitting the remaining segment while lines remain and it is
        // still over the limit.
        $remaining = $maxLines - 1;
        $from = $split;
        while ($remaining >= 1 && $from < $n) {
            if ($remaining === 1 || self::width($atoms, $from, $n) <= $cpl) {
                $out[] = self::render($atoms, $from, $n);
                break;
            }
            $cut = self::bestSplit($atoms, $from, $n, $cpl);
            if ($cut === null) {
                return null;
            }
            $out[] = self::render($atoms, $from, $cut);
            $from = $cut;
            $remaining--;
        }

        return count($out) <= $maxLines ? $out : null;
    }

    /**
     * The break index before which the balanced split of atoms[$from..$to)
     * should fall: both halves at most $cpl wide, the longer half as
     * short as possible, preferring the top half not longer than the
     * bottom. Kinsoku-infeasible breaks are not considered.
     *
     * @param list<array{t: string, cjk: bool, glue: string}> $atoms
     * @return int|null the split index (from < index < to), or null
     */
    private static function bestSplit(array $atoms, int $from, int $to, int $cpl): ?int
    {
        $best = null;
        for ($k = $from + 1; $k < $to; $k++) {
            if (!self::canBreakBefore($atoms, $k)) {
                continue;
            }
            $w1 = self::width($atoms, $from, $k);
            if ($w1 > $cpl) {
                continue;
            }
            $w2 = self::width($atoms, $k, $to);
            if ($w2 > $cpl) {
                continue;
            }
            $cost = max($w1, $w2);
            if ($best === null
                || $cost < $best['cost']
                || ($cost === $best['cost'] && $w1 <= $w2 && $best['w1'] > $best['w2'])) {
                $best = ['cost' => $cost, 'k' => $k, 'w1' => $w1, 'w2' => $w2];
            }
        }

        return $best !== null ? $best['k'] : null;
    }

    /**
     * Atomize a cue: each CJK character alone, every other non-space
     * run as one word atom. Each atom (except the first) records the
     * separator that preceded it: ' ' for a whitespace run, 'B' for an
     * original line break, '' for direct adjacency. The separator
     * decides both where breaks may fall and what glue to re-insert
     * when neighbouring atoms render on the same line: a space is
     * re-inserted for whitespace runs, and for line breaks only when
     * neither side is a CJK atom (a CJK break never displayed a space
     * and must not invent one).
     *
     * @param list<string> $lines
     * @return list<array{t: string, cjk: bool, glue: string, brk: bool}>
     *         brk: whether a break opportunity precedes this atom
     */
    private static function atoms(array $lines): array
    {
        /** @var list<array{t: string, cjk: bool, sep: string}> $atoms */
        $atoms = [];
        $pending = ''; // separator waiting for the next atom: ' ', 'B' or ''

        foreach ($lines as $line) {
            if (trim($line) === '' && $atoms !== []) {
                $pending = $pending === ' ' ? ' ' : 'B';
                continue;
            }
            preg_match_all(
                '/(\p{Han}|\p{Hiragana}|\p{Katakana}|\p{Hangul})|([^\s\p{Han}\p{Hiragana}\p{Katakana}\p{Hangul}]+)|(\s+)/u',
                $line,
                $matches,
                PREG_SET_ORDER
            );
            foreach ($matches as $match) {
                if (($match[3] ?? '') !== '') {
                    if ($atoms !== []) {
                        $pending = ' ';
                    }
                    continue;
                }
                $atoms[] = ['t' => $match[0], 'cjk' => ($match[1] ?? '') !== '', 'sep' => $atoms === [] ? '' : $pending];
                $pending = '';
            }
            if ($atoms !== []) {
                $pending = 'B'; // the line boundary itself
            }
        }

        $out = [];
        $count = count($atoms);
        for ($i = 0; $i < $count; $i++) {
            $sep = $atoms[$i]['sep'];
            $cjkBoundary = $atoms[$i]['cjk'] || ($i > 0 && $atoms[$i - 1]['cjk']);
            $out[] = [
                't' => $atoms[$i]['t'],
                'cjk' => $atoms[$i]['cjk'],
                'glue' => ($i > 0 && ($sep === ' ' || ($sep === 'B' && !$cjkBoundary))) ? ' ' : '',
                'brk' => $i > 0 && ($sep !== '' || $cjkBoundary),
            ];
        }

        return $out;
    }

    /** Width (validator counting) of atoms[$from..$to) rendered on one line. */
    private static function width(array $atoms, int $from, int $to): int
    {
        $w = 0;
        for ($i = $from; $i < $to; $i++) {
            $w += ReadabilityChecker::plainLength($atoms[$i]['t']);
            if ($i > $from && $atoms[$i]['glue'] !== '') {
                $w += 1;
            }
        }

        return $w;
    }

    /** Render atoms[$from..$to) as one line. */
    private static function render(array $atoms, int $from, int $to): string
    {
        $out = '';
        for ($i = $from; $i < $to; $i++) {
            $out .= ($i > $from ? $atoms[$i]['glue'] : '') . $atoms[$i]['t'];
        }

        return $out;
    }

    /** Whether a line may break immediately before atoms[$k]. */
    private static function canBreakBefore(array $atoms, int $k): bool
    {
        if ($k <= 0 || $k >= count($atoms)) {
            return false;
        }

        $next = $atoms[$k];
        $prev = $atoms[$k - 1];

        if (mb_strpos(self::NO_LINE_START, mb_substr($next['t'], 0, 1)) !== false) {
            return false;
        }
        if (mb_strpos(self::NO_LINE_END, mb_substr($prev['t'], -1)) !== false) {
            return false;
        }

        return $next['brk'];
    }

    /** @param list<string> $lines */
    private static function maxLineLength(array $lines): int
    {
        $max = 0;
        foreach ($lines as $line) {
            $max = max($max, ReadabilityChecker::plainLength($line));
        }

        return $max;
    }

    /** @param list<string> $lines */
    private static function containsMarkup(array $lines): bool
    {
        foreach ($lines as $line) {
            if (strip_tags($line) !== $line) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array{start: float, end: float, lines: list<string>} $block
     * @param list<string> $lines
     * @param bool $splittable whether break points are legal at all
     *        (false for markup cues, where a break could split a tag)
     * @return array<string, mixed>
     */
    private function capacityResidual(int $index, array $block, array $lines, int $cpl, int $maxLines, bool $splittable): array
    {
        $chars = ReadabilityChecker::plainLength(implode(' ', $lines));
        $budget = $cpl * $maxLines;

        // The character budget alone understates the cut when word
        // granularity makes every split infeasible: a cue can fit the
        // budget yet no word boundary yields two fitting halves. The
        // honest demand is then the excess of the BEST split, which
        // cutting that many characters would satisfy.
        $cut = max(0, $chars - $budget, $splittable ? self::minSplitExcess($lines, $cpl) : 0);

        return [
            'type' => 'over_line_capacity',
            'caption' => $index + 1,
            'start' => (float) $block['start'],
            'end' => (float) $block['end'],
            'text' => implode("\n", $lines),
            'chars' => $chars,
            'char_budget' => $budget,
            'chars_over' => $cut,
        ];
    }

    /**
     * Excess over $cpl of the best legal two-way split of the cue's
     * atoms: 0 when some word-boundary break yields two fitting halves,
     * otherwise how many characters must go before the closest split
     * fits. (A single unbreakable atom is measured against one line.)
     *
     * @param list<string> $lines
     */
    private static function minSplitExcess(array $lines, int $cpl): int
    {
        $atoms = self::atoms($lines);
        $n = count($atoms);
        if ($n < 2) {
            return max(0, self::width($atoms, 0, $n) - $cpl);
        }

        $best = null;
        for ($k = 1; $k < $n; $k++) {
            if (!self::canBreakBefore($atoms, $k)) {
                continue;
            }
            $excess = max(0, max(
                self::width($atoms, 0, $k),
                self::width($atoms, $k, $n)
            ) - $cpl);
            if ($best === null || $excess < $best) {
                $best = $excess;
            }
        }

        // Every break point kinsoku-blocked: pathological, but the
        // caller's budget formula still applies.
        return $best ?? 0;
    }
}
