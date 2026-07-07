<?php

include_once __DIR__ . "/PortalConfig.php";
include_once __DIR__ . "/PortalLogger.php";

function portalValidateGuestCode($code, $client)
{
    $config = portalGuestCodeProviderConfig();
    if (($config["mode"] ?? "auth_api") !== "auth_api" || !$config["validate_url"]) {
        return null;
    }

    $headers = ["Content-Type: application/json", "Accept: application/json"];
    $apiKey = portalGuestCodeApiKey($config);
    if ($apiKey) {
        $headers[] = "x-api-key: " . $apiKey;
    }

    $payload = [
        "code" => $code,
        "client" => $client,
        "portal" => [
            "request_id" => $GLOBALS["PORTAL_REQUEST_ID"] ?? null,
            "base_url" => portalBaseUrl(),
        ],
    ];

    $ch = curl_init($config["validate_url"]);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => max(1, $config["timeout_seconds"]),
    ]);

    $response = curl_exec($ch);
    if ($response === false) {
        $error = curl_error($ch);
        curl_close($ch);
        portalLog("error", "guest_code_auth_api_failed", ["error" => $error]);
        return ["ok" => false, "error" => "Guest code validation is currently unavailable."];
    }

    $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    $decoded = json_decode($response ?: "", true);
    if (!is_array($decoded)) {
        portalLog("error", "guest_code_auth_api_invalid_response", [
            "status" => $status,
            "body" => substr((string) $response, 0, 500),
        ]);
        return ["ok" => false, "error" => "Guest code validation returned an invalid response."];
    }

    if ($status >= 400 || empty($decoded["ok"])) {
        return [
            "ok" => false,
            "error" => $decoded["error"] ?? "That access code was not accepted.",
            "status" => $status,
        ];
    }

    return [
        "ok" => true,
        "minutes" => (int) ($decoded["minutes"] ?? portalUnifiConfig()["default_minutes"]),
        "expires_at" => $decoded["expires_at"] ?? null,
        "label" => $decoded["label"] ?? null,
        "reference" => $decoded["reference"] ?? null,
        "raw" => $decoded,
    ];
}

function portalGuestCodeApiKey($config)
{
    $apiKey = trim((string) ($config["api_key"] ?? ""));
    if ($apiKey) {
        return $apiKey;
    }

    $serverPath = $config["api_key_server_path"] ?? "/Server/app/support/Apikeyserver.php";
    if (is_readable($serverPath)) {
        include_once $serverPath;
    }

    if (function_exists("Getapikeyforpath")) {
        return trim((string) Getapikeyforpath($config["api_key_path"] ?? "/TDS/Auth/WifiGuestCodes"));
    }

    return "";
}
