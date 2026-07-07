<?php

require_once "/Server/app/mongoDBConfig/includes/vendor/autoload.php";
include_once __DIR__ . "/PortalConfig.php";

$mongoConfig = portalMongoConfig();
$mongoUri = $mongoConfig["uri"];
$mongoDatabase = $mongoConfig["database"];

$connection = new MongoDB\Client($mongoUri);
$database = $connection->selectDatabase($mongoDatabase);

$GuestCodesCollection = $database->selectCollection("GuestAccessCodes");
$VoucherCollection = $database->selectCollection("Vouchers");
$PortalSessionsCollection = $database->selectCollection("PortalSessions");
