<?php

include_once __DIR__ . "/PortalConfig.php";
include_once __DIR__ . "/PortalLogger.php";

function portalJson($data, $status = 200)
{
    http_response_code($status);
    return $data;
}

function portalRequestBody($body)
{
    if (is_array($body)) {
        return $body;
    }

    $raw = file_get_contents("php://input");
    if (!$raw) {
        return [];
    }

    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

function portalClientContext($body = [])
{
    $source = array_merge($_GET, is_array($body) ? $body : []);

    return [
        "client_mac" => portalNormalizeMac($source["id"] ?? $source["client_mac"] ?? $source["mac"] ?? ""),
        "ap_mac" => portalNormalizeMac($source["ap"] ?? $source["ap_mac"] ?? ""),
        "ssid" => trim($source["ssid"] ?? ""),
        "site" => trim($source["site"] ?? portalUnifiConfig()["site"]),
        "redirect_url" => trim($source["url"] ?? $source["redirect_url"] ?? ""),
    ];
}

function portalNormalizeMac($value)
{
    $value = strtolower(trim((string) $value));
    $hex = preg_replace('/[^0-9a-f]/', '', $value);
    if (strlen($hex) === 12) {
        return implode(":", str_split($hex, 2));
    }

    return $value;
}

function portalValidateClientContext($client)
{
    if (!$client["client_mac"]) {
        return "Missing client device MAC address.";
    }

    if (!preg_match('/^[0-9a-f]{2}(:[0-9a-f]{2}){5}$/', $client["client_mac"])) {
        return "Invalid client device MAC address.";
    }

    return null;
}

function portalBase64UrlEncode($value)
{
    return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
}

function portalBase64UrlDecode($value)
{
    $value = strtr($value, '-_', '+/');
    $padding = strlen($value) % 4;
    if ($padding) {
        $value .= str_repeat('=', 4 - $padding);
    }

    return base64_decode($value, true);
}

function portalBuildOauthState($client)
{
    $nonce = bin2hex(random_bytes(16));
    $payload = portalBase64UrlEncode(json_encode([
        "client" => $client,
        "nonce" => $nonce,
        "created_at" => time(),
    ], JSON_UNESCAPED_SLASHES));

    return $nonce . "." . $payload;
}

function portalDecodeOauthState($state)
{
    $parts = explode(".", (string) $state, 2);
    if (count($parts) !== 2) {
        return null;
    }

    $decoded = portalBase64UrlDecode($parts[1]);
    if ($decoded === false) {
        return null;
    }

    $payload = json_decode($decoded, true);
    if (!is_array($payload) || ($payload["nonce"] ?? null) !== $parts[0]) {
        return null;
    }

    return $payload;
}

function portalSafeRedirectUrl($url)
{
    if (!$url) {
        return portalPublicUrl() ?: "/";
    }

    $parts = parse_url($url);
    if (!$parts || empty($parts["host"])) {
        return $url;
    }

    $allowed = portalAllowedRedirectHosts();
    if (!$allowed || in_array($parts["host"], $allowed, true)) {
        return $url;
    }

    return portalPublicUrl() ?: "/";
}

function portalCodeList($envName)
{
    $codes = [];

    $configPath = $envName === "PORTAL_GUEST_CODES" ? "codes.guest" : "codes.vouchers";
    $configuredCodes = portalConfigValue($configPath, []);
    if (is_array($configuredCodes)) {
        foreach ($configuredCodes as $entry) {
            if (empty($entry["code"])) {
                continue;
            }
            $key = strtolower($entry["code"]);
            $codes[$key] = [
                "code" => $entry["code"],
                "minutes" => (int) ($entry["minutes"] ?? portalUnifiConfig()["default_minutes"]),
                "label" => $entry["label"] ?? "config",
            ];
        }
    }

    $raw = portalEnv($envName, "");
    foreach (array_filter(array_map("trim", explode(",", $raw))) as $entry) {
        $parts = array_map("trim", explode(":", $entry));
        if (!$parts[0]) {
            continue;
        }
        $key = strtolower($parts[0]);
        $codes[$key] = [
            "code" => $parts[0],
            "minutes" => isset($parts[1]) ? (int) $parts[1] : portalUnifiConfig()["default_minutes"],
            "label" => $parts[2] ?? "environment",
        ];
    }

    return $codes;
}

function portalCodeHash($code)
{
    return hash("sha256", strtolower(trim($code)));
}

function portalRandomCode($length = 8)
{
    $length = max(4, min(32, $length));
    $alphabet = "ABCDEFGHJKLMNPQRSTUVWXYZ23456789";
    $code = "";
    for ($i = 0; $i < $length; $i++) {
        $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
    }
    return $code;
}
