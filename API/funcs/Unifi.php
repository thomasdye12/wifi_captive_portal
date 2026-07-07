<?php

include_once __DIR__ . "/PortalConfig.php";
include_once __DIR__ . "/PortalLogger.php";

function portalUnifiRequest($method, $path, $payload = null, $cookieFile = null, $extraHeaders = [])
{
    $config = portalUnifiConfig();
    if (!$config["base_url"]) {
        throw new Exception("UNIFI_BASE_URL is not configured.");
    }

    $ch = curl_init($config["base_url"] . $path);
    $headers = array_merge(["Content-Type: application/json", "Accept: application/json"], $extraHeaders);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => $config["verify_tls"],
        CURLOPT_SSL_VERIFYHOST => $config["verify_tls"] ? 2 : 0,
    ]);

    if ($cookieFile) {
        curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieFile);
        curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile);
    }

    if ($payload !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    }

    $response = curl_exec($ch);
    if ($response === false) {
        $error = curl_error($ch);
        curl_close($ch);
        throw new Exception($error);
    }

    $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $headerText = substr($response, 0, $headerSize);
    $body = substr($response, $headerSize);
    curl_close($ch);

    return [$status, $body, portalUnifiParseHeaders($headerText)];
}

function portalUnifiParseHeaders($headerText)
{
    $headers = [];
    foreach (preg_split('/\r\n|\r|\n/', trim($headerText)) as $line) {
        if (strpos($line, ':') === false) {
            continue;
        }

        [$name, $value] = explode(':', $line, 2);
        $headers[strtolower(trim($name))] = trim($value);
    }

    return $headers;
}

function portalUnifiAuthHeaders($headers, $body = "")
{
    $token = $headers["x-csrf-token"] ?? $headers["x-csrf"] ?? "";
    if (!$token && $body) {
        $json = json_decode($body, true);
        if (is_array($json)) {
            $token = $json["csrfToken"] ?? $json["csrf_token"] ?? $json["data"]["csrfToken"] ?? "";
        }
    }

    $baseUrl = portalUnifiConfig()["base_url"];
    $authHeaders = [
        "Referer: " . $baseUrl . "/",
        "Origin: " . $baseUrl,
        "X-Requested-With: XMLHttpRequest",
    ];
    if ($token) {
        $authHeaders[] = "X-CSRF-Token: " . $token;
    }

    return $authHeaders;
}

function portalUnifiApiKeyHeaders($config)
{
    $apiKey = trim((string) ($config["api_key"] ?? ""));
    if (!$apiKey && ($config["auth_mode"] ?? "") === "api_key_server") {
        $serverPath = $config["api_key_server_path"] ?? "/Server/app/support/Apikeyserver.php";
        if (is_readable($serverPath)) {
            include_once $serverPath;
        }

        if (function_exists("Getapikeyforpath")) {
            $apiKey = trim((string) Getapikeyforpath($config["api_key_path"] ?? "/TDS/Unifi/HomeSystem"));
        }
    }

    if (!$apiKey) {
        return [];
    }

    return ["x-api-key: " . $apiKey];
}

function portalAuthorizeUnifiGuest($client, $minutes = null)
{
    $config = portalUnifiConfig();
    if (!$config["enabled"]) {
        portalLog("info", "unifi_authorize_skipped", ["client_mac" => $client["client_mac"]]);
        return ["authorized" => true, "mode" => "dry-run"];
    }

    $minutes = $minutes ?: $config["default_minutes"];
    $cookieFile = null;

    try {
        $authMode = $config["auth_mode"] ?? "api_key_server";
        $authHeaders = portalUnifiApiKeyHeaders($config);
        if ($authHeaders) {
            $authMode = "api_key";
        } else {
            if (!$config["username"] || !$config["password"]) {
                throw new Exception("UniFi API key and credentials are not configured.");
            }

            $cookieFile = tempnam(sys_get_temp_dir(), "unifi-cookie-");
            [$loginStatus, $loginBody, $loginHeaders] = portalUnifiRequest("POST", "/api/auth/login", [
                "username" => $config["username"],
                "password" => $config["password"],
                "remember" => true,
            ], $cookieFile);
            $authHeaders = portalUnifiAuthHeaders($loginHeaders, $loginBody);

            if ($loginStatus >= 400) {
                [$loginStatus, $loginBody, $loginHeaders] = portalUnifiRequest("POST", "/api/login", [
                    "username" => $config["username"],
                    "password" => $config["password"],
                ], $cookieFile);
                $authHeaders = portalUnifiAuthHeaders($loginHeaders, $loginBody);
            }

            if ($loginStatus >= 400) {
                throw new Exception("UniFi login failed with HTTP " . $loginStatus);
            }
        }

        $payload = [
            "cmd" => "authorize-guest",
            "mac" => $client["client_mac"],
            "minutes" => (int) $minutes,
        ];

        [$status, $body, $attempts] = portalUnifiTryAuthorizePaths($client, $config, $payload, $cookieFile, $authHeaders);

        if ($status >= 400) {
            portalLog("error", "unifi_authorize_rejected", [
                "site" => $client["site"] ?: $config["site"],
                "fallback_site" => $config["site"],
                "client_mac" => $client["client_mac"],
                "status" => $status,
                "has_csrf" => portalUnifiHasCsrfHeader($authHeaders),
                "auth_mode" => $authMode,
                "has_api_key" => portalUnifiHasApiKeyHeader($authHeaders),
                "body" => $body,
                "attempts" => $attempts,
            ]);
            throw new Exception("UniFi guest authorization failed with HTTP " . $status . ": " . $body);
        }

        return ["authorized" => true, "minutes" => (int) $minutes];
    } finally {
        if ($cookieFile && file_exists($cookieFile)) {
            unlink($cookieFile);
        }
    }
}

function portalUnifiTryAuthorizePaths($client, $config, $payload, $cookieFile, $authHeaders)
{
    $siteCandidates = [];
    foreach ([$client["site"] ?? "", $config["site"] ?? ""] as $site) {
        $site = trim((string) $site);
        if ($site && !in_array($site, $siteCandidates, true)) {
            $siteCandidates[] = $site;
        }
    }

    $attempts = [];
    $lastStatus = 0;
    $lastBody = "";

    foreach ($siteCandidates as $site) {
        foreach ($config["authorize_paths"] as $template) {
            $path = str_replace("{site}", rawurlencode($site), $template);
            [$status, $body] = portalUnifiRequest("POST", $path, $payload, $cookieFile, $authHeaders);
            $attempts[] = [
                "site" => $site,
                "path" => $path,
                "status" => $status,
                "body" => substr((string) $body, 0, 300),
            ];

            $lastStatus = $status;
            $lastBody = $body;
            if ($status < 400) {
                return [$status, $body, $attempts];
            }
        }
    }

    return [$lastStatus, $lastBody, $attempts];
}

function portalUnifiHasCsrfHeader($headers)
{
    foreach ($headers as $header) {
        if (stripos($header, "X-CSRF-Token:") === 0) {
            return true;
        }
    }

    return false;
}

function portalUnifiHasApiKeyHeader($headers)
{
    foreach ($headers as $header) {
        if (stripos($header, "x-api-key:") === 0) {
            return true;
        }
    }

    return false;
}
