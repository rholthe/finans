@php
    $tz = config('app.display_timezone');
    $emoji = ['header' => '🏦', 'info' => 'ℹ️', 'warn' => '⚠️', 'error' => '⛔'];
    $heading = match ($syncEvent->status) {
        \App\Models\SyncEvent::STATUS_FAILED => '⛔ Banksynk mislyktes',
        \App\Models\SyncEvent::STATUS_WITH_ERRORS => '⚠️ Banksynk fullført med merknader',
        default => '✅ Banksynk fullført',
    };
@endphp

@component('mail::message')
# {{ $heading }}

@if ($syncEvent->status === \App\Models\SyncEvent::STATUS_NEW)
Importerte **{{ $syncEvent->imported_count }}** {{ $syncEvent->imported_count === 1 ? 'ny transaksjon' : 'nye transaksjoner' }}.
@elseif ($syncEvent->status === \App\Models\SyncEvent::STATUS_NO_NEW)
Ingen nye transaksjoner å importere denne runden.
@else
Se detaljene nedenfor.
@endif

@component('mail::table')
| | |
|:--------------------------|:--------------------------------------------|
| **Status**                | {{ $syncEvent->statusLabel() }}              |
| **Nye transaksjoner**     | {{ $syncEvent->imported_count }}             |
| **Dager synket**          | {{ $syncEvent->days_synced }}                |
| **Tidspunkt**             | {{ $syncEvent->created_at->timezone($tz)->format('d.m.Y H:i') }} ({{ $syncEvent->created_at->timezone($tz)->format('T') }}) |
@endcomponent

@if (!empty($syncEvent->report))
## Detaljer

@foreach ($syncEvent->report as $line)
@if ($line['status'] === 'header')

**{{ $emoji['header'] }} {{ $line['message'] }}**
@else
- {{ $emoji[$line['status']] ?? '•' }} {{ $line['message'] }}
@endif
@endforeach
@endif

@component('mail::button', ['url' => config('app.url').'/bank'])
Åpne Bank-siden
@endcomponent

@endcomponent
