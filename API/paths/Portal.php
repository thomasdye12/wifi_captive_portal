<?php

include_once __DIR__ . "/../funcs/PortalHelpers.php";
include_once __DIR__ . "/../funcs/Unifi.php";
include_once __DIR__ . "/../funcs/GuestCodes.php";

$GLOBALS["PORTAL_REQUEST_ID"] = bin2hex(random_bytes(8));

function portalGetConfig($userinfo = null, $body = null)
{
    $providers = [];
    foreach (portalOauthProviders() as $provider) {
        $providers[] = [
            "id" => $provider["id"],
            "label" => $provider["label"],
        ];
    }

    return portalJson([
        "ok" => true,
        "oauth_providers" => $providers,
        "voucher_enabled" => true,
        "guest_code_enabled" => true,
    ]);
}

function portalRedeemGuestCode($userinfo = null, $body = null)
{
    global $GuestCodesCollection;

    $body = portalRequestBody($body);
    $client = portalClientContext($body);
    $error = portalValidateClientContext($client);
    if ($error) {
        portalLog("warning", "guest_code_invalid_context", ["error" => $error]);
        return portalJson(["ok" => false, "error" => $error], 400);
    }

    $code = trim($body["code"] ?? "");
    if (!$code) {
        return portalJson(["ok" => false, "error" => "Missing access code."], 400);
    }

    $validation = portalValidateGuestCode($code, $client);
    $record = null;
    if ($validation && empty($validation["ok"])) {
        portalLog("warning", "guest_code_denied", [
            "client_mac" => $client["client_mac"],
            "error" => $validation["error"] ?? null,
        ]);
        return portalJson(["ok" => false, "error" => $validation["error"] ?? "That access code was not accepted."], 403);
    }

    if ($validation && !empty($validation["ok"])) {
        $record = $validation;
    }

    $envCodes = portalCodeList("PORTAL_GUEST_CODES");
    $codeKey = strtolower($code);
    if (!$record && isset($envCodes[$codeKey])) {
        $record = $envCodes[$codeKey];
    } elseif (!$record && isset($GuestCodesCollection)) {
        $record = $GuestCodesCollection->findOne([
            "code_hash" => portalCodeHash($code),
            "active" => true,
        ]);
    }

    if (!$record) {
        portalLog("warning", "guest_code_denied", ["client_mac" => $client["client_mac"]]);
        return portalJson(["ok" => false, "error" => "That access code was not accepted."], 403);
    }

    $minutes = (int) ($record["minutes"] ?? portalUnifiConfig()["default_minutes"]);
    try {
        $auth = portalAuthorizeUnifiGuest($client, $minutes);
    } catch (Exception $exception) {
        portalLog("error", "guest_code_authorize_failed", [
            "client_mac" => $client["client_mac"],
            "error" => $exception->getMessage(),
        ]);
        return portalJson(["ok" => false, "error" => "The code was accepted, but WiFi access could not be enabled."], 502);
    }
    portalLog("info", "guest_code_accepted", [
        "client_mac" => $client["client_mac"],
        "minutes" => $minutes,
        "expires_at" => $record["expires_at"] ?? null,
        "reference" => $record["reference"] ?? null,
    ]);

    return portalJson([
        "ok" => true,
        "minutes" => $minutes,
        "expires_at" => $record["expires_at"] ?? gmdate("c", time() + ($minutes * 60)),
        "authorization" => $auth,
        "redirect_url" => portalSafeRedirectUrl($client["redirect_url"]),
    ]);
}

function portalRedeemVoucher($userinfo = null, $body = null)
{
    global $VoucherCollection;

    $body = portalRequestBody($body);
    $client = portalClientContext($body);
    $error = portalValidateClientContext($client);
    if ($error) {
        portalLog("warning", "voucher_invalid_context", ["error" => $error]);
        return portalJson(["ok" => false, "error" => $error], 400);
    }

    $code = trim($body["voucher"] ?? $body["code"] ?? "");
    if (!$code) {
        return portalJson(["ok" => false, "error" => "Missing voucher code."], 400);
    }

    $record = null;
    $envCodes = portalCodeList("PORTAL_VOUCHERS");
    $codeKey = strtolower($code);
    if (isset($envCodes[$codeKey])) {
        $record = $envCodes[$codeKey];
    } elseif (isset($VoucherCollection)) {
        $record = $VoucherCollection->findOne([
            "code_hash" => portalCodeHash($code),
            "active" => true,
        ]);
    }

    if (!$record) {
        portalLog("warning", "voucher_denied", ["client_mac" => $client["client_mac"]]);
        return portalJson(["ok" => false, "error" => "That voucher was not accepted."], 403);
    }

    $minutes = (int) ($record["minutes"] ?? portalUnifiConfig()["default_minutes"]);
    try {
        $auth = portalAuthorizeUnifiGuest($client, $minutes);
    } catch (Exception $exception) {
        portalLog("error", "voucher_authorize_failed", [
            "client_mac" => $client["client_mac"],
            "error" => $exception->getMessage(),
        ]);
        return portalJson(["ok" => false, "error" => "The voucher was accepted, but WiFi access could not be enabled."], 502);
    }

    if (isset($VoucherCollection) && isset($record["_id"])) {
        $VoucherCollection->updateOne(["_id" => $record["_id"]], [
            '$set' => ["last_used_at" => new MongoDB\BSON\UTCDateTime(), "last_client_mac" => $client["client_mac"]],
            '$inc' => ["uses" => 1],
        ]);
    }

    portalLog("info", "voucher_accepted", [
        "client_mac" => $client["client_mac"],
        "minutes" => $minutes,
    ]);

    return portalJson([
        "ok" => true,
        "authorization" => $auth,
        "redirect_url" => portalSafeRedirectUrl($client["redirect_url"]),
    ]);
}

function portalCreateVoucher($userinfo = null, $body = null)
{
    global $VoucherCollection;

    $body = portalRequestBody($body);
    $code = portalRandomCode((int) ($body["length"] ?? 8));
    $minutes = (int) ($body["minutes"] ?? portalUnifiConfig()["default_minutes"]);

    if (isset($VoucherCollection)) {
        $VoucherCollection->insertOne([
            "code_hash" => portalCodeHash($code),
            "minutes" => $minutes,
            "active" => true,
            "created_at" => new MongoDB\BSON\UTCDateTime(),
            "created_by" => $userinfo["sub"] ?? $userinfo["email"] ?? null,
            "uses" => 0,
        ]);
    }

    portalLog("info", "voucher_created", ["minutes" => $minutes]);

    return portalJson([
        "ok" => true,
        "voucher" => $code,
        "minutes" => $minutes,
    ], 201);
}

function portalOauthStart($providerId, $userinfo = null, $body = null)
{
    session_start();
    $providers = portalOauthProviders();
    if (!isset($providers[$providerId])) {
        return portalJson(["ok" => false, "error" => "Unknown OAuth provider."], 404);
    }

    $provider = $providers[$providerId];
    $client = portalClientContext($_GET);
    $state = portalBuildOauthState($client);
    $_SESSION["portal_oauth_state"] = $state;
    $_SESSION["portal_oauth_client"] = $client;

    $redirectUri = portalBaseUrl() . "/API/portal/oauth/" . rawurlencode($providerId) . "/callback";
    $query = http_build_query([
        "response_type" => "code",
        "client_id" => $provider["client_id"],
        "redirect_uri" => $redirectUri,
        "scope" => $provider["scopes"],
        "state" => $state,
    ]);

    header("Location: " . $provider["authorize_url"] . "?" . $query, true, 302);
    exit;
}

function portalOauthCallback($providerId, $userinfo = null, $body = null)
{
    session_start();
    $providers = portalOauthProviders();
    if (!isset($providers[$providerId])) {
        return portalJson(["ok" => false, "error" => "Unknown OAuth provider."], 404);
    }

    $state = $_GET["state"] ?? "";
    $sessionState = $_SESSION["portal_oauth_state"] ?? null;
    if ($sessionState && $state !== $sessionState) {
        portalLog("warning", "oauth_state_rejected", ["provider" => $providerId, "reason" => "session_mismatch"]);
        return portalJson(["ok" => false, "error" => "OAuth state did not match."], 400);
    }

    $provider = $providers[$providerId];
    $decodedState = portalDecodeOauthState($state);
    $client = $_SESSION["portal_oauth_client"] ?? $decodedState["client"] ?? portalClientContext($_GET);
    $error = portalValidateClientContext($client);
    if ($error) {
        portalLog("warning", "oauth_invalid_context", [
            "provider" => $providerId,
            "error" => $error,
            "has_session_state" => (bool) $sessionState,
            "has_decoded_state" => (bool) $decodedState,
        ]);
        return portalJson(["ok" => false, "error" => $error], 400);
    }

    $redirectUri = portalBaseUrl() . "/API/portal/oauth/" . rawurlencode($providerId) . "/callback";
    $tokenResponse = portalOauthTokenRequest($provider, [
        "grant_type" => "authorization_code",
        "code" => $_GET["code"] ?? "",
        "redirect_uri" => $redirectUri,
        "client_id" => $provider["client_id"],
        "client_secret" => $provider["client_secret"],
    ]);

    if (empty($tokenResponse["access_token"])) {
        portalLog("warning", "oauth_token_rejected", ["provider" => $providerId]);
        return portalJson(["ok" => false, "error" => "OAuth token exchange failed."], 502);
    }

    $profile = portalOauthUserInfo($provider, $tokenResponse["access_token"]);
    $minutes = portalUnifiConfig()["oauth_minutes"];
    try {
        $auth = portalAuthorizeUnifiGuest($client, $minutes);
    } catch (Exception $exception) {
        portalLog("error", "oauth_authorize_failed", [
            "provider" => $providerId,
            "client_mac" => $client["client_mac"],
            "minutes" => $minutes,
            "error" => $exception->getMessage(),
        ]);
        return portalJson(["ok" => false, "error" => "Login succeeded, but WiFi access could not be enabled."], 502);
    }
    portalLog("info", "oauth_accepted", [
        "provider" => $providerId,
        "client_mac" => $client["client_mac"],
        "minutes" => $minutes,
        "subject" => $profile["sub"] ?? $profile["email"] ?? null,
    ]);

    header("Location: " . portalSafeRedirectUrl($client["redirect_url"]), true, 302);
    exit;
}

function portalOauthTokenRequest($provider, $params)
{
    $ch = curl_init($provider["token_url"]);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($params),
        CURLOPT_HTTPHEADER => ["Content-Type: application/x-www-form-urlencoded", "Accept: application/json"],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
    ]);
    $response = curl_exec($ch);
    curl_close($ch);

    $decoded = json_decode($response ?: "", true);
    return is_array($decoded) ? $decoded : [];
}

function portalOauthUserInfo($provider, $accessToken)
{
    if (!$provider["userinfo_url"]) {
        return [];
    }

    $ch = curl_init($provider["userinfo_url"]);
    curl_setopt_array($ch, [
        CURLOPT_HTTPHEADER => ["Authorization: Bearer " . $accessToken, "Accept: application/json"],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
    ]);
    $response = curl_exec($ch);
    curl_close($ch);

    $decoded = json_decode($response ?: "", true);
    return is_array($decoded) ? $decoded : [];
}

$GLOBALS["endpoints"] = array_merge($GLOBALS["endpoints"], [
    "portal/config{GET}" => ["func" => "portalGetConfig", "auth" => false],
    "portal/guest-code{POST}" => ["func" => "portalRedeemGuestCode", "auth" => false],
    "portal/voucher{POST}" => ["func" => "portalRedeemVoucher", "auth" => false],
    "portal/vouchers{POST}" => [
        "func" => "portalCreateVoucher",
        "auth" => true,
        "rights" => ["net.thomasdye.internal.networking.CaptivePortal.Vouchers"],
    ],
    "portal/oauth/{String}/start{GET}" => ["func" => "portalOauthStart", "auth" => false],
    "portal/oauth/{String}/callback{GET}" => ["func" => "portalOauthCallback", "auth" => false],
]);
