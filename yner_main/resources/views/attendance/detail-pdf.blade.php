{{-- Per-employee attendance detail for dompdf (PDF export only — not an Inertia page). --}}
@extends('pdf.layout')

@section('title', 'Employee Attendance Detail')
@section('subtitle', 'Day-by-day punch record, worked hours and lateness')

@section('meta')
    <tr>
        <td class="k">Period</td>
        <td class="v">{{ $meta['from_label'] }} &ndash; {{ $meta['to_label'] }}</td>
        <td class="k">Records</td>
        <td class="v">{{ $meta['records'] }}</td>
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
        <td class="v">Attendance detail</td>
    </tr>
@endsection

@section('content')
    <table class="tiles">
        <tr>
            <td style="width:16.6%"><div class="tk">Present</div><div class="tv">{{ $totals['present'] }}</div></td>
            <td style="width:16.6%"><div class="tk">Late</div><div class="tv">{{ $totals['late'] }}</div></td>
            <td style="width:16.6%"><div class="tk">Absent</div><div class="tv">{{ $totals['absent'] }}</div></td>
            <td style="width:16.6%"><div class="tk">On leave</div><div class="tv">{{ $totals['leave'] }}</div></td>
            <td style="width:16.6%"><div class="tk">Worked hours</div><div class="tv">{{ $totals['worked_hours'] }}</div></td>
            <td style="width:16.6%"><div class="tk">Late minutes</div><div class="tv">{{ $totals['late_minutes'] }}</div></td>
        </tr>
    </table>

    <div class="section-label">Daily records</div>

    <table class="data">
        <thead>
            <tr>
                <th>Employee</th>
                <th>Date</th>
                <th>Status</th>
                <th>In</th>
                <th>Out</th>
                <th class="num">Hours</th>
                <th class="num">Late min</th>
                <th>Reason / remarks</th>
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
                <tr><td colspan="8" class="empty">No attendance records for this range.</td></tr>
            @endforelse
        </tbody>
    </table>
@endsection
