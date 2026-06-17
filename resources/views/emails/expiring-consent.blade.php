@php
    $tz = config('app.display_timezone');
@endphp

@component('mail::message')
# ⚠️ Bankgodkjenning utløper snart

En eller flere banktilkoblinger må fornyes for at den nattlige synken skal fortsette å hente transaksjoner.

@component('mail::table')
| Bank | Utløper | Dager igjen |
|:-----|:--------|------------:|
@foreach ($connections as $connection)
| {{ $connection->name }} | {{ $connection->valid_until->timezone($tz)->format('d.m.Y') }} | {{ max(0, (int) ceil(now()->floatDiffInDays($connection->valid_until, false))) }} |
@endforeach
@endcomponent

Velg **Forny** på tilkoblingen for å godkjenne på nytt. Kontokoblingene beholdes.

@component('mail::button', ['url' => config('app.url').'/bank'])
Forny godkjenning
@endcomponent

@endcomponent
