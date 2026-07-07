# UniFi WiFi Captive Portal

Small PHP captive portal for UniFi guest access. It supports public voucher and guest-device-code endpoints, optional TDS OAuth2 login, authenticated voucher generation, and Loki structured logging.

## Configure

Put the main config in [API/funcs/portal-config.json](API/funcs/portal-config.json). It should look like this:

```json
{
  "portal": {
    "base_url": "https://portal.local.thomasdye.net",
    "public_url": "https://portal.local.thomasdye.net/",
    "allowed_redirect_hosts": ["portal.local.thomasdye.net", "captive.apple.com"]
  },
  "mongo": {
    "uri": "mongodb://main.db.local.thomasdye.net:27018",
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
    "api_key_server_path": "/Server/app/support/Apikeyserver.php",
    "api_key_path": "/TDS/Unifi/HomeSystem",
    "verify_tls": false,
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
      "label": "TDS Login",
      "client_id": "change-me",
      "client_secret": "change-me",
      "issuer": "https://auth.thomasdye.net",
      "authorize_url": "https://auth.thomasdye.net/auth/app/odic/authorize",
      "token_url": "https://auth.thomasdye.net/auth/app/odic/token",
      "userinfo_url": "https://auth.thomasdye.net/auth/app/odic/userinfo",
      "jwks_uri": "https://auth.thomasdye.net/.well-known/jwks.json",
      "scopes": "openid profile email groups"
    },
    "providers": []
  },
  "logging": {
    "app": "wifi-captive-portal",
    "environment": "production",
    "tds_loki_path": "/Server/app/support/Grafana_Loki.php"
  },
  "guest_codes": {
    "mode": "auth_api",
    "validate_url": "https://auth.thomasdye.net/auth/app/wifi-guest-codes/validate",
    "api_key": "",
    "api_key_server_path": "/Server/app/support/Apikeyserver.php",
    "api_key_path": "/TDS/Auth/WifiGuestCodes",
    "timeout_seconds": 8
  },
  "codes": {
    "guest": [{"code": "FRONTDESK", "minutes": 1440, "label": "front-desk"}],
    "vouchers": [{"code": "WELCOME24", "minutes": 1440, "label": "bootstrap"}]
  }
}
```

The app reads this file by default. Set `PORTAL_CONFIG_PATH=/path/to/config.json` if you want to keep secrets outside this project folder.

The TDS provider defaults to the current Thomas Dye auth discovery values:

```text
issuer: https://auth.thomasdye.net
authorization_endpoint: https://auth.thomasdye.net/auth/app/odic/authorize
token_endpoint: https://auth.thomasdye.net/auth/app/odic/token
userinfo_endpoint: https://auth.thomasdye.net/auth/app/odic/userinfo
jwks_uri: https://auth.thomasdye.net/.well-known/jwks.json
```

Override those with `TDS_OAUTH_ISSUER`, `TDS_OAUTH_AUTHORIZE_URL`, `TDS_OAUTH_TOKEN_URL`, `TDS_OAUTH_USERINFO_URL`, or `TDS_OAUTH_JWKS_URI` if they change.

Environment variables still override JSON values for deployment-specific settings, including `PORTAL_BASE_URL`, `PORTAL_PUBLIC_URL`, `PORTAL_ALLOWED_REDIRECT_HOSTS`, `PORTAL_MONGO_URI`, `PORTAL_MONGO_DATABASE`, `UNIFI_*`, `TDS_OAUTH_*`, `PORTAL_OAUTH_PROVIDERS_JSON`, `PORTAL_GUEST_CODES`, and `PORTAL_VOUCHERS`.

Logging uses the shared TDS helper at `/Server/app/support/Grafana_Loki.php`. If it is available, portal logs are sent through `Grafana_Loki_Log("TDS_PHP_service", "wifi-captive-portal", ...)`; otherwise the portal falls back to `error_log`.

Guest access is enabled by sending UniFi Network the `authorize-guest` command for the client MAC. The portal tries each configured `authorize_paths` template with the site from `/guest/s/{site}/`, then with the configured fallback site. A 404 means the UniFi base URL, site, or path template does not match that controller; check the `unifi_authorize_rejected.attempts` Loki field to see every path tried.

For UniFi auth, the default `auth_mode` is `api_key_server`. The portal includes `/Server/app/support/Apikeyserver.php` and calls `Getapikeyforpath("/TDS/Unifi/HomeSystem")`, then sends the result as `x-api-key`. Set `auth_mode` to `password` only if you want to fall back to the older cookie/CSRF login flow.

Authorization duration is source-specific: TDS OAuth uses `unifi.oauth_minutes` (`7200`, five days), voucher codes use the voucher record's `minutes`, and guest device codes use the guest-code record's `minutes`. `unifi.default_minutes` is only the fallback when a record does not specify a duration.

## Guest Code Auth API

Guest device codes are validated by the Auth system when `guest_codes.mode` is `auth_api`. The portal sends:

```http
POST {guest_codes.validate_url}
x-api-key: Getapikeyforpath("/TDS/Auth/WifiGuestCodes")
Content-Type: application/json
```

```json
{
  "code": "CONTRACTOR-8H",
  "client": {
    "client_mac": "5a:fd:a0:86:c3:66",
    "ap_mac": "78:8a:20:d6:8a:ae",
    "ssid": "TDS Guest",
    "site": "hwpuwyxu",
    "redirect_url": "http://captive.apple.com/"
  },
  "portal": {
    "request_id": "portal-request-id",
    "base_url": "https://portal.local.thomasdye.net"
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
https://portal.local.thomasdye.net/guest/s/{site}/?ap={ap_mac}&id={client_mac}&url={redirect_url}&ssid={ssid}
```

The root URL also works if you pass `site` as a query parameter. The page forwards those values to the API when a user redeems a voucher, enters a guest device access code, or starts OAuth login.

For OAuth, send users through `/API/portal/oauth/tds/start` with the same UniFi query parameters. The callback recovers the client details from OAuth `state`, with PHP session and callback query parameters as fallbacks. The `id` value must be the real client MAC address, for example `aa:bb:cc:dd:ee:ff`; a test value such as `id=test` will be rejected before UniFi authorization.
