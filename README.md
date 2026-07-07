# UniFi WiFi Captive Portal

Small PHP captive portal for UniFi guest access. It supports public voucher codes, external guest-code validation, OAuth2 login, UniFi guest authorization, and structured logging.

## Configure

Keep live secrets out of the repository. Put the real config outside the web root and point the app at it:

```sh
PORTAL_CONFIG_PATH=/path/to/wifi-captive-portal.json
```

A safe example config looks like this:

```json
{
  "portal": {
    "base_url": "https://portal.example.com",
    "public_url": "https://portal.example.com/",
    "allowed_redirect_hosts": ["portal.example.com", "captive.apple.com"]
  },
  "mongo": {
    "uri": "mongodb://mongo.example.internal:27017",
    "database": "wifi_captive_portal"
  },
  "unifi": {
    "enabled": true,
    "base_url": "https://unifi.example.com",
    "site": "default",
    "username": "portal-user",
    "password": "change-me",
    "auth_mode": "api_key_server",
    "api_key": "",
    "api_key_server_path": "/path/to/Apikeyserver.php",
    "api_key_path": "/Example/Unifi/API",
    "verify_tls": true,
    "default_minutes": 1440,
    "oauth_minutes": 7200,
    "authorize_paths": [
      "/proxy/network/api/s/{site}/cmd/stamgr",
      "/api/s/{site}/cmd/stamgr",
      "/network/api/s/{site}/cmd/stamgr"
    ]
  },
  "oauth": {
    "tds": {
      "enabled": true,
      "label": "SSO Login",
      "client_id": "change-me",
      "client_secret": "change-me",
      "issuer": "https://auth.example.com",
      "authorize_url": "https://auth.example.com/oauth/authorize",
      "token_url": "https://auth.example.com/oauth/token",
      "userinfo_url": "https://auth.example.com/oauth/userinfo",
      "jwks_uri": "https://auth.example.com/.well-known/jwks.json",
      "scopes": "openid profile email groups"
    },
    "providers": []
  },
  "logging": {
    "app": "wifi-captive-portal",
    "environment": "production",
    "tds_loki_path": "/path/to/Grafana_Loki.php"
  },
  "guest_codes": {
    "mode": "auth_api",
    "validate_url": "https://auth.example.com/api/wifi-guest-codes/validate",
    "api_key": "",
    "api_key_server_path": "/path/to/Apikeyserver.php",
    "api_key_path": "/Example/Auth/WifiGuestCodes",
    "timeout_seconds": 8
  },
  "codes": {
    "guest": [{"code": "FRONTDESK", "minutes": 1440, "label": "front-desk"}],
    "vouchers": [{"code": "WELCOME24", "minutes": 1440, "label": "bootstrap"}]
  }
}
```

The app can also read `API/funcs/portal-config.json` by default, but that live config file is ignored by Git. Use it only for local/private deployments.

Environment variables override JSON values for deployment-specific settings, including `PORTAL_BASE_URL`, `PORTAL_PUBLIC_URL`, `PORTAL_ALLOWED_REDIRECT_HOSTS`, `PORTAL_MONGO_URI`, `PORTAL_MONGO_DATABASE`, `UNIFI_*`, `TDS_OAUTH_*`, `PORTAL_OAUTH_PROVIDERS_JSON`, `GUEST_CODE_*`, `PORTAL_GUEST_CODES`, and `PORTAL_VOUCHERS`.

## UniFi Authorization

Guest access is enabled by sending UniFi Network the `authorize-guest` command for the client MAC:

```json
{
  "cmd": "authorize-guest",
  "mac": "5a:fd:a0:86:c3:66",
  "minutes": 1440
}
```

The portal tries each configured `authorize_paths` template with the site from `/guest/s/{site}/`, then with the configured fallback site. A 404 usually means the UniFi base URL, site, or path template does not match that controller. Check the `unifi_authorize_rejected.attempts` log field to see every path tried.

For UniFi auth, `auth_mode` can be:

- `api_key_server`: include a local API-key helper and call `Getapikeyforpath(api_key_path)`, then send `x-api-key`.
- `password`: fall back to the older cookie/CSRF login flow.
- direct API key: set `api_key` in the private config.

## Durations

Authorization duration is source-specific:

- OAuth login uses `unifi.oauth_minutes` (`7200` is five days).
- Voucher codes use the voucher record's `minutes`.
- Guest device codes use the guest-code validation response's `minutes`.
- `unifi.default_minutes` is only a fallback.

## Guest Code Auth API

Guest device codes are validated by an external Auth API when `guest_codes.mode` is `auth_api`. The portal sends:

```http
POST {guest_codes.validate_url}
x-api-key: Getapikeyforpath("{guest_codes.api_key_path}")
Content-Type: application/json
```

```json
{
  "code": "CONTRACTOR-8H",
  "client": {
    "client_mac": "5a:fd:a0:86:c3:66",
    "ap_mac": "78:8a:20:d6:8a:ae",
    "ssid": "Guest WiFi",
    "site": "site-id",
    "redirect_url": "http://captive.apple.com/"
  },
  "portal": {
    "request_id": "portal-request-id",
    "base_url": "https://portal.example.com"
  }
}
```

Accepted response:

```json
{
  "ok": true,
  "minutes": 480,
  "expires_at": "2026-07-07T21:40:00Z",
  "label": "Contractor 8 hour pass",
  "reference": "auth-system-reference"
}
```

Denied response:

```json
{
  "ok": false,
  "error": "That access code has expired."
}
```

## UniFi Portal URL

UniFi commonly sends guests to `/guest/s/{site}/` and includes the site in the URL path. This portal supports that shape:

```text
https://portal.example.com/guest/s/{site}/?ap={ap_mac}&id={client_mac}&url={redirect_url}&ssid={ssid}
```

The root URL also works if you pass `site` as a query parameter. The page forwards those values to the API when a user redeems a voucher, enters a guest device access code, or starts OAuth login.

For OAuth, send users through `/API/portal/oauth/tds/start` with the same UniFi query parameters. The callback recovers the client details from OAuth `state`, with PHP session and callback query parameters as fallbacks. The `id` value must be the real client MAC address, for example `aa:bb:cc:dd:ee:ff`; a test value such as `id=test` will be rejected before UniFi authorization.
