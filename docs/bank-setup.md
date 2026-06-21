# Bank setup

Finans pulls transactions through a **bank aggregator** (an account-information
provider regulated under PSD2). You can use either or both of:

- **GoCardless Bank Account Data** (formerly Nordigen)
- **Enable Banking**

Each connection stores its own provider, so you can mix them — e.g. one bank via
GoCardless and another via Enable Banking. The bank integration is **optional**;
you can run Finans fully manually without configuring either.

After configuring a provider's credentials in `.env`, the actual connecting
happens **in the app**, on the **Bank** page (described under
[Connecting a bank in the app](#connecting-a-bank-in-the-app)).

> Provider dashboards change their wording and layout over time. The steps below
> reflect the concepts each provider uses; if a label differs, look for the
> equivalent. Authoritative docs:
> [GoCardless Bank Account Data](https://developer.gocardless.com/bank-account-data/),
> [Enable Banking](https://enablebanking.com/docs/).

---

## Which provider should I choose?

| | GoCardless Bank Account Data | Enable Banking |
|---|---|---|
| Cost | Free tier | Free tier for **personal, non-commercial** use |
| Auth | Secret ID + Secret Key | Self-signed RSA **JWT** (app ID + private key) |
| Approval needed | No (works immediately) | Yes — app must be reviewed; needs public privacy/terms pages |
| Rate limit | ~4 requests/endpoint/day per real bank account | PSD2 ~4 unattended accesses/day per account |
| Consent length | ~90 days, then re-consent | ~90–180 days (bank-dependent), with renewal |

**If you already have a GoCardless account, start there** — it's the fastest to
get running. Enable Banking is a good second provider or an alternative if a bank
you need isn't available on GoCardless.

---

## Option A — GoCardless Bank Account Data

### 1. Create an account and get API secrets

1. Sign up for **GoCardless Bank Account Data** (the *Bank Account Data* product,
   not the payments product) at
   <https://gocardless.com/bank-account-data/>.
2. In the developer portal, open **Developers → User secrets** (or "Secrets")
   and **create a new secret**.
3. Copy the **Secret ID** and **Secret Key**. The key is shown once — store it
   safely.

### 2. Configure `.env`

```dotenv
GOCARDLESS_BASE_URI=https://bankaccountdata.gocardless.com/api/v2
GOCARDLESS_SECRET_ID=your-secret-id
GOCARDLESS_SECRET_KEY=your-secret-key
GOCARDLESS_REDIRECT_URI="${APP_URL}/api/bank/callback"
# Used for testing with the always-available sandbox bank:
GOCARDLESS_SANDBOX_BANK=SANDBOXFINANCE_SFIN0000
```

- `GOCARDLESS_REDIRECT_URI` is where the bank sends the user back after
  authorizing. It must be a URL your app is reachable at, ending in
  `/api/bank/callback`. Locally that's e.g. `http://localhost:8000/api/bank/callback`;
  in production your real domain.
- Finans exchanges the secrets for a short-lived access token automatically; you
  don't manage tokens yourself.

### 3. Test with the sandbox bank (recommended first)

Real banks have a **low rate limit (~4 requests per endpoint per day)**, so do
your first end-to-end test against the **sandbox bank**
(`SANDBOXFINANCE_SFIN0000`), which has no such limit. Select it on the Bank page
like any other bank; it returns synthetic transactions.

### 4. Rate limits & consent

- Each **real** bank account allows roughly **4 data requests per endpoint per
  day**. Finans reads GoCardless's `X-RateLimit-*` headers and shows the
  remaining count per account on the Bank page; it skips accounts that are out of
  quota until the window resets.
- A consent typically lasts **~90 days**. Finans stores the expiry, warns you by
  email before it lapses, and offers **Renew** on the Bank page.

---

## Option B — Enable Banking (personal use)

Enable Banking authenticates each API request with a **self-signed RSA JWT**
(no token exchange): you register an application, download its **private key**,
and Finans signs requests with it (using the application ID as the JWT `kid`).

### 1. Register an application

1. Sign up at <https://enablebanking.com/> and open the **Control Panel**.
2. **Create an application.** Choose the **personal / non-commercial** use case.
3. Set the application's **redirect URL** to your callback:
   `https://your-domain/api/bank/callback` (and/or your local URL for testing).
   This must match `ENABLEBANKING_REDIRECT_URI` exactly.
4. **Generate and download the RSA private key** (a `.pem` file). Note the
   **Application ID** shown for the app.

### 2. Provide public privacy & terms pages (required for approval)

Enable Banking reviews applications before they can access real banks and
requires **publicly reachable privacy and terms pages**. Finans serves these as
standalone pages — once deployed they're available at:

- `https://your-domain/privacy`
- `https://your-domain/terms`

Give those URLs to Enable Banking during app registration/approval. (You can
customize the content in `resources/views/legal/`.)

### 3. Configure `.env`

```dotenv
ENABLEBANKING_BASE_URI=https://api.enablebanking.com
ENABLEBANKING_APPLICATION_ID=your-application-id
# The private key: either the PEM contents (with \n line breaks) OR a file path
ENABLEBANKING_PRIVATE_KEY="-----BEGIN PRIVATE KEY-----\n...\n-----END PRIVATE KEY-----\n"
# ...or, preferred in production, a path to the .pem outside the web root:
# ENABLEBANKING_PRIVATE_KEY=/etc/finans/enablebanking.pem
ENABLEBANKING_REDIRECT_URI="${APP_URL}/api/bank/callback"
ENABLEBANKING_COUNTRY=NO
```

> Keep the private key out of version control. A file path outside the web root
> is safer than inlining the PEM in `.env`.

### 4. The `psu-ip-address` header (important for unattended sync)

Under PSD2, some banks require a **`psu-ip-address`** header and will reject
requests without it — returning `422 PSU_HEADER_NOT_PROVIDED` or a generic
`400 ASPSP_ERROR` on the **nightly (unattended) sync**, even though the first
sync right after connecting worked.

- During the **interactive** connect/renew flow, Finans automatically sends the
  real user's IP.
- For the **unattended** nightly sync there is no user present, so Finans falls
  back to a configured IP. Set it to your **server's outbound public IP**:

  ```dotenv
  ENABLEBANKING_PSU_IP=203.0.113.10
  ENABLEBANKING_PSU_USER_AGENT=Finans/1.0
  ```

  Find your server's outbound IP with `curl -s https://api.ipify.org`. Leave
  `ENABLEBANKING_PSU_IP` empty if you don't need it (no header is sent — the
  prior behavior).

After changing these in production, rebuild the config cache and restart the
worker so the change takes effect:

```bash
php artisan config:cache && php artisan queue:restart
```

You can inspect what a given bank requires with the built-in diagnostic command:

```bash
php artisan bank:aspsp-metadata --filter=<bank-name>
```

It dumps the raw ASPSP metadata, including `required_psu_headers`,
`maximum_consent_validity`, and whether the integration is flagged `beta`.

### 5. Rate limits & consent

- Enable Banking does **not** expose rate-limit headers. PSD2 allows roughly **4
  unattended accesses per account per day**; exceeding that returns `429`, and
  Finans marks the account as temporarily un-syncable until the next day.
- Consents last **~90–180 days** (bank-dependent). Finans stores the expiry,
  warns by email before it lapses, and supports **Renew** (which keeps your
  account links intact by re-matching on IBAN).

---

## Connecting a bank in the app

Once at least one provider is configured in `.env` (and, for Enable Banking,
your app is approved):

1. Log in and open the **Bank** page.
2. Choose the **provider** and the **bank**, then start the connection.
3. You're redirected to your bank to authorize read access (this is the PSD2
   consent / strong customer authentication step).
4. After authorizing you're returned to Finans, which stores the connection and
   discovers your accounts.
5. **Link each bank account to a budget account** (or to a tracking account).
   Unlinked accounts are skipped during sync. You can also mark an account as
   *ignored*.
6. Trigger a manual sync, or wait for the nightly job. Imported transactions land
   uncategorized (or are categorized by your [rules](#tips)); review and assign
   them on the account page and in the budget.

### Tips

- **Sync runs as a queued job.** Locally, use `composer dev` (it includes a
  queue worker). In production you need a persistent worker (see the main
  [README](../README.md#production-deployment)). Without a worker, syncs sit in
  the queue and nothing imports.
- **Rules** (the *Regler* page) auto-set payee/memo and a target — a category,
  *Ready to Assign*, or a transfer — based on the bank's description text. The
  most specific matching rule wins. Manually edited rows are locked and never
  overwritten.
- **Booked vs. pending:** Finans imports both. Pending (reserved) transactions
  count toward activity but not reconciliation, and are replaced on each sync
  until the bank books them.

---

## Troubleshooting

| Symptom | Likely cause & fix |
|---|---|
| First sync works, later syncs fail with `ASPSP_ERROR` / `PSU_HEADER_NOT_PROVIDED` (Enable Banking) | Bank requires `psu-ip-address` on unattended access. Set `ENABLEBANKING_PSU_IP` to the server's outbound IP, then `config:cache && queue:restart`. |
| `.env` change had no effect in production | Config is cached and the queue worker is long-lived. Run `php artisan config:cache && php artisan queue:restart`. |
| "0 sync left today" / account skipped | Rate limit reached for that account. GoCardless: ~4/endpoint/day; Enable Banking: ~4 unattended/day. Wait for the daily reset. |
| Nothing imports, no error | No queue worker running. Start one (`composer dev` locally, Supervisor in prod). |
| Consent expired | Use **Renew** on the Bank page to re-authorize without losing your account links. |
| Bank not in the list | It may only be available on the *other* provider — configure both and pick the one that lists it. |
