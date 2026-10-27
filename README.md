# srt-translation-fixer

Repairs translated subtitle files (SRT/WebVTT) against the target
language's readability profile — the mechanical counterpart to
[srt-translation-validator](../srt-translation-validator). The validator
decides *whether* a translation is deliverable; the fixer makes the
mechanically-repairable parts of it deliverable. It **never modifies
text**: it moves, re-times and re-wraps what already exists.

## The fix ladder

The professional order of operations for reading-speed (cps) and
line-limit (cpl/lines) problems, generalized to every language pair
(only the profile numbers differ):

| Step | Component | What it does | Status |
|---|---|---|---|
| 1 | Re-slotter | put translated text back onto the source cue timeline (repairs engine merge/drift) | **dropped** — measured dead on production data (see below) |
| 2 | `TimeHarvester` | extend out-times (then in-times) exactly as far as needed to reach `chars <= cps * duration` — never past the neighbouring cue (2-frame min gap) and never beyond 7 s total; need-driven and anchored to neighbours/duration, so re-running is a no-op | **implemented** |
| 3 | NeighborMerger | merge adjacent dense cues when the combined text fits `cpl x lines` (gains the gap seconds, averages the speed) | descoped — marginal (≤ 7 cues/file, see below) |
| 4 | `LineWrapper` | re-break text to the target's cpl/lines: each CJK char breaks anywhere (kinsoku-ruled), Latin words are atomic, breaks consume spaces; balanced fill minimizes the longer line; runs **before** the harvester so re-timing covers the final char count; wraps that would break reading speed in locked slots are reverted (cps outranks line shape) | **implemented** |
| 5 | Residual report | cues no mechanical step can save are reported with their exact character budget (`cps x duration = N chars` for speed, `cpl x lines` for display — or the split excess when word granularity blocks the wrap) and the text itself, ready for a condensation pass upstream | **implemented** |
| 6 | Self-verification | re-run the validator on the output and reject any repair that makes its verdict worse (`--source`) | **implemented** |

### The verification gate (step 6 semantics)

Structural invariants always run in memory: cue count, text identity
**up to re-wrapping** (the atom fingerprint sanctions a line break
replacing a space — never a changed character, merged or split word),
durations only widening, no new overlaps, the 7 s ceiling. With
`--source`, the validator itself compares (source, input) vs
(source, repaired) per defect type:

- **Sanctioned** (deltas reported, never fail the gate):
  `timestamp_mismatch`, `reading_speed`, and the aligner's
  `merged_captions`/`split_captions`/`extra_caption`/`missing_caption`
  — these are classified from cue start times within the validator's
  0.5 s pairing tolerance, so a sanctioned in-pull legitimately
  re-pairs cues (proven on dump #6: harvest-only grows
  `merged_captions` by 2). The CLI reports them as
  `merged_captions 96 -> 98 (sanctioned re-pairing)`.
- **Reading speed is judged per cue, not by defect count.** The
  validator only counts an over-limit caption when its aligned source
  caption is not itself overloaded (inheritance exemption) and its
  duration is at least 0.2 s — both are alignment/threshold
  bookkeeping that the fixer's timing changes legitimately disturb.
  When the count grows, the gate examines every newly counted caption:
  if its cps rose above its own input cps (the wrap join-space case),
  the repair is rejected naming the cue; if every newly counted cue
  stayed equal or improved, the growth is sanctioned and reported as
  `reading_speed_newly_counted` in `sanctioned_deltas`. Corpus soak
  (2026-10-27): the four files this rule rescued (ai1/44575, 44576,
  44586, 44593) had every newly counted cue improved 3–5× (e.g. cps
  110.2 → 24.5) while the old count-based check rejected them.
- **Guarded** (may not appear or grow): everything else — text, cue
  count, scripts, format. Any delta rejects the whole repair (exit 4,
  nothing applied or written).

An unparseable source *skips* the comparison loudly (structural checks
still ran) rather than rejecting.

## Pipeline order

The verdict and the repair judge different artefacts:

1. **Validate the raw translation** — the validator's verdict (including
   the drift gate and billing decisions) runs on exactly what the
   engine produced.
2. **Fix** — this tool re-wraps and re-times for readability; every
   change is proven safe by the verification gate first.
3. **Deliver the fixed file.**

Never re-validate a fixed file with default thresholds: reading-time
extensions are *intentional* drift from the source timeline, and the
validator's `timestamp_drift` gate (2 % of pairs) correctly rejects
exactly that — measured on the 29 production dumps, 8 files flip
valid→INVALID on drift alone while their readability improves
(`docs/scripts/verdict-replay.php`). If a fixed file must be
re-validated, raise the gate (`--max-drift-ratio=1`) and expect the
resulting `timestamp_mismatch` defects: they are the repair. The CLI
prints a `re-validate:` hint whenever it re-timed cues.


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

### Step 4 evidence (2026-10-27 probe + replay, same 29 dumps)

- ja targets (13 cpl) are where wrapping pays: line-limit violations
  drop 40–75 % (#17 420→109, #18 814→296, #26 428→99 after the full
  chain); the remainder are true capacity failures (text longer than
  `cpl x lines`) that only condensation can cure.
- Latin targets gain mostly line-count collapses (#23 fi 34→1,
  #22 bg 52→3).
- Wrap→harvest ordering never regresses reading speed end-to-end (0
  regressions across 29 files); the rare locked-slot regression is
  reverted (cps outranks line shape), keeping every replay at or below
  the harvest-only baseline (except #18: +1, an in-pull allocation
  artifact — a wrapped cue's extra char consumed a neighbour's gap).


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
into), `--source <file>` (the original subtitle; enables the full
validator comparison in the gate), `--max-cps=F` (override the profile
limit), `--out=<file>` (write the fixed file; without it: dry run),
`--json`, `-h/--help`, `-V/--version`.

Exit codes: `0` fixed/nothing to fix · `1` input error · `2` usage
error · `3` fixes applied but residual cues remain (they need a shorter
translation — each is reported with its character budget) · `4` the
gate rejected the repair (nothing applied or written; the reasons are
listed).

```bash
$ php bin/srt-fixer target_ja.srt -l ja --out=fixed_ja.srt

  file:      target_ja.srt
  language:  ja (profile: 4 cps / 13 cpl / 2 lines)
  captions:  513

verification: passed (structural; pass --source <original> for a full validator comparison)

fixes (231):
  #4    0:00:11.094  end +0.847s  9.6 -> 4.0 cps
  #7    0:03:40.444  rewrap  1 -> 2 lines, 16 -> 8 max cpl
  ...

residual - mechanical repair cannot save these, shorten the translation (12):
  #48   0:02:31.200  18 chars / 2.100s = 8.6 cps  (budget: 8 chars)
  #51   0:02:40.100  29 chars > 26 display budget  (cut 3+)  ...
  ...

summary:
  over reading speed:  243 -> 12 captions
  over line limits:    120 -> 31 captions
  max cps:             12.4 -> 6.1
  avg cps:             4.9 -> 4.1
  chars to cut:        87 across 14 cues
  output:              fixed_ja.srt
```

The input file is never modified in place.

## Library use

```php
use SrtFixer\SrtTranslationFixer;

$fixer = new SrtTranslationFixer();
$fixer->setCpsOverride(6.0);          // optional, wins over the profile

$result = $fixer->fix('target_ja.srt', 'ja');
// $result['fixes']      applied repairs, per cue ('retimed' / 'rewrapped'),
//                      with before/after metrics
// $result['residual']   cues still over the limit ('over_reading_speed'
//                      with a cps budget, 'over_line_capacity' with a
//                      display budget or split excess)
// $result['blocks']     the repaired cue blocks
// $result['summary']    over-limit counts (speed + lines), max/avg cps,
//                      chars to cut, before vs after

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

- PHAR build (`build/build-phar.php`, embedding the validator) — after
  the implemented steps soak on real traffic.
- Min-duration / max-duration repair pass (max-duration ceiling is
  already enforced; a repair pass would shorten over-long cues).
