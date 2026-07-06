{{-- Phase 10: server-rendered attendance report for dompdf (PDF export only — not an Inertia page). --}}
@php($meta = $report['meta'])
@php($totals = $report['totals'])
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Attendance Report</title>
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
        th, td { padding: 6px 8px; text-align: left; border-bottom: 1px solid #e2e8f0; }
        th { background: #f1f5f9; font-size: 10px; text-transform: uppercase; letter-spacing: 0.03em; color: #475569; }
        td.num, th.num { text-align: right; }
        tr.totals td { border-top: 2px solid #cbd5e1; font-weight: bold; background: #f8fafc; }
        .footer { margin-top: 16px; font-size: 9px; color: #94a3b8; text-align: right; }
    </style>
</head>
<body>
    <div class="header">
        <h1>Employee Attendance Report</h1>
        <div class="muted">EAPMS — Institute of Finance Management</div>
        <div class="meta-row">
            <span><b>Period:</b> {{ $meta['from_label'] }} &ndash; {{ $meta['to_label'] }}</span>
            <span><b>Scope:</b> {{ $meta['scope'] }}</span>
            <span><b>Employees:</b> {{ $meta['employees'] }}</span>
            <span><b>Working days:</b> {{ $meta['working_days'] }}</span>
        </div>
    </div>

    <div class="summary">
        <span>Attendance rate <b>{{ $totals['attendance_rate'] }}%</b></span>
        <span>Punctuality <b>{{ $totals['punctuality_rate'] }}%</b></span>
        <span>Present <b>{{ $totals['present'] }}</b></span>
        <span>Late <b>{{ $totals['late'] }}</b></span>
        <span>Absent <b>{{ $totals['absent'] }}</b></span>
        <span>On leave <b>{{ $totals['leave'] }}</b></span>
    </div>

    <table>
        <thead>
            <tr>
                <th>Code</th>
                <th>Employee</th>
                <th>Department</th>
                <th class="num">Present</th>
                <th class="num">Late</th>
                <th class="num">Absent</th>
                <th class="num">Leave</th>
                <th class="num">Hours</th>
                <th class="num">Late min</th>
                <th class="num">Attend %</th>
                <th class="num">Punct %</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($report['rows'] as $row)
                <tr>
                    <td>{{ $row['employee_code'] }}</td>
                    <td>{{ $row['name'] }}</td>
                    <td>{{ $row['department'] }}</td>
                    <td class="num">{{ $row['present'] }}</td>
                    <td class="num">{{ $row['late'] }}</td>
                    <td class="num">{{ $row['absent'] }}</td>
                    <td class="num">{{ $row['leave'] }}</td>
                    <td class="num">{{ $row['worked_hours'] }}</td>
                    <td class="num">{{ $row['late_minutes'] }}</td>
                    <td class="num">{{ $row['attendance_rate'] }}</td>
                    <td class="num">{{ $row['punctuality_rate'] }}</td>
                </tr>
            @empty
                <tr><td colspan="11" class="muted" style="text-align:center; padding:16px;">No employees in scope.</td></tr>
            @endforelse
            <tr class="totals">
                <td colspan="3">TOTAL — {{ $totals['employees'] }} employees</td>
                <td class="num">{{ $totals['present'] }}</td>
                <td class="num">{{ $totals['late'] }}</td>
                <td class="num">{{ $totals['absent'] }}</td>
                <td class="num">{{ $totals['leave'] }}</td>
                <td class="num">{{ $totals['worked_hours'] }}</td>
                <td class="num">{{ $totals['late_minutes'] }}</td>
                <td class="num">{{ $totals['attendance_rate'] }}</td>
                <td class="num">{{ $totals['punctuality_rate'] }}</td>
            </tr>
        </tbody>
    </table>

    <div class="footer">Generated {{ $meta['generated_at'] }} · EAPMS reporting</div>
</body>
</html>
