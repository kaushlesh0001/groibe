<x-mail::message>
# An exception was reported

**Exception:** {{ $report['class'] }}

**Message:** {{ $report['message'] }}

**Where:** {{ $report['where'] }}

**Request:** {{ $report['request'] }}

**Environment:** {{ $report['environment'] }}, {{ $report['time'] }}

The full report is in the daily log under `storage/logs`. This exception is not mailed again for an hour, and at most a few are mailed in any one hour.

{{ config('app.name') }}
</x-mail::message>
