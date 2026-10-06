<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>{{ $reference }} · {{ $typeName }} booking form</title>
<style>
    @font-face { font-family: 'Poppins'; font-weight: 400; src: url('{{ $fonts }}/Poppins-Regular.ttf') format('truetype'); }
    @font-face { font-family: 'Poppins'; font-weight: 500; src: url('{{ $fonts }}/Poppins-Medium.ttf') format('truetype'); }
    @font-face { font-family: 'Poppins'; font-weight: 600; src: url('{{ $fonts }}/Poppins-SemiBold.ttf') format('truetype'); }
    @font-face { font-family: 'Poppins'; font-weight: 700; src: url('{{ $fonts }}/Poppins-Bold.ttf') format('truetype'); }

    /* A4 with room above for the running header and below for the footer.
       dompdf scales line-height by Poppins' tall glyph box (about 1.64em),
       not by the font size: 0.8 here reads like 1.3 on paper. */
    @page { margin: 30mm 16mm 24mm 16mm; }

    * { box-sizing: border-box; }

    body {
        font-family: 'Poppins', sans-serif;
        font-size: 9.5pt;
        line-height: 0.86;
        color: #1d1c1a;
        margin: 0;
    }

    /* ---- Running header and footer (every page) ---- */
    .running-header { position: fixed; top: -22mm; left: 0; right: 0; height: 16mm; }
    .running-header table { width: 100%; border-collapse: collapse; }
    .running-header .logo { height: 11mm; }
    .running-header .doc { text-align: right; vertical-align: bottom; font-size: 8pt; color: #5b5a55; line-height: 0.8; }
    .running-header .doc strong { display: block; color: #1d1c1a; font-size: 10pt; font-weight: 600; letter-spacing: 0.04em; }
    .running-header .rule { border-bottom: 0.8pt solid #1d1c1a; margin-top: 2.5mm; }

    .running-footer { position: fixed; bottom: -16mm; left: 0; right: 0; height: 12mm; font-size: 7.5pt; color: #6b6a64; line-height: 0.82; }
    .running-footer table { width: 100%; border-collapse: collapse; border-top: 0.5pt solid #c9c7c0; }
    .running-footer td { padding-top: 2mm; vertical-align: top; }
    /* "Page X of Y" is drawn onto each page after layout (BookingDocument). */
    .running-footer .pages { width: 24mm; }

    /* ---- Title block ---- */
    .title-block { width: 100%; border-collapse: collapse; margin-bottom: 5mm; }
    .title-block td { vertical-align: top; }
    .eyebrow { font-size: 8pt; font-weight: 600; letter-spacing: 0.08em; text-transform: uppercase; color: #5b5a55; }
    h1 { font-size: 17pt; line-height: 0.74; font-weight: 600; margin: 1.5mm 0 1.5mm; }
    .subtitle { color: #4a4944; font-size: 10pt; }

    .stamp { width: 58mm; border: 1.4pt solid #1f7a4f; border-radius: 2mm; padding: 2.5mm 3.5mm; text-align: left; }
    .stamp .label { color: #1f7a4f; font-size: 13pt; font-weight: 700; letter-spacing: 0.14em; }
    .stamp .by { font-size: 8pt; color: #3c3b37; line-height: 0.82; }
    .reference { font-size: 8pt; color: #5b5a55; margin-bottom: 1.5mm; text-align: left; }
    .reference strong { font-size: 11pt; color: #1d1c1a; letter-spacing: 0.04em; }

    /* ---- Details ---- */
    h2 { font-size: 10.5pt; font-weight: 600; margin: 0 0 2mm; }
    .section { margin-bottom: 6mm; }
    .details { width: 100%; border-collapse: collapse; }
    .details th, .details td { text-align: left; vertical-align: top; padding: 1.6mm 0; border-bottom: 0.5pt solid #e1dfd8; }
    .details th { width: 34mm; font-weight: 500; color: #5b5a55; padding-right: 4mm; }
    .details td { word-wrap: break-word; }
    .muted { color: #6b6a64; }

    /* ---- People and signatures ---- */
    .people-intro { margin: 0 0 2.5mm; color: #4a4944; font-size: 8.5pt; }
    .signatures { width: 100%; border-collapse: collapse; }
    .signatures thead { display: table-header-group; }
    .signatures th {
        text-align: left; font-size: 8pt; font-weight: 600; letter-spacing: 0.06em; text-transform: uppercase;
        color: #3c3b37; background: #f1efe9; padding: 2mm 3mm; border: 0.6pt solid #1d1c1a;
    }
    .signatures td { border: 0.6pt solid #1d1c1a; padding: 1.5mm 3mm; height: 13mm; vertical-align: middle; }
    .signatures tr { page-break-inside: avoid; }
    .signatures .name { width: 48%; }
    /* The row number gets its own column inside the name cell, so wrapped
       names stay aligned under their first line. */
    .signatures .name table { width: 100%; border-collapse: collapse; }
    .signatures .name table td { border: 0; padding: 0; height: auto; vertical-align: top; }
    .signatures .num { width: 7mm; color: #8a8880; font-size: 8pt; }
    .signatures .who { line-height: 0.8; word-wrap: break-word; }

    /* ---- Sign-off ---- */
    .signoff { width: 100%; border-collapse: collapse; margin-top: 9mm; page-break-inside: avoid; }
    .signoff td { width: 50%; vertical-align: bottom; padding-right: 8mm; }
    .signoff .line { border-bottom: 0.6pt solid #1d1c1a; height: 14mm; }
    .signoff .who { font-weight: 500; margin-top: 1.5mm; }
    .signoff .role { font-size: 8pt; color: #5b5a55; }

    .note { margin-top: 6mm; font-size: 8pt; color: #5b5a55; page-break-inside: avoid; }
</style>
</head>
<body>

<div class="running-header">
    <table>
        <tr>
            <td><img class="logo" src="{{ $logo }}" alt="{{ $org['acronym'] }}"></td>
            <td class="doc">
                <strong>{{ strtoupper($typeName) }} BOOKING FORM</strong>
                {{ $org['name'] }}
            </td>
        </tr>
    </table>
    <div class="rule"></div>
</div>

<div class="running-footer">
    <table>
        <tr>
            <td>
                {{ $reference }} · Generated {{ $generatedAt }}<br>
                Valid only while this booking stays approved. Check its current status at {{ $checkUrl }}
            </td>
            <td class="pages"></td>
        </tr>
    </table>
</div>

<table class="title-block">
    <tr>
        <td>
            <div class="eyebrow">{{ $typeName }} · {{ $resource['name'] }}</div>
            <h1>{{ $title }}</h1>
            <div class="subtitle">{{ $summary }}</div>
        </td>
        <td style="width: 60mm; text-align: right;">
            <div class="reference">Reference<br><strong>{{ $reference }}</strong></div>
            <div class="stamp">
                <div class="label">APPROVED</div>
                <div class="by">
                    @if ($approvedBy)
                        by {{ $approvedBy }}<br>{{ $approvedAt }}
                    @else
                        No review needed for this type
                    @endif
                </div>
            </div>
        </td>
    </tr>
</table>

<div class="section">
    <h2>Booking details</h2>
    <table class="details">
        @foreach ($when as [$label, $value])
            <tr><th>{{ $label }}</th><td>{{ $value }}</td></tr>
        @endforeach
        <tr>
            <th>{{ $nouns['resource'] }}</th>
            <td>
                {{ $resource['name'] }}
                @if ($resource['location'] || $resource['capacity'])
                    <span class="muted">
                        · {{ collect([$resource['location'], $resource['capacity'] ? $resource['capacity'].' '.$nouns['seats'] : null])->filter()->join(' · ') }}
                    </span>
                @endif
            </td>
        </tr>
        <tr><th>Purpose</th><td>{{ $purpose ?: '—' }}</td></tr>
        <tr>
            <th>Requested by</th>
            <td>
                {{ $requester['name'] }}
                @if ($requester['division'] || $requester['email'])
                    <span class="muted">· {{ collect([$requester['division'], $requester['email']])->filter()->join(' · ') }}</span>
                @endif
            </td>
        </tr>
        <tr><th>Requested on</th><td>{{ $requestedAt ?? '—' }}</td></tr>
        <tr>
            <th>Status</th>
            <td>
                Approved
                @if ($approvedBy)
                    <span class="muted">· by {{ $approvedBy }}, {{ $approvedAt }}</span>
                @endif
            </td>
        </tr>
        <tr>
            <th>{{ $nouns['people'] }}</th>
            <td>
                {{ count($people) }}
                @if ($resource['capacity'])
                    <span class="muted">of {{ $resource['capacity'] }} {{ $nouns['seats'] }}</span>
                @endif
            </td>
        </tr>
    </table>
</div>

<div class="section">
    <h2>{{ $nouns['people'] }} and signatures</h2>
    <p class="people-intro">
        @if ($namesRecorded)
            Each {{ $nouns['person'] }} signs beside their name on the day.
        @else
            Names were not recorded with this booking. Write each {{ $nouns['person'] }}'s name, then have them sign.
        @endif
    </p>
    <table class="signatures">
        <thead>
            <tr><th class="name">Name</th><th>Signature</th></tr>
        </thead>
        <tbody>
            @foreach ($people as $i => $name)
                <tr>
                    <td class="name">
                        <table><tr><td class="num">{{ $i + 1 }}</td><td class="who">{{ $name }}</td></tr></table>
                    </td>
                    <td></td>
                </tr>
            @endforeach
        </tbody>
    </table>
</div>

<table class="signoff">
    <tr>
        <td>
            <div class="line"></div>
            <div class="who">{{ $requester['name'] }}</div>
            <div class="role">Requested by · signature and date</div>
        </td>
        <td>
            <div class="line"></div>
            <div class="who">@if ($approvedBy){{ $approvedBy }}@else&nbsp;@endif</div>
            <div class="role">Approved by · signature and date</div>
        </td>
    </tr>
</table>

</body>
</html>
