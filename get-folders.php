<?php
header("Content-Type: application/json");

$token = "kqNT7Z8BpwhA0d4MFZVgju0kZbR12PpsX93VWhpTOL5i4jVefcDdX";

$url = "https://api.pcloud.com/listfolder?folderid=0&recursive=1&access_token=" . $token;

$res = file_get_contents($url);
$json = json_decode($res, true);

if (!isset($json["metadata"])) {
    echo json_encode([]);
    exit;
}

$folders = [];

function collectFolders($items, &$folders) {
    foreach ($items as $i) {
        if (isset($i['isfolder']) && $i['isfolder']) {

            // some folders do not contain `path` → generate fallback
            $path = isset($i['path']) ? $i['path'] : "/" . $i['name'];

            $folders[] = [
                "folderid" => $i['folderid'],
                "name"     => $i['name'],
                "path"     => $path
            ];

            if (isset($i['contents'])) {
                collectFolders($i['contents'], $folders);
            }
        }
    }
}

collectFolders($json["metadata"]["contents"], $folders);

// Sort correctly
usort($folders, fn($a, $b) => strcmp($a["path"], $b["path"]));

// FRONTEND EXPECTS ARRAY ONLY!
echo json_encode($folders);
