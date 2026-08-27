<?php
/**
 * get_ip.php
 * 查询某个host最近一次上报记录的IP
 * 用法: GET https://你的域名/get_ip.php?host=home
 *
 * 注意: 建议给这个接口加访问限制(比如放到.htaccess/nginx里限制IP,
 * 或者也加个token),避免被外人随便看到你家里的公网IP。
 */

$DATA_DIR = __DIR__ . '/ip_data';
$host = preg_replace('/[^a-zA-Z0-9_\-]/', '', $_GET['host'] ?? 'default');
$record_file = $DATA_DIR . '/' . $host . '.json';

header('Content-Type: application/json; charset=utf-8');

if (!file_exists($record_file)) {
    http_response_code(404);
    echo json_encode(['msg' => 'no record for this host']);
    exit;
}

$data = json_decode(file_get_contents($record_file), true);

// 可选: ?family=ipv4 或 ?family=ipv6 只看某一个
$family = $_GET['family'] ?? '';
if ($family === 'ipv4' || $family === 'ipv6') {
    echo json_encode($data[$family] ?? ['msg' => 'no record for this family'], JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
