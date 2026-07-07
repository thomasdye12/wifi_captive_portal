<?php

include_once __DIR__ . "/PortalConfig.php";

function portalLog($level, $event, $context = [])
{
    $config = portalLokiConfig();
    $entry = [
        "ts" => gmdate("c"),
        "level" => $level,
        "event" => $event,
        "request_id" => $GLOBALS["PORTAL_REQUEST_ID"] ?? null,
        "path" => $_SERVER["REQUEST_URI"] ?? null,
        "method" => $_SERVER["REQUEST_METHOD"] ?? null,
        "remote_addr" => $_SERVER["REMOTE_ADDR"] ?? null,
        "matched_endpoint" => $GLOBALS["TDS_MatchedEndpoint"] ?? null,
        "matched_function" => $GLOBALS["TDS_MatchedFunction"] ?? null,
        "context" => $context,
    ];

    $json = json_encode($entry, JSON_UNESCAPED_SLASHES);
    $lokiPath = $config["tds_loki_path"];
    if (is_readable($lokiPath)) {
        include_once $lokiPath;
    }

    if (function_exists("Grafana_Loki_Log")) {
        Grafana_Loki_Log("TDS_PHP_service", $config["app"], $json);
        return;
    }

    error_log($json);
}
