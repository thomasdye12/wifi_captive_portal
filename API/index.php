<?php

ini_set('memory_limit', '9000M');
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);

ini_set('upload_max_filesize', '5000M');
ini_set('post_max_size', '5000M');
ini_set('max_input_time', 3600);
ini_set('max_execution_time', 3600);

$GLOBALS["TDS_Auth_Request_MaxRequests"] = 100;

header('Content-Type: application/json; charset=utf-8');
header('X-Powered-By: TDS API');

header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Access-Control-Allow-Credentials: true');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

include_once "/Server/app/php/TDSApiKernel.php";
include_once __DIR__ . "/funcs/include.php";

function getEndpoints($userinfo = null, $body = null)
{
    return array_keys($GLOBALS["endpoints"]);
}

$GLOBALS["endpoints"] = array_merge([
    "endpoints{GET}" => [
        "func" => "getEndpoints",
        "auth" => true,
    ],
    "{GET}" => [
        "func" => "getEndpoints",
        "auth" => true,
    ],
], $GLOBALS["endpoints"]);

$kernel = new TDSApiKernel();

$kernel
    ->setBasePath(dirname($_SERVER['SCRIPT_NAME']))
    ->setBodyParamPosition("after_userinfo")
    ->setAppendNullBodyForNoBodyMethods(true)
    ->setGlobalRequiredAnyOf([
        "net.thomasdye.internal.networking.CaptivePortal",
    ])
    ->addEndpoints($GLOBALS["endpoints"]);

$kernel->dispatch();

