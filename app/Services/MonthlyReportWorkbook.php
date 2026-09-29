<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use RuntimeException;
use ZipArchive;

class MonthlyReportWorkbook
{
    private const NS = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';

    public function write(array $report, string $path): void
    {
        $sheets = $this->sheets($report);
        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Не удалось создать XLSX.');
        }
        try {
            $types = '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>';
            $book = '<workbook xmlns="'.self::NS.'" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><bookViews><workbookView/></bookViews><sheets>';
            $rels = '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';
            foreach ($sheets as $index => $sheet) {
                $id = $index + 1;
                $types .= '<Override PartName="/xl/worksheets/sheet'.$id.'.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
                $book .= '<sheet name="'.$this->xml($sheet['name']).'" sheetId="'.$id.'" r:id="rId'.$id.'"/>';
                $rels .= '<Relationship Id="rId'.$id.'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet'.$id.'.xml"/>';
                $this->add($zip, 'xl/worksheets/sheet'.$id.'.xml', $this->sheet($report, $sheet));
            }
            $this->add($zip, '[Content_Types].xml', $types.'</Types>');
            $this->add($zip, 'xl/workbook.xml', $book.'</sheets></workbook>');
            $this->add($zip, 'xl/_rels/workbook.xml.rels', $rels.'<Relationship Id="styles" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>');
            $this->add($zip, '_rels/.rels', '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
            $this->add($zip, 'xl/styles.xml', $this->styles());
        } finally {
            if (! $zip->close()) {
                throw new RuntimeException('Не удалось завершить XLSX.');
            }
        }
    }

    private function sheets(array $report): array
    {
        $summary = array_map(fn (array $row): array => [$row['name'], $row['completed_tasks'], $row['qualified_kpis'], $row['bonus_points'], $row['bonus_percent'] / 100], $report['summary']);
        $kpis = [];
        foreach ($report['kpis'] as $kpi) {
            foreach ($kpi['tasks'] as $index => $task) {
                $kpis[] = [$kpi['user_name'], $kpi['name'], $kpi['kpi_id'], $kpi['minimum_completed_tasks'], $kpi['completed_tasks'],
                    $index === 0 ? $kpi['points'] : null, $task['task_key'], $task['title'], $task['project_name'], $task['completed_at']];
            }
        }
        $done = array_map(fn (array $task): array => [$task['task_key'], $task['title'], $task['project_name'], $task['assignee_name'], $task['customer_name'], $task['completed_at'], ($task['planned'] ?? false) ? 'Да' : 'Нет', $task['result']], $report['completed']);
        $plan = array_map(fn (array $row): array => [
            $row['month'], $row['title'], $row['project']['key'] ?? 'Без проекта', $row['assignee']['name'] ?? 'Не назначен',
            $row['description'], $row['resources'], $row['expected_result'], $row['impact'],
            $row['estimated_minutes'] === null ? null : $row['estimated_minutes'] / 60,
            implode(' — ', array_filter([$row['starts_on'], $row['ends_on']])),
            $row['completed_at'] ? 'Выполнено' : 'Не выполнено', $row['completed_tasks_count'].' / '.$row['tasks_count'],
            $row['actual_result'], $row['completed_at'],
        ], $report['plan_items'] ?? []);
        foreach ($report['plan'] as $row) {
            $plan[] = [$row['month'], $row['task']['title'], $row['task']['project_name'], $row['assignee_name'],
                null, null, $row['expected_result'], null, null, null, $this->status($row['task']['status']),
                ($row['task']['status'] === 'done' ? '1' : '0').' / 1', null, $row['task']['completed_at']];
        }

        return [
            ['name' => 'Сводка', 'headers' => ['Исполнитель', 'Выполнено задач', 'Зачтено KPI', 'Премиальные баллы', 'Премия'], 'widths' => [30, 19, 17, 23, 17], 'rows' => $summary, 'percent' => [4], 'dates' => []],
            ['name' => 'KPI', 'headers' => ['Исполнитель', 'KPI', 'KPI ID', 'Порог задач', 'Выполнено', 'Начислено баллов', 'Код задачи', 'Задача', 'Проект', 'Завершено'], 'widths' => [27, 35, 29, 16, 16, 22, 18, 55, 30, 23], 'rows' => $kpis, 'percent' => [], 'dates' => [9]],
            ['name' => 'Выполнено', 'headers' => ['Код задачи', 'Задача', 'Проект', 'Исполнитель', 'Заказчик', 'Завершено', 'Плановая', 'Результат'], 'widths' => [18, 55, 30, 27, 27, 23, 14, 75], 'rows' => $done, 'percent' => [], 'dates' => [5]],
            ['name' => 'План', 'headers' => ['Месяц', 'Плановая единица', 'Проект', 'Исполнитель', 'Описание', 'Ресурсы', 'Ожидаемый результат', 'Эффект', 'Оценка, ч', 'Диапазон дат', 'Выполнение', 'Задачи: готово / всего', 'Фактический результат', 'Завершено'], 'widths' => [13, 45, 25, 25, 50, 40, 50, 45, 15, 28, 20, 25, 50, 23], 'rows' => $plan, 'percent' => [], 'dates' => [13]],
        ];
    }

    private function sheet(array $report, array $sheet): string
    {
        $lastCol = $this->column(count($sheet['headers']) - 1);
        $title = $sheet['name'].' · '.($sheet['name'] === 'План' ? ($report['plan_year'] ?? $report['plan_month']) : $report['month']);
        $info = $report['scope']['name'].' · '.($report['person_name'] ?? 'Все исполнители').' · '.$report['timezone'].' · Снимок '.$report['generated_at'];
        $xml = '<worksheet xmlns="'.self::NS.'"><sheetViews><sheetView workbookViewId="0"><pane ySplit="4" topLeftCell="A5" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews><sheetFormatPr defaultRowHeight="20"/><cols>';
        foreach ($sheet['widths'] as $i => $width) {
            $xml .= '<col min="'.($i + 1).'" max="'.($i + 1).'" width="'.$width.'" customWidth="1"/>';
        }
        $xml .= '</cols><sheetData><row r="1" ht="28" customHeight="1">'.$this->cell('A1', $title, 3).'</row><row r="2" ht="30" customHeight="1">'.$this->cell('A2', $info, 0).'</row><row r="4" ht="32" customHeight="1">';
        foreach ($sheet['headers'] as $i => $header) {
            $xml .= $this->cell($this->column($i).'4', $header, 1);
        }
        $xml .= '</row>';
        $rowNumber = 5;
        foreach ($sheet['rows'] as $row) {
            // Long Markdown results continue on extra rows rather than being silently truncated by Excel.
            $chunks = [];
            foreach ($row as $i => $value) {
                $chunks[] = is_string($value) ? $this->textParts($value, max(8, $sheet['widths'][$i] - 3)) : [$value];
            }
            $parts = max(array_map('count', $chunks));
            for ($part = 0; $part < $parts; $part++) {
                $cells = '';
                $lines = 1;
                foreach ($chunks as $i => $values) {
                    $value = $values[$part] ?? null;
                    $style = 0;
                    if ($value !== null && in_array($i, $sheet['dates'], true)) {
                        $date = CarbonImmutable::parse($value)->setTimezone($report['timezone']);
                        $value = ($date->getTimestamp() + $date->getOffset()) / 86400 + 25569;
                        $style = 2;
                    } elseif (in_array($i, $sheet['percent'], true)) {
                        $style = 4;
                    }
                    if (is_string($value)) {
                        $lineCount = 0;
                        foreach (explode("\n", $value) as $line) {
                            $lineCount += max(1, (int) ceil(mb_strlen($line) / max(8, $sheet['widths'][$i] - 3)));
                        }
                        $lines = max($lines, $lineCount);
                    }
                    $cells .= $this->cell($this->column($i).$rowNumber, $value, $style);
                }
                $xml .= '<row r="'.$rowNumber.'" ht="'.min(409, max(24, $lines * 15 + 6)).'" customHeight="1">'.$cells.'</row>';
                $rowNumber++;
            }
        }
        $lastRow = max(4, $rowNumber - 1);

        return $xml.'</sheetData><autoFilter ref="A4:'.$lastCol.$lastRow.'"/><mergeCells count="2"><mergeCell ref="A1:'.$lastCol.'1"/><mergeCell ref="A2:'.$lastCol.'2"/></mergeCells><pageMargins left="0.25" right="0.25" top="0.5" bottom="0.5" header="0.2" footer="0.2"/><pageSetup orientation="landscape" paperSize="9" fitToWidth="1" fitToHeight="0"/></worksheet>';
    }

    private function cell(string $ref, mixed $value, int $style): string
    {
        if ($value === null) {
            return '<c r="'.$ref.'" s="'.$style.'"/>';
        }
        if (is_int($value) || is_float($value)) {
            return '<c r="'.$ref.'" s="'.$style.'"><v>'.$value.'</v></c>';
        }

        return '<c r="'.$ref.'" s="'.$style.'" t="inlineStr"><is><t xml:space="preserve">'.$this->xml((string) $value).'</t></is></c>';
    }

    private function textParts(string $value, int $width): array
    {
        $parts = [];
        $part = '';
        $line = 1;
        $column = 0;
        foreach (mb_str_split($value) as $character) {
            if ($line > 20) {
                $parts[] = $part;
                $part = '';
                $line = 1;
                $column = 0;
            }
            $part .= $character;
            if ($character === "\n" || ++$column >= $width) {
                $line++;
                $column = 0;
            }
        }
        $parts[] = $part;

        return $parts;
    }

    private function xml(string $value): string
    {
        return htmlspecialchars(preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', $value) ?? '', ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    private function column(int $index): string
    {
        $name = '';
        do {
            $name = chr(65 + $index % 26).$name;
            $index = intdiv($index, 26) - 1;
        } while ($index >= 0);

        return $name;
    }

    private function add(ZipArchive $zip, string $name, string $xml): void
    {
        if (! $zip->addFromString($name, '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'.$xml)) {
            throw new RuntimeException('Ошибка записи XLSX.');
        }
    }

    private function status(string $status): string
    {
        return ['scheduled' => 'Запланировано', 'todo' => 'К выполнению', 'in_progress' => 'В работе', 'blocked' => 'Заблокировано', 'review' => 'На проверке', 'done' => 'Готово', 'cancelled' => 'Удалено'][$status] ?? $status;
    }

    private function styles(): string
    {
        return '<styleSheet xmlns="'.self::NS.'"><numFmts count="1"><numFmt numFmtId="164" formatCode="dd.mm.yyyy hh:mm"/></numFmts><fonts count="3"><font><sz val="11"/><name val="Calibri"/><color rgb="FF243247"/></font><font><b/><sz val="11"/><name val="Calibri"/><color rgb="FFFFFFFF"/></font><font><b/><sz val="16"/><name val="Calibri"/><color rgb="FF243247"/></font></fonts><fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FF245A81"/><bgColor indexed="64"/></patternFill></fill></fills><borders count="1"><border/></borders><cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs><cellXfs count="5"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0" applyAlignment="1"><alignment vertical="top" wrapText="1"/></xf><xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1" applyAlignment="1"><alignment vertical="center" wrapText="1"/></xf><xf numFmtId="164" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1" applyAlignment="1"><alignment vertical="top"/></xf><xf numFmtId="0" fontId="2" fillId="0" borderId="0" xfId="0" applyFont="1"/><xf numFmtId="9" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1" applyAlignment="1"><alignment vertical="top"/></xf></cellXfs><cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>';
    }
}
