# srt-translation-fixer

Repairs translated subtitle files (SRT/WebVTT) against the target
language's readability profile — the mechanical counterpart to
[srt-translation-validator](../srt-translation-validator). The validator
decides *whether* a translation is deliverable; the fixer makes the
mechanically-repairable parts of it deliverable. It **never modifies
text**: it moves, re-times and re-wraps what already exists.

## The fix ladder

The professional order of operations for reading-speed (cps) problems,
generalized to every language pair (only the profile numbers differ):

| Step | Component | What it does | Status |
|---|---|---|---|
| 1 | Re-slotter | put translated text back onto the source cue timeline (repairs engine merge/drift) | **dropped** — measured dead on production data (see below) |
| 2 | `TimeHarvester` | extend out-times (then in-times) exactly as far as needed to reach `chars <= cps * duration` — never past the neighbouring cue (2-frame min gap) and never beyond 7 s total; need-driven and anchored to neighbours/duration, so re-running is a no-op | **implemented** |
| 3 | NeighborMerger | merge adjacent dense cues when the combined text fits `cpl x lines` (gains the gap seconds, averages the speed) | descoped — marginal (≤ 7 cues/file, see below) |
| 4 | LineWrapper | re-break text to the target's cpl/lines with script-aware rules (word boundaries for Latin scripts, kinsoku for CJK, …) | planned |
| 5 | Residual report | cues no mechanical step can save are reported with their exact character budget (`cps x duration = N chars`) and the text itself, ready for a condensation pass upstream | **implemented** |
| 6 | Self-verification | re-run the validator on the output and reject any repair that makes its verdict worse (`--source`) | **implemented** |

Splitting cues into *shorter* ones is deliberately not on the ladder: it
does not lower cps (same characters over the same seconds) and faster
cue turnover measurably harms reading.

### Why steps 1 and 3 were dropped (2026-10-27 probe, 29 production dump pairs)

Scripts and raw findings: `docs/scripts/` (gitignored, local).

- **Zero timing drift.** Every target cue in all 29 dumps sits exactly on
  its source cue's times (max start drift 0.000 s) — the translation
  pipeline passes source timing through untouched. There is nothing to
  re-slot.
- **Real engine merges are rare and splitting them backfires.** An engine
  merge keeps the first cue's timing (signature: an *uncovered* source
  slot, not a target spanning two slots). Genuine merge evidence exists
  only in #4 (it→ja) and #6 (ja→en); drops (translation simply missing)
  are ~3× more common and are the validator's defect, not the fixer's.
  Simulated splitting on #4 made the file *worse* (544 → 555 over-limit
  cues): the split halves land in short slots that cannot hold them at
  4 cps, producing two broken cues where there was one.
- **NeighborMerger is marginal under the compliance rule.** Merging
  dense-with-dense cannot lower cps; only rare dense+sparse pairs qualify
  (display limit `cpl × lines` must also hold). Simulation: #1 384→379,
  #2 651→647, #17 413→410. Not worth the added semantics.
- **Density is a text-length problem.** The worst files (#1/#2 uk→ja) are
  87–92 % back-to-back cues with ja text ~2× too long per slot — no
  timing operation can cure that. The cure is condensation upstream, fed
  by the residual report (step 5).


## Single source of truth

The fixer depends on `srt/translation-validator` via a Composer path
repository. All measurement lives in the validator; repair policy lives
here:

| Thing | Owner |
|---|---|
| readability profiles (`cps`/`cpl`/`lines` per language), char counting, cps math | validator |
| `SubtitleLoader`, `CaptionAligner` | validator |
| extension caps, min gap, dry-run/exit-code behavior | fixer |

The two tools can therefore never disagree about a limit. In local dev
the path repository is symlinked: editing the validator is immediately
visible to the fixer, and a validator change that breaks the fixer's
contract fails the fixer's own test suite.

## Installation

Requires a sibling checkout of the validator (the path repository points
at `../srt-translation-validator`):

```bash
git clone <validator repo> srt-translation-validator
git clone <fixer repo> srt-translation-fixer
cd srt-translation-fixer
composer install
```

## CLI

```
srt-fixer <translation-file> --lang <code> [options]
```

Options: `-l/--lang` (required — the language the file was translated
into), `--max-cps=F` (override the profile limit), `--out=<file>`
(write the fixed file; without it: dry run), `--json`, `-h/--help`,
`-V/--version`.

Exit codes: `0` fixed/nothing to fix · `1` input error · `2` usage
error · `3` fixes applied but residual cues remain (they need a shorter
translation — each is reported with its character budget).

```bash
$ php bin/srt-fixer target_ja.srt -l ja --out=fixed_ja.srt

  file:      target_ja.srt
  language:  ja (profile: 4 cps / 13 cpl / 2 lines)
  captions:  513

fixes (231):
  #4    0:00:11.094  end +0.847s  9.6 -> 4.0 cps
  ...

residual - retiming cannot save these, shorten the translation (12):
  #48   0:02:31.200  18 chars / 2.100s = 8.6 cps  (budget: 8 chars)
  ...

summary:
  over reading speed:  243 -> 12 captions
  max cps:             12.4 -> 6.1
  avg cps:             4.9 -> 4.1
  output:              fixed_ja.srt
```

The input file is never modified in place.

## Library use

```php
use SrtFixer\SrtTranslationFixer;

$fixer = new SrtTranslationFixer();
$fixer->setCpsOverride(6.0);          // optional, wins over the profile

$result = $fixer->fix('target_ja.srt', 'ja');
// $result['fixes']      applied repairs, per cue, with before/after metrics
// $result['residual']   cues still over the limit, with their char budget
// $result['blocks']     the repaired cue blocks
// $result['summary']    over-limit count / max cps / avg cps, before vs after

$fixer->toFile($result, 'fixed_ja.srt');
```

`fix()` is pure computation (nothing is written); `toFile()` writes.
Fixing an already-fixed file is a no-op (integer-millisecond arithmetic,
idempotent by construction and covered by tests).

## Development

```bash
composer test          # vendor/bin/phpunit tests
```

Tests are self-contained (synthetic fixtures generated in tmp). After
any source edit: `php -l` the touched files and run the full suite.

## Roadmap

- Step 1: re-slotter (port `CaptionAligner`; needs the source file as a
  second input — the CLI will grow `--source`).
- Step 3: neighbor merger.
- Step 4: script-aware line wrapper (kinsoku for CJK, word boundaries
  for Latin scripts, no-space scripts need special care).
- Min-duration / max-duration repair pass.
- Self-verification: re-run `SrtTranslationValidator` on the output and
  reject any change that makes the validator's verdict worse.
- PHAR build (`build/build-phar.php`, embedding the validator).
