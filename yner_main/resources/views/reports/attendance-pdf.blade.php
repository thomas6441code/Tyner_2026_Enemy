{{-- Phase 10: server-rendered attendance report for dompdf (PDF export only — not an Inertia page). --}}
@extends('pdf.layout')

@php($meta = $report['meta'])
@php($totals = $report['totals'])

@section('title', 'Employee Attendance Report')
@section('subtitle', 'Consolidated attendance, punctuality and leave summary per employee')

@section('meta')
    <tr>
        <td class="k">Period</td>
        <td class="v">{{ $meta['from_label'] }} &ndash; {{ $meta['to_label'] }}</td>
        <td class="k">Working days</td>
        <td class="v">{{ $meta['working_days'] }}</td>
    </tr>
    <tr>
        <td class="k">Scope</td>
        <td class="v">{{ $meta['scope'] }}</td>
        <td class="k">Employees</td>
        <td class="v">{{ $meta['employees'] }}</td>
    </tr>
    <tr>
        <td class="k">Generated</td>
        <td class="v">{{ $meta['generated_at'] }}</td>
        <td class="k">Document</td>
        <td class="v">Attendance summary</td>
    </tr>
@endsection

@section('content')
    <table class="tiles">
        <tr>
            <td style="width:16.6%"><div class="tk">Attendance rate</div><div class="tv">{{ $totals['attendance_rate'] }}%</div></td>
            <td style="width:16.6%"><div class="tk">Punctuality</div><div class="tv">{{ $totals['punctuality_rate'] }}%</div></td>
            <td style="width:16.6%"><div class="tk">Present</div><div class="tv">{{ $totals['present'] }}</div></td>
            <td style="width:16.6%"><div class="tk">Late</div><div class="tv">{{ $totals['late'] }}</div></td>
            <td style="width:16.6%"><div class="tk">Absent</div><div class="tv">{{ $totals['absent'] }}</div></td>
            <td style="width:16.6%"><div class="tk">On leave</div><div class="tv">{{ $totals['leave'] }}</div></td>
        </tr>
    </table>

    <div class="section-label">Per-employee breakdown</div>

    <table class="data">
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
                <tr><td colspan="11" class="empty">No employees in scope.</td></tr>
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
@endsection
