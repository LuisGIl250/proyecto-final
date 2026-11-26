<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/signature.php';
require_once __DIR__ . '/includes/uploads.php';
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/lib/fpdf/fpdf.php';

// Pueden descargar el PDF el administrador (de cualquier región) y el usuario (solo el de sus solicitudes).
$isAdmin = isAdminLoggedIn();
if (!$isAdmin && !isUserLoggedIn()) {
    header('Location: /diagnosticos-de-equipo/index.php?error=session');
    exit;
}

$id = (int)($_GET['id'] ?? 0);
if ($isAdmin) {
    $region = trim((string)($_GET['region'] ?? ''));
    $uid    = null;
} else {
    $u      = getCurrentUser();
    $region = $u['region'];            // la región del usuario sale de la sesión, no de la URL
    $uid    = (int)$u['id'];
}

if (!$id || !$region) {
    http_response_code(400);
    exit('Parámetros inválidos.');
}

$regions = getRegions();
if (!isset($regions[$region])) {
    http_response_code(400);
    exit('Región inválida.');
}

$conn = getConnection($region);
if ($isAdmin) {
    $stmt = $conn->prepare("SELECT * FROM solicitudes WHERE id = ? LIMIT 1");
    $stmt->bind_param('i', $id);
} else {
    $stmt = $conn->prepare("SELECT * FROM solicitudes WHERE id = ? AND usuario_id = ? LIMIT 1");
    $stmt->bind_param('ii', $id, $uid);
}
$stmt->execute();
$sol = $stmt->get_result()->fetch_assoc();
$stmt->close();
$conn->close();

if (!$sol) {
    http_response_code(404);
    exit('Solicitud no encontrada.');
}

// El PDF lleva el diagnóstico del ADMINISTRADOR, que solo existe al completar la solicitud.
if ($sol['estado'] !== 'completada') {
    http_response_code(403);
    exit('El PDF solo está disponible para solicitudes completadas.');
}
if (trim((string)($sol['diagnostico_admin'] ?? '')) === '') {
    http_response_code(409);
    exit($isAdmin
        ? 'Esta solicitud no tiene diagnóstico del administrador. Agrégalo desde el panel y vuelve a exportar.'
        : 'Esta solicitud todavía no tiene el diagnóstico del especialista.');
}

// ─────────────────────────────────────────────────────
// Subclase de FPDF que replica el formato oficial de diagnóstico.
// Dos copias idénticas por hoja (mitad superior e inferior).
// ─────────────────────────────────────────────────────
class DiagnosticoPDF extends FPDF
{
    private const DIAG_ROWS  = 6;   // renglones del diagnóstico
    private const DIAG_PITCH = 5;   // separación entre renglones (mm)

    /**
     * Dibuja una copia del formato empezando en $startY (mm desde el borde superior).
     */
    public function drawForm(array $s, float $startY): void
    {
        $lm = 15;        // margen izquierdo (mm)
        $pw = 180;       // ancho del formato
        $ix = $lm + 2;   // x interior
        $iw = $pw - 4;   // ancho interior (176 mm)

        // ── Borde exterior ───────────────────────────
        $this->SetDrawColor(0, 0, 0);
        $this->SetLineWidth(0.5);
        $this->Rect($lm, $startY, $pw, 125);

        // ── Encabezado ───────────────────────────────
        $this->SetFont('Arial', 'B', 11);
        $this->SetTextColor(0, 0, 0);
        $this->SetXY($lm, $startY + 4);
        $this->Cell($pw, 6, $this->u8('Coordinación General de Tecnologías de Información'), 0, 1, 'C');
        $this->SetX($lm);
        $this->Cell($pw, 6, $this->u8('Dirección General de Servicios Telemáticos'), 0, 1, 'C');
        $this->SetFont('Arial', 'BI', 10);
        $this->SetX($lm);
        $this->Cell($pw, 6, $this->u8('Diagnóstico de equipo tecnológico'), 0, 1, 'C');

        $this->SetLineWidth(0.3);
        $this->Line($lm, $startY + 22, $lm + $pw, $startY + 22);
        $this->SetLineWidth(0.2);

        // ── Campos: cada etiqueta se mide, así el valor nunca se encima ──
        $this->identificationRows($s, $ix, $startY + 25, $iw);

        // ── Diagnóstico (del administrador) ──────────
        $yd = $startY + 47;
        $this->SetXY($ix, $yd);
        $this->SetFont('Arial', 'B', 8.5);
        $this->Cell(0, 5, $this->u8('Diagnóstico:'), 0, 1);

        $y0   = $startY + 53;                   // arriba del primer renglón
        $rows = self::DIAG_ROWS;
        $p    = self::DIAG_PITCH;
        $text = $this->u8((string)($s['diagnostico_admin'] ?? ''));

        // Si el texto no cabe en 6 renglones se reduce la letra; como último recurso se corta con "…"
        $lines = [];
        foreach ([8, 7.5, 7, 6.5] as $size) {
            $this->SetFont('Arial', '', $size);
            $lines = $this->wrapText($text, $iw - 2, PHP_INT_MAX);
            if (count($lines) <= $rows) break;
        }
        if (count($lines) > $rows) {
            $lines = $this->wrapText($text, $iw - 2, $rows);
        }

        $this->SetLineWidth(0.2);
        for ($i = 0; $i < $rows; $i++) {
            $ly = $y0 + ($i + 1) * $p;
            $this->Line($ix, $ly, $ix + $iw, $ly);
            if (isset($lines[$i])) {
                $this->SetXY($ix, $y0 + $i * $p);
                $this->Cell($iw, $p, $lines[$i], 0, 0, 'L');
            }
        }

        // ── Firmas ───────────────────────────────────
        // Cada firma lleva, arriba de la línea, el código de firma y su fecha; debajo, el nombre de la
        // cuenta de quien firmó y la etiqueta del campo. Si todavía no hay firma, el campo queda en blanco.
        $ys  = $startY + 90;     // línea del especialista
        $ys2 = $ys + 21;         // línea de quien recibe
        $sw  = 60;               // ancho de las líneas de firma
        $this->SetDrawColor(0, 0, 0);
        $this->SetLineWidth(0.3);

        // Especialista que revisó: firma automática al completar la solicitud
        $this->Line($lm + 1, $ys, $lm + 1 + $sw, $ys);
        $this->signatureStamp(
            $lm + 1, $ys, $sw,
            (string)($s['completada_por'] ?? ''),
            formatSignature($s['firma_admin'] ?? null),
            formatSignatureDate($s['firma_admin_at'] ?? null),
            ['Nombre y firma del Especialista que revisó']
        );

        // Persona que recibe el diagnóstico y el equipo: firma del usuario (botón "Firmar")
        $this->Line($lm + 1, $ys2, $lm + 1 + $sw, $ys2);
        $this->signatureStamp(
            $lm + 1, $ys2, $sw,
            (string)($s['firma_usuario_nombre'] ?? ''),
            formatSignature($s['firma_usuario'] ?? null),
            formatSignatureDate($s['firma_usuario_at'] ?? null),
            ['Nombre y firma de la persona, que recibe', 'el diagnóstico y el equipo.']
        );

        // Recuadro de sello (borde punteado)
        $this->setDashedLine(2, 1);
        $this->SetDrawColor(100, 100, 100);
        $this->Rect($lm + 65, $ys - 4, 50, 38);
        $this->setDashedLine(0, 0);
        $this->SetDrawColor(0, 0, 0);
        $this->SetXY($lm + 65, $ys + 15);
        $this->SetFont('Arial', '', 7.5);
        $this->Cell(50, 5, $this->u8('Sello de la Dirección'), 0, 0, 'C');

        // Director
        $this->SetFont('Arial', 'U', 7.5);
        $this->SetXY($lm + 120, $ys - 2);
        $this->Cell(0, 5, $this->u8('Ing. Juan Francisco Vaca Gutiérrez'), 0, 1);
        $this->SetFont('Arial', '', 7.5);
        $this->SetX($lm + 120);
        $this->Cell(0, 5, 'Vo. bo. del Director General', 0, 1);
    }

    /**
     * Estampa una firma sobre una línea de firma ya dibujada en ($x, $lineY) de ancho $w.
     *  · encima de la línea: código de firma (izquierda) y fecha/hora (derecha), en tinta azul
     *  · debajo: nombre de la cuenta en negritas y las líneas de la etiqueta del campo
     * Sin nombre ni código solo se escribe la etiqueta.
     */
    public function signatureStamp(float $x, float $lineY, float $w, string $name, string $code, string $when, array $labelLines): void
    {
        if ($code !== '') {
            $this->SetFont('Courier', 'B', 7.5);
            $this->SetTextColor(20, 40, 130);
            $cw = $this->GetStringWidth($code) + 2;
            $this->SetXY($x, $lineY - 5.5);
            $this->Cell($cw, 4, $code, 0, 0, 'L');
            if ($when !== '') {
                $this->SetFont('Arial', '', 6.5);
                $this->SetTextColor(90, 90, 90);
                $this->Cell($w - $cw, 4, $when, 0, 0, 'R');
            }
        }

        $y = $lineY + 1;
        if ($name !== '') {
            $txt = $this->u8($name);
            foreach ([8, 7, 6.5, 6] as $size) {
                $this->SetFont('Arial', 'B', $size);
                if ($this->GetStringWidth($txt) <= $w - 2) break;
            }
            $txt = $this->fitWidth($txt, $w - 2);
            $this->SetTextColor(0, 0, 0);
            $this->SetXY($x, $y);
            $this->Cell($w, 4, $txt, 0, 0, 'L');
            $y += 4;
        }

        $this->SetFont('Arial', '', 7);
        $this->SetTextColor(0, 0, 0);
        foreach ($labelLines as $line) {
            $this->SetXY($x, $y);
            $this->Cell($w, 3.5, $this->u8($line), 0, 0, 'L');
            $y += 3.5;
        }
    }

    /** Las tres filas de datos del solicitante y del equipo (de $y a $y + 19 mm). */
    private function identificationRows(array $s, float $ix, float $y, float $iw): void
    {
        $lugarFecha = trim(
            trim((string)($s['lugar'] ?? '')) . '  ' .
            (!empty($s['fecha']) ? date('d/m/Y', strtotime($s['fecha'])) : '')
        );

        $this->fieldRow($ix, $y, $iw, [
            ['Nombre del solicitante:', (string)($s['nombre_solicitante'] ?? ''), 1.0],
            ['Centro de trabajo:',      (string)($s['centro_trabajo']     ?? ''), 1.0],
        ]);
        $this->fieldRow($ix, $y + 7, $iw, [
            ['Tipo de equipo:', (string)($s['tipo_equipo'] ?? ''), 1.2],
            ['Marca:',          (string)($s['marca']       ?? ''), 0.9],
            ['Modelo:',         (string)($s['modelo']      ?? ''), 0.9],
        ]);
        $this->fieldRow($ix, $y + 14, $iw, [
            ['Número de serie:',      (string)($s['numero_serie']      ?? ''), 1.0],
            ['Número de inventario:', (string)($s['numero_inventario'] ?? ''), 1.1],
            ['Lugar y fecha:',        $lugarFecha,                              1.0],
        ]);
    }

    /**
     * Segunda hoja: anexo con la fotografía del equipo que subió el usuario.
     * Repite los datos de identificación y centra la imagen conservando su proporción.
     */
    public function drawImagePage(array $s, string $imagePath): void
    {
        $lm = 15;
        $pw = 180;

        $this->AddPage();
        $this->SetDrawColor(0, 0, 0);
        $this->SetLineWidth(0.5);
        $this->Rect($lm, 10, $pw, 277);

        $this->SetFont('Arial', 'B', 11);
        $this->SetTextColor(0, 0, 0);
        $this->SetXY($lm, 14);
        $this->Cell($pw, 6, $this->u8('Coordinación General de Tecnologías de Información'), 0, 1, 'C');
        $this->SetX($lm);
        $this->Cell($pw, 6, $this->u8('Dirección General de Servicios Telemáticos'), 0, 1, 'C');
        $this->SetFont('Arial', 'BI', 10);
        $this->SetX($lm);
        $this->Cell($pw, 6, $this->u8('Anexo: fotografía del equipo'), 0, 1, 'C');

        $this->SetLineWidth(0.3);
        $this->Line($lm, 32, $lm + $pw, 32);
        $this->SetLineWidth(0.2);

        $this->identificationRows($s, $lm + 2, 36, $pw - 4);

        // Imagen: cabe en 176 × 200 mm sin deformarse
        $size = @getimagesize($imagePath);
        if ($size === false) return;
        $ratio = min(176 / $size[0], 200 / $size[1]);
        $w = $size[0] * $ratio;
        $h = $size[1] * $ratio;
        $x = $lm + ($pw - $w) / 2;
        $y = 62;

        $this->Image($imagePath, $x, $y, $w, $h, 'JPG');
        $this->SetDrawColor(120, 120, 120);
        $this->SetLineWidth(0.2);
        $this->Rect($x, $y, $w, $h);

        $this->SetFont('Arial', 'I', 7.5);
        $this->SetTextColor(90, 90, 90);
        $this->SetXY($lm, $y + $h + 3);
        $this->Cell($pw, 4, $this->u8('Fotografía del equipo proporcionada por el solicitante'), 0, 0, 'C');
        $this->SetTextColor(0, 0, 0);
    }

    /** Línea punteada que separa las dos copias. */
    public function drawSeparator(float $y): void
    {
        $this->setDashedLine(3, 2);
        $this->SetDrawColor(140, 140, 140);
        $this->SetLineWidth(0.3);
        $this->Line(10, $y, 200, $y);
        $this->setDashedLine(0, 0);
        $this->SetDrawColor(0, 0, 0);
        $this->SetLineWidth(0.5);
    }

    // ── Helpers privados ──────────────────────────────

    /** UTF-8 → Windows-1252, la codificación que FPDF usa con las fuentes estándar. */
    private function u8(string $text): string
    {
        return mb_convert_encoding($text, 'Windows-1252', 'UTF-8');
    }

    /**
     * Dibuja una fila de campos "Etiqueta: valor____" repartiendo el ancho
     * según el peso de cada uno. $fields = [[etiqueta, valor, peso], ...]
     */
    private function fieldRow(float $x, float $y, float $width, array $fields): void
    {
        $gap    = 4;
        $total  = $width - $gap * (count($fields) - 1);
        $weight = array_sum(array_column($fields, 2));
        foreach ($fields as [$label, $value, $wt]) {
            $w = $total * $wt / $weight;
            $this->field($x, $y, $w, $label, $value);
            $x += $w + $gap;
        }
    }

    /**
     * Un campo: la etiqueta ocupa exactamente lo que mide y el valor (en negritas,
     * con línea inferior) usa el resto. Si el valor no cabe, se achica la letra
     * y, como último recurso, se corta con "…".
     */
    private function field(float $x, float $y, float $w, string $label, string $value): void
    {
        $label = $this->u8($label);
        $this->SetFont('Arial', '', 8);
        $lw = $this->GetStringWidth($label) + 1.5;    // 1 mm de margen izquierdo del Cell + holgura
        $vw = $w - $lw;

        $this->SetXY($x, $y);
        $this->Cell($lw, 5, $label, 0, 0);

        $txt   = $this->u8($value);
        $avail = $vw - 2;                              // margen interno del Cell (1 mm por lado)
        foreach ([8, 7, 6.5, 6] as $size) {
            $this->SetFont('Arial', 'B', $size);
            if ($this->GetStringWidth($txt) <= $avail) break;
        }
        $txt = $this->fitWidth($txt, $avail);
        $this->Cell($vw, 5, $txt, 'B', 0);
    }

    /** Recorta el texto (ya en Windows-1252) para que quepa en $maxW mm, agregando "…". */
    private function fitWidth(string $text, float $maxW): string
    {
        if ($this->GetStringWidth($text) <= $maxW) return $text;
        while ($text !== '' && $this->GetStringWidth($text . "\x85") > $maxW) {
            $text = substr($text, 0, -1);
        }
        return rtrim($text) . "\x85";                  // 0x85 = "…" en Windows-1252
    }

    /**
     * Divide un texto (ya en Windows-1252) en líneas que caben en $maxW mm.
     * Devuelve como máximo $maxLines; si sobra texto, la última termina en "…".
     */
    private function wrapText(string $text, float $maxW, int $maxLines): array
    {
        $text  = str_replace(["\r\n", "\r"], "\n", $text);
        $lines = [];
        foreach (explode("\n", $text) as $para) {
            $words = preg_split('/\s+/', trim($para), -1, PREG_SPLIT_NO_EMPTY);
            if (!$words) continue;
            $cur = '';
            foreach ($words as $w) {
                // Palabra más ancha que la línea: se parte por caracteres
                while ($this->GetStringWidth($w) > $maxW) {
                    $cut = strlen($w) - 1;
                    while ($cut > 1 && $this->GetStringWidth(substr($w, 0, $cut)) > $maxW) $cut--;
                    if ($cur !== '') { $lines[] = $cur; $cur = ''; }
                    $lines[] = substr($w, 0, $cut);
                    $w = substr($w, $cut);
                }
                $try = ($cur === '') ? $w : $cur . ' ' . $w;
                if ($this->GetStringWidth($try) <= $maxW) {
                    $cur = $try;
                } else {
                    $lines[] = $cur;
                    $cur = $w;
                }
            }
            if ($cur !== '') $lines[] = $cur;
        }
        if (count($lines) > $maxLines) {
            $lines = array_slice($lines, 0, $maxLines);
            $last  = $this->fitWidth(rtrim($lines[$maxLines - 1]) . "\x85", $maxW);
            $lines[$maxLines - 1] = $last;
        }
        return $lines;
    }

    private function setDashedLine(float $black, float $white): void
    {
        if ($black > 0) {
            $this->_out(sprintf('[%.3F %.3F] 0 d', $black * $this->k, $white * $this->k));
        } else {
            $this->_out('[] 0 d');
        }
    }
}

// ── Construir y entregar el PDF ───────────
$pdf = new DiagnosticoPDF('P', 'mm', 'A4');
$pdf->SetAutoPageBreak(false);
$pdf->SetMargins(15, 10, 15);
$pdf->AddPage();

// Copia 1 — mitad superior
$pdf->drawForm($sol, 10);

// Línea punteada entre copias
$pdf->drawSeparator(140);

// Copia 2 — mitad inferior
$pdf->drawForm($sol, 145);

// Hoja 2 (solo si la solicitud tiene fotografía): anexo con la imagen del equipo
$imagePath = equipoImagePath($region, $sol['imagen'] ?? null);
if ($imagePath !== null) {
    $pdf->drawImagePage($sol, $imagePath);
}

$filename = 'diagnostico_' . $id . '_' . $region . '_' . date('Ymd') . '.pdf';
$pdf->Output('D', $filename);
exit;
