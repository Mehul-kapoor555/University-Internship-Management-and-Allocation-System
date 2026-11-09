<?php
/**
 * PdfGenerator — Category-2 integration adapter around Dompdf.
 *
 * Callers hand it HTML and get back a written file path. If Dompdf is installed
 * (composer require dompdf/dompdf, autoloaded from vendor/) the output is a real
 * PDF. If Dompdf is not yet installed, the adapter degrades to writing the HTML
 * to a .html file with the same base name, so the surrounding workflow (and the
 * oral demo) never hard-crashes. The return value tells you which happened.
 *
 * Generated files live in  storage/documents/  at the project root.
 */
class PdfGenerator
{
    private static function storageDir(): string
    {
        $dir = __DIR__ . '/../storage/documents';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        return $dir;
    }

    private static function dompdfAvailable(): bool
    {
        $autoload = __DIR__ . '/../vendor/autoload.php';
        if (is_file($autoload)) {
            require_once $autoload;
        }
        return class_exists('Dompdf\\Dompdf');
    }

    /**
     * Render HTML to a file.
     *
     * @return array{ok:bool, path:string, url:string, format:string}
     *   format is 'pdf' when Dompdf produced a PDF, 'html' on fallback.
     */
    public static function fromHtml(string $html, string $baseName): array
    {
        $dir = self::storageDir();
        $safeBase = preg_replace('/[^A-Za-z0-9_\-]/', '_', $baseName);

        if (self::dompdfAvailable()) {
            $dompdf = new \Dompdf\Dompdf(['isRemoteEnabled' => false]);
            $dompdf->loadHtml($html);
            $dompdf->setPaper('A4', 'portrait');
            $dompdf->render();
            $path = $dir . '/' . $safeBase . '.pdf';
            file_put_contents($path, $dompdf->output());
            return ['ok' => true, 'path' => $path,
                    'url' => 'storage/documents/' . $safeBase . '.pdf',
                    'format' => 'pdf'];
        }

        // Fallback: write the HTML so the document still exists and is viewable.
        $path = $dir . '/' . $safeBase . '.html';
        file_put_contents($path, $html);
        return ['ok' => true, 'path' => $path,
                'url' => 'storage/documents/' . $safeBase . '.html',
                'format' => 'html'];
    }

    /**
     * A simple one-page notice/certificate (revision notice, rejection notice,
     * completion certificate). Returns the full HTML document string; callers
     * pass it to fromHtml().
     */
    public static function simpleNotice(string $title, string $bodyHtml): string
    {
        $t = htmlspecialchars($title, ENT_QUOTES);
        $today = date('d F Y');
        return '<!DOCTYPE html><html><head><meta charset="utf-8"><style>'
            . 'body{font-family:DejaVu Sans, Arial, sans-serif;font-size:13px;color:#111;margin:40px;}'
            . 'h1{font-size:22px;color:#1e3a5f;} .sub{color:#555;font-size:11px;margin-bottom:24px;}'
            . '.body{line-height:1.7;margin-top:16px;}'
            . '.foot{margin-top:60px;color:#666;font-size:11px;}'
            . '</style></head><body>'
            . '<h1>' . $t . '</h1>'
            . '<div class="sub">University Internship Management System · ' . $today . '</div>'
            . '<div class="body">' . $bodyHtml . '</div>'
            . '<div class="foot">This document was generated automatically by the internship lifecycle engine.</div>'
            . '</body></html>';
    }

    /**
     * Concrete document: the internship placement agreement for one accepted
     * allocation. Pulls the student, offer, poster, supervisor and cycle, then
     * renders a formal one-page agreement.
     *
     * @return array{ok:bool, path?:string, url?:string, format?:string, error?:string}
     */
    public static function placementAgreement(PDO $db, int $allocationId): array
    {
        $stmt = $db->prepare("
            SELECT a.id AS allocation_id, a.decision,
                   o.title AS offer_title, o.description AS offer_description,
                   o.domain, o.type, o.location, o.duration_months,
                   CONCAT(su.first_name,' ',su.last_name) AS student_name,
                   s.matricola, s.program,
                   CONCAT(pu.first_name,' ',pu.last_name) AS poster_name,
                   c.name AS cycle_name,
                   CONCAT(supu.first_name,' ',supu.last_name) AS supervisor_name,
                   prof.department AS supervisor_department
            FROM allocations a
            JOIN internship_offers o ON o.id = a.offer_id
            JOIN students s          ON s.id = a.student_id
            JOIN users su            ON su.id = s.user_id
            JOIN users pu            ON pu.id = o.posted_by_user_id
            JOIN matching_rounds r   ON r.id = a.round_id
            JOIN academic_cycles c   ON c.id = r.cycle_id
            LEFT JOIN supervisor_assignments sa ON sa.allocation_id = a.id
            LEFT JOIN professors prof ON prof.id = sa.professor_id
            LEFT JOIN users supu      ON supu.id = prof.user_id
            WHERE a.id = ?
            LIMIT 1
        ");
        $stmt->execute([$allocationId]);
        $d = $stmt->fetch();

        if (!$d) {
            return ['ok' => false, 'error' => 'Allocation not found.'];
        }
        if ($d['decision'] !== 'accepted') {
            return ['ok' => false, 'error' => 'Agreement is only available for accepted placements.'];
        }

        $e = fn($v) => htmlspecialchars((string)($v ?? '—'), ENT_QUOTES);
        $today = date('d F Y');

        $html = '<!DOCTYPE html><html><head><meta charset="utf-8"><style>'
            . 'body{font-family:DejaVu Sans, Arial, sans-serif;font-size:12px;color:#111;}'
            . 'h1{font-size:20px;color:#1e3a5f;margin-bottom:2px;}'
            . '.sub{color:#555;font-size:11px;margin-bottom:20px;}'
            . 'table{width:100%;border-collapse:collapse;margin:14px 0;}'
            . 'td{padding:6px 8px;border:1px solid #ccc;vertical-align:top;}'
            . 'td.k{background:#f3f4f6;width:34%;font-weight:bold;}'
            . '.sign{margin-top:40px;} .sign td{border:none;padding-top:36px;}'
            . '.line{border-top:1px solid #000;width:70%;}'
            . '</style></head><body>'
            . '<h1>Internship Placement Agreement</h1>'
            . '<div class="sub">University Internship Management System · ' . $e($d['cycle_name'])
            . ' · Issued ' . $e($today) . '</div>'
            . '<p>This agreement records the internship placement matched and confirmed '
            . 'through the University Internship Management System.</p>'
            . '<table>'
            . '<tr><td class="k">Student</td><td>' . $e($d['student_name'])
            . ' (matricola ' . $e($d['matricola']) . ')</td></tr>'
            . '<tr><td class="k">Programme</td><td>' . $e($d['program']) . '</td></tr>'
            . '<tr><td class="k">Placement</td><td>' . $e($d['offer_title']) . '</td></tr>'
            . '<tr><td class="k">Type</td><td>' . $e($d['type'] === 'research' ? 'Research' : 'Company') . '</td></tr>'
            . '<tr><td class="k">Domain</td><td>' . $e($d['domain']) . '</td></tr>'
            . '<tr><td class="k">Location</td><td>' . $e($d['location']) . '</td></tr>'
            . '<tr><td class="k">Duration</td><td>' . $e($d['duration_months']) . ' month(s)</td></tr>'
            . '<tr><td class="k">Offered by</td><td>' . $e($d['poster_name']) . '</td></tr>'
            . '<tr><td class="k">Academic supervisor</td><td>' . $e($d['supervisor_name'])
            . ($d['supervisor_department'] ? ' (' . $e($d['supervisor_department']) . ')' : '') . '</td></tr>'
            . '</table>'
            . '<p><strong>Placement description.</strong> ' . $e($d['offer_description']) . '</p>'
            . '<p>By signing below, the parties confirm the placement described above and agree '
            . 'to the standard internship terms of the University.</p>'
            . '<table class="sign"><tr>'
            . '<td><div class="line"></div>Student</td>'
            . '<td><div class="line"></div>Academic supervisor</td>'
            . '</tr><tr>'
            . '<td><div class="line"></div>Host / offer provider</td>'
            . '<td><div class="line"></div>Internship coordinator</td>'
            . '</tr></table>'
            . '</body></html>';

        $result = self::fromHtml($html, 'agreement_alloc_' . $allocationId);
        return $result;
    }
}
