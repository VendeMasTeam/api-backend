<?php

namespace App\Services;

use App\Models\Quotation;
use App\Models\QuotationItem;

class QuotationPdfService
{
    private const PAGE_WIDTH = 612;
    private const PAGE_HEIGHT = 792;
    private const MARGIN = 42;
    private const BODY_BOTTOM = 82;

    public function __construct(private readonly PlatformBrandingService $platformBrandingService) {}

    public function render(Quotation $quotation): string
    {
        $quotation->loadMissing([
            'tenant.currency',
            'owner',
            'createdBy',
            'items.product',
            'items.catalogProduct',
            'items.warehouse',
            'quoteable',
            'priceBook',
        ]);

        return $this->buildPdfDocument($this->pages($quotation));
    }

    public function filename(Quotation $quotation): string
    {
        $quoteNumber = preg_replace('/[^A-Za-z0-9\-_]+/', '-', $quotation->quote_number ?: $quotation->uid);

        return 'cotizacion-' . trim((string) $quoteNumber, '-') . '.pdf';
    }

    private function pages(Quotation $quotation): array
    {
        $pages = [];
        $page = 1;
        $content = $this->pageHeader($quotation, $page);
        $y = 594;

        $content .= $this->itemsHeader($y);
        $y -= 26;

        foreach ($quotation->items as $index => $item) {
            if ($y < self::BODY_BOTTOM + 56) {
                $content .= $this->pageFooter($page);
                $pages[] = $content;
                $page++;
                $content = $this->pageHeader($quotation, $page);
                $y = 622;
                $content .= $this->itemsHeader($y);
                $y -= 26;
            }

            $content .= $this->itemRow($item, $index + 1, $y, $index % 2 === 1);
            $y -= 34;
        }

        if ($y < 206) {
            $content .= $this->pageFooter($page);
            $pages[] = $content;
            $page++;
            $content = $this->pageHeader($quotation, $page);
            $y = 622;
        }

        $content .= $this->totalsBlock($quotation, $y - 8);
        $y -= 112;

        if ($quotation->notes) {
            $content .= $this->notesBlock((string) $quotation->notes, $y);
        }

        $content .= $this->pageFooter($page);
        $pages[] = $content;

        return $pages;
    }

    private function pageHeader(Quotation $quotation, int $page): string
    {
        $tenant = $quotation->tenant;
        $companyName = $tenant?->name ?: $this->platformBrandingService->get()['name'];
        $quoteNumber = $quotation->quote_number ?: $quotation->uid;
        $client = $quotation->quoteable;
        $clientName = $quotation->client_name ?: 'Cliente';
        $clientEmail = $client?->email ?? null;
        $clientDocument = $client?->document ?? $client?->tax_id ?? null;
        $clientPhone = $client?->phone ?? null;
        $clientAddress = $client?->address ?? null;
        $status = $this->statusLabel((string) $quotation->status);

        $content = '';
        $content .= $this->rect(0, 720, self::PAGE_WIDTH, 72, '0.09 0.16 0.26');
        $content .= $this->rect(0, 716, self::PAGE_WIDTH, 4, '0.20 0.55 0.80');
        $content .= $this->text(42, 760, $companyName, 18, 'F1', '1 1 1');
        $content .= $this->text(42, 738, 'Cotizacion comercial', 9.5, 'F2', '0.82 0.90 0.98');
        $content .= $this->text(440, 760, 'COTIZACION', 16, 'F1', '1 1 1');
        $content .= $this->text(440, 738, $quoteNumber, 10, 'F2', '0.82 0.90 0.98');

        $content .= $this->panel(42, 610, 250, 86, 'Cliente');
        $content .= $this->text(56, 670, $clientName, 11, 'F1', '0.10 0.17 0.26');
        $content .= $this->text(56, 652, 'Email: ' . ($clientEmail ?: 'N/A'), 8.5, 'F2', '0.30 0.35 0.42');
        $content .= $this->text(56, 636, 'Documento: ' . ($clientDocument ?: 'N/A'), 8.5, 'F2', '0.30 0.35 0.42');
        $content .= $this->text(56, 620, 'Telefono: ' . ($clientPhone ?: 'N/A'), 8.5, 'F2', '0.30 0.35 0.42');

        if ($clientAddress) {
            $content .= $this->text(56, 604, $this->shorten('Direccion: ' . $clientAddress, 46), 8.5, 'F2', '0.30 0.35 0.42');
        }

        $content .= $this->panel(320, 610, 250, 86, 'Detalle');
        $content .= $this->text(334, 670, 'Estado: ' . $status, 9, 'F1', '0.10 0.17 0.26');
        $content .= $this->text(334, 652, 'Fecha: ' . $this->date($quotation->created_at), 8.5, 'F2', '0.30 0.35 0.42');
        $content .= $this->text(334, 636, 'Valida hasta: ' . ($quotation->valid_until ? $quotation->valid_until->format('Y-m-d') : 'N/A'), 8.5, 'F2', '0.30 0.35 0.42');
        $content .= $this->text(334, 620, 'Moneda: ' . ($quotation->currency ?: $tenant?->currency?->code ?: 'N/A'), 8.5, 'F2', '0.30 0.35 0.42');
        $content .= $this->text(334, 604, 'Vendedor: ' . ($quotation->owner?->name ?: $quotation->createdBy?->name ?: 'N/A'), 8.5, 'F2', '0.30 0.35 0.42');

        $content .= $this->text(42, 575, $this->shorten($quotation->title ?: 'Detalle de productos y servicios', 86), 12, 'F1', '0.10 0.17 0.26');
        $content .= $this->text(526, 28, 'Pagina ' . $page, 8, 'F2', '0.45 0.50 0.58');

        return $content;
    }

    private function itemsHeader(int $y): string
    {
        $content = $this->rect(42, $y - 8, 528, 24, '0.10 0.17 0.26');
        $content .= $this->text(52, $y, '#', 8, 'F1', '1 1 1');
        $content .= $this->text(76, $y, 'Descripcion', 8, 'F1', '1 1 1');
        $content .= $this->text(286, $y, 'SKU', 8, 'F1', '1 1 1');
        $content .= $this->text(370, $y, 'Cant.', 8, 'F1', '1 1 1');
        $content .= $this->text(420, $y, 'Precio', 8, 'F1', '1 1 1');
        $content .= $this->text(486, $y, 'Desc.', 8, 'F1', '1 1 1');
        $content .= $this->text(534, $y, 'Total', 8, 'F1', '1 1 1');

        return $content;
    }

    private function itemRow(QuotationItem $item, int $number, int $y, bool $alternate): string
    {
        $fill = $alternate ? '0.96 0.98 1' : '1 1 1';
        $name = $item->description ?: $item->catalogProduct?->name ?: $item->product?->name ?: 'Item';

        $content = $this->rect(42, $y - 10, 528, 32, $fill, '0.82 0.88 0.94');
        $content .= $this->text(52, $y + 5, (string) $number, 8, 'F2', '0.17 0.20 0.25');
        $content .= $this->text(76, $y + 5, $this->shorten($name, 40), 8.2, 'F1', '0.17 0.20 0.25');
        $content .= $this->text(76, $y - 8, $this->shorten($item->catalogProduct?->type === 'service' ? 'Servicio' : 'Producto', 38), 7.2, 'F2', '0.45 0.50 0.58');
        $content .= $this->text(286, $y + 5, $this->shorten((string) ($item->sku ?: $item->product?->sku ?: ''), 16), 7.8, 'F2', '0.17 0.20 0.25');
        $content .= $this->text(374, $y + 5, $this->quantity((float) $item->quantity), 7.8, 'F2', '0.17 0.20 0.25');
        $content .= $this->text(416, $y + 5, $this->money((float) $item->list_unit_price), 7.8, 'F2', '0.17 0.20 0.25');
        $content .= $this->text(486, $y + 5, $this->money((float) $item->discount_amount), 7.8, 'F2', '0.17 0.20 0.25');
        $content .= $this->text(528, $y + 5, $this->money((float) $item->line_total), 7.8, 'F1', '0.17 0.20 0.25');

        return $content;
    }

    private function totalsBlock(Quotation $quotation, int $y): string
    {
        $x = 360;
        $content = $this->panel($x, $y - 86, 210, 88, 'Resumen');
        $content .= $this->totalLine($x + 14, $y - 22, 'Subtotal', (float) $quotation->subtotal);
        $content .= $this->totalLine($x + 14, $y - 42, 'Descuento', (float) $quotation->discount_total);
        $content .= $this->rect($x + 10, $y - 74, 190, 28, '0.10 0.17 0.26');
        $content .= $this->text($x + 20, $y - 64, 'Total', 10, 'F1', '1 1 1');
        $content .= $this->text($x + 118, $y - 64, $this->money((float) $quotation->total), 10, 'F1', '1 1 1');

        return $content;
    }

    private function totalLine(int $x, int $y, string $label, float $value): string
    {
        return $this->text($x, $y, $label, 8.5, 'F2', '0.30 0.35 0.42')
            . $this->text($x + 104, $y, $this->money($value), 8.5, 'F1', '0.17 0.20 0.25');
    }

    private function notesBlock(string $notes, int $y): string
    {
        $content = $this->panel(42, max(86, $y - 76), 292, 78, 'Notas');
        $lineY = max(120, $y - 22);

        foreach (array_slice($this->wrap($notes, 60), 0, 4) as $line) {
            $content .= $this->text(56, $lineY, $line, 8.2, 'F2', '0.30 0.35 0.42');
            $lineY -= 14;
        }

        return $content;
    }

    private function pageFooter(int $page): string
    {
        return $this->line(42, 56, 570, 56, '0.82 0.88 0.94')
            . $this->text(42, 38, 'Gracias por su confianza. Esta cotizacion esta sujeta a disponibilidad y condiciones comerciales.', 7.6, 'F2', '0.45 0.50 0.58')
            . $this->text(42, 26, 'Archivo generado por '.$this->platformBrandingService->get()['name'], 7.2, 'F2', '0.45 0.50 0.58');
    }

    private function panel(int $x, int $y, int $width, int $height, string $title): string
    {
        return $this->rect($x, $y, $width, $height, '1 1 1', '0.82 0.88 0.94')
            . $this->rect($x, $y + $height - 22, $width, 22, '0.95 0.98 1')
            . $this->text($x + 12, $y + $height - 15, $title, 8, 'F1', '0.10 0.17 0.26');
    }

    private function buildPdfDocument(array $pageContents): string
    {
        $objects = [
            "1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n",
            "2 0 obj\n<< /Type /Pages /Kids " . $this->pageKids($pageContents) . ' /Count ' . count($pageContents) . " >>\nendobj\n",
            "3 0 obj\n<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold >>\nendobj\n",
            "4 0 obj\n<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>\nendobj\n",
        ];

        $nextObject = 5;

        foreach ($pageContents as $content) {
            $pageObject = $nextObject++;
            $contentObject = $nextObject++;
            $objects[] = "{$pageObject} 0 obj\n<< /Type /Page /Parent 2 0 R /MediaBox [0 0 " . self::PAGE_WIDTH . ' ' . self::PAGE_HEIGHT . "] /Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents {$contentObject} 0 R >>\nendobj\n";
            $objects[] = "{$contentObject} 0 obj\n<< /Length " . strlen($content) . " >>\nstream\n{$content}endstream\nendobj\n";
        }

        $pdf = "%PDF-1.4\n";
        $offsets = [0];

        foreach ($objects as $object) {
            $offsets[] = strlen($pdf);
            $pdf .= $object;
        }

        $xrefOffset = strlen($pdf);
        $pdf .= "xref\n0 " . (count($objects) + 1) . "\n";
        $pdf .= "0000000000 65535 f \n";

        foreach (array_slice($offsets, 1) as $offset) {
            $pdf .= str_pad((string) $offset, 10, '0', STR_PAD_LEFT) . " 00000 n \n";
        }

        return $pdf . "trailer\n<< /Size " . (count($objects) + 1) . " /Root 1 0 R >>\nstartxref\n{$xrefOffset}\n%%EOF";
    }

    private function pageKids(array $pageContents): string
    {
        $kids = [];

        for ($i = 0; $i < count($pageContents); $i++) {
            $kids[] = (5 + ($i * 2)) . ' 0 R';
        }

        return '[' . implode(' ', $kids) . ']';
    }

    private function rect(int $x, int $y, int $width, int $height, string $fill, ?string $stroke = null): string
    {
        if ($stroke) {
            return "q\n{$fill} rg\n{$stroke} RG\n{$x} {$y} {$width} {$height} re B\nQ\n";
        }

        return "q\n{$fill} rg\n{$x} {$y} {$width} {$height} re f\nQ\n";
    }

    private function line(int $x1, int $y1, int $x2, int $y2, string $stroke): string
    {
        return "q\n{$stroke} RG\n{$x1} {$y1} m {$x2} {$y2} l S\nQ\n";
    }

    private function text(int $x, int $y, string $value, float $size, string $font, string $color): string
    {
        return "BT\n{$color} rg\n/{$font} {$size} Tf\n{$x} {$y} Td\n(" . $this->escape($value) . ") Tj\nET\n";
    }

    private function statusLabel(string $status): string
    {
        return match ($status) {
            'draft' => 'Borrador',
            'sent' => 'Enviada',
            'approved' => 'Aprobada',
            'invoiced' => 'Facturada',
            'rejected' => 'Rechazada',
            'cancelled' => 'Cancelada',
            default => ucfirst($status),
        };
    }

    private function money(float $value): string
    {
        return '$' . number_format($value, 2, '.', ',');
    }

    private function quantity(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ','), '0'), '.');
    }

    private function date(mixed $date): string
    {
        return $date instanceof \DateTimeInterface ? $date->format('Y-m-d') : 'N/A';
    }

    private function wrap(string $value, int $chars): array
    {
        $value = trim(preg_replace('/\s+/', ' ', $value) ?? '');

        return $value === '' ? [] : explode("\n", wordwrap($value, $chars, "\n", true));
    }

    private function shorten(string $value, int $limit): string
    {
        $value = trim(preg_replace('/\s+/', ' ', $value) ?? '');

        return strlen($value) > $limit ? substr($value, 0, $limit - 3) . '...' : $value;
    }

    private function escape(string $value): string
    {
        $value = iconv('UTF-8', 'ISO-8859-1//TRANSLIT', $value) ?: $value;

        return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $value);
    }
}
