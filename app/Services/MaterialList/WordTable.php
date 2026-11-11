<?php

namespace App\Services\MaterialList;

/**
 * Rebuilds a table from word positions: Tesseract's or pdftotext's "-tsv" output.
 *
 * Words are grouped into lines. The first line that $isHeader accepts names the columns. Where
 * one column ends and the next begins is read from the data below it: between two headers, the
 * edge goes in the blank strip (the "river") that the rows leave between the columns. Header
 * words with no river between them belong to one column ("Product Name"). Each word then goes
 * into the column it sits in. This keeps columns apart even when the text has no separators,
 * as in scans, photos and most PDFs.
 */
final class WordTable
{
    /**
     * @param  array<int, string>  $tsvPages  TSV output (one string may hold several pages)
     * @param  callable(array<int, string>, bool): bool  $isHeader  is this line the column header row?
     *                                                              (2nd argument: the cells are single words)
     * @param  bool  $tesseractLines  group words by Tesseract's own line ids (copes with slightly
     *                                tilted photos); otherwise by vertical position (PDF text)
     * @return array{aligned: bool, rows: array<int, array<int, string>>, conf: array<int, array<int, float>>} aligned: a header row was found; conf: lowest OCR confidence (0-100) of the words in each cell
     */
    public static function build(array $tsvPages, callable $isHeader, bool $tesseractLines): array
    {
        $columns = null; // [{text, x0, x1}], edges between them
        $edges = [];
        $rows = [];
        $conf = [];

        foreach ($tsvPages as $tsv) {
            foreach (self::pages($tsv) as $words) {
                $lines = self::lines($words, $tesseractLines);

                [$headerAt, $header, $titles] = self::findHeader($lines, $isHeader);

                $data = $headerAt === null ? $lines : array_slice($lines, $headerAt + 1);
                if ($headerAt !== null) {
                    [$found, $edges] = self::columns($header, $data);
                    if ($columns === null) {
                        foreach (array_slice($lines, 0, $titles) as $title) {
                            $rows[] = array_column(self::phrases($title), 'text');
                            $conf[] = array_map(fn ($p) => self::lowestConf($p['words']), self::phrases($title));
                        }
                        $rows[] = array_column($found, 'text');
                        $conf[] = array_fill(0, count($found), 100.0);
                        $first = ['row' => array_key_last($rows), 'columns' => $found, 'letter' => self::letterHeight($header)];
                    }
                    $columns = $found; // a repeated header on a later page realigns the columns
                } elseif ($columns === null) {
                    foreach ($lines as $line) {
                        $rows[] = array_column(self::phrases($line), 'text'); // no table (yet): plain lines
                        $conf[] = array_map(fn ($p) => self::lowestConf($p['words']), self::phrases($line));
                    }

                    continue;
                }

                self::addRows($rows, $conf, $data, $columns, $edges);
            }
        }

        if (isset($first)) {
            self::joinEmptyColumns($rows, $conf, $first['row'], $first['columns'], $first['letter']);
        }

        return ['aligned' => $columns !== null, 'rows' => $rows, 'conf' => $conf];
    }

    /** Lowest confidence of a group of words (100 when the source gives none, as for PDF text). */
    private static function lowestConf(array $words): float
    {
        $values = array_map(fn ($w) => (float) ($w['conf'] ?? 100), $words);

        return $values === [] ? 100.0 : min($values);
    }

    /**
     * The header line of a page: [index of its last line, its words, number of lines above it],
     * or [null, [], 0]. A header whose cells wrap onto a second line ("Available" over
     * "Quantity") is read as one, but only when no single line is a header.
     */
    private static function findHeader(array $lines, callable $isHeader): array
    {
        // Phrases first ("Product Name" | "Qty"); single words only when the phrases ran
        // together. Word-level cells are flagged so a title ("Bill of Materials and
        // Quantities") is not taken for a header just because two of its words are labels.
        $test = fn (array $line) => $isHeader(array_column(self::phrases($line), 'text'), false)
            || $isHeader(array_column($line, 'text'), true);

        foreach ($lines as $i => $line) {
            if ($test($line)) {
                return [$i, $line, $i];
            }
        }
        for ($i = 0; $i + 1 < count($lines); $i++) {
            $joined = self::joinWrapped($lines[$i], $lines[$i + 1]);
            if ($joined !== null && $test($joined)) {
                return [$i + 1, $joined, $i];
            }
        }

        return [null, [], 0];
    }

    /**
     * Two lines read as one header line when the second sits right under the first (wrapped
     * text, not the next table row). Words under each other become one cell, top line first.
     */
    private static function joinWrapped(array $upper, array $lower): ?array
    {
        [, $bottom, $h1] = self::extent($upper);
        [$top, , $h2] = self::extent($lower);
        if ($top - $bottom >= 0.6 * min($h1, $h2)) {
            return null;
        }

        $words = array_merge(
            array_map(fn ($w) => $w + ['row' => 0], $upper),
            array_map(fn ($w) => $w + ['row' => 1], $lower),
        );
        usort($words, fn ($a, $b) => $a['x0'] <=> $b['x0']);
        $cell = -1;
        $right = -INF;
        foreach ($words as &$w) {
            if ($w['x0'] > $right) {
                $cell++;
            }
            $w['cell'] = $cell;
            $right = max($right, $w['x1']);
        }
        unset($w);
        usort($words, fn ($a, $b) => [$a['cell'], $a['row'], $a['x0']] <=> [$b['cell'], $b['row'], $b['x0']]);

        return array_map(fn ($w) => array_diff_key($w, ['row' => 0, 'cell' => 0]), $words);
    }

    /** Place each data line into the columns; skip notes and page numbers, join wrapped cells. */
    private static function addRows(array &$rows, array &$conf, array $lines, array $columns, array $edges): void
    {
        $lastData = null;
        $prevBottom = null;
        foreach ($lines as $line) {
            // Drop "Page 2 of 3" wherever it sits on the line.
            $line = array_merge(...array_map(
                fn ($p) => self::isPageMarker($p['text']) ? [] : $p['words'],
                self::phrases($line) ?: [['text' => '', 'words' => []]],
            ));
            if ($line === []) {
                continue;
            }
            [$top, $bottom, $height] = self::extent($line);

            $placed = self::place($line, count($columns), $edges);
            if ($placed === null) {
                continue; // a sentence running across the columns: a note or footer, not a row
            }
            [$cells, $cellConf] = $placed;

            $filled = array_keys(array_filter($cells, fn ($c) => $c !== ''));
            $close = $prevBottom !== null && $top - $prevBottom < 0.8 * $height;
            $prevFilledCount = $lastData !== null ? count(array_filter($rows[$lastData], fn ($c) => $c !== '')) : 0;
            $canWrapCol0 = $filled === [0]
                && $prevFilledCount >= 2
                && ($rows[$lastData][0] ?? '') !== ''
                && ! preg_match('/^(\d+[\.\)]|[A-Z]{2,6}-\d{2,}|total|sub\s*-?\s*total|grand\s+total)\b/iu', $cells[0]);

            if ($lastData !== null && $close && count($filled) === 1 && ($filled[0] > 0 || $canWrapCol0)) {
                // A long cell wrapped onto the next line.
                $rows[$lastData][$filled[0]] = trim($rows[$lastData][$filled[0]].' '.$cells[$filled[0]]);
                $conf[$lastData][$filled[0]] = min($conf[$lastData][$filled[0]] ?? 100.0, $cellConf[$filled[0]]);
            } else {
                $rows[] = $cells;
                $conf[] = $cellConf;
                $lastData = array_key_last($rows);
            }
            $prevBottom = $bottom;
        }
    }

    /**
     * Columns from the header words, with edges read from the rows below.
     *
     * @return array{0: array<int, array{text: string, x0: float, x1: float}>, 1: array<int, float>}
     */
    private static function columns(array $header, array $data): array
    {
        $coverage = self::coverage($data);
        $rows = count($data);
        $letter = self::letterHeight($header);

        $columns = [];
        foreach ($header as $w) {
            $last = array_key_last($columns);
            if ($last !== null) {
                // Same column when the gap is a normal space between words, or when the rows fill
                // it (at least 2, so one footer sentence running across cannot join two columns).
                $same = 0.5 * $letter > $w['x0'] - $columns[$last]['x1']
                    || ($rows >= 2 && $coverage->min($columns[$last]['x1'], $w['x0']) >= max(2, 0.3 * $rows));
                if ($same) {
                    $columns[$last]['text'] .= ' '.$w['text'];
                    $columns[$last]['x1'] = max($columns[$last]['x1'], $w['x1']);

                    continue;
                }
            }
            $columns[] = ['text' => $w['text'], 'x0' => $w['x0'], 'x1' => $w['x1']];
        }

        $edges = [];
        for ($i = 0; $i < count($columns) - 1; $i++) {
            $a = $columns[$i];
            $b = $columns[$i + 1];
            if ($rows === 0) {
                $edges[] = ($a['x1'] + $b['x0']) / 2;

                continue;
            }
            // The river is normally in the space between the two headers. Numbers wider than their
            // header (right-aligned) can reach into it, so look between the header middles too.
            $between = [$a['x1'], $b['x0']];
            $middles = [($a['x0'] + $a['x1']) / 2, ($b['x0'] + $b['x1']) / 2];
            $range = $coverage->min(...$between) <= $coverage->min(...$middles) ? $between : $middles;
            $edges[] = $coverage->river(...$range);
        }

        return [$columns, $edges];
    }

    /**
     * A header split in two by the rule above ("Product" | "Name" over short names) leaves one
     * part with no data. Such an empty column is joined to a neighbour whose header is only a
     * space away.
     */
    private static function joinEmptyColumns(array &$rows, array &$conf, int $headerRow, array $columns, float $letter): void
    {
        for ($j = count($columns) - 1; $j >= 0; $j--) {
            $empty = true;
            for ($r = $headerRow + 1; $r < count($rows) && $empty; $r++) {
                $empty = ($rows[$r][$j] ?? '') === '';
            }
            if (! $empty) {
                continue;
            }

            $gapLeft = $j > 0 ? $columns[$j]['x0'] - $columns[$j - 1]['x1'] : INF;
            $gapRight = $j < count($columns) - 1 ? $columns[$j + 1]['x0'] - $columns[$j]['x1'] : INF;
            $into = $gapLeft <= $gapRight ? $j - 1 : $j + 1;
            if (min($gapLeft, $gapRight) >= $letter) {
                continue;
            }

            $rows[$headerRow][$into] = $into < $j
                ? $rows[$headerRow][$into].' '.$rows[$headerRow][$j]
                : $rows[$headerRow][$j].' '.$rows[$headerRow][$into];
            for ($r = $headerRow; $r < count($rows); $r++) {
                array_splice($rows[$r], $j, 1);
                if (isset($conf[$r])) {
                    array_splice($conf[$r], $j, 1);
                }
            }
            $columns[$into]['x0'] = min($columns[$into]['x0'], $columns[$j]['x0']);
            $columns[$into]['x1'] = max($columns[$into]['x1'], $columns[$j]['x1']);
            array_splice($columns, $j, 1);
        }
    }

    /**
     * Height of plain letters in a line. OCR boxes vary a lot (some include the whole header
     * bar), so the lower quartile is used rather than the median.
     */
    private static function letterHeight(array $line): float
    {
        $heights = array_map(fn ($w) => $w['y1'] - $w['y0'], $line);
        sort($heights);

        return (float) ($heights[intdiv(count($heights), 4)] ?? 10);
    }

    /**
     * Put each word in the column it sits in: [cells, lowest word confidence per cell]. Null when
     * the line is a sentence running across columns (3+ words with normal spacing over 2+
     * columns), which is a note or footer, not a row.
     */
    private static function place(array $line, int $count, array $edges): ?array
    {
        $column = function (array $w) use ($edges): int {
            $x = ($w['x0'] + $w['x1']) / 2;
            $i = 0;
            while ($i < count($edges) && $x > $edges[$i]) {
                $i++;
            }

            return $i;
        };

        foreach (self::phrases($line) as $p) {
            if (count($p['words']) >= 3 && count(array_unique(array_map($column, $p['words']))) > 1) {
                return null;
            }
        }

        $cells = array_fill(0, $count, '');
        $conf = array_fill(0, $count, 100.0);
        foreach ($line as $w) {
            $c = $column($w);
            $cells[$c] = trim($cells[$c].' '.$w['text']);
            $conf[$c] = min($conf[$c], (float) ($w['conf'] ?? 100));
        }

        return [$cells, $conf];
    }

    /** @return array<int, array<int, array{text: string, x0: float, x1: float, y0: float, y1: float, line: string}>> words per page */
    private static function pages(string $tsv): array
    {
        $lines = preg_split('/\r\n|\n|\r/', trim($tsv)) ?: [];
        $head = array_flip(explode("\t", (string) array_shift($lines)));
        foreach (['level', 'page_num', 'left', 'top', 'width', 'height', 'text'] as $required) {
            if (! isset($head[$required])) {
                return [];
            }
        }

        $pages = [];
        foreach ($lines as $raw) {
            $f = explode("\t", $raw);
            // Table borders read by OCR ("|", "Name|") are never content.
            $text = trim(preg_replace('/^[|¦‖]+|[|¦‖]+$/u', '', trim($f[$head['text']] ?? '')) ?? '');
            if (($f[$head['level']] ?? '') !== '5' || $text === '' || str_starts_with($text, '###')) {
                continue;
            }
            // Tesseract marks specks and stray lines with very low confidence.
            if (isset($head['conf']) && (float) ($f[$head['conf']] ?? 100) < 15 && ! preg_match('/[\p{L}\p{N}]{2}/u', $text)) {
                continue;
            }
            $x = (float) $f[$head['left']];
            $y = (float) $f[$head['top']];
            $line = implode('-', array_map(fn ($k) => isset($head[$k]) ? ($f[$head[$k]] ?? '') : '', ['block_num', 'par_num', 'line_num']));
            $pages[(int) $f[$head['page_num']]][] = [
                'text' => $text, 'x0' => $x, 'x1' => $x + (float) $f[$head['width']],
                'y0' => $y, 'y1' => $y + (float) $f[$head['height']], 'line' => $line,
                // Text-layer words (pdftotext) report -1 or 100: only OCR words can be unsure.
                'conf' => isset($head['conf']) && (float) ($f[$head['conf']] ?? -1) >= 0 ? (float) $f[$head['conf']] : 100.0,
            ];
        }
        ksort($pages);

        return array_values($pages);
    }

    /** Words grouped into lines, top to bottom, each line left to right. */
    private static function lines(array $words, bool $tesseractLines): array
    {
        $lines = [];
        if ($tesseractLines) {
            foreach ($words as $w) {
                $lines[$w['line']][] = $w;
            }
            $lines = array_values($lines);
        } else {
            usort($words, fn ($a, $b) => ($a['y0'] + $a['y1']) <=> ($b['y0'] + $b['y1']));
            $current = null;
            foreach ($words as $w) {
                $mid = ($w['y0'] + $w['y1']) / 2;
                $h = $w['y1'] - $w['y0'];
                if ($current !== null && abs($mid - $current['mid']) <= 0.5 * max($h, $current['h'])) {
                    $lines[$current['i']][] = $w;

                    continue;
                }
                $lines[] = [$w];
                $current = ['i' => array_key_last($lines), 'mid' => $mid, 'h' => $h];
            }
        }

        foreach ($lines as &$line) {
            usort($line, fn ($a, $b) => $a['x0'] <=> $b['x0']);
        }
        unset($line);
        usort($lines, fn ($a, $b) => self::extent($a)[0] <=> self::extent($b)[0]);

        if ($tesseractLines && count($lines) > 1) {
            $merged = [];
            foreach ($lines as $line) {
                $last = array_key_last($merged);
                if ($last !== null) {
                    [$topA, $botA, $hA] = self::extent($merged[$last]);
                    [$topB, $botB, $hB] = self::extent($line);
                    $midA = ($topA + $botA) / 2;
                    $midB = ($topB + $botB) / 2;
                    $minXA = min(array_column($merged[$last], 'x0'));
                    $maxXA = max(array_column($merged[$last], 'x1'));
                    $minXB = min(array_column($line, 'x0'));
                    $maxXB = max(array_column($line, 'x1'));
                    $disjointX = $maxXA <= $minXB || $maxXB <= $minXA;

                    if ($disjointX && abs($midA - $midB) <= 0.5 * max($hA, $hB)) {
                        $merged[$last] = array_merge($merged[$last], $line);
                        usort($merged[$last], fn ($a, $b) => $a['x0'] <=> $b['x0']);

                        continue;
                    }
                }
                $merged[] = $line;
            }
            $lines = $merged;
        }

        return $lines;
    }

    /** Neighbouring words with a gap smaller than about one letter join into a phrase. */
    private static function phrases(array $line): array
    {
        $gap = 0.8 * self::letterHeight($line);
        $phrases = [];
        foreach ($line as $w) {
            $last = array_key_last($phrases);
            if ($last !== null && $gap > $w['x0'] - $phrases[$last]['x1']) {
                $phrases[$last]['text'] .= ' '.$w['text'];
                $phrases[$last]['x1'] = max($phrases[$last]['x1'], $w['x1']);
                $phrases[$last]['words'][] = $w;
            } else {
                $phrases[] = ['text' => $w['text'], 'x0' => $w['x0'], 'x1' => $w['x1'], 'words' => [$w]];
            }
        }

        return $phrases;
    }

    /** How many lines have a word over each x position (1-unit steps). */
    private static function coverage(array $lines): object
    {
        $min = INF;
        $max = -INF;
        foreach ($lines as $line) {
            foreach ($line as $w) {
                $min = min($min, $w['x0']);
                $max = max($max, $w['x1']);
            }
        }
        $origin = is_finite($min) ? (int) floor($min) : 0;
        $size = is_finite($max) ? (int) ceil($max) - $origin + 2 : 1;

        $diff = array_fill(0, $size + 1, 0);
        foreach ($lines as $line) {
            // One count per line, even where its words overlap.
            $spans = [];
            foreach ($line as $w) {
                $a = (int) floor($w['x0']) - $origin;
                $b = (int) ceil($w['x1']) - $origin;
                if ($spans !== [] && $a <= $spans[array_key_last($spans)][1]) {
                    $spans[array_key_last($spans)][1] = max($spans[array_key_last($spans)][1], $b);
                } else {
                    $spans[] = [$a, $b];
                }
            }
            foreach ($spans as [$a, $b]) {
                $diff[max(0, $a)]++;
                $diff[min($size, max(0, $b))]--;
            }
        }
        $counts = [];
        $run = 0;
        for ($i = 0; $i < $size; $i++) {
            $run += $diff[$i];
            $counts[$i] = $run;
        }

        return new class($counts, $origin)
        {
            public function __construct(private array $counts, private int $origin) {}

            private function at(int $x): int
            {
                return $this->counts[$x - $this->origin] ?? 0;
            }

            /** Fewest lines covering any point between $from and $to. */
            public function min(float $from, float $to): int
            {
                [$a, $b] = [(int) floor(min($from, $to)), (int) ceil(max($from, $to))];
                $least = PHP_INT_MAX;
                for ($x = $a; $x <= $b; $x++) {
                    $least = min($least, $this->at($x));
                }

                return $least === PHP_INT_MAX ? 0 : $least;
            }

            /** Middle of the widest stretch with the fewest covering lines between $from and $to. */
            public function river(float $from, float $to): float
            {
                [$a, $b] = [(int) floor($from), (int) ceil($to)];
                $least = $this->min($a, $b);
                $best = [$a, $a];
                $start = null;
                for ($x = $a; $x <= $b + 1; $x++) {
                    if ($x <= $b && $this->at($x) === $least) {
                        $start ??= $x;

                        continue;
                    }
                    if ($start !== null && $x - 1 - $start > $best[1] - $best[0]) {
                        $best = [$start, $x - 1];
                    }
                    $start = null;
                }

                return ($best[0] + $best[1]) / 2;
            }
        };
    }

    /** @return array{0: float, 1: float, 2: float} top, bottom and median word height of a line */
    private static function extent(array $line): array
    {
        $heights = array_map(fn ($w) => $w['y1'] - $w['y0'], $line);
        sort($heights);

        return [min(array_column($line, 'y0')), max(array_column($line, 'y1')), (float) ($heights[intdiv(count($heights), 2)] ?? 10)];
    }

    private static function isPageMarker(string $text): bool
    {
        return (bool) preg_match('/^(?:page|image)?\s*\d{1,4}\s*(?:of|\/)\s*\d{1,4}$/i', trim($text));
    }
}
