<?php

namespace App\Services;

use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\IOFactory as WordIOFactory;
use PhpOffice\PhpWord\Shared\Converter;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;
use Mpdf\Mpdf;

/**
 * 对话成稿导出服务：把 AI 产出的口播稿（标题 + 多篇正文）转成常见办公/文档格式。
 *
 * 支持：docx / pdf / xlsx / md / txt
 *   - docx：微软雅黑正文 10.5pt、标题 13pt 加粗、段首空两格、两端对齐（沿用对外交付规范）
 *   - pdf ：mpdf 渲染，中文字体用内置 simhei.ttf（storage/app/export/fonts/）
 *   - xlsx：每篇一行（标题 + 正文），便于做排期/台账
 *   - md  / txt：纯文本成稿，适合复制进公众号/飞书
 *
 * 由 StudioController::chatExport 调用，返回 Symfony Response 流式下载，不落盘。
 */
class ChatExportService
{
    /** 合法的导出格式白名单 */
    public const FORMATS = ['docx', 'pdf', 'xlsx', 'md', 'txt'];

    /**
     * @param array $pieces 每篇成稿：['title' => string, 'script' => string]
     * @param string $format docx|pdf|xlsx|md|txt
     * @return \Symfony\Component\HttpFoundation\BinaryFileResponse|string 文件流(除md/txt返回字符串由上层处理)
     */
    public function export(array $pieces, string $format, string $fallbackTitle = '对话成稿')
    {
        // 清洗：确保每篇有 title + 正文
        $rows = [];
        foreach ($pieces as $p) {
            $title = trim((string) ($p['title'] ?? ''));
            $body  = trim((string) ($p['script'] ?? $p['text'] ?? ''));
            if ($body === '') {
                continue;
            }
            $rows[] = ['title' => $title !== '' ? $title : '口播稿', 'body' => $body];
        }
        if (count($rows) === 0) {
            // 退化：直接一段文本
            $rows[] = ['title' => $fallbackTitle, 'body' => trim((string) ($pieces[0]['script'] ?? $pieces[0] ?? '无内容'))];
        }

        return match ($format) {
            'docx' => $this->buildDocx($rows, $fallbackTitle),
            'pdf'  => $this->buildPdf($rows, $fallbackTitle),
            'xlsx' => $this->buildXlsx($rows, $fallbackTitle),
            'md'   => $this->buildMarkdown($rows),
            'txt'  => $this->buildPlainText($rows),
            default => throw new \InvalidArgumentException('不支持的导出格式: ' . $format),
        };
    }

    /** 段首空两格 + 按中文习惯把每段拆成干净行 */
    private function paragraphs(string $text): array
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        // 分段落：空行分隔
        $blocks = preg_split('/\n\s*\n/', $text) ?: [];
        $out = [];
        foreach ($blocks as $b) {
            $b = trim(preg_replace('/\n+/', "\n", $b) ?? '');
            if ($b !== '') {
                // 段内超过一句的按行保留，方便 Word 段落延续
                $out[] = $b;
            }
        }
        return $out;
    }

    /** ==================== Word (.docx) ==================== */
    private function buildDocx(array $rows, string $fallbackTitle): \Symfony\Component\HttpFoundation\BinaryFileResponse
    {
        $phpWord = new PhpWord();
        // 默认样式：微软雅黑（西文同字体回退）
        $phpWord->setDefaultFontName('微软雅黑');
        $phpWord->setDefaultFontSize(10.5);
        $phpWord->getSettings()->setThemeFontLang(new \PhpOffice\PhpWord\Style\Language('zh-CN'));

        $sec = $phpWord->addSection([
            'marginLeft'  => Converter::cmToTwip(2.54),
            'marginRight' => Converter::cmToTwip(2.54),
            'marginTop'   => Converter::cmToTwip(2.54),
            'marginBottom'=> Converter::cmToTwip(2.54),
        ]);

        $docTitle = $fallbackTitle !== '' && $fallbackTitle !== '对话成稿' ? $fallbackTitle : '口播稿';
        // 文档大标题 13pt 加粗居中
        $sec->addText($docTitle, ['size' => 13, 'bold' => true, 'name' => '微软雅黑'], ['alignment' => \PhpOffice\PhpWord\SimpleType\Jc::CENTER, 'spaceAfter' => 160]);

        $first = true;
        foreach ($rows as $r) {
            if (! $first) {
                // 篇间分隔
                $sec->addTextBreak(1);
            }
            $first = false;

            // 单篇小标题 13pt 加粗
            $sec->addText($r['title'], ['size' => 13, 'bold' => true, 'name' => '微软雅黑'], ['spaceBefore' => 120, 'spaceAfter' => 80]);

            // 正文：段首空两格（首行缩进 2 字符 = 10.5pt*2 ≈ 21pt ≈ 0.74cm），两端对齐
            foreach ($this->paragraphs($r['body']) as $para) {
                $sec->addText($para, [
                    'size' => 10.5, 'name' => '微软雅黑', 'color' => '333333',
                ], [
                    'alignment' => \PhpOffice\PhpWord\SimpleType\Jc::BOTH,
                    'indentation' => ['firstLine' => Converter::pointToTwip(10.5 * 2)],
                    'spaceAfter' => 60,
                    'lineHeight' => 1.5,
                ]);
            }
        }

        $objWriter = WordIOFactory::createWriter($phpWord, 'Word2007');
        $tmp = tempnam(sys_get_temp_dir(), 'hgt_export_') . '.docx';
        $objWriter->save($tmp);

        return $this->downloadResponse($tmp, $docTitle . '.docx', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document');
    }

    /** ==================== PDF ==================== */
    private function buildPdf(array $rows, string $fallbackTitle): \Symfony\Component\HttpFoundation\BinaryFileResponse
    {
        // 中文字体：项目内置 simhei.ttf（public/fonts，随代码进仓，部署不丢）
        $fontDir = public_path('fonts');
        $fontFile = $fontDir . '/simhei.ttf';

        $fontdata = [];
        if (is_file($fontFile)) {
            $fontdata = [
                'simhei' => [
                    'R'          => 'simhei.ttf',
                    'useOTL'     => 0xFF,
                    'useKashida' => 75,
                ],
            ];
        }

        $mpdf = new Mpdf([
            'mode'          => 'utf-8',
            'format'        => 'A4',
            'margin_left'   => 20,
            'margin_right'  => 20,
            'margin_top'    => 20,
            'margin_bottom' => 20,
            'tempDir'       => storage_path('app/export/tmp'),
            'fontDir'       => is_dir($fontDir) ? [$fontDir] : [],
            'fontdata'      => $fontdata,
            'default_font'  => 'simhei',
        ]);
        $mpdf->WriteHTML($this->pdfHtml($rows, $fallbackTitle));

        $tmp = tempnam(sys_get_temp_dir(), 'hgt_export_') . '.pdf';
        $mpdf->Output($tmp, \Mpdf\Output\Destination::FILE);

        return $this->downloadResponse($tmp, ($fallbackTitle !== '' && $fallbackTitle !== '对话成稿' ? $fallbackTitle : '口播稿') . '.pdf', 'application/pdf');
    }

    private function pdfHtml(array $rows, string $fallbackTitle): string
    {
        $title = ($fallbackTitle !== '' && $fallbackTitle !== '对话成稿') ? $fallbackTitle : '口播稿';
        $h = '<html><head><style>'
            . 'body{font-family:simhei,sans-serif;font-size:10.5pt;color:#333;line-height:1.7;}'
            . 'h1{font-size:14pt;text-align:center;margin-bottom:18pt;}'
            . 'h2{font-size:12pt;margin-top:14pt;margin-bottom:6pt;border-bottom:0.5pt solid #ccc;padding-bottom:3pt;}'
            . 'p{text-indent:2em;margin:0 0 6pt 0;text-align:justify;}'
            . '</style></head><body>'
            . '<h1>' . $this->esc($title) . '</h1>';
        foreach ($rows as $r) {
            $h .= '<h2>' . $this->esc($r['title']) . '</h2>';
            foreach ($this->paragraphs($r['body']) as $para) {
                $h .= '<p>' . $this->esc($para) . '</p>';
            }
        }
        $h .= '</body></html>';
        return $h;
    }

    /** ==================== Excel (.xlsx) ==================== */
    private function buildXlsx(array $rows, string $fallbackTitle): \Symfony\Component\HttpFoundation\BinaryFileResponse
    {
        $ss = new Spreadsheet();
        $sheet = $ss->getActiveSheet();
        $sheet->setTitle('成稿台账');

        // 表头
        $sheet->setCellValue('A1', '序号');
        $sheet->setCellValue('B1', '标题');
        $sheet->setCellValue('C1', '正文');
        $sheet->getStyle('A1:C1')->getFont()->setBold(true);
        $sheet->getColumnDimension('A')->setWidth(6);
        $sheet->getColumnDimension('B')->setWidth(28);
        $sheet->getColumnDimension('C')->setWidth(80);

        $row = 2;
        foreach ($rows as $i => $r) {
            $sheet->setCellValue('A' . $row, $i + 1);
            $sheet->setCellValue('B' . $row, $r['title']);
            $sheet->setCellValue('C' . $row, $r['body']);
            $sheet->getStyle('C' . $row)->getAlignment()->setWrapText(true);
            $row++;
        }
        $ss->getActiveSheet()->getStyle('A1:C' . ($row - 1))->getAlignment()->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_TOP);

        $tmp = tempnam(sys_get_temp_dir(), 'hgt_export_') . '.xlsx';
        $writer = new XlsxWriter($ss);
        $writer->save($tmp);

        return $this->downloadResponse($tmp, ($fallbackTitle !== '' && $fallbackTitle !== '对话成稿' ? $fallbackTitle : '口播稿') . '.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    }

    /** ==================== Markdown ==================== */
    private function buildMarkdown(array $rows): string
    {
        $h = [];
        foreach ($rows as $i => $r) {
            $h[] = '## ' . ($r['title'] ?? ('口播稿' . ($i + 1)));
            $h[] = '';
            $h[] = $r['body'];
            $h[] = '';
        }
        return trim(implode("\n", $h)) . "\n";
    }

    /** ==================== 纯文本 ==================== */
    private function buildPlainText(array $rows): string
    {
        $h = [];
        foreach ($rows as $i => $r) {
            $h[] = ($r['title'] ?? ('口播稿' . ($i + 1)));
            $h[] = str_repeat('=', mb_strlen($r['title'] ?? ''));
            $h[] = '';
            $h[] = $r['body'];
            $h[] = '';
        }
        return trim(implode("\n", $h)) . "\n";
    }

    private function esc(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private function downloadResponse(string $tmpPath, string $filename, string $mime): \Symfony\Component\HttpFoundation\BinaryFileResponse
    {
        return response()->download($tmpPath, $filename, ['Content-Type' => $mime])->deleteFileAfterSend(true);
    }
}
