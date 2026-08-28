{{--
    Corporate letterhead layout shared by every dompdf export.

    Page chrome is split in two:
      • HTML `position: fixed` blocks draw the running header (brand mark, corner
        accents, tri-colour rule) — dompdf repeats those on every page.
      • The inline `<script type="text/php">` block draws the bottom brand band on
        every page and the full contact strip on the LAST page only, which is the
        one thing CSS cannot express in dompdf (there is no last-page selector).
        It needs the `isPhpEnabled` dompdf option, set by the exporting controller.

    Child views provide @section('title'), @section('subtitle'), @section('meta')
    (rows for the meta table) and @section('content').
--}}
@php
    $pdfCfg = config('pdf');
    $exp = static fn ($value) => var_export((string) $value, true);

    // dompdf reads the crest straight off disk; drop it if the file is absent so the
    // header still renders (e.g. a deployment that has not published public/logo.png).
    $logoPath = $pdfCfg['logo'] ? public_path($pdfCfg['logo']) : null;
    $logoPath = $logoPath && is_file($logoPath) ? $logoPath : null;
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>@yield('title', 'Report')</title>
    <style>
        @page { margin: 132pt 42pt 104pt 42pt; }

        * { font-family: "DejaVu Sans", sans-serif; }
        body { margin: 0; color: #0f172a; font-size: 9.5pt; line-height: 1.45; }

        /* ---------- running header (repeats on every page) ---------- */
        .deco-square { position: fixed; top: -132pt; left: -42pt; width: 26pt; height: 26pt; background: #22d3ee; }

        .brand { position: fixed; top: -106pt; left: 0; right: 0; width: 100%; border-collapse: collapse; }
        .brand td { padding: 0; vertical-align: middle; }
        .crest { width: 54pt; height: 54pt; }
        .brand-inst { padding-left: 9pt; }
        .inst-name { font-size: 9.5pt; font-weight: bold; letter-spacing: 0.4pt; color: #1e3a8a; line-height: 1.2; }
        .inst-motto { font-size: 7pt; font-style: italic; letter-spacing: 1.4pt; text-transform: uppercase; color: #64748b; margin-top: 2pt; }
        .brand-right { text-align: right; }
        .brand-dots { margin-bottom: 3pt; }
        .brand-dots span { display: inline-block; width: 5pt; height: 5pt; border-radius: 2.5pt; margin-left: 3pt; }
        .brand-word { font-size: 18pt; font-weight: bold; letter-spacing: 2.6pt; color: #0f172a; line-height: 1.1; }
        .brand-sys { font-size: 6.5pt; letter-spacing: 0.9pt; text-transform: uppercase; color: #64748b; margin-top: 2pt; }

        .head-rule { position: fixed; top: -22pt; left: 0; right: 0; width: 100%; border-collapse: collapse; }
        .head-rule td { height: 3pt; padding: 0; line-height: 3pt; font-size: 0; }

        /* ---------- document opening block ---------- */
        .doc-title { font-size: 19pt; font-weight: bold; letter-spacing: 1.4pt; text-transform: uppercase; color: #0f172a; }
        .doc-sub { font-size: 8.5pt; color: #64748b; margin-top: 3pt; }
        .doc-rule { height: 1pt; background: #e2e8f0; margin: 12pt 0 10pt; font-size: 0; line-height: 1pt; }

        .meta { width: 100%; border-collapse: collapse; margin-bottom: 16pt; }
        .meta td { padding: 2pt 0; vertical-align: top; font-size: 8.5pt; }
        .meta .k { color: #64748b; text-transform: uppercase; letter-spacing: 0.6pt; font-size: 7pt; width: 74pt; }
        .meta .v { color: #0f172a; font-weight: bold; }

        /* ---------- summary tiles ---------- */
        .tiles { width: 100%; border-collapse: separate; border-spacing: 4pt 0; margin-bottom: 16pt; }
        .tiles td { background: #f8fafc; border: 0.6pt solid #e2e8f0; border-top: 2pt solid #2563eb; padding: 7pt 8pt; }
        .tiles .tk { font-size: 6.5pt; text-transform: uppercase; letter-spacing: 0.8pt; color: #64748b; }
        .tiles .tv { font-size: 14pt; font-weight: bold; color: #0f172a; padding-top: 2pt; }

        /* ---------- data table ---------- */
        .section-label { font-size: 8pt; font-weight: bold; text-transform: uppercase; letter-spacing: 1.2pt; color: #2563eb; margin-bottom: 6pt; }
        table.data { width: 100%; border-collapse: collapse; }
        table.data thead { display: table-header-group; }
        table.data tr { page-break-inside: avoid; }
        table.data th {
            background: #0f172a; color: #ffffff; font-size: 7pt; text-transform: uppercase;
            letter-spacing: 0.7pt; text-align: left; padding: 6pt; font-weight: bold;
        }
        table.data td { padding: 5pt 6pt; border-bottom: 0.5pt solid #e8edf3; font-size: 8.5pt; }
        table.data tbody tr:nth-child(even) td { background: #f8fafc; }
        table.data td.num, table.data th.num { text-align: right; }
        table.data tr.totals td { background: #eff6ff; border-top: 1.2pt solid #2563eb; border-bottom: 1.2pt solid #2563eb; font-weight: bold; }
        .empty { text-align: center; padding: 18pt; color: #94a3b8; font-style: italic; }

        /* ---------- closing block (end of the last page's flow) ---------- */
        .closing { margin-top: 18pt; page-break-inside: avoid; }
        .end-mark { text-align: center; font-size: 7pt; letter-spacing: 2pt; text-transform: uppercase; color: #94a3b8; border-top: 0.5pt solid #e2e8f0; padding-top: 8pt; }
        .sign { width: 100%; border-collapse: collapse; margin-top: 22pt; }
        .sign td { width: 33%; padding-right: 18pt; vertical-align: bottom; }
        .sign .line { border-bottom: 0.8pt solid #94a3b8; height: 22pt; }
        .sign .lbl { font-size: 7pt; text-transform: uppercase; letter-spacing: 0.8pt; color: #64748b; padding-top: 4pt; }
    </style>
</head>
<body>
    {{-- letterhead corner accent + institutional crest + system mark, on every page --}}
    <div class="deco-square"></div>

    <table class="brand">
        <tr>
            @if ($logoPath)
                <td style="width:54pt"><img class="crest" src="{{ $logoPath }}" alt=""></td>
            @endif
            <td class="brand-inst">
                <div class="inst-name">{{ $pdfCfg['organisation'] }}</div>
                <div class="inst-motto">{{ $pdfCfg['motto'] }}</div>
            </td>
            <td class="brand-right">
                <div class="brand-dots">
                    <span style="background:#22d3ee"></span><span style="background:#0ea5e9"></span><span style="background:#2563eb"></span>
                </div>
                <div class="brand-word">{{ $pdfCfg['brand'] }}</div>
                <div class="brand-sys">{{ $pdfCfg['tagline'] }}</div>
            </td>
        </tr>
    </table>

    <table class="head-rule">
        <tr>
            <td style="width:14%; background:#2563eb"></td>
            <td style="width:7%;  background:#0ea5e9"></td>
            <td style="width:5%;  background:#22d3ee"></td>
            <td style="background:#e2e8f0"></td>
        </tr>
    </table>

    {{-- document opening --}}
    <div class="doc-title">@yield('title', 'Report')</div>
    <div class="doc-sub">@yield('subtitle', $pdfCfg['tagline'])</div>
    <div class="doc-rule"></div>

    <table class="meta">
        @yield('meta')
    </table>

    @yield('content')

    <div class="closing">
        <div class="end-mark">End of report</div>
        <table class="sign">
            <tr>
                <td><div class="line"></div><div class="lbl">Prepared by — HR Officer</div></td>
                <td><div class="line"></div><div class="lbl">Verified by — Head of Department</div></td>
                <td><div class="line"></div><div class="lbl">Date &amp; official stamp</div></td>
            </tr>
        </table>
    </div>

    {{-- Bottom brand band on every page; full contact strip on the last page only. --}}
    <script type="text/php">
    if (isset($pdf)) {
        $pdf->page_script(<<<'PAGESCRIPT'
$email   = {!! $exp($pdfCfg['contact']['email']) !!};
$address = {!! $exp($pdfCfg['contact']['address']) !!};
$phone   = {!! $exp($pdfCfg['contact']['phone']) !!};
$site    = {!! $exp($pdfCfg['contact']['website']) !!};
$brand   = {!! $exp($pdfCfg['brand']) !!};
$classif = {!! $exp($pdfCfg['classification']) !!};

$w = $pdf->get_width();
$h = $pdf->get_height();

$blue  = [0.145, 0.388, 0.922];
$sky   = [0.055, 0.647, 0.914];
$cyan  = [0.133, 0.827, 0.933];
$ink   = [0.059, 0.090, 0.165];
$muted = [0.392, 0.455, 0.545];
$rule  = [0.886, 0.910, 0.941];
$white = [1.0, 1.0, 1.0];

$sans = $fontMetrics->getFont('DejaVu Sans', 'normal');
$bold = $fontMetrics->getFont('DejaVu Sans', 'bold');

$m = 42;
$bandH = 30;
$bandY = $h - $bandH;

// hairline closing the body area, on every page
$pdf->filled_rectangle($m, $h - 96, $w - 2 * $m, 0.6, $rule);

// contact strip — last page only
if ($PAGE_NUM == $PAGE_COUNT) {
    $y = $h - 80;
    $cols = [[$blue, 'Email', $email], [$sky, 'Address', $address], [$cyan, 'Phone', $phone]];
    $colW = ($w - 2 * $m) / 3;
    foreach ($cols as $i => $col) {
        $x = $m + $i * $colW;
        $pdf->circle($x + 5, $y + 9, 5, $col[0], null, [], true);
        $pdf->text($x + 16, $y, $col[1], $bold, 6.5, $muted);
        $pdf->text($x + 16, $y + 8, $col[2], $sans, 8, $ink);
    }
}

// bottom brand band, every page
$pdf->filled_rectangle(0, $bandY, $w, $bandH, $blue);
$pdf->filled_rectangle(0, $bandY, 78, $bandH, $cyan);

$pdf->text($m + 52, $bandY + 11, $brand . '  ·  ' . $classif, $sans, 7.5, $white);

$right = 'Page ' . $PAGE_NUM . ' of ' . $PAGE_COUNT;
$rw = $fontMetrics->getTextWidth($right, $bold, 7.5);
$pdf->text($w - $m - $rw, $bandY + 11, $right, $bold, 7.5, $white);

if ($PAGE_NUM == $PAGE_COUNT) {
    $sw = $fontMetrics->getTextWidth($site, $sans, 7.5);
    $pdf->text(($w - $sw) / 2, $bandY + 11, $site, $sans, 7.5, $white);
}
PAGESCRIPT
        );
    }
    </script>
</body>
</html>
