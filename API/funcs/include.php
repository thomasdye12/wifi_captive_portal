<?php

$GLOBALS["endpoints"] = [];

include_once __DIR__ . "/DB.php";

$pathFiles = scandir(__DIR__ . "/../paths");

foreach ($pathFiles as $pathFile) {
    if (substr($pathFile, -4) === ".php") {
        include_once __DIR__ . "/../paths/" . $pathFile;
    }
}

