@component('mail::message')
# Banksynk-rapport

**Status:** {{ $syncEvent->status }}
**Importerte transaksjoner:** {{ $syncEvent->imported_count }}
**Dager synket:** {{ $syncEvent->days_synced }}
**Tidspunkt:** {{ $syncEvent->created_at->format('d.m.Y H:i') }}

@if (!empty($syncEvent->report))
## Detaljer

@foreach ($syncEvent->report as $line)
- **{{ ucfirst($line['status']) }}:** {{ $line['message'] }}
@endforeach
@endif

@endcomponent
