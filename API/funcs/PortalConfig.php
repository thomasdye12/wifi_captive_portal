<?php

function portalConfigPath()
{
    return getenv("PORTAL_CONFIG_PATH") ?: __DIR__ . "/portal-config.json";
}

function portalConfig()
{
    static $config = null;
    if ($config !== null) {
        return $config;
    }

    $path = portalConfigPath();
    if (!is_readable($path)) {
        $config = [];
        return $config;
    }

    $decoded = json_decode(file_get_contents($path), true);
    $config = is_array($decoded) ? $decoded : [];
    return $config;
}

function portalConfigValue($path, $default = null)
{
    $value = portalConfig();
    foreach (explode(".", $path) as $part) {
        if (!is_array($value) || !array_key_exists($part, $value)) {
            return $default;
        }
        $value = $value[$part];
    }

    return $value;
}

function portalEnv($name, $default = null)
{
    $value = getenv($name);
    return ($value === false || $value === "") ? $default : $value;
}

function portalBool($value, $default = false)
{
    if ($value === null) {
        return $default;
    }
    if (is_bool($value)) {
        return $value;
    }

    return in_array(strtolower((string) $value), ["1", "true", "yes", "on"], true);
}

function portalBaseUrl()
{
    $configured = portalEnv("PORTAL_BASE_URL", portalConfigValue("portal.base_url", ""));
    if ($configured) {
        return rtrim($configured, "/");
    }

    $scheme = (!empty($_SERVER["HTTPS"]) && $_SERVER["HTTPS"] !== "off") ? "https" : "http";
    $host = $_SERVER["HTTP_HOST"] ?? "";
    return $host ? $scheme . "://" . $host : "";
}

function portalPublicUrl()
{
    return rtrim(portalEnv("PORTAL_PUBLIC_URL", portalConfigValue("portal.public_url", portalBaseUrl())), "/");
}

function portalAllowedRedirectHosts()
{
    $configured = portalConfigValue("portal.allowed_redirect_hosts", []);
    $hosts = portalEnv("PORTAL_ALLOWED_REDIRECT_HOSTS", "");
    if ($hosts) {
        return array_values(array_filter(array_map("trim", explode(",", $hosts))));
    }

    if (is_array($configured)) {
        return $configured;
    }

    return array_values(array_filter(array_map("trim", explode(",", $hosts))));
}

function portalTdsOauthProvider()
{
    $config = portalConfigValue("oauth.tds", []);
    return [
        "id" => "tds",
        "enabled" => portalBool(portalEnv("TDS_OAUTH_ENABLED", $config["enabled"] ?? true), true),
        "label" => portalEnv("TDS_OAUTH_LABEL", $config["label"] ?? "TDS Login"),
        "client_id" => portalEnv("TDS_OAUTH_CLIENT_ID", $config["client_id"] ?? ""),
        "client_secret" => portalEnv("TDS_OAUTH_CLIENT_SECRET", $config["client_secret"] ?? ""),
        "issuer" => portalEnv("TDS_OAUTH_ISSUER", $config["issuer"] ?? "https://auth.thomasdye.net"),
        "authorize_url" => portalEnv("TDS_OAUTH_AUTHORIZE_URL", $config["authorize_url"] ?? "https://auth.thomasdye.net/auth/app/odic/authorize"),
        "token_url" => portalEnv("TDS_OAUTH_TOKEN_URL", $config["token_url"] ?? "https://auth.thomasdye.net/auth/app/odic/token"),
        "userinfo_url" => portalEnv("TDS_OAUTH_USERINFO_URL", $config["userinfo_url"] ?? "https://auth.thomasdye.net/auth/app/odic/userinfo"),
        "jwks_uri" => portalEnv("TDS_OAUTH_JWKS_URI", $config["jwks_uri"] ?? "https://auth.thomasdye.net/.well-known/jwks.json"),
        "scopes" => portalEnv("TDS_OAUTH_SCOPES", $config["scopes"] ?? "openid profile email groups"),
    ];
}

function portalOauthProviders()
{
    $providers = [];
    $tds = portalTdsOauthProvider();
    if ($tds["enabled"] && $tds["client_id"] && $tds["authorize_url"] && $tds["token_url"]) {
        $providers[$tds["id"]] = $tds;
    }

    $json = portalEnv("PORTAL_OAUTH_PROVIDERS_JSON", "");
    $extraProviders = $json ? json_decode($json, true) : portalConfigValue("oauth.providers", []);
    if (is_array($extraProviders)) {
        foreach ($extraProviders as $provider) {
            if (
                !empty($provider["id"]) &&
                !empty($provider["client_id"]) &&
                !empty($provider["authorize_url"]) &&
                !empty($provider["token_url"])
            ) {
                $providers[$provider["id"]] = array_merge([
                    "label" => $provider["id"],
                    "client_secret" => "",
                    "userinfo_url" => "",
                    "scopes" => "openid profile email",
                ], $provider);
            }
        }
    }

    return $providers;
}

function portalUnifiConfig()
{
    $config = portalConfigValue("unifi", []);
    return [
        "enabled" => portalBool(portalEnv("UNIFI_ENABLED", $config["enabled"] ?? true), true),
        "base_url" => rtrim(portalEnv("UNIFI_BASE_URL", $config["base_url"] ?? ""), "/"),
        "site" => portalEnv("UNIFI_SITE", $config["site"] ?? "default"),
        "username" => portalEnv("UNIFI_USERNAME", $config["username"] ?? ""),
        "password" => portalEnv("UNIFI_PASSWORD", $config["password"] ?? ""),
        "auth_mode" => portalEnv("UNIFI_AUTH_MODE", $config["auth_mode"] ?? "api_key_server"),
        "api_key" => portalEnv("UNIFI_API_KEY", $config["api_key"] ?? ""),
        "api_key_server_path" => portalEnv("UNIFI_API_KEY_SERVER_PATH", $config["api_key_server_path"] ?? "/Server/app/support/Apikeyserver.php"),
        "api_key_path" => portalEnv("UNIFI_API_KEY_PATH", $config["api_key_path"] ?? "/TDS/Unifi/HomeSystem"),
        "verify_tls" => portalBool(portalEnv("UNIFI_VERIFY_TLS", $config["verify_tls"] ?? false), false),
        "default_minutes" => (int) portalEnv("UNIFI_DEFAULT_MINUTES", $config["default_minutes"] ?? "1440"),
        "oauth_minutes" => (int) portalEnv("UNIFI_OAUTH_MINUTES", $config["oauth_minutes"] ?? "7200"),
        "authorize_paths" => is_array($config["authorize_paths"] ?? null) ? $config["authorize_paths"] : [
            "/proxy/network/api/s/{site}/cmd/stamgr",
            "/api/s/{site}/cmd/stamgr",
            "/network/api/s/{site}/cmd/stamgr",
        ],
    ];
}

function portalGuestCodeProviderConfig()
{
    $config = portalConfigValue("guest_codes", []);
    return [
        "mode" => portalEnv("GUEST_CODE_MODE", $config["mode"] ?? "auth_api"),
        "validate_url" => portalEnv("GUEST_CODE_VALIDATE_URL", $config["validate_url"] ?? ""),
        "api_key" => portalEnv("GUEST_CODE_API_KEY", $config["api_key"] ?? ""),
        "api_key_server_path" => portalEnv("GUEST_CODE_API_KEY_SERVER_PATH", $config["api_key_server_path"] ?? "/Server/app/support/Apikeyserver.php"),
        "api_key_path" => portalEnv("GUEST_CODE_API_KEY_PATH", $config["api_key_path"] ?? "/TDS/Auth/WifiGuestCodes"),
        "timeout_seconds" => (int) portalEnv("GUEST_CODE_TIMEOUT_SECONDS", $config["timeout_seconds"] ?? "8"),
    ];
}

function portalLokiConfig()
{
    $config = portalConfigValue("logging", []);
    return [
        "tds_loki_path" => portalEnv("TDS_LOKI_PATH", $config["tds_loki_path"] ?? "/Server/app/support/Grafana_Loki.php"),
        "app" => portalEnv("LOKI_APP_LABEL", $config["app"] ?? "wifi-captive-portal"),
        "environment" => portalEnv("APP_ENV", $config["environment"] ?? "production"),
    ];
}

function portalMongoConfig()
{
    $config = portalConfigValue("mongo", []);
    return [
        "uri" => portalEnv("PORTAL_MONGO_URI", $config["uri"] ?? "mongodb://main.db.local.thomasdye.net:27018"),
        "database" => portalEnv("PORTAL_MONGO_DATABASE", $config["database"] ?? "wifi_captive_portal"),
    ];
}
