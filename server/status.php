<?php
/**
 * status.php
 * 汇总展示所有家庭上报的最新IP状态
 * 用法: https://你的域名/jk/xd/status.php
 * 首次访问要求输入密码, 登录状态保留N天(可在页面内设置)
 * 每个家庭可设置一个端口, 自动生成 http://IP:端口 的直达链接
 */

// ============ 配置区(务必修改) ============
$VIEW_PASSWORD = 'change_this_to_another_random_string'; // 查看密码
$DATA_DIR = __DIR__ . '/ip_data';
$CONFIG_FILE = $DATA_DIR . '/status_config.json';
$DEFAULT_SESSION_DAYS = 30;
// ============================================

if (!is_dir($DATA_DIR)) {
    mkdir($DATA_DIR, 0755, true);
}

// 读取配置(免登录天数 + 每个host的端口), 不存在就用默认值
function load_config($file, $default_days) {
    if (file_exists($file)) {
        $c = json_decode(file_get_contents($file), true);
        if (is_array($c)) {
            $c['session_days'] = $c['session_days'] ?? $default_days;
            $c['ports'] = $c['ports'] ?? [];
            return $c;
        }
    }
    return ['session_days' => $default_days, 'ports' => []];
}

$config = load_config($CONFIG_FILE, $DEFAULT_SESSION_DAYS);

// session cookie 有效期需要在 session_start 之前设置, 所以配置要先读出来
session_set_cookie_params((int)$config['session_days'] * 86400);
session_start();

$authed = !empty($_SESSION['authed']);

// 处理登录提交
$login_error = '';
if (!$authed && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['password'])) {
    if (hash_equals($VIEW_PASSWORD, $_POST['password'])) {
        session_regenerate_id(true);
        $_SESSION['authed'] = true;
        header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
        exit;
    } else {
        $login_error = '密码错误';
    }
}

// 退出登录
if (isset($_GET['logout'])) {
    $_SESSION = [];
    session_destroy();
    header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
    exit;
}

// 保存设置(免登录天数 + 各host端口), 仅登录状态下允许
$save_msg = '';
if ($authed && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_settings') {
    $days = (int)($_POST['session_days'] ?? $DEFAULT_SESSION_DAYS);
    if ($days < 1) $days = 1;

    $ports = [];
    if (isset($_POST['port']) && is_array($_POST['port'])) {
        foreach ($_POST['port'] as $host => $port_str) {
            $host_clean = preg_replace('/[^a-zA-Z0-9_\-]/', '', $host);
            if ($host_clean === '') continue;

            $port_list = [];
            foreach (explode(',', $port_str) as $p) {
                $p = trim($p);
                if ($p === '') continue;
                $p_num = (int)$p;
                if ($p_num >= 1 && $p_num <= 65535) {
                    $port_list[] = $p_num;
                }
            }
            $port_list = array_values(array_unique($port_list));
            if (!empty($port_list)) {
                $ports[$host_clean] = $port_list;
            }
        }
    }

    $config = ['session_days' => $days, 'ports' => $ports];
    file_put_contents($CONFIG_FILE, json_encode($config, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX);

    header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?') . '?saved=1');
    exit;
}
if (isset($_GET['saved'])) {
    $save_msg = '设置已保存';
}

// ============ 未登录: 只显示密码输入框 ============
if (!$authed) {
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>需要登录</title>
<style>
    body {
        font-family: -apple-system, "Segoe UI", "PingFang SC", "Microsoft YaHei", sans-serif;
        background: #f5f6f8;
        margin: 0;
        min-height: 100vh;
        display: flex;
        align-items: center;
        justify-content: center;
        padding: 24px;
        box-sizing: border-box;
    }
    .login-box {
        background: #fff;
        border-radius: 12px;
        padding: 32px 28px;
        box-shadow: 0 2px 8px rgba(0,0,0,0.08);
        width: 100%;
        max-width: 320px;
    }
    .login-box h1 {
        font-size: 17px;
        margin: 0 0 20px;
        text-align: center;
        color: #1a1a1a;
    }
    input[type="password"] {
        width: 100%;
        box-sizing: border-box;
        padding: 10px 12px;
        border: 1px solid #ddd;
        border-radius: 8px;
        font-size: 15px;
        margin-bottom: 12px;
    }
    input[type="password"]:focus {
        outline: none;
        border-color: #1a7f37;
    }
    button {
        width: 100%;
        padding: 10px;
        border: none;
        border-radius: 8px;
        background: #1a7f37;
        color: #fff;
        font-size: 15px;
        cursor: pointer;
    }
    button:hover {
        background: #166830;
    }
    .error {
        color: #c0392b;
        font-size: 13px;
        margin-bottom: 12px;
        text-align: center;
    }
</style>
</head>
<body>
    <div class="login-box">
        <h1>请输入访问密码</h1>
        <?php if ($login_error): ?>
            <div class="error"><?= htmlspecialchars($login_error) ?></div>
        <?php endif; ?>
        <form method="post">
            <input type="password" name="password" placeholder="密码" autofocus required>
            <button type="submit">进入</button>
        </form>
    </div>
</body>
</html>
<?php
    exit;
}

// ============ 已登录: 正常展示内容 ============

// 读取所有 host 的记录文件
$records = [];
if (is_dir($DATA_DIR)) {
    foreach (glob($DATA_DIR . '/*.json') as $file) {
        // 跳过配置文件本身
        if (basename($file) === basename($CONFIG_FILE)) continue;
        $data = json_decode(file_get_contents($file), true);
        if ($data) {
            $records[] = $data;
        }
    }
}

// 按host名字排序
usort($records, fn($a, $b) => strcmp($a['host'] ?? '', $b['host'] ?? ''));

function fmt_ago($timestamp) {
    if (!$timestamp) return '-';
    $diff = time() - $timestamp;
    if ($diff < 60) return $diff . '秒前';
    if ($diff < 3600) return floor($diff / 60) . '分钟前';
    if ($diff < 86400) return floor($diff / 3600) . '小时前';
    return floor($diff / 86400) . '天前';
}

function is_stale($timestamp, $threshold = 3600) {
    return !$timestamp || (time() - $timestamp) > $threshold;
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>家庭公网IP状态</title>
<style>
    body {
        font-family: -apple-system, "Segoe UI", "PingFang SC", "Microsoft YaHei", sans-serif;
        background: #f5f6f8;
        margin: 0;
        padding: 24px;
        color: #1a1a1a;
    }
    .page-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 16px;
    }
    h1 {
        font-size: 20px;
        margin: 0;
    }
    .header-links {
        display: flex;
        align-items: center;
        gap: 14px;
    }
    .header-links a {
        font-size: 13px;
        color: #999;
        text-decoration: none;
        cursor: pointer;
    }
    .header-links a:hover {
        color: #1a1a1a;
    }
    .card {
        background: #fff;
        border-radius: 10px;
        padding: 16px 20px;
        margin-bottom: 12px;
        box-shadow: 0 1px 3px rgba(0,0,0,0.08);
    }
    .card-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 10px;
        gap: 8px;
    }
    .host-name-group {
        display: flex;
        align-items: center;
        gap: 8px;
        min-width: 0;
        flex-wrap: wrap;
    }
    .host-name {
        font-size: 16px;
        font-weight: 600;
        white-space: nowrap;
    }
    .open-link {
        font-size: 12px;
        color: #fff;
        background: #2563eb;
        padding: 3px 10px;
        border-radius: 999px;
        text-decoration: none;
        white-space: nowrap;
    }
    .open-link:hover {
        background: #1d4ed8;
    }
    .badge {
        font-size: 12px;
        padding: 2px 8px;
        border-radius: 999px;
        background: #e6f4ea;
        color: #1a7f37;
        white-space: nowrap;
        flex-shrink: 0;
    }
    .badge.stale {
        background: #fdecea;
        color: #c0392b;
    }
    .row {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 6px 0;
        border-top: 1px solid #f0f0f0;
        font-size: 14px;
    }
    .row:first-child { border-top: none; }
    .label {
        color: #666;
        width: 60px;
        flex-shrink: 0;
    }
    .ip {
        font-family: "SF Mono", Consolas, monospace;
        word-break: break-all;
        flex: 1;
    }
    .time {
        color: #999;
        font-size: 12px;
        white-space: nowrap;
        margin-left: 12px;
    }
    .copy-btn {
        border: none;
        background: #f0f1f3;
        color: #555;
        font-size: 12px;
        padding: 4px 10px;
        border-radius: 6px;
        cursor: pointer;
        margin-left: 8px;
        flex-shrink: 0;
        transition: background 0.15s, color 0.15s;
    }
    .copy-btn:hover {
        background: #e2e4e8;
    }
    .copy-btn.copied {
        background: #1a7f37;
        color: #fff;
    }
    .empty {
        color: #999;
        font-size: 14px;
        padding: 20px;
        text-align: center;
    }
    /* 设置面板 */
    .settings-panel {
        display: none;
        background: #fff;
        border-radius: 10px;
        padding: 20px;
        margin-bottom: 16px;
        box-shadow: 0 1px 3px rgba(0,0,0,0.08);
    }
    .settings-panel.open {
        display: block;
    }
    .settings-panel h2 {
        font-size: 15px;
        margin: 0 0 14px;
    }
    .field-row {
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding: 8px 0;
        border-top: 1px solid #f0f0f0;
        font-size: 14px;
        gap: 12px;
    }
    .field-row:first-of-type {
        border-top: none;
    }
    .field-row label {
        color: #444;
        flex-shrink: 0;
    }
    .field-row input[type="number"],
    .field-row input[type="text"] {
        width: 160px;
        padding: 6px 10px;
        border: 1px solid #ddd;
        border-radius: 6px;
        font-size: 14px;
        text-align: right;
    }
    .settings-panel .save-btn {
        margin-top: 16px;
        width: 100%;
        padding: 10px;
        border: none;
        border-radius: 8px;
        background: #1a7f37;
        color: #fff;
        font-size: 14px;
        cursor: pointer;
    }
    .settings-panel .save-btn:hover {
        background: #166830;
    }
    .save-msg {
        background: #e6f4ea;
        color: #1a7f37;
        font-size: 13px;
        padding: 8px 14px;
        border-radius: 8px;
        margin-bottom: 14px;
    }
    .hint {
        font-size: 12px;
        color: #999;
        margin-top: -6px;
        margin-bottom: 10px;
    }
</style>
</head>
<body>
<div class="page-header">
    <h1>家庭公网IP状态</h1>
    <div class="header-links">
        <a onclick="toggleSettings()">设置</a>
        <a href="?logout=1">退出登录</a>
    </div>
</div>

<?php if ($save_msg): ?>
    <div class="save-msg"><?= htmlspecialchars($save_msg) ?></div>
<?php endif; ?>

<div class="settings-panel" id="settingsPanel">
    <h2>常规设置</h2>
    <form method="post">
        <input type="hidden" name="action" value="save_settings">

        <div class="field-row">
            <label>免登录天数</label>
            <input type="number" name="session_days" min="1" value="<?= (int)$config['session_days'] ?>">
        </div>
        <div class="hint">修改后, 下次重新登录时生效; 注意部分浏览器(如Chrome)会把cookie有效期强制限制在400天以内, 填更大的数字也不会超过这个实际上限</div>

        <?php if (!empty($records)): ?>
            <h2 style="margin-top:18px;">各家庭访问端口</h2>
            <?php foreach ($records as $r): $h = $r['host'] ?? ''; ?>
                <div class="field-row">
                    <label><?= htmlspecialchars($h) ?></label>
                    <input type="text" name="port[<?= htmlspecialchars($h) ?>]"
                        placeholder="如 80,8080,443"
                        value="<?= isset($config['ports'][$h]) ? htmlspecialchars(implode(',', (array)$config['ports'][$h])) : '' ?>">
                </div>
            <?php endforeach; ?>
            <div class="hint">多个端口用英文逗号分隔, 设置后每个家庭卡片上会分别出现对应的 http://IP:端口 直达链接</div>
        <?php endif; ?>

        <button type="submit" class="save-btn">保存设置</button>
    </form>
</div>

<?php if (empty($records)): ?>
    <div class="card empty">暂无记录</div>
<?php else: foreach ($records as $r): ?>
    <?php
        $host = $r['host'] ?? '';
        $v4 = $r['ipv4'] ?? null;
        $v6 = $r['ipv6'] ?? null;
        $latest_ts = max($v4['timestamp'] ?? 0, $v6['timestamp'] ?? 0);
        $stale = is_stale($latest_ts);
        $ports_for_host = $config['ports'][$host] ?? [];
        // 优先用v4拼直达链接, 没有v4再用v6(IPv6地址需要用方括号包起来)
        $open_urls = [];
        foreach ($ports_for_host as $p) {
            if ($v4) {
                $open_urls[] = ['port' => $p, 'url' => 'http://' . $v4['ip'] . ':' . $p];
            } elseif ($v6) {
                $open_urls[] = ['port' => $p, 'url' => 'http://[' . $v6['ip'] . ']:' . $p];
            }
        }
    ?>
    <div class="card">
        <div class="card-header">
            <div class="host-name-group">
                <span class="host-name"><?= htmlspecialchars($host ?: '未知') ?></span>
                <?php foreach ($open_urls as $item): ?>
                    <a class="open-link" href="<?= htmlspecialchars($item['url']) ?>" target="_blank" rel="noopener">打开 :<?= (int)$item['port'] ?></a>
                <?php endforeach; ?>
            </div>
            <span class="badge <?= $stale ? 'stale' : '' ?>"><?= $stale ? '可能离线' : '在线' ?></span>
        </div>
        <?php if ($v4): ?>
        <div class="row">
            <span class="label">IPv4</span>
            <span class="ip"><?= htmlspecialchars($v4['ip']) ?></span>
            <button class="copy-btn" onclick="copyIp(this, '<?= htmlspecialchars($v4['ip'], ENT_QUOTES) ?>')">复制</button>
            <span class="time"><?= fmt_ago($v4['timestamp']) ?></span>
        </div>
        <?php endif; ?>
        <?php if ($v6): ?>
        <div class="row">
            <span class="label">IPv6</span>
            <span class="ip"><?= htmlspecialchars($v6['ip']) ?></span>
            <button class="copy-btn" onclick="copyIp(this, '<?= htmlspecialchars($v6['ip'], ENT_QUOTES) ?>')">复制</button>
            <span class="time"><?= fmt_ago($v6['timestamp']) ?></span>
        </div>
        <?php endif; ?>
        <?php if (!$v4 && !$v6): ?>
        <div class="row"><span class="label">状态</span><span class="ip">暂无数据</span></div>
        <?php endif; ?>
    </div>
<?php endforeach; endif; ?>

<script>
function toggleSettings() {
    document.getElementById('settingsPanel').classList.toggle('open');
}
<?php if ($save_msg): ?>
document.getElementById('settingsPanel').classList.add('open');
<?php endif; ?>

function copyIp(btn, text) {
    var done = function () {
        var original = btn.textContent;
        btn.textContent = '已复制';
        btn.classList.add('copied');
        setTimeout(function () {
            btn.textContent = original;
            btn.classList.remove('copied');
        }, 1200);
    };
    var fail = function () {
        var ta = document.createElement('textarea');
        ta.value = text;
        ta.style.position = 'fixed';
        ta.style.opacity = '0';
        document.body.appendChild(ta);
        ta.focus();
        ta.select();
        try {
            document.execCommand('copy');
            done();
        } catch (e) {
            alert('复制失败,请手动选择文本复制');
        }
        document.body.removeChild(ta);
    };

    if (navigator.clipboard && window.isSecureContext) {
        navigator.clipboard.writeText(text).then(done).catch(fail);
    } else {
        fail();
    }
}
</script>

</body>
</html>
