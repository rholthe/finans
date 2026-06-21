@extends('legal.layout', ['title' => 'Vilkår for bruk', 'updated' => '15. juni 2026'])

@section('content')
    <p class="lead">
        Finans er en privat, selvhostet budsjett- og økonomiapplikasjon for personlig bruk,
        tilgjengelig på {{ config('legal.domain') }}. Disse vilkårene gjelder bruken av applikasjonen.
    </p>

    <h2>1. Om tjenesten</h2>
    <p>
        Finans er et ikke-kommersielt verktøy laget for eierens egen privatøkonomi. Tjenesten
        tilbys ikke til offentligheten, har ingen brukerregistrering og er beskyttet med
        passord. Applikasjonen lar deg føre regnskap, budsjettere, sette sparemål og koble til
        egne bankkontoer for å hente saldo og transaksjoner.
    </p>

    <h2>2. Bankkobling</h2>
    <p>
        Tilkobling til banker skjer gjennom Enable Banking, en lisensiert tilbyder av
        kontoinformasjonstjenester (AISP) under PSD2. Tilgangen er utelukkende for lesing –
        applikasjonen henter kontoinformasjon og transaksjoner, men kan ikke initiere
        betalinger eller flytte penger. Bankkobling krever ditt uttrykkelige samtykke hos banken,
        og samtykket kan trekkes tilbake når som helst.
    </p>

    <h2>3. Ditt ansvar</h2>
    <ul>
        <li>Du er ansvarlig for å holde passordet og tilgangen til applikasjonen sikker.</li>
        <li>Du er ansvarlig for opplysningene du legger inn, og for hvilke bankkontoer du kobler til.</li>
        <li>Applikasjonen er et hjelpemiddel for oversikt og erstatter ikke kontoutskrifter eller annen offisiell dokumentasjon fra banken.</li>
    </ul>

    <h2>4. Ingen garanti</h2>
    <p>
        Tjenesten leveres «som den er», uten noen form for garanti for tilgjengelighet,
        nøyaktighet eller feilfrihet. Data hentet fra banker kan være forsinket eller ufullstendig.
        Beslutninger du tar basert på informasjon i applikasjonen, er ditt eget ansvar.
    </p>

    <h2>5. Ansvarsbegrensning</h2>
    <p>
        Så langt loven tillater, fraskrives ethvert ansvar for direkte eller indirekte tap som
        følge av bruk av, eller manglende mulighet til å bruke, applikasjonen – inkludert tap
        knyttet til feil i hentede bankdata eller avbrudd i tjenesten.
    </p>

    <h2>6. Personvern</h2>
    <p>
        Behandling av personopplysninger er beskrevet i <a href="/privacy">personvernerklæringen</a>,
        som utgjør en del av disse vilkårene.
    </p>

    <h2>7. Endringer</h2>
    <p>
        Vilkårene kan oppdateres ved behov. Gjeldende versjon er alltid tilgjengelig på denne
        siden, med dato for siste oppdatering øverst.
    </p>

    <h2>8. Lovvalg</h2>
    <p>
        Vilkårene reguleres av norsk rett.
    </p>

    <h2>9. Kontakt</h2>
    <p>
        Spørsmål om vilkårene rettes til
        @if (config('legal.operator_email'))
            <a href="mailto:{{ config('legal.operator_email') }}">{{ config('legal.operator_email') }}</a>.
        @else
            applikasjonens eier.
        @endif
    </p>
@endsection
