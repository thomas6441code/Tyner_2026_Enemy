{{-- Per-employee attendance detail for dompdf (PDF export only — not an Inertia page). --}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Attendance Detail</title>
    <style>
        * { font-family: DejaVu Sans, sans-serif; }
        body { color: #1e293b; font-size: 11px; margin: 0; }
        .header { border-bottom: 2px solid #10b981; padding-bottom: 10px; margin-bottom: 14px; }
        .header h1 { font-size: 18px; margin: 0 0 4px; }
        .muted { color: #64748b; }
        .meta-row { margin-top: 6px; }
        .meta-row span { display: inline-block; margin-right: 18px; }
        .summary { background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 6px; padding: 8px 12px; margin-bottom: 14px; }
        .summary span { display: inline-block; margin-right: 22px; }
        .summary b { font-size: 13px; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 5px 6px; text-align: left; border-bottom: 1px solid #e2e8f0; }
        th { background: #f1f5f9; font-size: 9px; text-transform: uppercase; letter-spacing: 0.03em; color: #475569; }
        td.num, th.num { text-align: right; }
        .footer { margin-top: 16px; font-size: 9px; color: #94a3b8; text-align: right; }
    </style>
</head>
<body>
    <div class="header">
        <h1>Employee Attendance Detail</h1>
        <div class="muted">EAPMS — Institute of Finance Management</div>
        <div class="meta-row">
            <span><b>Period:</b> {{ $meta['from_label'] }} &ndash; {{ $meta['to_label'] }}</span>
            <span><b>Scope:</b> {{ $meta['scope'] }}</span>
            <span><b>Records:</b> {{ $meta['records'] }}</span>
        </div>
    </div>

    <div class="summary">
        <span>Present <b>{{ $totals['present'] }}</b></span>
        <span>Late <b>{{ $totals['late'] }}</b></span>
        <span>Absent <b>{{ $totals['absent'] }}</b></span>
        <span>On leave <b>{{ $totals['leave'] }}</b></span>
        <span>Worked hours <b>{{ $totals['worked_hours'] }}</b></span>
        <span>Late minutes <b>{{ $totals['late_minutes'] }}</b></span>
    </div>

    <table>
        <thead>
            <tr>
                <th>Employee</th>
                <th>Date</th>
                <th>Status</th>
                <th>In</th>
                <th>Out</th>
                <th class="num">Hours</th>
                <th class="num">Late min</th>
                <th>Remarks</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($rows as $row)
                <tr>
                    <td>{{ $row['name'] }}</td>
                    <td>{{ $row['date'] }}</td>
                    <td>{{ $row['status'] }}</td>
                    <td>{{ $row['first_in'] }}</td>
                    <td>{{ $row['last_out'] }}</td>
                    <td class="num">{{ $row['worked_hours'] }}</td>
                    <td class="num">{{ $row['late_minutes'] }}</td>
                    <td>{{ $row['remarks'] }}</td>
                </tr>
            @empty
                <tr><td colspan="8" class="muted" style="text-align:center; padding:16px;">No attendance records for this range.</td></tr>
            @endforelse
        </tbody>
    </table>

    <div class="footer">Generated {{ $meta['generated_at'] }} · EAPMS reporting</div>
</body>
</html>
