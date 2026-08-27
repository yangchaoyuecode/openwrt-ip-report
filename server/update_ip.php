<?php
/**
 * update_ip.php
 * 简易DDNS替代方案 - 记录OpenWrt路由器上报的最新公网IP
 *
 * 用法:
 *   GET https://你的域名/update_ip.php?token=YOUR_SECRET_TOKEN&host=home
 *
 * 部署要求: 一台有公网可访问域名/IP的PHP虚拟主机或VPS
 */

// ============ 配置区(务必修改) ============
$SECRET_TOKEN = 'change_this_to_a_long_random_string'; // 改成足够长的随机字符串
$DATA_DIR = __DIR__ . '/ip_data';       // 数据存储目录
$LOG_FILE = $DATA_DIR . '/update.log';  // 日志文件
// ============================================

if (!is_dir($DATA_DIR)) {
    mkdir($DATA_DIR, 0755, true);
}

function respond($code, $msg, $data = []) {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code($code);
    echo json_encode(array_merge(['msg' => $msg], $data), JSON_UNESCAPED_UNICODE);
    exit;
}

function write_log($text) {
    global $LOG_FILE;
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $text . "\n";
    file_put_contents($LOG_FILE, $line, FILE_APPEND | LOCK_EX);
}

// 校验token
$token = $_GET['token'] ?? '';
if (!hash_equals($SECRET_TOKEN, $token)) {
    write_log("拒绝请求: token错误, 来源IP=" . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
    respond(403, 'invalid token');
}

// host标识,支持多台设备/多个域名分别记录
$host = preg_replace('/[^a-zA-Z0-9_\-]/', '', $_GET['host'] ?? 'default');
if ($host === '') {
    $host = 'default';
}

// 服务器看到的真实来源IP(最可靠,不会被伪造)
// 如果你的服务器前面有CDN/反向代理, 需要改成解析 X-Forwarded-For
$client_ip = $_SERVER['REMOTE_ADDR'] ?? '';

// 判断这次连接用的是v4还是v6
$is_v4 = filter_var($client_ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false;
$is_v6 = filter_var($client_ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false;

if (!$is_v4 && !$is_v6) {
    respond(400, 'cannot determine client ip');
}
$family = $is_v4 ? 'ipv4' : 'ipv6';

$record_file = $DATA_DIR . '/' . $host . '.json';

// 读取旧记录, 保留另一个协议族的数据不被覆盖
$old = [];
if (file_exists($record_file)) {
    $old = json_decode(file_get_contents($record_file), true) ?: [];
}

$old_ip_same_family = $old[$family]['ip'] ?? '';
$changed = $old_ip_same_family !== $client_ip;

$old[$family] = [
    'ip'          => $client_ip,
    'update_time' => date('Y-m-d H:i:s'),
    'timestamp'   => time(),
    'source'      => 'connection', // 通过实际连接来源确认, 最可靠
];
$old['host'] = $host;

// ============ 自报IPv6地址支持 ============
// 场景: 服务器只有公网v4, 收不到v6连接, 但路由器本地网卡确实有v6地址,
// 允许客户端通过v4连接把本地读到的v6地址当参数传过来。
// 注意: 这种方式服务器无法像验证v4那样交叉验证真实性, 是客户端自己说的。
$reported_v6 = $_GET['v6'] ?? '';
if ($reported_v6 !== '' && filter_var($reported_v6, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
    $old_v6 = $old['ipv6']['ip'] ?? '';
    $v6_changed = $old_v6 !== $reported_v6;
    $old['ipv6'] = [
        'ip'          => $reported_v6,
        'update_time' => date('Y-m-d H:i:s'),
        'timestamp'   => time(),
        'source'      => 'self-reported', // 客户端自报, 服务器未直接验证
    ];
    if ($v6_changed) {
        write_log("[{$host}][ipv6-self-reported] IP变更: " . ($old_v6 ?: '无') . " -> {$reported_v6}");
    }
}
// ==============================================

file_put_contents($record_file, json_encode($old, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX);

if ($changed) {
    write_log("[{$host}][{$family}] IP变更: " . ($old_ip_same_family ?: '无') . " -> {$client_ip}");
    // ============ IP变更后想做的额外操作,写在这里 ============
    // 例如: 调用你的DNS服务商API更新解析记录(v4更新A记录, v6更新AAAA记录)
    // 例如: 用Server酱/Telegram Bot/邮件发个变更通知
    // ==========================================================
} else {
    write_log("[{$host}][{$family}] 心跳, IP未变化: {$client_ip}");
}

respond(200, 'ok', ['family' => $family, 'ip' => $client_ip, 'changed' => $changed]);
