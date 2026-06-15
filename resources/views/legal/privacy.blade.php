@extends('legal.layout', ['title' => 'Personvernerklæring', 'updated' => '15. juni 2026'])

@section('content')
    <p class="lead">
        Finans er en privat, selvhostet budsjett- og økonomiapplikasjon for personlig bruk.
        Denne erklæringen forklarer hvilke personopplysninger som behandles, hvorfor, og
        hvilke rettigheter du har.
    </p>

    <h2>1. Behandlingsansvarlig</h2>
    <p>
        Behandlingsansvarlig for opplysningene i applikasjonen er:
    </p>
    <ul>
        <li>Ragnar Holthe</li>
        <li>E-post: <a href="mailto:76702553+rholthe@users.noreply.github.com">76702553+rholthe@users.noreply.github.com</a></li>
    </ul>
    <p>
        Applikasjonen driftes på domenet <strong>finans.example.com</strong> og brukes av én
        enkelt person (eieren). Det finnes ingen registrering, ingen flere brukere og ingen
        offentlig tilgang utover de offentlige sidene for personvern og vilkår.
    </p>

    <h2>2. Hvilke opplysninger behandles</h2>
    <ul>
        <li>
            <strong>Bankkontoinformasjon</strong> – kontonavn, kontonummer/identifikator,
            saldo og kontotype, hentet fra dine egne banker.
        </li>
        <li>
            <strong>Transaksjonsdata</strong> – beløp, dato, beskrivelse, mottaker/avsender og
            referanser for transaksjoner på de tilkoblede kontoene.
        </li>
        <li>
            <strong>Egne registreringer</strong> – budsjettkategorier, mål, planlagte
            transaksjoner, notater og regler du selv legger inn.
        </li>
        <li>
            <strong>Teknisk tilgang</strong> – en innloggingsøkt (session-cookie) som holder
            deg innlogget etter at du har oppgitt applikasjonens passord.
        </li>
    </ul>

    <h2>3. Formålet med behandlingen</h2>
    <p>
        Opplysningene behandles utelukkende for å gi deg som eier en oversikt over egen
        privatøkonomi: budsjettering, kategorisering av forbruk, avstemming mot bankkonto og
        rapporter. Dataene brukes ikke til profilering, markedsføring, analyse på tvers av
        brukere eller noe annet formål.
    </p>

    <h2>4. Bankkobling via Enable Banking</h2>
    <p>
        For å hente kontoinformasjon og transaksjoner kobler applikasjonen seg til dine banker
        gjennom <strong>Enable Banking</strong>, en lisensiert tilbyder av kontoinformasjons­tjenester
        (AISP) under betalingstjenestedirektivet (PSD2). Tilkoblingen skjer på følgende premisser:
    </p>
    <ul>
        <li>Du gir uttrykkelig samtykke direkte hos banken din før noen data hentes.</li>
        <li>Tilgangen er <strong>kun for lesing</strong> – applikasjonen kan ikke flytte penger eller utføre betalinger.</li>
        <li>Samtykket er tidsbegrenset og kan når som helst trekkes tilbake hos banken eller ved å koble fra kontoen i applikasjonen.</li>
    </ul>
    <p>
        Enable Bankings egen behandling av data er beskrevet i deres personvernerklæring på
        <a href="https://enablebanking.com/privacy-policy/" target="_blank" rel="noopener">enablebanking.com</a>.
    </p>

    <h2>5. Rettslig grunnlag</h2>
    <p>
        Behandlingen bygger på ditt samtykke (personvernforordningen artikkel 6 nr. 1 bokstav a)
        ved tilkobling av bankkontoer, og på at behandlingen er nødvendig for å levere
        funksjonaliteten du som eier selv har bedt om.
    </p>

    <h2>6. Lagring og lagringstid</h2>
    <p>
        Alle opplysninger lagres i applikasjonens egen database på serveren der den driftes,
        og deles ikke med andre tjenester enn det som er nødvendig for bankkoblingen beskrevet
        over. Data beholdes så lenge du bruker applikasjonen. Du kan slette enkeltdata,
        koble fra kontoer eller slette hele databasen når du ønsker.
    </p>

    <h2>7. Deling med tredjeparter</h2>
    <p>
        Opplysningene <strong>selges ikke</strong> og deles ikke med tredjeparter for
        markedsføring eller analyse. De eneste eksterne aktørene som er involvert, er Enable
        Banking (for å hente bankdata) og din egen bank.
    </p>

    <h2>8. Sikkerhet</h2>
    <p>
        Applikasjonen er beskyttet med passord, og all trafikk går over kryptert forbindelse
        (HTTPS). Nøkler og samtykker for bankkobling oppbevares som serverkonfigurasjon og
        eksponeres ikke i grensesnittet.
    </p>

    <h2>9. Dine rettigheter</h2>
    <p>
        Som registrert har du rett til innsyn i, retting og sletting av opplysninger om deg,
        samt rett til å trekke tilbake samtykke til bankkobling. Siden applikasjonen er privat
        og brukes av eieren selv, utøves disse rettighetene direkte i applikasjonen eller ved å
        kontakte behandlingsansvarlig. Du kan også klage til Datatilsynet
        (<a href="https://www.datatilsynet.no" target="_blank" rel="noopener">datatilsynet.no</a>).
    </p>

    <h2>10. Endringer</h2>
    <p>
        Denne erklæringen kan oppdateres ved behov. Gjeldende versjon er alltid tilgjengelig på
        denne siden, med dato for siste oppdatering øverst.
    </p>

    <h2>11. Kontakt</h2>
    <p>
        Spørsmål om personvern rettes til
        <a href="mailto:76702553+rholthe@users.noreply.github.com">76702553+rholthe@users.noreply.github.com</a>.
    </p>
@endsection
