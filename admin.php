<?php

/**
 * =====================================================
 * 趣味学习助手 - 抽卡大乐透 (Study Gacha) - 管理员后台
 * =====================================================
 *
 * @author      fghdz
 * @version     1.1.0
 * @date        2026-08-05
 * @license     MIT
 *
 * =====================================================
 * v1.1.0 修复与改进
 * =====================================================
 * 1. 兼容 PHP 8.0+ / 8.1+ / 8.2+：
 *    - 所有 $_POST 访问改为安全取值函数，杜绝 Undefined array key 警告
 * 2. 安全加固：
 *    - 管理员密码改为 password_hash 哈希存储（兼容旧明文，登录成功自动升级）
 *    - 用户管理操作增加用户名合法性校验，防路径遍历
 *    - 用户数据编辑弹窗 JSON 转义修复，防止破坏 HTML
 *    - 登出操作使用 POST 表单提交
 * 3. 配置写入：
 *    - 保持 admin_config.php 为 PHP 的 "return array(...)" 格式，
 *      与 index.php 的 include 读取方式完全兼容
 * 4. UI 改进：
 *    - 学习时长格式化支持超过 24 小时
 *    - 修复用户表格在移动端显示
 */

session_start();

define('ADMIN_FILE', __DIR__ . '/admin_config.php');
define('USER_DATA_DIR', __DIR__ . '/data/user_data/');

if (!ini_get('date.timezone')) {
    date_default_timezone_set('Asia/Shanghai');
}

/** 安全获取 POST 字符串字段 */
function post($key, $default = '') {
    return (isset($_POST[$key]) && is_string($_POST[$key])) ? $_POST[$key] : $default;
}

/** 用户名合法性校验 */
function isSafeUsername($username) {
    return is_string($username)
        && preg_match('/^[\x{4e00}-\x{9fa5}a-zA-Z0-9_-]{2,20}$/u', $username) === 1;
}

/** 判断字符串是否为 bcrypt 密码哈希 */
function isHash($str) {
    return is_string($str) && preg_match('/^\$2[aby]\$/', $str) === 1;
}

/** 读取 JSON 数据文件 */
function readJsonFile($file) {
    if (!is_file($file)) {
        return null;
    }
    $content = @file_get_contents($file);
    if ($content === false) {
        return null;
    }
    $content = preg_replace('/^<\?php exit; \?>\s*/', '', $content);
    $data = json_decode($content, true);
    return is_array($data) ? $data : null;
}

/** 写入 JSON 数据文件（带 LOCK_EX） */
function writeJsonFile($file, $data) {
    $dir = dirname($file);
    if (!is_dir($dir)) {
        @mkdir($dir, 0777, true);
    }
    $content = "<?php exit; ?>\n" . json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    return @file_put_contents($file, $content, LOCK_EX) !== false;
}

/** 读取管理员配置 */
function readAdminConfig() {
    if (!is_file(ADMIN_FILE)) {
        // 默认配置
        $defaultConfig = [
            'admin' => [
                'username' => 'admin',
                'password' => 'admin123'
            ],
            'gacha' => [
                'pools' => [
                    'common' => ['name' => '普通卡池', 'cost' => 10, 'color' => '#9CA3AF', 'min_reward' => 1, 'max_reward' => 5, 'probability' => 60],
                    'rare' => ['name' => '稀有卡池', 'cost' => 50, 'color' => '#3B82F6', 'min_reward' => 6, 'max_reward' => 20, 'probability' => 25],
                    'epic' => ['name' => '史诗卡池', 'cost' => 200, 'color' => '#8B5CF6', 'min_reward' => 21, 'max_reward' => 50, 'probability' => 10],
                    'legendary' => ['name' => '传说卡池', 'cost' => 500, 'color' => '#F59E0B', 'min_reward' => 51, 'max_reward' => 200, 'probability' => 4],
                    'mythic' => ['name' => '神话卡池', 'cost' => 1000, 'color' => '#EC4899', 'min_reward' => 201, 'max_reward' => 500, 'probability' => 1]
                ],
                'special_events' => [
                    'enabled' => true,
                    'double_probability' => 5,
                    'jackpot_multiplier' => 10,
                    'consolation_prize' => 1
                ]
            ]
        ];
        writeAdminConfig($defaultConfig);
        return $defaultConfig;
    }

    // 兼容两种格式：
    // 1. PHP return array(...) 格式（旧版，include 读取）
    // 2. exit防护前缀 + JSON 格式（可读性更好）
    $content = @file_get_contents(ADMIN_FILE);
    if ($content !== false) {
        $stripped = preg_replace('/^<\?php exit; \?>\s*/', '', $content);
        if ($stripped !== $content) {
            // 是 JSON 格式
            $data = json_decode($stripped, true);
            if (is_array($data)) {
                return $data;
            }
        }
    }

    $config = @include(ADMIN_FILE);
    return is_array($config) ? $config : readAdminConfig();
}

/** 保存管理员配置（PHP return array 格式，与 index.php include 兼容） */
function writeAdminConfig($config) {
    $dir = dirname(ADMIN_FILE);
    if (!is_dir($dir)) {
        @mkdir($dir, 0777, true);
    }
    $content = "<?php\nreturn " . var_export($config, true) . ";\n";
    return @file_put_contents(ADMIN_FILE, $content, LOCK_EX) !== false;
}

/**
 * 校验管理员密码
 * 兼容新格式（bcrypt哈希）与旧格式（明文），登录成功自动升级为哈希存储
 */
function verifyAdminPassword($input, $stored, &$config) {
    if (isHash($stored)) {
        if (password_verify($input, $stored)) {
            return true;
        }
        return false;
    }
    // 旧明文格式：用 hash_equals 恒时比较
    if (hash_equals($stored, $input)) {
        // 升级为哈希存储
        $config['admin']['password'] = password_hash($input, PASSWORD_DEFAULT);
        writeAdminConfig($config);
        return true;
    }
    return false;
}

/** 读取所有用户 */
function getAllUsers() {
    $users = [];
    if (is_dir(USER_DATA_DIR)) {
        $files = glob(USER_DATA_DIR . '*.php');
        if ($files) {
            foreach ($files as $file) {
                $name = basename($file, '.php');
                if (!isSafeUsername($name)) {
                    continue;
                }
                $userData = readJsonFile($file);
                if ($userData) {
                    $users[$name] = $userData;
                }
            }
        }
    }
    ksort($users);
    return $users;
}

/** 格式化学习时长（支持超过 24 小时） */
function formatDuration($seconds) {
    $seconds = max(0, (int)$seconds);
    $h = floor($seconds / 3600);
    $m = floor(($seconds % 3600) / 60);
    $s = $seconds % 60;
    return sprintf('%02d:%02d:%02d', $h, $m, $s);
}

$config = readAdminConfig();
$message = '';
$messageType = '';

// ---------- 管理员登录 ----------
if (isset($_POST['login'])) {
    $username = post('username');
    $password = post('password');

    if (($config['admin']['username'] ?? '') === $username
        && !empty($password)
        && verifyAdminPassword($password, $config['admin']['password'] ?? '', $config)) {
        $_SESSION['admin_logged_in'] = true;
        $message = '登录成功！';
        $messageType = 'success';
    } else {
        $message = '用户名或密码错误！';
        $messageType = 'error';
    }
}

// ---------- 管理员登出（POST/GET） ----------
if (isset($_POST['logout']) || isset($_GET['logout'])) {
    unset($_SESSION['admin_logged_in']);
    session_destroy();
    header('Location: ' . basename($_SERVER['PHP_SELF']));
    exit;
}

// ---------- 修改管理员密码 ----------
if (isset($_POST['change_password']) && !empty($_SESSION['admin_logged_in'])) {
    $oldPassword = post('old_password');
    $newPassword = post('new_password');
    $confirmPassword = post('confirm_password');

    if (verifyAdminPassword($oldPassword, $config['admin']['password'] ?? '', $config)) {
        if (strlen($newPassword) < 6) {
            $message = '新密码长度至少6位！';
            $messageType = 'error';
        } elseif ($newPassword !== $confirmPassword) {
            $message = '新密码不一致！';
            $messageType = 'error';
        } else {
            $config['admin']['password'] = password_hash($newPassword, PASSWORD_DEFAULT);
            writeAdminConfig($config);
            $message = '密码修改成功！';
            $messageType = 'success';
        }
    } else {
        $message = '原密码错误！';
        $messageType = 'error';
    }
}

// ---------- 更新抽卡配置 ----------
if (isset($_POST['update_gacha']) && !empty($_SESSION['admin_logged_in'])) {
    foreach ($config['gacha']['pools'] as $key => $pool) {
        $config['gacha']['pools'][$key] = [
            'name' => trim(post($key . '_name', $pool['name'])),
            'cost' => max(1, (int)post($key . '_cost', $pool['cost'])),
            'color' => post($key . '_color', $pool['color']),
            'min_reward' => max(0, (int)post($key . '_min', $pool['min_reward'])),
            'max_reward' => max(0, (int)post($key . '_max', $pool['max_reward'])),
            'probability' => max(0, min(100, (int)post($key . '_prob', $pool['probability'])))
        ];
        // 保证 max >= min
        if ($config['gacha']['pools'][$key]['max_reward'] < $config['gacha']['pools'][$key]['min_reward']) {
            $config['gacha']['pools'][$key]['max_reward'] = $config['gacha']['pools'][$key]['min_reward'];
        }
    }

    $config['gacha']['special_events'] = [
        'enabled' => isset($_POST['special_enabled']),
        'double_probability' => max(0, (int)post('double_prob', 5)),
        'jackpot_multiplier' => max(1, (int)post('jackpot_mult', 10)),
        'consolation_prize' => max(0, (int)post('consolation', 1))
    ];

    writeAdminConfig($config);
    $message = '抽卡配置已更新！';
    $messageType = 'success';
}

// ---------- 用户管理操作 ----------
if (isset($_POST['user_action']) && !empty($_SESSION['admin_logged_in'])) {
    $action = post('user_action');
    $targetUser = post('target_user');

    if (!isSafeUsername($targetUser)) {
        $message = '目标用户不合法！';
        $messageType = 'error';
    } else {
        $userFile = USER_DATA_DIR . $targetUser . '.php';
        if ($action === 'delete' && is_file($userFile)) {
            @unlink($userFile);
            // 同步从用户列表删除
            $users = readJsonFile(__DIR__ . '/data/users.php');
            if (is_array($users) && isset($users[$targetUser])) {
                unset($users[$targetUser]);
                writeJsonFile(__DIR__ . '/data/users.php', $users);
            }
            $message = "用户 {$targetUser} 已删除！";
            $messageType = 'success';
        } elseif ($action === 'update' && is_file($userFile)) {
            $userData = readJsonFile($userFile);
            if ($userData) {
                if (isset($_POST['rest_flux']) && is_numeric($_POST['rest_flux'])) {
                    $userData['restFlux'] = max(0, (float)$_POST['rest_flux']);
                }
                if (isset($_POST['study_time']) && is_numeric($_POST['study_time'])) {
                    $userData['studyTime'] = max(0, (float)$_POST['study_time']);
                }
                if (isset($_POST['memory_value']) && is_numeric($_POST['memory_value'])) {
                    $userData['memoryValue'] = min(100, max(0, (float)$_POST['memory_value']));
                }
                writeJsonFile($userFile, $userData);
                $message = "用户 {$targetUser} 数据已更新！";
                $messageType = 'success';
            }
        }
    }
}

// 计算总概率
$totalProb = 0;
foreach ($config['gacha']['pools'] as $pool) {
    $totalProb += (int)($pool['probability'] ?? 0);
}
?>
<!DOCTYPE html>
<html lang="zh">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>管理员后台 - 抽卡配置</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', 'Microsoft YaHei', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #1a1a2e 0%, #16213e 100%);
            min-height: 100vh;
            padding: 20px;
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
        }

        .login-card, .admin-card {
            background: rgba(255, 255, 255, 0.97);
            border-radius: 20px;
            padding: 30px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            margin-bottom: 20px;
        }

        h1 {
            text-align: center;
            color: #333;
            margin-bottom: 30px;
            font-size: 2.2em;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        h2 {
            color: #4a5568;
            margin: 20px 0;
            padding-bottom: 10px;
            border-bottom: 2px solid #e2e8f0;
        }

        h3 {
            color: #4a5568;
            margin: 15px 0;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            color: #333;
            font-weight: 500;
        }

        .form-group input, .form-group select {
            width: 100%;
            padding: 12px 15px;
            border: 2px solid #e0e0e0;
            border-radius: 10px;
            font-size: 16px;
            transition: all 0.3s;
        }

        .form-group input:focus, .form-group select:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }

        .form-group small {
            display: block;
            margin-top: 5px;
            color: #718096;
            font-size: 12px;
        }

        .btn {
            padding: 12px 24px;
            border: none;
            border-radius: 10px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            color: white;
        }

        .btn-primary { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); }
        .btn-success { background: linear-gradient(135deg, #48bb78 0%, #38a169 100%); }
        .btn-danger { background: linear-gradient(135deg, #f56565 0%, #e53e3e 100%); }
        .btn-warning { background: linear-gradient(135deg, #ed8936 0%, #dd6b20 100%); }

        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 20px rgba(0, 0, 0, 0.2);
        }

        .message {
            padding: 12px;
            border-radius: 10px;
            margin-bottom: 20px;
        }

        .message.error {
            background: #fed7d7;
            color: #c53030;
            border: 1px solid #fc8181;
        }

        .message.success {
            background: #c6f6d5;
            color: #276749;
            border: 1px solid #9ae6b4;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .stat-card {
            background: linear-gradient(135deg, #f3f4f6 0%, #e5e7eb 100%);
            border-radius: 15px;
            padding: 20px;
            text-align: center;
        }

        .stat-label {
            font-size: 14px;
            color: #718096;
            margin-bottom: 10px;
        }

        .stat-value {
            font-size: 32px;
            font-weight: bold;
            color: #2d3748;
        }

        .stat-value.success { color: #48bb78; }
        .stat-value.warning { color: #ed8936; }
        .stat-value.danger { color: #f56565; }

        .pools-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .pool-card {
            background: white;
            border-radius: 15px;
            padding: 20px;
            border: 2px solid;
            transition: all 0.3s;
        }

        .pool-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.2);
        }

        .pool-header {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 15px;
        }

        .pool-color {
            width: 30px;
            height: 30px;
            border-radius: 50%;
        }

        .pool-title {
            font-size: 20px;
            font-weight: bold;
        }

        .user-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }

        .user-table th, .user-table td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #e2e8f0;
        }

        .user-table th {
            background: #f7fafc;
            font-weight: 600;
            color: #4a5568;
        }

        .user-table tr:hover {
            background: #f7fafc;
        }

        .action-buttons {
            display: flex;
            gap: 10px;
        }

        .action-buttons button {
            padding: 6px 12px;
            font-size: 14px;
        }

        .tabs {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
            border-bottom: 2px solid #e2e8f0;
            padding-bottom: 10px;
            flex-wrap: wrap;
        }

        .tab {
            padding: 10px 20px;
            cursor: pointer;
            border-radius: 8px;
            transition: all 0.3s;
            color: #4a5568;
        }

        .tab.active {
            background: #667eea;
            color: white;
        }

        .tab-content {
            display: none;
        }

        .tab-content.active {
            display: block;
            animation: fadeIn 0.5s ease;
        }

        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }

        .warning-box {
            background: #fff5f5;
            border: 2px solid #fc8181;
            border-radius: 10px;
            padding: 20px;
            margin: 20px 0;
        }

        .info-box {
            background: #ebf4ff;
            border-left: 4px solid #4299e1;
            padding: 15px;
            border-radius: 8px;
            margin: 20px 0;
        }

        .user-info {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding: 15px;
            background: #edf2f7;
            border-radius: 10px;
            flex-wrap: wrap;
            gap: 10px;
        }

        .logout-btn {
            padding: 8px 16px;
            background: #e53e3e;
            color: white;
            text-decoration: none;
            border-radius: 8px;
            font-size: 14px;
            transition: all 0.3s;
            display: inline-block;
            border: none;
            cursor: pointer;
        }

        .logout-btn:hover {
            background: #c53030;
            transform: translateY(-2px);
        }

        /* 编辑用户模态框 */
        #editUserModal {
            display: none;
            position: fixed;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            background: white;
            padding: 30px;
            border-radius: 20px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            z-index: 1000;
            max-width: 500px;
            width: 90%;
        }

        #modalOverlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.5);
            z-index: 999;
        }

        @media (max-width: 768px) {
            .pools-grid {
                grid-template-columns: 1fr;
            }

            .user-table {
                font-size: 13px;
            }

            .action-buttons {
                flex-direction: column;
            }

            .user-table th, .user-table td {
                padding: 8px;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <?php if (empty($_SESSION['admin_logged_in'])): ?>
            <!-- 管理员登录界面 -->
            <div class="login-card">
                <h1>管理员后台登录</h1>

                <?php if ($message): ?>
                    <div class="message <?php echo $messageType; ?>">
                        <?php echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?>
                    </div>
                <?php endif; ?>

                <form method="POST">
                    <div class="form-group">
                        <label>用户名</label>
                        <input type="text" name="username" required autocomplete="username">
                    </div>
                    <div class="form-group">
                        <label>密码</label>
                        <input type="password" name="password" required autocomplete="current-password">
                    </div>
                    <button type="submit" name="login" class="btn btn-primary">登录</button>
                </form>

                <div class="info-box" style="margin-top: 20px;">
                    <h4>管理员账号提示</h4>
                    <p>默认用户名: admin</p>
                    <p>默认密码见 admin_config.php 配置文件（已加密存储）</p>
                    <p style="color: #e53e3e;">⚠️ 首次登录后请立即修改密码！</p>
                </div>
            </div>
        <?php else: ?>
            <!-- 管理员主界面 -->
            <div class="admin-card">
                <div class="user-info">
                    <span>
                        欢迎回来，管理员 <?php echo htmlspecialchars((string)($config['admin']['username'] ?? 'admin'), ENT_QUOTES, 'UTF-8'); ?>
                    </span>
                    <form method="POST" style="margin: 0;">
                        <button type="submit" name="logout" class="logout-btn">退出登录</button>
                    </form>
                </div>

                <h1>抽卡系统管理后台</h1>

                <?php if ($message): ?>
                    <div class="message <?php echo $messageType; ?>">
                        <?php echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?>
                    </div>
                <?php endif; ?>

                <!-- 统计卡片 -->
                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-label">总卡池数</div>
                        <div class="stat-value"><?php echo count($config['gacha']['pools']); ?></div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-label">总概率</div>
                        <div class="stat-value <?php echo $totalProb != 100 ? 'danger' : 'success'; ?>">
                            <?php echo $totalProb; ?>%
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-label">最高奖励</div>
                        <div class="stat-value">
                            <?php
                            $maxReward = 0;
                            foreach ($config['gacha']['pools'] as $pool) {
                                $maxReward = max($maxReward, (int)($pool['max_reward'] ?? 0));
                            }
                            echo $maxReward;
                            ?> 通量
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-label">特殊事件</div>
                        <div class="stat-value <?php echo !empty($config['gacha']['special_events']['enabled']) ? 'success' : 'warning'; ?>">
                            <?php echo !empty($config['gacha']['special_events']['enabled']) ? '开启' : '关闭'; ?>
                        </div>
                    </div>
                </div>

                <!-- 标签页 -->
                <div class="tabs">
                    <div class="tab active" onclick="switchTab('gacha')">抽卡配置</div>
                    <div class="tab" onclick="switchTab('users')">用户管理</div>
                    <div class="tab" onclick="switchTab('password')">修改密码</div>
                </div>

                <!-- 抽卡配置标签 -->
                <div id="tab-gacha" class="tab-content active">
                    <h2>🎰 抽卡卡池配置</h2>

                    <form method="POST">
                        <div class="pools-grid">
                            <?php foreach ($config['gacha']['pools'] as $key => $pool):
                                $pool = array_merge(['name' => '卡池', 'cost' => 10, 'color' => '#9CA3AF', 'min_reward' => 0, 'max_reward' => 0, 'probability' => 0], $pool);
                            ?>
                            <div class="pool-card" style="border-color: <?php echo htmlspecialchars($pool['color'], ENT_QUOTES, 'UTF-8'); ?>">
                                <div class="pool-header">
                                    <div class="pool-color" style="background: <?php echo htmlspecialchars($pool['color'], ENT_QUOTES, 'UTF-8'); ?>"></div>
                                    <div class="pool-title"><?php echo htmlspecialchars($pool['name'], ENT_QUOTES, 'UTF-8'); ?></div>
                                </div>

                                <div class="form-group">
                                    <label>卡池名称</label>
                                    <input type="text" name="<?php echo $key; ?>_name" value="<?php echo htmlspecialchars($pool['name'], ENT_QUOTES, 'UTF-8'); ?>" required>
                                </div>

                                <div class="form-group">
                                    <label>消耗通量</label>
                                    <input type="number" name="<?php echo $key; ?>_cost" value="<?php echo (int)$pool['cost']; ?>" min="1" required>
                                </div>

                                <div class="form-group">
                                    <label>颜色代码</label>
                                    <input type="color" name="<?php echo $key; ?>_color" value="<?php echo htmlspecialchars($pool['color'], ENT_QUOTES, 'UTF-8'); ?>" required>
                                </div>

                                <div class="form-group">
                                    <label>最小奖励 (通量)</label>
                                    <input type="number" name="<?php echo $key; ?>_min" value="<?php echo (int)$pool['min_reward']; ?>" min="0" required>
                                </div>

                                <div class="form-group">
                                    <label>最大奖励 (通量)</label>
                                    <input type="number" name="<?php echo $key; ?>_max" value="<?php echo (int)$pool['max_reward']; ?>" min="0" required>
                                </div>

                                <div class="form-group">
                                    <label>抽中概率 (%)</label>
                                    <input type="number" name="<?php echo $key; ?>_prob" value="<?php echo (int)$pool['probability']; ?>" min="0" max="100" required>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>

                        <h2>✨ 特殊事件配置</h2>

                        <div class="pool-card" style="margin-bottom: 20px;">
                            <div class="form-group">
                                <label>
                                    <input type="checkbox" name="special_enabled" <?php echo !empty($config['gacha']['special_events']['enabled']) ? 'checked' : ''; ?>>
                                    启用特殊事件
                                </label>
                            </div>

                            <div class="form-group">
                                <label>双倍概率 (%)</label>
                                <input type="number" name="double_prob" value="<?php echo (int)($config['gacha']['special_events']['double_probability'] ?? 5); ?>" min="0" max="100" required>
                                <small>抽卡时有概率获得双倍奖励</small>
                            </div>

                            <div class="form-group">
                                <label>大奖倍数</label>
                                <input type="number" name="jackpot_mult" value="<?php echo (int)($config['gacha']['special_events']['jackpot_multiplier'] ?? 10); ?>" min="1" required>
                                <small>1%概率获得此倍数奖励</small>
                            </div>

                            <div class="form-group">
                                <label>保底奖励 (通量)</label>
                                <input type="number" name="consolation" value="<?php echo (int)($config['gacha']['special_events']['consolation_prize'] ?? 1); ?>" min="0" required>
                                <small>最差也能获得的通量</small>
                            </div>
                        </div>

                        <div class="warning-box">
                            <h4>⚠️ 概率检查</h4>
                            <p>当前总概率: <strong><?php echo $totalProb; ?>%</strong> <?php echo $totalProb != 100 ? '(应该等于100%)' : '(正确)' ?></p>
                            <?php if ($totalProb != 100): ?>
                            <p style="color: #e53e3e;">请调整各卡池概率，使总和为100%</p>
                            <?php endif; ?>
                        </div>

                        <button type="submit" name="update_gacha" class="btn btn-primary" style="width: 100%;">保存抽卡配置</button>
                    </form>
                </div>

                <!-- 用户管理标签 -->
                <div id="tab-users" class="tab-content">
                    <h2>👥 用户管理</h2>

                    <?php $users = getAllUsers(); ?>

                    <table class="user-table">
                        <thead>
                            <tr>
                                <th>用户名</th>
                                <th>通量</th>
                                <th>学习时长</th>
                                <th>记忆值</th>
                                <th>抽卡次数</th>
                                <th>操作</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($users as $username => $userData): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($username, ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?php echo (float)($userData['restFlux'] ?? 0); ?></td>
                                <td><?php echo formatDuration($userData['studyTime'] ?? 0); ?></td>
                                <td><?php echo (float)($userData['memoryValue'] ?? 100) . '%'; ?></td>
                                <td><?php echo (int)($userData['gachaStats']['totalPulls'] ?? 0); ?></td>
                                <td>
                                    <div class="action-buttons">
                                        <button onclick="editUser('<?php echo htmlspecialchars($username, ENT_QUOTES, 'UTF-8'); ?>', <?php echo json_encode([
                                            'restFlux' => (float)($userData['restFlux'] ?? 0),
                                            'studyTime' => (int)($userData['studyTime'] ?? 0),
                                            'memoryValue' => (float)($userData['memoryValue'] ?? 100)
                                        ], JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>)" class="btn btn-success" style="padding: 6px 12px;">编辑</button>
                                        <form method="POST" style="display: inline;" onsubmit="return confirm('确定要删除用户 <?php echo htmlspecialchars($username, ENT_QUOTES, 'UTF-8'); ?> 吗？');">
                                            <input type="hidden" name="target_user" value="<?php echo htmlspecialchars($username, ENT_QUOTES, 'UTF-8'); ?>">
                                            <input type="hidden" name="user_action" value="delete">
                                            <button type="submit" class="btn btn-danger" style="padding: 6px 12px;">删除</button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>

                    <!-- 编辑用户模态框 -->
                    <div id="editUserModal">
                        <h3 style="margin-bottom: 20px;">编辑用户数据</h3>
                        <form method="POST" id="editUserForm">
                            <input type="hidden" name="target_user" id="edit_username">
                            <input type="hidden" name="user_action" value="update">

                            <div class="form-group">
                                <label>通量</label>
                                <input type="number" name="rest_flux" id="edit_flux" required min="0">
                            </div>

                            <div class="form-group">
                                <label>学习时长 (秒)</label>
                                <input type="number" name="study_time" id="edit_study" required min="0">
                            </div>

                            <div class="form-group">
                                <label>记忆值 (%)</label>
                                <input type="number" name="memory_value" id="edit_memory" step="0.1" min="0" max="100" required>
                            </div>

                            <div style="display: flex; gap: 10px;">
                                <button type="submit" class="btn btn-success" style="flex: 1;">保存修改</button>
                                <button type="button" onclick="closeEditModal()" class="btn btn-danger" style="flex: 1;">取消</button>
                            </div>
                        </form>
                    </div>

                    <div id="modalOverlay" onclick="closeEditModal()"></div>

                    <script>
                        function editUser(username, userData) {
                            document.getElementById('edit_username').value = username;
                            document.getElementById('edit_flux').value = userData.restFlux || 0;
                            document.getElementById('edit_study').value = userData.studyTime || 0;
                            document.getElementById('edit_memory').value = userData.memoryValue || 100;

                            document.getElementById('editUserModal').style.display = 'block';
                            document.getElementById('modalOverlay').style.display = 'block';
                        }

                        function closeEditModal() {
                            document.getElementById('editUserModal').style.display = 'none';
                            document.getElementById('modalOverlay').style.display = 'none';
                        }
                    </script>
                </div>

                <!-- 修改密码标签 -->
                <div id="tab-password" class="tab-content">
                    <h2>🔐 修改管理员密码</h2>

                    <div class="pool-card" style="max-width: 500px; margin: 0 auto;">
                        <form method="POST">
                            <div class="form-group">
                                <label>原密码</label>
                                <input type="password" name="old_password" required autocomplete="current-password">
                            </div>

                            <div class="form-group">
                                <label>新密码</label>
                                <input type="password" name="new_password" required minlength="6" autocomplete="new-password">
                            </div>

                            <div class="form-group">
                                <label>确认新密码</label>
                                <input type="password" name="confirm_password" required minlength="6" autocomplete="new-password">
                            </div>

                            <button type="submit" name="change_password" class="btn btn-warning" style="width: 100%;">修改密码</button>
                        </form>
                    </div>
                </div>
            </div>

            <script>
                function switchTab(tab) {
                    const tabs = document.querySelectorAll('.tab');
                    const contents = document.querySelectorAll('.tab-content');

                    tabs.forEach(t => t.classList.remove('active'));
                    contents.forEach(c => c.classList.remove('active'));

                    document.querySelector(`.tab[onclick="switchTab('${tab}')"]`).classList.add('active');
                    document.getElementById(`tab-${tab}`).classList.add('active');
                }
            </script>
        <?php endif; ?>
    </div>
</body>
</html>
