# Finans — personlig budsjettapp (YNAB-erstatning)

Selvhostet budsjett-/økonomiapp for én bruker, med bankintegrasjon. Erstatter YNAB.
Penger som kommer inn → "Ready to Assign" → fordeles til kategorier i kategorigrupper.

## Stack

- **Backend:** Laravel 12 (PHP 8.4+) som JSON-API. SQLite lokalt, MySQL/MariaDB i prod
  (rent `.env`-styrt; migrasjonene er DB-agnostiske).
- **Frontend:** React 19 + TypeScript + React Router 7, bygget med Vite. Tailwind 4.
  Diagrammer med Recharts (kun lastet på Rapporter-siden via lazy/Suspense).
- **Auth:** Enkeltpassord (hele appen), session-cookie med 1 års levetid. Ingen brukertabell-innlogging.
- **Kø/cache:** `database`-driver (også i prod – ingen Redis nødvendig).

Frontend og backend er **samme Laravel-app, samme origin**. React-SPA-en serveres av en
catch-all blade-rute; API-et ligger under `/api/*` med session + CSRF (XSRF-TOKEN-cookie
som axios speiler tilbake automatisk).

## Kjøre lokalt

```bash
composer install && npm install
php artisan migrate
php artisan app:set-password "<ditt-passord>"   # setter APP_PASSWORD_HASH i .env
composer dev   # starter serve + Vite + queue:listen + pail samtidig
```

`composer dev` inkluderer en kø-arbeider, som trengs fordi banksynk kjøres som
en køet jobb. Kjører du bare `php artisan serve` blir synk-jobben liggende i kø.

Test: `php artisan test`

## Kjøre i prod

Prod kjører på **finans.example.com** (Apache mod_php + MariaDB; `/var/www/finans`).
Redeploy gjøres med `./deploy.sh` i prosjektroten (maintenance-modus, `git pull`,
`composer install --no-dev`, `npm ci && npm run build`, `migrate --force`,
config/route/view-cache, `queue:restart`). Kjøres som kode-eier – ingen sudo.

Køet synk + scheduler krever to ting på serveren (ingen Redis nødvendig — `database`-driver):

- **Cron:** `* * * * * cd /sti/til/app && php artisan schedule:run >> /dev/null 2>&1`
- **Kø-arbeider** under Supervisor: `php artisan queue:work --tries=3 --max-time=3600`

Scheduleren kjører nattlig banksynk og postering av planlagte transaksjoner (se `routes/console.php`).
Offentlige `/privacy` + `/terms` (frittstående Blade, utenfor login/SPA) kreves av Enable
Banking for prod-app-godkjenning.

## Struktur

- `routes/web.php` — `/api/*`-ruter + SPA catch-all
- `routes/console.php` — scheduler (nattlig banksynk-jobb + `transactions:post-due`)
- `app/Http/Controllers/` — Auth, Account, Transaction, Transfer, Reconciliation, Report, Budget,
  Category, CategoryGroup, Goal, ScheduledTransaction, Bank, Rule, Settings
- `app/Http/Middleware/Authenticated.php` — alias `auth.session`, beskytter API-ruter
- `app/Http/Middleware/EnsureScheduledTransactionsPosted.php` — posterer forfalte planlagte ved hvert API-kall
- `app/Services/Bank/` — `BankDataProvider`-grensesnitt (leverandøruavhengig, «consent»),
  `BankConsent`-DTO, `BankProviderRegistry` (velger leverandør per tilkobling),
  `GoCardlessProvider`, `EnableBankingProvider`, `NormalizedTransaction`-DTO (m/`booked`-flagg),
  `BankRateLimitException` (429), `BankSyncService`, `Mapping/` (per-bank feltmapping)
- `app/Services/Rules/` — `RuleEngine`, `RuleResult` (payee/memo + mål), `ReapplyRules` (leverandøruavhengig)
- `app/Services/` — `BudgetService` (beregner available/RTA), `GoalService`, `ScheduledTransactionService`,
  `TransferService` (oppretter overføringsben m/budsjett↔overvåket-regler – delt av manuell
  overføring, planlagt overføring og overføringsregel), `ReconciliationService` (avstemming:
  klarert saldo → justering), `ReportService` (rapportaggregeringer fra transactions;
  måneds-gruppering er DB-agnostisk via `monthExpr()` – `strftime` på SQLite, `DATE_FORMAT` på
  MySQL/MariaDB. Rå dato-SQL må alltid forgrenes på driver, ellers feiler det i prod men ikke lokalt)
- `app/Jobs/SyncBankTransactionsJob.php` — køet banksynk (WithoutOverlapping)
- `app/Support/AppSettings.php` — brukerstyrte innstillinger (nøkkel/verdi)
- `app/Enums/` — `AccountType`, `GoalType`, `ScheduleFrequency`, `RuleApplies`, `RuleTarget`
- `app/Console/Commands/` — `SetPassword`, `SyncBankTransactions` (`bank:sync`),
  `PostDueScheduledTransactions`, `ReapplyRules` (`rules:reapply`)
- `resources/js/` — React-SPA (`app.tsx` entry, `Root.tsx` ruter, `pages/`, `components/`,
  `lib/api.ts`, `lib/data.ts`, `auth.tsx`)
- `resources/views/app.blade.php` — SPA-skall; `resources/views/legal/` — offentlige personvern/vilkår

## Arkitektur-prinsipper

- **Bankleverandør bak abstraksjon:** all aggregator-tilgang (GoCardless, Enable Banking) går
  via et leverandøruavhengig `BankDataProvider`-grensesnitt → normalisert `BankConsent`/
  `NormalizedTransaction`-DTO → per-bank feltmapping. Samtykkeflyten er nøytral («consent», ikke
  GoCardless «requisition»), og status normaliseres til `BankConsent::$linked` (ikke koder som
  «LN»). Ny leverandør = ny klasse registrert i `BankProviderRegistry`; hver tilkobling lagrer
  sin `provider`, så flere leverandører kan være aktive samtidig. Ingen endring i budsjett-/
  synklogikk. Mønsteret er portet fra referanse-appen.
- **Regelmotor er leverandøruavhengig:** `RuleEngine` tar info-teksten (`bank_description`)
  + beløp og setter payee/memo + et **mål** (`RuleTarget`): `category` (konkret kategori),
  `rta` (marker som «Klar til å fordele» → `rta=true`), eller `transfer` (gjør raden om til en
  overføring til en valgt konto). Overføringsmål kan kun peke på en **ikke-synket** konto (ellers
  importerer motparten sitt eget ben → dobbeltpostering) og oppretter motpart-benet ved import;
  `ReapplyRules` hopper over overføringsmål (konvertering skjer kun ved import). Anvendes ved import
  og på et avgrenset, brukervalgt sett på kontosiden — aldri globalt. Manuelt redigerte rader er
  `locked` og overskrives aldri. **Ved overlapp vinner den mest spesifikke regelen** (flest, så
  lengst, inneholder-termer via `Rule::specificity()`, lavest id som tie-break) — det finnes ingen
  manuell prioritet eller navn på regler; Regler-siden er søk-/filtrerbar og sorteres på inneholder-teksten.
- **«available» lagres aldri** — beregnes kumulativt (assigned + activity) i `BudgetService`,
  så redigering av historikk alltid gir korrekte tall. Samme for `needed` (mål) og kommende/
  projisert (planlagte poster).
- **Mål (`GoalType`) og `needed` (`Goal::neededThisMonth`):** `monthly` = tildel et fast beløp hver
  måned (`target − tildelt denne måneden`, rullering ignoreres). `target_balance` = ha målbeløpet
  **tilgjengelig hver måned** (typisk løpende utgift som dagligvarer): `target − availableAtStart`,
  der `availableAtStart` = rullering fra forrige måned + tildelt denne (= `available − denne månedens
  activity`), så **månedens forbruk teller ikke** og man kan bruke fritt av beløpet uten at målet blir
  uoppfylt. `target_balance_by_date` = spar opp til beløp innen dato (jevn pacing mot `available`).
- **Ready to Assign = kun ukategoriserte inntekter − tildelt.** Kategorisert forbruk hører til
  kategoriens `available`, ikke RTA. Identiteten `RTA + Σtilgjengelig = penger på budsjettkonto`
  skal alltid holde (ingen lekkasje).
- **Ukategorisert skal kategoriseres:** alle ukategoriserte rader teller mot RTA med fortegn (som
  før), men en egen `transactions.rta`-kolonne skiller **bevisst plassert i RTA** (inntekt/lønn,
  avstemmingsjustering, satt av bruker/regel) fra **ikke vurdert ennå**. `rta` endrer ikke RTA-
  regnestykket. «Trenger kategorisering» = `Transaction::scopeNeedsCategorization` (on-budget ·
  `category_id` null · `rta` false · ikke overføring/startsaldo/pending) → badge per konto, filter
  på kontosiden, og et ikke-blokkerende advarsel-banner på budsjettsiden når tidligere måneder har
  ukategoriserte. «Klar til å fordele (RTA)» er et eksplisitt valg i kategori-nedtrekkene.
  Overvåkede (ikke-budsjett) kontoer har aldri kategori – kolonnen viser «ikke behov».
- **Splitt på flere kategorier:** en transaksjon kan fordeles på flere kategorier via
  `transaction_splits` (`category_id` + signert `amount` + memo). **Pengeraden forblir én rad** i
  `transactions` (saldo/avstemming/RTA-identitet uberørt); splitt-forelderen får `category_id=null`
  og `is_split=true`. Σ splittbeløp må være lik transaksjonsbeløpet med samme fortegn (min. 2
  linjer), valideres i `TransactionController::syncSplits`. Invarianten forsvares også ved redigering:
  `TransactionController::update` avviser (422) å endre `amount` på en splittet rad uten at nye
  `splits` følger med (med mindre raden samtidig av-splittes via `category_id`/`rta`) – ellers ville
  splittsummen desynke fra pengeraden og budsjettet komme i ubalanse. Kun manuelt (etter postering) –
  regler, planlagte og banksynk lager aldri splitter. Kategori-aktivitet (budsjett + rapporter) leses fra
  `Transaction::categoryActivity($from, $to)` (UNION av direkte kategoriserte rader + splittlinjer; et
  valgfritt dato-intervall pushes inn i begge union-grenene så `transactions`-indeksene brukes), så splitter
  telles uten dobbelttelling; splitt-foreldre ekskluderes fra ukategorisert/RTA/inntekt (`is_split`).
  Overføringer kan splittes **kun** på det kategoriserte budsjett→overvåket-benet; øvrige ben røres ikke.
- **Bokført vs reservert:** banksynk henter både bokførte og reserverte (`NormalizedTransaction.
  booked`). Reservert = `pending=true`/`cleared=false` (teller i activity, ikke i avstemming).
  Bokførte dedup'es på `external_id`; reserverte mangler stabil id, så kontoens ulåste reserverte
  rader byttes ut med dagens reserverte sett ved hver synk (en post som er bokført siden sist
  forsvinner og kommer inn som bokført = «oppdatert ved bokføring»).
- **Kredittkort = vanlig budsjettkonto som kan ha negativ saldo.** Ingen egen
  betalingskategori. Et kjøp på kortet er et helt vanlig kategorisert forbruk (trekker
  kategoriens `available`, ikke RTA), og gjelda reduserer «penger på konto». Kortet betales
  ned med en overføring fra en annen konto. (En YNAB-stil betalingskategori ble vurdert og
  forkastet til fordel for denne enkelheten.)
- **Avstemming** gjør klarert saldo (sum av `cleared`-transaksjoner) lik oppgitt faktisk
  banksaldo ved å bokføre en **ukategorisert** «Avstemmingsjustering» for avviket. Justeringen
  påvirker dermed RTA på budsjettkontoer (positivt avvik øker, negativt reduserer), mens
  identiteten RTA + Σtilgjengelig = penger på konto holder. Alle klarerte rader stemples
  `reconciled_at`; avstemte rader kan fortsatt redigeres, men frontend varsler først. Historikk
  lagres i `reconciliations` (`ReconciliationService`).
- **Overføringer** er to sammenkoblede transaksjoner (`transactions.transfer_id`), opprettet via
  `TransferService`. Kategori avhenger av budsjettgrensen: budsjett↔budsjett og overvåket↔overvåket
  er RTA-nøytrale (ingen kategori); **budsjett→overvåket** er kategorisert forbruk (krever kategori
  på budsjett-benet); **overvåket→budsjett** er tilflyt (budsjett-benet får `rta=true`). Begge ben
  er `locked` (kan ikke redigeres) og slettes samlet. Brukes bl.a. til å betale ned kredittkort
  (budsjett↔budsjett). Manuell overføring, planlagt overføring og overføringsregel deler samme tjeneste.
- **Payee-utledning (Enable Banking):** banken legger ofte ikke noe i de strukturerte
  `creditor`/`debtor`-feltene, men putter motpartsnavnet i en `Overføring Innland/Utland, <navn>`-
  linje i `remittance_information` (resten er kolonnepadet referansetekst). `EnableBankingProvider`
  setter derfor payee til det strukturerte navnet når det finnes, ellers navnet fra den linja; all
  whitespace-padding kollapses til enkle mellomrom så lengdegrensen ikke spises opp. `description`/
  `memo` (regelmotorens matchegrunnlag) beholder fortsatt all tekst.
- **Banksynk:** deduplisering per `account_id:external_id` (samme external_id kan gjelde flere
  kontoer). Rapport-e-post sendes ved både suksess og feil til `AppSettings::reportEmail()` –
  innstillingen `report_email` (satt under Innstillinger) vinner, med `BANK_SYNC_REPORT_EMAIL` i
  config som legacy-fallback. GoCardless
  oppgir rate-limit i headere (`X-RateLimit-Account-Success-*`); Enable Banking gjør **ikke** det –
  kun `429` signaliserer grensen, som fanges (`BankRateLimitException`) og markerer kontoen ikke-
  synkbar til neste runde.
- **Samtykkeutløp + fornying:** et bank-samtykke varer typisk 90 dager. `BankConsent` bærer en
  normalisert `expiresAt` (GoCardless: end-user-agreement `accepted` + `access_valid_for_days`;
  Enable Banking: session-ens `access.valid_until`), som lagres som `bank_connections.valid_until`
  ved tilkobling og oppdateres ved hver synk. `bank:check-expiry` (scheduler, daglig 06:00) sender
  e-post til `AppSettings::reportEmail()` når utløp er < `BANK_CONSENT_EXPIRY_WARNING_DAYS` (default 7)
  unna – idempotent via `expiry_notified_at` (nullstilles når `valid_until` flyttes). **Fornying**
  (`POST bank/connections/{id}/renew` → ny samtykkeflyt merket i økten med `bank_renew_connection_id`)
  gjenbruker den eksisterende tilkoblingen i callback: oppdaterer consent/utløp og re-mapper nye
  eksterne konto-id-er til eksisterende `bank_accounts` via IBAN, så budsjettkoblingene overlever.
- **E-poster:** `SyncReportMail` + `ExpiringConsentMail` er stylede markdown-mail (panel/tabell/
  knapp). Databasen lagres i **UTC**; tidspunkter konverteres kun ved *visning* til
  `config('app.display_timezone')` (`APP_DISPLAY_TIMEZONE`, default `Europe/Oslo`) med tidssone-
  etikett (CET/CEST).
- **Ingen YNAB-lengdegrenser** på payee/memo lenger. **Kun NOK** i første omgang.
- **Mobil hurtigregistrering:** `MobilRegistrer` (`/registrer`) er mobilens landingsside – åpnes
  appen på smal skjerm (< `md`) sendes man én gang dit fra `/` (engangsflagg `mobileLandingDone` i
  `Root.tsx`, nullstilles ved full sideinnlasting); etterpå viser `/` budsjettet som normalt, og en
  «Registrer»-lenke (`md:hidden`) finnes i menyen. Siden har store knapper for å føre en transaksjon
  på en **ikke-banksynket budsjettkonto** (kun `on_budget && !bank_synced && !closed` er velgbare –
  overvåkede/synkede ekskluderes), dato forvalgt til i dag, obligatorisk mottaker + beløp (retning
  Utgift/Inntekt → fortegn), valgfritt notat, og en bekreftelses-modal før lagring. Lager en
  ukategorisert transaksjon (kategoriseres senere på desktop). Vises ikke på stor skjerm.

## Faseplan

0. ✅ Fundament: auth, layout, API, tester
1. ✅ Manuelt regnskap: kontoer (cash/bank/saving/credit/loan, aktiv vs overvåket), transaksjoner, saldoer
2. ✅ Budsjettmotor: kategorigrupper/kategorier, månedlig tildeling, activity/available, Ready to Assign
3. ✅ Mål + auto-allokering (sparemål, fyll opp til mål / dekk overtrekk)
4. ✅ Bankintegrasjon (GoCardless bak `BankDataProvider`)
5. ✅ Auto-kategorisering (regelmotor: payee + memo + kategori, med avgrenset anvendelse + lås)
6. ✅ Nattlig sync-jobb (kø + scheduler) + innstillinger (synk-dager + rapport-e-post) + gjenstående synk
7. ✅ Planlagte/repeterende transaksjoner (regningsmodul: frekvens, auto-postering, projeksjon).
   Støtter også planlagte **overføringer** (`transfer_account_id`) og RTA-mål.
8. 🟡 Avansert:
   - ✅ Kredittkort som vanlig konto (kan ha negativ saldo) + overføringer for nedbetaling
   - ✅ Avstemming (reconciliation)
   - ✅ Rapporter (forbruk per kategori, inntekt/forbruk, kategoritrend, nettoformue – Recharts)
   - ✅ 2. bankleverandør (Enable Banking; normalisert consent-grensesnitt + provider-kolonne;
     bokført/reservert + 429-håndtering)
9. 🟡 Tverrgående: design/UX-polish + brukertilbakemeldinger (side for side)
   - Konvensjon: inkrementell polish (ikke full redesign), desktop-først med
     grasiøs degradering, bulk-handlinger deaktiveres når ingenting er valgt
   - ✅ Budsjettsiden: seleksjon (avkrysning per kategori/gruppe/alle), avgrenset
     auto-allokering + bulk-flytt/nullstill, sticky header, badges, tydeligere
     skille mellom kategorigrupper/kategorier, advarsel om ukategoriserte fra tidligere måneder;
     ny kategorigruppe + nullstill-bekreftelse som `Modal` (ingen `prompt()`/`confirm()`).
     **Fortid er lesemodus:** mål gjelder kun inneværende/fremtidige måneder, så for `month <
     currentMonth()` skjules mål-UI, bulk-/auto-knapper og seleksjon – kun tildelt/forbruk/
     tilgjengelig vises, og endringer gjøres manuelt i radene (tildelt-input, flytt, navn).
   - ✅ Ukategorisert-håndhevelse: `rta`-kolonne, badge per konto + filter, «Klar til å
     fordele»-valg, varsel i avstemmingsmodal
   - ✅ Overføringer: budsjett↔overvåket-kategorisering via `TransferService`, egen
     «Overføring»-kolonne, alle overføringer låst, overvåkede kontoer uten kategori
   - ✅ Regel-mål: kategori / RTA / overføring (`RuleTarget`)
   - ✅ Regler-siden: søk (inneholder/ikke/payee/memo) + samlet målfilter, sorterbar på inneholder-tekst
     eller sist brukt, komprimerte rader med mål-/gjelder-badges + «sist brukt»-dato, stylet ny/rediger-
     modal og slett-bekreftelse (ingen `confirm()`); fjernet manuell prioritet/reorder og regelnavn (mest
     spesifikk regel vinner). `rules.last_applied_at` stemples av `RuleEngine` når en regel vinner
     (`saveQuietly`), eksponeres i `RuleResource`.
   - ✅ Kontodetaljsiden: hero-kort (accent budsjett/overvåket), slett- og «endre
     avstemt»-bekreftelse som modaler (ingen `confirm()`); inline kategori-nedtrekk
     (ukategorisert/RTA/kategori) direkte i raden for vanlige transaksjoner – splitt,
     overføringer og øvrige felt går fortsatt via rediger-dialogen; avstemte rader varsles først
   - ✅ Bank-siden: hero-chips m/utløpsbadge + «Forny», stylet synk-resultatkort,
     frakoblings-modal, fjernet overflødig «ignorer»-sjekkboks; inline visningsnavn på
     bank (`bank_connections.name`) og bankkonto (`bank_accounts.name`, fall tilbake på
     iban/external_id via `BankAccount::displayName()`) – brukt i frontend + synk-/utløps-e-poster
   - ✅ Innstillinger: rapport-e-post-mottaker (flyttet fra env); stylet med seksjonskort
     (ikon-headere for banksynk/e-post), undertittel og inline lagre-tilbakemelding
   - Konvensjon: bekreftelser bruker `Modal`-komponenten, ikke nettleserens `confirm()`

===

<laravel-boost-guidelines>
=== foundation rules ===

# Laravel Boost Guidelines

The Laravel Boost guidelines are specifically curated by Laravel maintainers for this application. These guidelines should be followed closely to ensure the best experience when building Laravel applications.

## Foundational Context

This application is a Laravel application and its main Laravel ecosystems package & versions are below. You are an expert with them all. Ensure you abide by these specific packages & versions.

- php - 8.4
- laravel/framework (LARAVEL) - v12
- laravel/prompts (PROMPTS) - v0
- laravel/boost (BOOST) - v2
- laravel/mcp (MCP) - v0
- laravel/pail (PAIL) - v1
- laravel/pint (PINT) - v1
- laravel/sail (SAIL) - v1
- phpunit/phpunit (PHPUNIT) - v11
- react (REACT) - v19
- tailwindcss (TAILWINDCSS) - v4

## Skills Activation

This project has domain-specific skills available in `**/skills/**`. You MUST activate the relevant skill whenever you work in that domain—don't wait until you're stuck.

## Conventions

- You must follow all existing code conventions used in this application. When creating or editing a file, check sibling files for the correct structure, approach, and naming.
- Use descriptive names for variables and methods. For example, `isRegisteredForDiscounts`, not `discount()`.
- Check for existing components to reuse before writing a new one.

## Verification Scripts

- Do not create verification scripts or tinker when tests cover that functionality and prove they work. Unit and feature tests are more important.

## Application Structure & Architecture

- Stick to existing directory structure; don't create new base folders without approval.
- Do not change the application's dependencies without approval.

## Frontend Bundling

- If the user doesn't see a frontend change reflected in the UI, it could mean they need to run `npm run build`, `npm run dev`, or `composer run dev`. Ask them.

## Documentation Files

- You must only create documentation files if explicitly requested by the user.

## Replies

- Be concise in your explanations - focus on what's important rather than explaining obvious details.

=== boost rules ===

# Laravel Boost

## Tools

- Laravel Boost is an MCP server with tools designed specifically for this application. Prefer Boost tools over manual alternatives like shell commands or file reads.
- Use `database-query` to run read-only queries against the database instead of writing raw SQL in tinker.
- Use `database-schema` to inspect table structure before writing migrations or models.
- Use `get-absolute-url` to resolve the correct scheme, domain, and port for project URLs. Always use this before sharing a URL with the user.
- Use `browser-logs` to read browser logs, errors, and exceptions. Only recent logs are useful, ignore old entries.

## Searching Documentation (IMPORTANT)

- Always use `search-docs` before making code changes. Do not skip this step. It returns version-specific docs based on installed packages automatically.
- Pass a `packages` array to scope results when you know which packages are relevant.
- Use multiple broad, topic-based queries: `['rate limiting', 'routing rate limiting', 'routing']`. Expect the most relevant results first.
- Do not add package names to queries because package info is already shared. Use `test resource table`, not `filament 4 test resource table`.

### Search Syntax

1. Use words for auto-stemmed AND logic: `rate limit` matches both "rate" AND "limit".
2. Use `"quoted phrases"` for exact position matching: `"infinite scroll"` requires adjacent words in order.
3. Combine words and phrases for mixed queries: `middleware "rate limit"`.
4. Use multiple queries for OR logic: `queries=["authentication", "middleware"]`.

## Artisan

- Run Artisan commands directly via the command line (e.g., `php artisan route:list`). Use `php artisan list` to discover available commands and `php artisan [command] --help` to check parameters.
- Inspect routes with `php artisan route:list`. Filter with: `--method=GET`, `--name=users`, `--path=api`, `--except-vendor`, `--only-vendor`.
- Read configuration values using dot notation: `php artisan config:show app.name`, `php artisan config:show database.default`. Or read config files directly from the `config/` directory.

## Tinker

- Execute PHP in app context for debugging and testing code. Do not create models without user approval, prefer tests with factories instead. Prefer existing Artisan commands over custom tinker code.
- Always use single quotes to prevent shell expansion: `php artisan tinker --execute 'Your::code();'`
  - Double quotes for PHP strings inside: `php artisan tinker --execute 'User::where("active", true)->count();'`

=== php rules ===

# PHP

- Always use curly braces for control structures, even for single-line bodies.
- Use PHP 8 constructor property promotion: `public function __construct(public GitHub $github) { }`. Do not leave empty zero-parameter `__construct()` methods unless the constructor is private.
- Use explicit return type declarations and type hints for all method parameters: `function isAccessible(User $user, ?string $path = null): bool`
- Use TitleCase for Enum keys: `FavoritePerson`, `BestLake`, `Monthly`.
- Prefer PHPDoc blocks over inline comments. Only add inline comments for exceptionally complex logic.
- Use array shape type definitions in PHPDoc blocks.

=== deployments rules ===

# Deployment

- Laravel can be deployed using [Laravel Cloud](https://cloud.laravel.com/), which is the fastest way to deploy and scale production Laravel applications.

=== tests rules ===

# Test Enforcement

- Every change must be programmatically tested. Write a new test or update an existing test, then run the affected tests to make sure they pass.
- Run the minimum number of tests needed to ensure code quality and speed. Use `php artisan test --compact` with a specific filename or filter.

=== laravel/core rules ===

# Do Things the Laravel Way

- Use `php artisan make:` commands to create new files (i.e. migrations, controllers, models, etc.). You can list available Artisan commands using `php artisan list` and check their parameters with `php artisan [command] --help`.
- If you're creating a generic PHP class, use `php artisan make:class`.
- Pass `--no-interaction` to all Artisan commands to ensure they work without user input. You should also pass the correct `--options` to ensure correct behavior.

### Model Creation

- When creating new models, create useful factories and seeders for them too. Ask the user if they need any other things, using `php artisan make:model --help` to check the available options.

## APIs & Eloquent Resources

- For APIs, default to using Eloquent API Resources and API versioning unless existing API routes do not, then you should follow existing application convention.

## URL Generation

- When generating links to other pages, prefer named routes and the `route()` function.

## Testing

- When creating models for tests, use the factories for the models. Check if the factory has custom states that can be used before manually setting up the model.
- Faker: Use methods such as `$this->faker->word()` or `fake()->randomDigit()`. Follow existing conventions whether to use `$this->faker` or `fake()`.
- When creating tests, make use of `php artisan make:test [options] {name}` to create a feature test, and pass `--unit` to create a unit test. Most tests should be feature tests.

## Vite Error

- If you receive an "Illuminate\Foundation\ViteException: Unable to locate file in Vite manifest" error, you can run `npm run build` or ask the user to run `npm run dev` or `composer run dev`.

=== laravel/v12 rules ===

# Laravel 12

- CRITICAL: ALWAYS use `search-docs` tool for version-specific Laravel documentation and updated code examples.
- Since Laravel 11, Laravel has a new streamlined file structure which this project uses.

## Laravel 12 Structure

- In Laravel 12, middleware are no longer registered in `app/Http/Kernel.php`.
- Middleware are configured declaratively in `bootstrap/app.php` using `Application::configure()->withMiddleware()`.
- `bootstrap/app.php` is the file to register middleware, exceptions, and routing files.
- `bootstrap/providers.php` contains application specific service providers.
- The `app/Console/Kernel.php` file no longer exists; use `bootstrap/app.php` or `routes/console.php` for console configuration.
- Console commands in `app/Console/Commands/` are automatically available and do not require manual registration.

## Database

- When modifying a column, the migration must include all of the attributes that were previously defined on the column. Otherwise, they will be dropped and lost.
- Laravel 12 allows limiting eagerly loaded records natively, without external packages: `$query->latest()->limit(10);`.

### Models

- Casts can and likely should be set in a `casts()` method on a model rather than the `$casts` property. Follow existing conventions from other models.

=== pint/core rules ===

# Laravel Pint Code Formatter

- If you have modified any PHP files, you must run `vendor/bin/pint --dirty --format agent` before finalizing changes to ensure your code matches the project's expected style.
- Do not run `vendor/bin/pint --test --format agent`, simply run `vendor/bin/pint --format agent` to fix any formatting issues.

=== phpunit/core rules ===

# PHPUnit

- This application uses PHPUnit for testing. All tests must be written as PHPUnit classes. Use `php artisan make:test --phpunit {name}` to create a new test.
- If you see a test using "Pest", convert it to PHPUnit.
- Every time a test has been updated, run that singular test.
- When the tests relating to your feature are passing, ask the user if they would like to also run the entire test suite to make sure everything is still passing.
- Tests should cover all happy paths, failure paths, and edge cases.
- You must not remove any tests or test files from the tests directory without approval. These are not temporary or helper files; these are core to the application.

## Running Tests

- Run the minimal number of tests, using an appropriate filter, before finalizing.
- To run all tests: `php artisan test --compact`.
- To run all tests in a file: `php artisan test --compact tests/Feature/ExampleTest.php`.
- To filter on a particular test name: `php artisan test --compact --filter=testName` (recommended after making a change to a related file).

</laravel-boost-guidelines>
