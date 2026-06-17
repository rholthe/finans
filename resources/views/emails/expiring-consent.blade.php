@component('mail::message')
# Bankgodkjenning utløper snart

En eller flere banktilkoblinger må fornyes for at den nattlige synken skal fortsette.

@foreach ($connections as $connection)
- **{{ $connection->name }}** – utløper {{ $connection->valid_until->format('d.m.Y') }}
({{ max(0, (int) ceil(now()->floatDiffInDays($connection->valid_until, false))) }} dager igjen)
@endforeach

Gå til Bank-siden og velg **Forny** på tilkoblingen for å godkjenne på nytt. Kontokoblingene beholdes.

@component('mail::button', ['url' => config('app.url').'/bank'])
Forny godkjenning
@endcomponent

@endcomponent
