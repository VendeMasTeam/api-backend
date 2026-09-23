<?php

namespace App\Support;

class SimplePdf
{
    private const PAGE_WIDTH = 612;

    private const PAGE_HEIGHT = 842;

    private const MARGIN_X = 44;

    private const BODY_TOP = 724;

    private const BODY_BOTTOM = 70;

    private const LINE_HEIGHT = 14;

    private const MAX_CHARS = 112;

    public static function document(string $title, array $lines): string
    {
        $pages = [];
        $content = self::pageHeader($title, 1);
        $y = self::BODY_TOP;

        foreach (self::bodyLines($lines) as $line) {
            $wrapped = self::wrap((string) $line);

            if ($wrapped === []) {
                $y -= 8;

                continue;
            }

            foreach ($wrapped as $segment) {
                if ($y < self::BODY_BOTTOM) {
                    $pages[] = $content.self::pageFooter(count($pages) + 1);
                    $content = self::pageHeader($title, count($pages) + 1);
                    $y = self::BODY_TOP;
                }

                $content .= self::text(self::MARGIN_X, $y, $segment, 9.5, 'F2', '0.17 0.20 0.25');
                $y -= self::LINE_HEIGHT;
            }

            $y -= 2;
        }

        $pages[] = $content.self::pageFooter(count($pages) + 1);

        return self::build($pages);
    }

    public static function tableReport(string $title, array $rows, array $options = []): string
    {
        $rows = array_values(array_map(fn ($row) => is_array($row) ? $row : ['value' => (string) $row], $rows));
        $columns = self::tableColumns($rows);
        $pages = [];
        $content = self::pageHeader($title, 1);
        $y = self::BODY_TOP;

        foreach (self::summaryLines($options) as $line) {
            $content .= self::text(self::MARGIN_X, $y, $line, 9.5, 'F2', '0.17 0.20 0.25');
            $y -= self::LINE_HEIGHT;
        }

        $y -= 12;
        $content .= self::tableHeader($columns, $y);
        $y -= 24;

        if ($rows === []) {
            $content .= self::tableRow($columns, ['message' => 'Sin datos'], $y, false);
        }

        foreach ($rows as $index => $row) {
            if ($y < self::BODY_BOTTOM + 22) {
                $pages[] = $content.self::pageFooter(count($pages) + 1);
                $content = self::pageHeader($title, count($pages) + 1);
                $y = self::BODY_TOP;
                $content .= self::tableHeader($columns, $y);
                $y -= 24;
            }

            $content .= self::tableRow($columns, $row, $y, $index % 2 === 1);
            $y -= 22;
        }

        $pages[] = $content.self::pageFooter(count($pages) + 1);

        return self::build($pages);
    }

    private static function summaryLines(array $options): array
    {
        $lines = [
            'Generado: '.date('Y-m-d H:i:s'),
            'Filas: '.(int) ($options['total_rows'] ?? 0),
        ];

        $filters = array_filter((array) ($options['filters'] ?? []), fn ($value) => $value !== null && $value !== '' && $value !== []);

        if ($filters !== []) {
            $lines[] = 'Filtros: '.implode(' | ', array_map(
                fn ($key, $value) => self::label((string) $key).': '.self::display($value),
                array_keys($filters),
                $filters
            ));
        }

        return $lines;
    }

    private static function tableColumns(array $rows): array
    {
        $columns = $rows !== [] ? array_keys($rows[0]) : ['message'];
        $visible = array_slice($columns, 0, 8);
        $width = (int) floor((self::PAGE_WIDTH - (self::MARGIN_X * 2)) / max(1, count($visible)));

        return array_map(fn ($key) => [
            'key' => $key,
            'label' => self::label((string) $key),
            'width' => $width,
        ], $visible);
    }

    private static function tableHeader(array $columns, int $y): string
    {
        $content = "q\n0.10 0.17 0.26 rg\n".self::MARGIN_X.' '.($y - 6).' '.(self::PAGE_WIDTH - (self::MARGIN_X * 2))." 22 re f\nQ\n";
        $x = self::MARGIN_X + 6;

        foreach ($columns as $column) {
            $content .= self::text($x, $y, self::shorten($column['label'], self::charsForWidth($column['width'], 8)), 8, 'F1', '1 1 1');
            $x += $column['width'];
        }

        return $content;
    }

    private static function tableRow(array $columns, array $row, int $y, bool $alternate): string
    {
        $fill = $alternate ? '0.96 0.98 1' : '1 1 1';
        $content = "q\n{$fill} rg\n0.82 0.88 0.94 RG\n".self::MARGIN_X.' '.($y - 7).' '.(self::PAGE_WIDTH - (self::MARGIN_X * 2))." 22 re B\nQ\n";
        $x = self::MARGIN_X + 6;

        foreach ($columns as $column) {
            $value = self::display($row[$column['key']] ?? '');
            $content .= self::text($x, $y, self::shorten($value, self::charsForWidth($column['width'], 7.5)), 7.5, 'F2', '0.17 0.20 0.25');
            $x += $column['width'];
        }

        return $content;
    }

    private static function bodyLines(array $lines): array
    {
        $body = [
            'Documento generado: '.date('Y-m-d H:i:s'),
            '',
        ];

        foreach ($lines as $line) {
            $body[] = $line;
        }

        return $body;
    }

    private static function pageHeader(string $title, int $page): string
    {
        $safeTitle = self::shorten($title, 76);

        return "q\n"
            ."0.10 0.17 0.26 rg\n0 782 612 60 re f\n"
            ."0.21 0.55 0.80 rg\n0 778 612 4 re f\n"
            ."0.95 0.98 1 rg\n44 748 524 18 re f\n"
            ."0.82 0.88 0.94 RG\n44 748 m 568 748 l S\n"
            ."Q\n"
            .self::text(44, 812, $safeTitle, 18, 'F1', '1 1 1')
            .self::text(44, 790, 'Exportacion / Reporte', 9, 'F2', '0.80 0.88 0.96')
            .self::text(514, 790, 'Pagina '.$page, 9, 'F2', '0.80 0.88 0.96')
            .self::text(44, 755, 'Resumen y detalle', 10, 'F1', '0.17 0.20 0.25');
    }

    private static function pageFooter(int $page): string
    {
        return "q\n0.82 0.88 0.94 RG\n44 45 m 568 45 l S\nQ\n"
            .self::text(44, 30, 'Archivo generado por '.app(\App\Services\PlatformBrandingService::class)->get()['name'], 8, 'F2', '0.46 0.52 0.60')
            .self::text(520, 30, 'Pagina '.$page, 8, 'F2', '0.46 0.52 0.60');
    }

    private static function text(int $x, int $y, string $value, float $size, string $font, string $color): string
    {
        return "BT\n{$color} rg\n/{$font} {$size} Tf\n{$x} {$y} Td\n(".self::escape($value).") Tj\nET\n";
    }

    private static function label(string $key): string
    {
        return ucwords(str_replace('_', ' ', $key));
    }

    private static function display(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        if (is_bool($value)) {
            return $value ? 'Si' : 'No';
        }

        if (is_array($value)) {
            return json_encode($value, JSON_UNESCAPED_UNICODE) ?: '';
        }

        return (string) $value;
    }

    private static function charsForWidth(int $width, float $fontSize): int
    {
        return max(6, (int) floor($width / max(4, $fontSize * 0.52)));
    }

    private static function wrap(string $value): array
    {
        $value = trim(preg_replace('/\s+/', ' ', $value) ?? '');

        if ($value === '') {
            return [];
        }

        $chunks = [];

        foreach (explode("\n", wordwrap($value, self::MAX_CHARS, "\n", true)) as $line) {
            $line = trim($line);

            if ($line !== '') {
                $chunks[] = $line;
            }
        }

        return $chunks;
    }

    private static function build(array $pageContents): string
    {
        $objects = [
            "1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n",
            "2 0 obj\n<< /Type /Pages /Kids ".self::pageKids($pageContents).' /Count '.count($pageContents)." >>\nendobj\n",
            "3 0 obj\n<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold >>\nendobj\n",
            "4 0 obj\n<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>\nendobj\n",
        ];

        $nextObject = 5;

        foreach ($pageContents as $content) {
            $pageObject = $nextObject++;
            $contentObject = $nextObject++;
            $objects[] = "{$pageObject} 0 obj\n<< /Type /Page /Parent 2 0 R /MediaBox [0 0 ".self::PAGE_WIDTH.' '.self::PAGE_HEIGHT."] /Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents {$contentObject} 0 R >>\nendobj\n";
            $objects[] = "{$contentObject} 0 obj\n<< /Length ".strlen($content)." >>\nstream\n{$content}endstream\nendobj\n";
        }

        $pdf = "%PDF-1.4\n";
        $offsets = [0];

        foreach ($objects as $object) {
            $offsets[] = strlen($pdf);
            $pdf .= $object;
        }

        $xrefOffset = strlen($pdf);
        $pdf .= "xref\n0 ".(count($objects) + 1)."\n";
        $pdf .= "0000000000 65535 f \n";

        foreach (array_slice($offsets, 1) as $offset) {
            $pdf .= str_pad((string) $offset, 10, '0', STR_PAD_LEFT)." 00000 n \n";
        }

        return $pdf."trailer\n<< /Size ".(count($objects) + 1)." /Root 1 0 R >>\nstartxref\n{$xrefOffset}\n%%EOF";
    }

    private static function pageKids(array $pageContents): string
    {
        $kids = [];

        for ($i = 0; $i < count($pageContents); $i++) {
            $kids[] = (5 + ($i * 2)).' 0 R';
        }

        return '['.implode(' ', $kids).']';
    }

    private static function shorten(string $value, int $limit): string
    {
        return strlen($value) > $limit ? substr($value, 0, $limit - 3).'...' : $value;
    }

    private static function escape(string $value): string
    {
        return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $value);
    }
}
