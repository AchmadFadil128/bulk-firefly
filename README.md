# Bulk Transaction Firefly III

A small PHP + HTML/CSS/vanilla JavaScript app for entering many Firefly III transactions. It uses the Firefly III v6.6.2 API shape: account/category autocomplete endpoints and `POST /v1/transactions`.

## Features

- Dynamic rows, with add/delete controls.
- Paste a tab-separated block copied from Excel or Google Sheets.
- Source/destination dropdowns loaded from Firefly III.
- Automatic type detection:
  - **Withdrawal:** Asset account → Expense account
  - **Deposit:** Revenue account → Asset account
  - **Transfer:** Asset account → Asset account
- Category is optional; it is omitted for transfers. Transactions between accounts with different currencies are rejected because this simple form does not collect foreign-currency amounts.
- Each row is sent as its own API request so a row-level validation error does not stop the rest. If the API connection, authentication, rate limit, or server fails globally, remaining rows are marked not processed to avoid a long run of doomed requests.
- Row-by-row success/failure details. Successful rows become locked so a later retry does not resend them.
- Firefly III duplicate-hash protection is enabled for each submission.
- No app login page: the intended external access gate is Cloudflare Access.
- API token stays in the PHP container and is never sent to the browser.

## Requirements

- Docker Engine and Docker Compose plugin.
- A Firefly III Personal Access Token with permission to create transactions and read accounts/categories.
- A Cloudflare Tunnel and Cloudflare Access application/policy for the **bulk app hostname**.

## 1. Configure the API token

From this directory:

```bash
mkdir -p secrets
printf '%s' 'PASTE_FIREFLY_III_PERSONAL_ACCESS_TOKEN_HERE' > secrets/firefly_api_token
sudo chown root:33 secrets/firefly_api_token secrets/firefly_cf_access_client_id secrets/firefly_cf_access_client_secret
sudo chmod 640 secrets/firefly_api_token secrets/firefly_cf_access_client_id secrets/firefly_cf_access_client_secret
cp .env.example .env
```

Replace the example text with the real token; do not include quotes or a newline if possible. Never commit `secrets/firefly_api_token`, either Cloudflare service-token file, or `.env` to Git. The two Cloudflare service-token files may remain empty if the Firefly III hostname does not require Cloudflare Access for server-to-server API calls.

The default API base is:

```text
https://finance.achmad128.my.id/api
```

The app appends `/v1/...`, so it will call URLs such as `https://finance.achmad128.my.id/api/v1/autocomplete/accounts`. If your Firefly III is installed at another URL or under a subpath, edit `FIREFLY_BASE_URL` in `.env`. Do not put the token in `.env`.

If `finance.achmad128.my.id` itself is protected by Cloudflare Access, create a Cloudflare Access Service Token for backend-to-Firefly API calls and put its Client ID in `secrets/firefly_cf_access_client_id` and Client Secret in `secrets/firefly_cf_access_client_secret`. Add a **Service Auth** allow policy for that token to the Access application protecting the Firefly III hostname; do not disable Access globally. These files are separate from the identity-based Access policy that protects the bulk app hostname. Leave both files empty if the Firefly API hostname is not protected by Access. The backend sends these headers only when both files contain values.

## 2. Build and run

Production-style Compose does **not** publish a host port. The app is only reachable by containers connected to the Docker network `bulk-firefly-net`:

```bash
docker compose up -d --build
docker compose ps
docker compose logs -f bulk-firefly
```

The container exposes port `8080` only on the Docker network. It uses a read-only root filesystem and temporary writable mounts for Apache/PHP runtime files.

## 3. Connect your existing Cloudflare Tunnel

Use a separate hostname for this app. The hostname `finance.achmad128.my.id` is configured as the Firefly III API origin, so do not point the bulk app at the same hostname unless you intentionally want to replace that hostname's current service. For example, you could use `bulk-finance.achmad128.my.id` if that hostname is available and configured in your Cloudflare zone.

1. Create a distinct public hostname for the bulk app in Cloudflare Tunnel, for example `bulk-finance.achmad128.my.id` if available in your Cloudflare zone.
2. Configure the Tunnel service/origin as:

   ```text
   http://bulk-firefly:8080
   ```

3. Ensure the existing `cloudflared` container is connected to the same Docker network. Find its container name, then run:

   ```bash
   docker network connect bulk-firefly-net <cloudflared-container-name>
   ```

   If it is already connected, Docker may report that it is already connected; that is fine. For a persistent setup, add `bulk-firefly-net` as an external network to the existing cloudflared service in its Compose file, then recreate that service. If your Tunnel runs directly on the host rather than in a container, use `compose.host-tunnel.yaml` (instructions below) and point the Tunnel at `http://127.0.0.1:8088`. Do not expose port 8080 publicly.

4. Create a Cloudflare Access self-hosted application for the **bulk app hostname**, with an allow policy limited to your identity/email. Protect the hostname before sharing it.
5. Ensure Cloudflare WAF/security rules are enabled as appropriate for that hostname. Cloudflare Access is the authentication gate; WAF is an additional layer, not a replacement for Access.
6. Open the new bulk app hostname. The browser should be redirected to Cloudflare Access before the app is served.

The app uses a same-origin CSRF token and checks the `Origin` header on transaction writes. Do not publish the PHP origin directly around Cloudflare Access. The Compose file intentionally has no `ports:` mapping in its production configuration.

## 4. If cloudflared runs directly on the host

Use the production override to bind the app to loopback only while keeping production cookie/security settings:

```bash
docker compose -f compose.yaml -f compose.host-tunnel.yaml up -d --build
```

Configure the host's cloudflared origin as `http://127.0.0.1:8088`. Do not use this variant if your `cloudflared` runs in a container; use the shared Docker network setup above instead.

## 5. Local smoke test (optional)

For a temporary local-only test, use the override file. It binds to `127.0.0.1`, not all network interfaces:

```bash
docker compose -f compose.yaml -f compose.local.yaml up -d --build
```

Open `http://127.0.0.1:8088`. Stop it with:

```bash
docker compose -f compose.yaml -f compose.local.yaml down
```

The local override changes the session cookie setting for HTTP testing. Do not use the local override on a public server. API calls still go to the configured Firefly III URL and require a valid token.

## Spreadsheet paste format

Paste six columns in this order (no header required):

```text
125000	Belanja bulanan	BCA	Belanja	Kebutuhan rumah	2026-10-20
2500000	Gaji	Perusahaan	BCA	Gaji	20/10/2026
50000	Transfer antar rekening	BCA	Cash		2026-10-20
```

Use actual account and category names from Firefly III. Account/category names must match exactly; ambiguous duplicate names should be selected manually in the table. Supported date formats include `YYYY-MM-DD` and `DD/MM/YYYY`. For amounts, plain digits are safest (e.g. `125000`); decimal input such as `125000,50` is also supported. A value like `125.000` is interpreted as 125000 (thousands separator).

The app starts with three empty rows. Empty rows are ignored. The browser sends pending rows in groups of 25; the backend accepts at most 100 rows per request and the table can hold up to 500 rows. Transactions that succeed are locked in the table. If a request loses its connection, inspect Firefly III's transaction list before retrying because the server may have accepted the transaction even if the browser did not receive the response.

## Security notes

- Keep the Firefly III token in `secrets/firefly_api_token`; never place it in frontend code, HTML, query strings, Git, or screenshots.
- Keep the API token scoped to the minimum permissions required by your Firefly III setup.
- Do not publish the application container's port directly to the internet. Use the Tunnel network and Cloudflare Access.
- Cloudflare Access policy should be restrictive (allow only your intended identity); protect the bulk hostname separately from the Firefly III hostname.
- WAF does not replace authentication, token secrecy, server-side validation, or backups.
- This app creates real financial records. Test with one small transaction first.
- Duplicate-hash protection is enabled. Firefly III may reject a repeated transaction as a duplicate; review the response before retrying.
- This app does not persist a local transaction history or store financial data in a database. Firefly III is the source of truth.

## Troubleshooting

### `Token API Firefly III tidak ditemukan`
Check that `secrets/firefly_api_token` exists, contains the token, and has readable permissions for Docker to mount it. Recreate the container after changing the secret.

### HTTP 401 from Firefly III
Check the token, the API URL, and token permissions. The expected base URL includes `/api` but not `/v1`.

### No accounts/categories appear
Check the app logs and verify that the token can read autocomplete accounts and categories. The app only lists Asset, Expense, and Revenue account types; liability and special accounts are intentionally excluded. Cross-currency transactions are rejected to avoid guessing conversion amounts.

### Cloudflare Tunnel cannot resolve `bulk-firefly`
Make sure the `cloudflared` container and `bulk-firefly` are on the same Docker network (`bulk-firefly-net`) and that the Tunnel service points to `http://bulk-firefly:8080`.

### Cloudflare Access login succeeds but API calls fail
Confirm that the Access application covers the entire bulk app hostname and that `/api/*` is not excluded by a bypass policy. The browser and PHP endpoints should use the same hostname.
