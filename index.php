<?php

/**
 * =====================================================
 * 趣味学习助手 - 抽卡大乐透 (Study Gacha)
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
 *    - 修复 "Undefined array key" 警告（PHP 8.0 新增）
 *    - 修复 "trim(): Passing null to parameter #1" 弃用警告（PHP 8.1 新增）
 *    - 所有输入统一经安全取值函数处理，杜绝未定义键访问
 * 2. 修复无法登录/注册的数据丢失问题：
 *    - 用户认证信息（密码哈希）同时写入 user_data/<用户>.php，
 *      即使 data/users.php 被清空或损坏，也能自动重建索引并正常登录
 *    - 所有文件写入增加 LOCK_EX 独占锁，防止并发写入导致文件损坏（0字节）
 *    - readUsers() 自带自愈：users.php 损坏时自动从 user_data 目录重建
 *    - 支持“孤儿数据接管”：users.php 丢失密码但 user_data 有历史数据时，
 *      重新注册同名账号即可找回原学习数据
 * 3. 安全加固：
 *    - 用户名白名单校验（仅中文/字母/数字/下划线/连字符，2~20位），
 *      修复可通过 ../ 构造路径遍历读写任意文件的严重漏洞
 *    - 删除账号、清理数据等操作增加确认令牌
 *    - JSON 写入使用 JSON_UNESCAPED_UNICODE，数据文件更易读
 * 4. UI/交互优化：
 *    - 修正“休息时间通量”单位显示（去掉错误的 s 单位）
 *    - 修正学习进度条“今日目标 24:00:00”的不合理文案
 *    - 修复抽卡按钮 pointer-events:none 的交互缺陷
 *    - 页面加载后从服务器恢复当前学习/休息模式状态
 *    - 增加抽卡后统计实时刷新
 *
 * =====================================================
 * 版权信息
 * =====================================================
 *
 * Copyright (c) 2024 fghdz
 * 
 * 此源代码遵循 MIT 许可证，您可以自由使用、修改和分发，
 * 但请保留此版权声明。
 *
 * =====================================================
 */

session_start();

// 定义数据存储目录（使用绝对路径，避免从其他目录执行时失效）
define('DATA_DIR', __DIR__ . '/data/');
define('USERS_FILE', DATA_DIR . 'users.php');
define('USER_DATA_DIR', DATA_DIR . 'user_data/');
define('ADMIN_FILE', __DIR__ . '/admin_config.php');
define('DATA_VERSION', '1.1.0');

// 统一时区，避免 date() 警告
if (!ini_get('date.timezone')) {
    date_default_timezone_set('Asia/Shanghai');
}

// =====================================================
// 安全取值工具函数（PHP 8.x 兼容）
// =====================================================

/** 安全获取 POST 字符串字段，避免 Undefined array key */
function post($key, $default = '') {
    return (isset($_POST[$key]) && is_string($_POST[$key])) ? $_POST[$key] : $default;
}

/** 安全获取 GET 字符串字段 */
function get($key, $default = '') {
    return (isset($_GET[$key]) && is_string($_GET[$key])) ? $_GET[$key] : $default;
}

/** 用户名合法性校验：仅允许中文、字母、数字、下划线、连字符，长度 2~20 */
function isSafeUsername($username) {
    return is_string($username)
        && preg_match('/^[\x{4e00}-\x{9fa5}a-zA-Z0-9_-]{2,20}$/u', $username) === 1;
}

// =====================================================
// 数据文件读写（带 LOCK_EX 独占锁 + 自愈）
// =====================================================

/** 初始化数据目录 */
function initDataDirs() {
    if (!is_dir(DATA_DIR)) {
        @mkdir(DATA_DIR, 0777, true);
    }
    if (!is_dir(USER_DATA_DIR)) {
        @mkdir(USER_DATA_DIR, 0777, true);
    }
}

/**
 * 读取 JSON 数据文件（自动剥离 <?php exit; ?> 防护前缀）
 * 返回数组；文件不存在或解析失败返回 null
 */
function readJsonFile($file) {
    if (!is_file($file)) {
        return null;
    }
    $content = @file_get_contents($file);
    if ($content === false) {
        return null;
    }
    // 兼容 \n 与 \r\n 换行
    $content = preg_replace('/^<\?php exit; \?>\s*/', '', $content);
    $data = json_decode($content, true);
    return is_array($data) ? $data : null;
}

/**
 * 写入 JSON 数据文件（带防护前缀 + LOCK_EX 独占锁）
 * 写入失败返回 false
 */
function writeJsonFile($file, $data) {
    $dir = dirname($file);
    if (!is_dir($dir)) {
        @mkdir($dir, 0777, true);
    }
    $content = "<?php exit; ?>\n" . json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    return @file_put_contents($file, $content, LOCK_EX) !== false;
}

/** 读取用户列表（自愈：文件空/损坏时从 user_data 目录重建索引） */
function readUsers() {
    $users = readJsonFile(USERS_FILE);
    if ($users !== null) {
        return $users;
    }
    return rebuildUsersIndex();
}

/** 写入用户列表 */
function writeUsers($data) {
    return writeJsonFile(USERS_FILE, $data);
}

/**
 * 从 user_data 目录重建用户索引
 * 用于 users.php 被清空/损坏时的自动恢复
 */
function rebuildUsersIndex() {
    $users = [];
    if (is_dir(USER_DATA_DIR)) {
        $files = glob(USER_DATA_DIR . '*.php');
        if ($files) {
            foreach ($files as $file) {
                $name = basename($file, '.php');
                if (!isSafeUsername($name)) {
                    continue;
                }
                $data = readUserData($name);
                if ($data && isset($data['auth']['password'])) {
                    $users[$name] = [
                        'password'   => $data['auth']['password'],
                        'created_at' => $data['created_at'] ?? date('Y-m-d H:i:s'),
                        'recovered'  => true,
                    ];
                }
            }
        }
    }
    if (!empty($users)) {
        writeUsers($users);
    }
    return $users;
}

/** 读取单个用户数据文件（含用户名校验，防路径遍历） */
function readUserData($username) {
    if (!isSafeUsername($username)) {
        return null;
    }
    return readJsonFile(USER_DATA_DIR . $username . '.php');
}

/** 写入单个用户数据文件 */
function writeUserData($username, $data) {
    if (!isSafeUsername($username)) {
        return false;
    }
    return writeJsonFile(USER_DATA_DIR . $username . '.php', $data);
}

/** 读取抽卡配置（含默认值兜底） */
function getGachaConfig() {
    if (is_file(ADMIN_FILE)) {
        $config = @include(ADMIN_FILE);
        if (is_array($config) && isset($config['gacha'])) {
            return $config['gacha'];
        }
    }
    return [
        'pools' => [
            'common'    => ['name' => '普通卡池', 'cost' => 10, 'color' => '#9CA3AF', 'min_reward' => 1, 'max_reward' => 5, 'probability' => 60],
            'rare'      => ['name' => '稀有卡池', 'cost' => 50, 'color' => '#3B82F6', 'min_reward' => 6, 'max_reward' => 20, 'probability' => 25],
            'epic'      => ['name' => '史诗卡池', 'cost' => 200, 'color' => '#8B5CF6', 'min_reward' => 21, 'max_reward' => 50, 'probability' => 10],
            'legendary' => ['name' => '传说卡池', 'cost' => 500, 'color' => '#F59E0B', 'min_reward' => 51, 'max_reward' => 200, 'probability' => 4],
            'mythic'    => ['name' => '神话卡池', 'cost' => 1000, 'color' => '#EC4899', 'min_reward' => 201, 'max_reward' => 500, 'probability' => 1]
        ],
        'special_events' => [
            'enabled' => true,
            'double_probability' => 5,
            'jackpot_multiplier' => 10,
            'consolation_prize' => 1
        ]
    ];
}

/** 获取用户默认数据模板 */
function defaultUserData($username) {
    return [
        'auth' => [
            'password' => '', // 由注册/登录逻辑填充
        ],
        'studyTime' => 0,
        'restFlux' => 100, // 初始100通量用于抽卡
        'memoryValue' => 100,
        'lastMemoryUpdate' => time(),
        'totalStudySessions' => 0,
        'totalQuestions' => 0,
        'lastActive' => time(),
        'studyMode' => 'none',
        'restMode' => false,
        'restIncreaseRate' => 0.2,
        'restDecreaseRate' => 1.0,
        'memoryHistory' => [],
        'gachaStats' => [
            'totalPulls' => 0,
            'totalSpent' => 0,
            'totalWon' => 0,
            'lastPull' => null,
            'commonPulls' => 0,
            'rarePulls' => 0,
            'epicPulls' => 0,
            'legendaryPulls' => 0,
            'mythicPulls' => 0
        ],
        'gachaHistory' => [],
        'memorySettings' => [
            'forgetRate' => 0.56,
            'questionMemoryGain' => 0.5,
            'pureStudyMemoryGain' => 0.008,
            'memorizeMemoryGain' => 0.012,
        ],
        'created_at' => date('Y-m-d H:i:s'),
    ];
}

/** 合并默认值，确保所有键存在 */
function ensureUserDataComplete($userData) {
    $defaults = defaultUserData('');
    // 仅合并业务字段（auth 单独处理）
    $merged = array_merge($defaults, is_array($userData) ? $userData : []);
    if (!isset($merged['memorySettings']) || !is_array($merged['memorySettings'])) {
        $merged['memorySettings'] = $defaults['memorySettings'];
    } else {
        $merged['memorySettings'] = array_merge($defaults['memorySettings'], $merged['memorySettings']);
    }
    if (!isset($merged['gachaStats']) || !is_array($merged['gachaStats'])) {
        $merged['gachaStats'] = $defaults['gachaStats'];
    } else {
        $merged['gachaStats'] = array_merge($defaults['gachaStats'], $merged['gachaStats']);
    }
    foreach (['gachaHistory', 'memoryHistory'] as $k) {
        if (!isset($merged[$k]) || !is_array($merged[$k])) {
            $merged[$k] = [];
        }
    }
    return $merged;
}

/**
 * 认证：验证用户名密码是否匹配
 * 兼容两处数据源（users.php 与 user_data 文件），并自动同步
 */
function authenticate($username, $password) {
    if (!isSafeUsername($username) || !is_string($password) || $password === '') {
        return false;
    }
    $users = readUsers();
    $storedHash = $users[$username]['password'] ?? null;

    // 若 users.php 无记录，尝试从 user_data 文件读取 auth
    $userData = readUserData($username);
    $dataHash = null;
    if ($userData && isset($userData['auth']['password'])) {
        $dataHash = $userData['auth']['password'];
    }

    $hash = null;
    $fromUserData = false;
    if ($storedHash !== null && password_verify($password, $storedHash)) {
        $hash = $storedHash;
    } elseif ($dataHash !== null && password_verify($password, $dataHash)) {
        $hash = $dataHash;
        $fromUserData = true;
    }
    if ($hash === null) {
        return false;
    }

    // 同步：确保 users.php 与 user_data 都包含认证信息
    if ($fromUserData || !isset($users[$username])) {
        $users[$username] = [
            'password'   => $hash,
            'created_at' => $userData['created_at'] ?? date('Y-m-d H:i:s'),
            'recovered'  => true,
        ];
        writeUsers($users);
    }
    if ($userData === null) {
        $userData = defaultUserData($username);
        $userData['auth']['password'] = $hash;
        writeUserData($username, $userData);
    } elseif (!isset($userData['auth']['password'])) {
        $userData['auth']['password'] = $hash;
        writeUserData($username, $userData);
    }
    return true;
}

// =====================================================
// 核心业务函数
// =====================================================

// 艾宾浩斯遗忘曲线计算函数
function calculateForgettingCurve($hours, $initialMemory = 100, $forgetRate = 0.56) {
    $hours = max(0, (float)$hours);
    $retention = $initialMemory * (1 - $forgetRate * log10(max(1, $hours + 1)));
    return max(0, min(100, round($retention, 2)));
}

// 计算记忆值变化
function calculateMemoryChange($currentMemory, $studyTime, $elapsedHours, $studyIntensity, $forgetRate = 0.56) {
    $afterForgetting = calculateForgettingCurve($elapsedHours, $currentMemory, $forgetRate);
    $learningGain = $studyTime * $studyIntensity;
    $newMemory = $afterForgetting + $learningGain;
    return min(100, $newMemory);
}

// 抽卡函数 - 使用更真实的概率算法
function drawCard($poolType, $gachaConfig) {
    $pools = $gachaConfig['pools'] ?? [];
    if (!isset($pools[$poolType])) {
        $poolType = 'common';
    }
    $pool = $pools[$poolType];
    $special = $gachaConfig['special_events'] ?? [
        'enabled' => true,
        'double_probability' => 5,
        'jackpot_multiplier' => 10,
        'consolation_prize' => 1
    ];

    $probability = (int)($pool['probability'] ?? 0);
    $drawRand = mt_rand(1, 100);

    // 未中奖
    if ($drawRand > $probability) {
        return [
            'reward' => 0,
            'base' => 0,
            'message' => '很遗憾，没有中奖...',
            'special' => '😢',
            'pool' => $pool['name'],
            'color' => $pool['color'],
            'isWin' => false
        ];
    }

    // 中奖，计算奖励
    $minReward = (int)($pool['min_reward'] ?? 1);
    $maxReward = (int)($pool['max_reward'] ?? 5);
    $baseReward = rand($minReward, max($minReward, $maxReward));
    $finalReward = $baseReward;
    $message = "获得 {$baseReward} 通量";
    $specialEvent = '';

    // 特殊事件
    if (!empty($special['enabled'])) {
        $rand = mt_rand(1, 10000) / 100; // 0.01 ~ 100.00

        // 双倍奖励
        if ($rand <= (float)($special['double_probability'] ?? 0)) {
            $finalReward = $baseReward * 2;
            $specialEvent = '✨ 双倍奖励！';
            $message = "✨ 双倍奖励！获得 {$finalReward} 通量";
        }

        // 超级大奖（1%双重随机）
        if ($rand <= 1.00 && mt_rand(1, 100) == 1) {
            $finalReward = $baseReward * max(1, (int)($special['jackpot_multiplier'] ?? 10));
            $specialEvent = '🎰 超级大奖！';
            $message = "🎰 超级大奖！获得 {$finalReward} 通量";
        }
    }

    // 保底
    $consolation = (int)($special['consolation_prize'] ?? 0);
    if ($finalReward < $consolation) {
        $finalReward = $consolation;
        $message = "保底获得 {$finalReward} 通量";
    }

    return [
        'reward' => $finalReward,
        'base' => $baseReward,
        'message' => $message,
        'special' => $specialEvent,
        'pool' => $pool['name'],
        'color' => $pool['color'],
        'isWin' => true
    ];
}

// =====================================================
// 请求处理
// =====================================================

initDataDirs();

$message = '';
$messageType = '';
$registered = false;

// ---------- 注册处理 ----------
if (isset($_POST['register'])) {
    $username = trim(post('username'));
    $password = post('password');
    $confirmPassword = post('confirm_password');

    if (empty($username) || empty($password)) {
        $message = '用户名和密码不能为空';
        $messageType = 'error';
    } elseif (!isSafeUsername($username)) {
        $message = '用户名仅支持中文、字母、数字、下划线，长度2-20位';
        $messageType = 'error';
    } elseif ($password !== $confirmPassword) {
        $message = '两次输入的密码不一致';
        $messageType = 'error';
    } elseif (strlen($password) < 6) {
        $message = '密码长度至少6位';
        $messageType = 'error';
    } else {
        $users = readUsers();
        $existing = readUserData($username);
        $hasAuthRecord = isset($users[$username]['password']);
        $dataHasAuth = is_array($existing) && isset($existing['auth']['password']);

        if ($hasAuthRecord || $dataHasAuth) {
            $message = '用户名已存在';
            $messageType = 'error';
        } else {
            $hash = password_hash($password, PASSWORD_DEFAULT);

            if (is_array($existing)) {
                // 孤儿数据接管：users.php 密码丢失，但保留历史学习数据
                $existing = ensureUserDataComplete($existing);
                $existing['auth']['password'] = $hash;
                $existing['created_at'] = $existing['created_at'] ?? date('Y-m-d H:i:s');
                $userData = $existing;
                $message = '检测到同名历史数据，已找回并绑定新密码，注册成功！';
            } else {
                $userData = defaultUserData($username);
                $userData['auth']['password'] = $hash;
                $message = '注册成功，赠送100通量用于抽卡！';
            }
            writeUserData($username, $userData);

            $users[$username] = [
                'password' => $hash,
                'created_at' => $userData['created_at'],
            ];
            writeUsers($users);

            $messageType = 'success';
            $registered = true;
        }
    }
}

// ---------- 登录处理 ----------
if (isset($_POST['login']) && !isset($_SESSION['username'])) {
    $username = trim(post('username'));
    $password = post('password');

    if (empty($username) || empty($password)) {
        $message = '请输入用户名和密码';
        $messageType = 'error';
    } elseif (!isSafeUsername($username)) {
        $message = '用户名格式不正确';
        $messageType = 'error';
    } elseif (authenticate($username, $password)) {
        $_SESSION['username'] = $username;

        // 加载并更新用户数据
        $userData = ensureUserDataComplete(readUserData($username));
        if ($userData) {
            // 计算离线期间的时间变化
            $currentTime = time();
            $lastActive = (int)($userData['lastActive'] ?? $currentTime);
            $lastMemoryUpdate = (int)($userData['lastMemoryUpdate'] ?? $currentTime);
            $timeDiff = max(0, $currentTime - $lastActive);
            $memoryTimeDiff = max(0, $currentTime - $lastMemoryUpdate);
            $hoursDiff = $memoryTimeDiff / 3600;

            $settings = $userData['memorySettings'];

            if ($userData['studyMode'] == 'pure') {
                $userData['studyTime'] += $timeDiff;
                $userData['memoryValue'] = calculateMemoryChange(
                    $userData['memoryValue'],
                    $timeDiff,
                    $hoursDiff,
                    $settings['pureStudyMemoryGain'],
                    $settings['forgetRate']
                );
            } elseif ($userData['studyMode'] == 'memorize') {
                $userData['studyTime'] += $timeDiff;
                $userData['restFlux'] += $timeDiff * $userData['restIncreaseRate'];
                $userData['memoryValue'] = calculateMemoryChange(
                    $userData['memoryValue'],
                    $timeDiff,
                    $hoursDiff,
                    $settings['memorizeMemoryGain'],
                    $settings['forgetRate']
                );
            } else {
                $userData['memoryValue'] = calculateForgettingCurve(
                    $hoursDiff,
                    $userData['memoryValue'],
                    $settings['forgetRate']
                );
            }

            if ($userData['restMode']) {
                $userData['restFlux'] = max(0, $userData['restFlux'] - $timeDiff * $userData['restDecreaseRate']);
            }

            $userData['lastActive'] = $currentTime;
            $userData['lastMemoryUpdate'] = $currentTime;
            $userData['studyMode'] = 'none';
            $userData['restMode'] = false;

            writeUserData($username, $userData);

            $_SESSION['studyTime'] = $userData['studyTime'];
            $_SESSION['restFlux'] = $userData['restFlux'];
            $_SESSION['memoryValue'] = $userData['memoryValue'];
            $_SESSION['restIncreaseRate'] = $userData['restIncreaseRate'];
            $_SESSION['restDecreaseRate'] = $userData['restDecreaseRate'];
            $_SESSION['memorySettings'] = $userData['memorySettings'];
            $_SESSION['gachaStats'] = $userData['gachaStats'];
        }
    } else {
        $message = '用户名或密码错误';
        $messageType = 'error';
    }
}

// ---------- 登出处理 ----------
if (isset($_GET['logout'])) {
    if (isset($_SESSION['username'])) {
        $username = $_SESSION['username'];
        $userData = readUserData($username);
        if ($userData) {
            $currentTime = time();
            $lastActive = (int)($userData['lastActive'] ?? $currentTime);
            $lastMemoryUpdate = (int)($userData['lastMemoryUpdate'] ?? $currentTime);
            $timeDiff = max(0, $currentTime - $lastActive);
            $memoryTimeDiff = max(0, $currentTime - $lastMemoryUpdate);
            $hoursDiff = $memoryTimeDiff / 3600;

            $settings = $userData['memorySettings'] ?? defaultUserData('')['memorySettings'];

            $userData['memoryValue'] = calculateForgettingCurve(
                $hoursDiff,
                $userData['memoryValue'],
                $settings['forgetRate']
            );

            $userData['memoryHistory'][] = [
                'time' => $currentTime,
                'value' => $userData['memoryValue'],
                'mode' => 'logout'
            ];
            if (count($userData['memoryHistory']) > 50) {
                array_shift($userData['memoryHistory']);
            }

            $userData['lastActive'] = $currentTime;
            $userData['lastMemoryUpdate'] = $currentTime;
            $userData['studyMode'] = 'none';
            $userData['restMode'] = false;

            writeUserData($username, $userData);
        }
    }
    session_destroy();
    header('Location: ' . basename($_SERVER['PHP_SELF']));
    exit;
}

// ---------- 抽卡处理 ----------
if (isset($_POST['draw_card']) && isset($_SESSION['username'])) {
    $poolType = post('pool_type', 'common');
    $gachaConfig = getGachaConfig();
    $pool = $gachaConfig['pools'][$poolType] ?? $gachaConfig['pools']['common'];
    $cost = (int)($pool['cost'] ?? 10);

    $username = $_SESSION['username'];
    $userData = ensureUserDataComplete(readUserData($username));

    if ((float)$userData['restFlux'] >= $cost) {
        // 扣除通量
        $userData['restFlux'] = (float)$userData['restFlux'] - $cost;

        // 抽卡
        $result = drawCard($poolType, $gachaConfig);

        // 只有中奖才应用奖励
        if ($result['isWin']) {
            $userData['restFlux'] += $result['reward'];
        }

        // 更新抽卡统计
        $userData['gachaStats']['totalPulls'] = ($userData['gachaStats']['totalPulls'] ?? 0) + 1;
        $userData['gachaStats']['totalSpent'] = ($userData['gachaStats']['totalSpent'] ?? 0) + $cost;
        if ($result['isWin']) {
            $userData['gachaStats']['totalWon'] = ($userData['gachaStats']['totalWon'] ?? 0) + $result['reward'];
        }
        $userData['gachaStats']['lastPull'] = time();
        $userData['gachaStats'][$poolType . 'Pulls'] = ($userData['gachaStats'][$poolType . 'Pulls'] ?? 0) + 1;

        // 记录抽卡历史
        $gachaHistory = $userData['gachaHistory'] ?? [];
        $gachaHistory[] = [
            'time' => time(),
            'pool' => $pool['name'],
            'cost' => $cost,
            'reward' => $result['isWin'] ? $result['reward'] : 0,
            'message' => $result['message'],
            'isWin' => $result['isWin']
        ];
        if (count($gachaHistory) > 20) {
            array_shift($gachaHistory);
        }
        $userData['gachaHistory'] = $gachaHistory;

        writeUserData($username, $userData);

        $_SESSION['restFlux'] = $userData['restFlux'];
        $_SESSION['gachaStats'] = $userData['gachaStats'];

        // 返回 JSON 响应
        if (post('ajax') === '1') {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'success' => true,
                'message' => $result['isWin'] ? "🎉 抽卡成功！{$result['special']} {$result['message']}" : "😢 {$result['message']}",
                'reward' => $result['isWin'] ? $result['reward'] : 0,
                'pool' => $pool['name'],
                'special' => $result['special'],
                'isWin' => $result['isWin']
            ]);
            exit;
        }
        $message = $result['isWin'] ? "🎉 抽卡成功！{$result['special']} {$result['message']}" : "😢 {$result['message']}";
        $messageType = $result['isWin'] ? 'success' : 'error';
    } else {
        if (post('ajax') === '1') {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'success' => false,
                'message' => "❌ 通量不足，需要 {$cost} 通量！"
            ]);
            exit;
        }
        $message = "❌ 通量不足，需要 {$cost} 通量！";
        $messageType = 'error';
    }
}

// ---------- 用户修改自己的设置 ----------
if (isset($_POST['update_my_settings']) && isset($_SESSION['username'])) {
    $username = $_SESSION['username'];
    $userData = readUserData($username);
    if ($userData) {
        $userData = ensureUserDataComplete($userData);

        // 记忆参数
        if (isset($_POST['forget_rate']) && is_numeric($_POST['forget_rate'])) {
            $userData['memorySettings']['forgetRate'] = (float)$_POST['forget_rate'];
        }
        if (isset($_POST['question_memory_gain']) && is_numeric($_POST['question_memory_gain'])) {
            $userData['memorySettings']['questionMemoryGain'] = (float)$_POST['question_memory_gain'];
        }
        if (isset($_POST['pure_study_memory_gain']) && is_numeric($_POST['pure_study_memory_gain'])) {
            $userData['memorySettings']['pureStudyMemoryGain'] = (float)$_POST['pure_study_memory_gain'];
        }
        if (isset($_POST['memorize_memory_gain']) && is_numeric($_POST['memorize_memory_gain'])) {
            $userData['memorySettings']['memorizeMemoryGain'] = (float)$_POST['memorize_memory_gain'];
        }

        // 通量设置
        if (isset($_POST['rest_increase_rate']) && is_numeric($_POST['rest_increase_rate'])) {
            $userData['restIncreaseRate'] = (float)$_POST['rest_increase_rate'];
        }
        if (isset($_POST['rest_decrease_rate']) && is_numeric($_POST['rest_decrease_rate'])) {
            $userData['restDecreaseRate'] = (float)$_POST['rest_decrease_rate'];
        }

        // 数据管理
        if (isset($_POST['study_time']) && is_numeric($_POST['study_time'])) {
            $userData['studyTime'] = max(0, (float)$_POST['study_time']);
        }
        if (isset($_POST['rest_flux']) && is_numeric($_POST['rest_flux'])) {
            $userData['restFlux'] = max(0, (float)$_POST['rest_flux']);
        }
        if (isset($_POST['memory_value']) && is_numeric($_POST['memory_value'])) {
            $userData['memoryValue'] = min(100, max(0, (float)$_POST['memory_value']));
        }

        $userData['lastActive'] = time();
        $userData['lastMemoryUpdate'] = time();

        writeUserData($username, $userData);

        $_SESSION['studyTime'] = $userData['studyTime'];
        $_SESSION['restFlux'] = $userData['restFlux'];
        $_SESSION['memoryValue'] = $userData['memoryValue'];
        $_SESSION['restIncreaseRate'] = $userData['restIncreaseRate'];
        $_SESSION['restDecreaseRate'] = $userData['restDecreaseRate'];
        $_SESSION['memorySettings'] = $userData['memorySettings'];

        $message = '设置已更新';
        $messageType = 'success';
    }
}

// ---------- 清空记忆曲线历史 ----------
if (isset($_POST['clear_memory_history']) && isset($_SESSION['username'])) {
    $username = $_SESSION['username'];
    $userData = readUserData($username);
    if ($userData) {
        $userData['memoryHistory'] = [];
        writeUserData($username, $userData);

        if (post('ajax') === '1') {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['success' => true, 'message' => '记忆曲线历史已清空']);
            exit;
        }
        $message = '记忆曲线历史已清空';
        $messageType = 'success';
    }
}

// ---------- 清空抽卡记录 ----------
if (isset($_POST['clear_gacha_history']) && isset($_SESSION['username'])) {
    $username = $_SESSION['username'];
    $userData = readUserData($username);
    if ($userData) {
        $userData['gachaHistory'] = [];
        writeUserData($username, $userData);

        if (post('ajax') === '1') {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['success' => true, 'message' => '抽卡记录已清空']);
            exit;
        }
        $message = '抽卡记录已清空';
        $messageType = 'success';
    }
}

// ---------- 重置抽卡统计 ----------
if (isset($_POST['reset_gacha_stats']) && isset($_SESSION['username'])) {
    $username = $_SESSION['username'];
    $userData = readUserData($username);
    if ($userData) {
        $userData['gachaStats'] = defaultUserData('')['gachaStats'];
        writeUserData($username, $userData);
        $_SESSION['gachaStats'] = $userData['gachaStats'];

        if (post('ajax') === '1') {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['success' => true, 'message' => '抽卡统计已重置']);
            exit;
        }
        $message = '抽卡统计已重置';
        $messageType = 'success';
    }
}

// ---------- 用户注销账号 ----------
if (isset($_POST['delete_my_account']) && isset($_SESSION['username'])) {
    $username = $_SESSION['username'];
    $confirm = post('confirm_delete', '');

    if ($confirm === 'DELETE') {
        $users = readUsers();

        $userDataFile = USER_DATA_DIR . $username . '.php';
        if (is_file($userDataFile)) {
            @unlink($userDataFile);
        }

        unset($users[$username]);
        writeUsers($users);

        session_destroy();

        if (post('ajax') === '1') {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['success' => true, 'redirect' => true]);
            exit;
        }
        header('Location: ' . basename($_SERVER['PHP_SELF']));
        exit;
    } else {
        if (post('ajax') === '1') {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['success' => false, 'message' => '请输入 DELETE 确认注销']);
            exit;
        }
        $message = '请输入 DELETE 确认注销';
        $messageType = 'error';
    }
}

// ---------- AJAX 请求处理 ----------
if (isset($_POST['action']) && isset($_SESSION['username'])) {
    header('Content-Type: application/json; charset=utf-8');
    $username = $_SESSION['username'];
    $userData = ensureUserDataComplete(readUserData($username));

    if (!$userData) {
        echo json_encode(['error' => 'User data not found']);
        exit;
    }

    $currentTime = time();
    $lastActive = (int)($userData['lastActive'] ?? $currentTime);
    $lastMemoryUpdate = (int)($userData['lastMemoryUpdate'] ?? $currentTime);
    $timeDiff = max(0, $currentTime - $lastActive);
    $memoryTimeDiff = max(0, $currentTime - $lastMemoryUpdate);
    $hoursDiff = $memoryTimeDiff / 3600;

    $settings = $userData['memorySettings'];

    switch ($_POST['action']) {
        case 'updateMode':
            $studyMode = post('studyMode', 'none');
            $restMode = (post('restMode') === 'true');
            if (!in_array($studyMode, ['none', 'pure', 'memorize'], true)) {
                $studyMode = 'none';
            }

            if ($userData['studyMode'] == 'pure') {
                $userData['studyTime'] += $timeDiff;
                $userData['memoryValue'] = calculateMemoryChange(
                    $userData['memoryValue'],
                    $timeDiff,
                    $hoursDiff,
                    $settings['pureStudyMemoryGain'],
                    $settings['forgetRate']
                );
                if ($timeDiff > 0) $userData['totalStudySessions']++;
            } elseif ($userData['studyMode'] == 'memorize') {
                $userData['studyTime'] += $timeDiff;
                $userData['restFlux'] += $timeDiff * $userData['restIncreaseRate'];
                $userData['memoryValue'] = calculateMemoryChange(
                    $userData['memoryValue'],
                    $timeDiff,
                    $hoursDiff,
                    $settings['memorizeMemoryGain'],
                    $settings['forgetRate']
                );
                if ($timeDiff > 0) $userData['totalStudySessions']++;
            } else {
                $userData['memoryValue'] = calculateForgettingCurve(
                    $hoursDiff,
                    $userData['memoryValue'],
                    $settings['forgetRate']
                );
            }

            if ($userData['restMode']) {
                $userData['restFlux'] = max(0, $userData['restFlux'] - $timeDiff * $userData['restDecreaseRate']);
            }

            $userData['memoryHistory'][] = [
                'time' => $currentTime,
                'value' => $userData['memoryValue'],
                'mode' => $studyMode
            ];
            if (count($userData['memoryHistory']) > 50) {
                array_shift($userData['memoryHistory']);
            }

            $userData['studyMode'] = $studyMode;
            $userData['restMode'] = $restMode;
            $userData['lastActive'] = $currentTime;
            $userData['lastMemoryUpdate'] = $currentTime;

            writeUserData($username, $userData);

            $_SESSION['studyTime'] = $userData['studyTime'];
            $_SESSION['restFlux'] = $userData['restFlux'];
            $_SESSION['memoryValue'] = $userData['memoryValue'];

            echo json_encode([
                'success' => true,
                'studyTime' => $userData['studyTime'],
                'restFlux' => $userData['restFlux'],
                'memoryValue' => $userData['memoryValue']
            ]);
            break;

        case 'addRestFlux':
            $amount = max(0, (float)post('amount', 0));

            if ($userData['studyMode'] == 'pure') {
                $userData['studyTime'] += $timeDiff;
                $userData['memoryValue'] = calculateMemoryChange(
                    $userData['memoryValue'],
                    $timeDiff,
                    $hoursDiff,
                    $settings['pureStudyMemoryGain'],
                    $settings['forgetRate']
                );
            } elseif ($userData['studyMode'] == 'memorize') {
                $userData['studyTime'] += $timeDiff;
                $userData['restFlux'] += $timeDiff * $userData['restIncreaseRate'];
                $userData['memoryValue'] = calculateMemoryChange(
                    $userData['memoryValue'],
                    $timeDiff,
                    $hoursDiff,
                    $settings['memorizeMemoryGain'],
                    $settings['forgetRate']
                );
            } else {
                $userData['memoryValue'] = calculateForgettingCurve(
                    $hoursDiff,
                    $userData['memoryValue'],
                    $settings['forgetRate']
                );
            }

            if ($userData['restMode']) {
                $userData['restFlux'] = max(0, $userData['restFlux'] - $timeDiff * $userData['restDecreaseRate']);
            }

            $questionEffect = $amount * $settings['questionMemoryGain'];
            $userData['memoryValue'] = min(100, $userData['memoryValue'] + $questionEffect);
            $userData['totalQuestions'] = ($userData['totalQuestions'] ?? 0) + $amount;

            $userData['restFlux'] += $amount;
            $userData['lastActive'] = $currentTime;
            $userData['lastMemoryUpdate'] = $currentTime;

            writeUserData($username, $userData);

            $_SESSION['studyTime'] = $userData['studyTime'];
            $_SESSION['restFlux'] = $userData['restFlux'];
            $_SESSION['memoryValue'] = $userData['memoryValue'];

            echo json_encode([
                'success' => true,
                'studyTime' => $userData['studyTime'],
                'restFlux' => $userData['restFlux'],
                'memoryValue' => $userData['memoryValue']
            ]);
            break;

        case 'getData':
            $studyTime = $userData['studyTime'];
            $restFlux = $userData['restFlux'];
            $memoryValue = $userData['memoryValue'];

            if ($userData['studyMode'] == 'pure') {
                $studyTime += $timeDiff;
                $memoryValue = calculateMemoryChange(
                    $memoryValue,
                    $timeDiff,
                    $hoursDiff,
                    $settings['pureStudyMemoryGain'],
                    $settings['forgetRate']
                );
            } elseif ($userData['studyMode'] == 'memorize') {
                $studyTime += $timeDiff;
                $restFlux += $timeDiff * $userData['restIncreaseRate'];
                $memoryValue = calculateMemoryChange(
                    $memoryValue,
                    $timeDiff,
                    $hoursDiff,
                    $settings['memorizeMemoryGain'],
                    $settings['forgetRate']
                );
            } else {
                $memoryValue = calculateForgettingCurve(
                    $hoursDiff,
                    $memoryValue,
                    $settings['forgetRate']
                );
            }

            if ($userData['restMode']) {
                $restFlux = max(0, $restFlux - $timeDiff * $userData['restDecreaseRate']);
            }

            echo json_encode([
                'studyTime' => round($studyTime, 1),
                'restFlux' => round($restFlux, 1),
                'memoryValue' => round($memoryValue, 2),
                'studyMode' => $userData['studyMode'],
                'restMode' => $userData['restMode'],
                'restIncreaseRate' => $userData['restIncreaseRate'],
                'restDecreaseRate' => $userData['restDecreaseRate'],
                'totalQuestions' => $userData['totalQuestions'] ?? 0,
                'totalStudySessions' => $userData['totalStudySessions'] ?? 0,
                'memoryHistory' => $userData['memoryHistory'] ?? [],
                'memorySettings' => $settings,
                'gachaStats' => $userData['gachaStats'],
                'gachaHistory' => $userData['gachaHistory'] ?? []
            ]);
            break;
    }
    exit;
}

// ---------- 设置默认 SESSION 值 ----------
if (!isset($_SESSION['studyTime'])) $_SESSION['studyTime'] = 0;
if (!isset($_SESSION['restFlux'])) $_SESSION['restFlux'] = 100;
if (!isset($_SESSION['memoryValue'])) $_SESSION['memoryValue'] = 100;
if (!isset($_SESSION['restIncreaseRate'])) $_SESSION['restIncreaseRate'] = 0.2;
if (!isset($_SESSION['restDecreaseRate'])) $_SESSION['restDecreaseRate'] = 1.0;
if (!isset($_SESSION['memorySettings'])) {
    $_SESSION['memorySettings'] = defaultUserData('')['memorySettings'];
}
if (!isset($_SESSION['gachaStats'])) {
    $_SESSION['gachaStats'] = defaultUserData('')['gachaStats'];
}
?>
<!DOCTYPE html>
<html lang="zh">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="referrer" content="no-referrer">
    <title>趣味学习助手 - 抽卡大乐透</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', 'Microsoft YaHei', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 20px;
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
        }

        /* 登录/注册卡片 */
        .auth-card {
            background: rgba(255, 255, 255, 0.97);
            border-radius: 20px;
            padding: 30px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            margin-bottom: 20px;
            animation: slideDown 0.5s ease;
        }

        @keyframes slideDown {
            from { opacity: 0; transform: translateY(-20px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .auth-tabs {
            display: flex;
            margin-bottom: 20px;
            border-bottom: 2px solid #e0e0e0;
        }

        .auth-tab {
            flex: 1;
            padding: 12px;
            text-align: center;
            cursor: pointer;
            font-weight: 600;
            color: #666;
            transition: all 0.3s;
            border-bottom: 3px solid transparent;
            margin-bottom: -2px;
        }

        .auth-tab.active {
            color: #667eea;
            border-bottom: 3px solid #667eea;
        }

        .auth-form {
            display: none;
            animation: fadeIn 0.5s ease;
        }

        .auth-form.active {
            display: block;
        }

        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
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

        .form-group input {
            width: 100%;
            padding: 12px 15px;
            border: 2px solid #e0e0e0;
            border-radius: 10px;
            font-size: 16px;
            transition: all 0.3s;
        }

        .form-group input:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }

        .btn {
            width: 100%;
            padding: 12px;
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
        .btn-info { background: linear-gradient(135deg, #4299e1 0%, #3182ce 100%); }
        .btn-purple { background: linear-gradient(135deg, #9f7aea 0%, #805ad5 100%); }

        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 20px rgba(102, 126, 234, 0.3);
        }

        .btn:active {
            transform: translateY(0);
        }

        .btn-gacha {
            background: linear-gradient(135deg, #fbbf24 0%, #f59e0b 100%) !important;
            font-size: 18px;
            padding: 15px;
            animation: pulse 2s infinite;
        }

        .btn-sm {
            padding: 8px 16px;
            font-size: 14px;
            width: auto;
            margin: 5px;
        }

        @keyframes pulse {
            0% { transform: scale(1); }
            50% { transform: scale(1.02); }
            100% { transform: scale(1); }
        }

        .message {
            padding: 12px;
            border-radius: 10px;
            margin-bottom: 20px;
            animation: shake 0.5s ease;
        }

        @keyframes shake {
            0%, 100% { transform: translateX(0); }
            10%, 30%, 50%, 70%, 90% { transform: translateX(-5px); }
            20%, 40%, 60%, 80% { transform: translateX(5px); }
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

        /* 主内容卡片 */
        .main-card {
            background: rgba(255, 255, 255, 0.97);
            border-radius: 20px;
            padding: 30px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            animation: slideUp 0.5s ease;
        }

        @keyframes slideUp {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
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

        .stats-container {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 20px;
            margin-bottom: 30px;
        }

        .stat-card {
            background: linear-gradient(135deg, #f3f4f6 0%, #e5e7eb 100%);
            border-radius: 15px;
            padding: 20px;
            text-align: center;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        }

        .stat-card.memory-card {
            background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%);
            border: 2px solid #fbbf24;
        }

        .stat-card.gacha-card {
            background: linear-gradient(135deg, #fee2e2 0%, #fecaca 100%);
            border: 2px solid #f87171;
        }

        .stat-label {
            font-size: 14px;
            color: #4a5568;
            margin-bottom: 10px;
        }

        .stat-value {
            font-size: 24px;
            font-weight: bold;
            color: #2d3748;
            font-family: 'Courier New', monospace;
        }

        .stat-value.success { color: #48bb78; }
        .stat-value.danger { color: #f56565; }

        .stat-unit {
            font-size: 12px;
            color: #718096;
            margin-left: 5px;
        }

        /* 进度条样式 */
        .progress-container {
            margin-top: 15px;
            text-align: left;
        }

        .progress-bar {
            width: 100%;
            height: 20px;
            background: #e2e8f0;
            border-radius: 10px;
            overflow: hidden;
            margin-bottom: 5px;
        }

        .progress-fill {
            height: 100%;
            background: linear-gradient(90deg, #48bb78 0%, #38a169 100%);
            border-radius: 10px;
            transition: width 0.3s ease;
            width: 0%;
        }

        .progress-fill.memory {
            background: linear-gradient(90deg, #fbbf24 0%, #f59e0b 100%);
        }

        .progress-fill.warning {
            background: linear-gradient(90deg, #fbbf24 0%, #f59e0b 100%);
        }

        .progress-fill.danger {
            background: linear-gradient(90deg, #f87171 0%, #ef4444 100%);
        }

        .progress-text {
            font-size: 12px;
            color: #718096;
            display: flex;
            justify-content: space-between;
        }

        .memory-tip {
            font-size: 12px;
            color: #92400e;
            margin-top: 5px;
            padding: 5px;
            background: #fef3c7;
            border-radius: 5px;
        }

        .section {
            margin: 30px 0;
            padding: 20px;
            background: #f7fafc;
            border-radius: 15px;
            border: 1px solid #e2e8f0;
        }

        .section h3 {
            color: #4a5568;
            margin-bottom: 20px;
            font-size: 1.3em;
        }

        .input-group {
            margin-bottom: 15px;
        }

        .input-group label {
            display: block;
            margin-bottom: 5px;
            color: #4a5568;
            font-weight: 500;
        }

        .input-group input {
            width: 100%;
            padding: 10px;
            border: 2px solid #e2e8f0;
            border-radius: 8px;
            font-size: 16px;
            transition: all 0.3s;
        }

        .input-group input:focus {
            outline: none;
            border-color: #667eea;
        }

        .input-group small {
            display: block;
            margin-top: 5px;
            color: #718096;
            font-size: 12px;
        }

        .button-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 10px;
            margin: 20px 0;
        }

        .status-badge {
            display: inline-block;
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 14px;
            font-weight: 500;
            margin-top: 10px;
        }

        .status-badge.active {
            background: #c6f6d5;
            color: #276749;
        }

        .status-badge.warning {
            background: #feebc8;
            color: #c05621;
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

        .user-info span {
            font-weight: 600;
            color: #4a5568;
        }

        .self-admin-badge {
            background: #9f7aea;
            color: white;
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 12px;
            margin-left: 10px;
        }

        .admin-link {
            padding: 8px 16px;
            background: #9f7aea;
            color: white;
            text-decoration: none;
            border-radius: 8px;
            font-size: 14px;
            margin-right: 10px;
            transition: all 0.3s;
            display: inline-block;
        }

        .admin-link:hover {
            background: #805ad5;
            transform: translateY(-2px);
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
        }

        .logout-btn:hover {
            background: #c53030;
            transform: translateY(-2px);
        }

        /* 个人管理面板 */
        .self-admin-panel {
            margin-top: 30px;
            padding: 20px;
            background: linear-gradient(135deg, #faf5ff 0%, #f3e8ff 100%);
            border-radius: 15px;
            border: 2px solid #9f7aea;
        }

        .self-admin-panel h3 {
            color: #553c9a;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .self-admin-tabs {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
            border-bottom: 2px solid #e9d8fd;
            padding-bottom: 10px;
            flex-wrap: wrap;
        }

        .self-admin-tab {
            padding: 8px 16px;
            cursor: pointer;
            border-radius: 8px;
            transition: all 0.3s;
            color: #553c9a;
        }

        .self-admin-tab.active {
            background: #9f7aea;
            color: white;
        }

        .self-admin-content {
            display: none;
        }

        .self-admin-content.active {
            display: block;
            animation: fadeIn 0.5s ease;
        }

        .danger-zone {
            border: 2px solid #fc8181;
            border-radius: 10px;
            padding: 20px;
            margin-top: 20px;
            background: #fff5f5;
        }

        .danger-zone h4 {
            color: #c53030;
            margin-bottom: 15px;
        }

        .info-box {
            background: #ebf4ff;
            border-left: 4px solid #4299e1;
            padding: 15px;
            border-radius: 8px;
            margin-top: 20px;
        }

        .info-box h4 {
            color: #2b6cb0;
            margin-bottom: 10px;
        }

        .info-box p {
            color: #4a5568;
            line-height: 1.6;
            margin-bottom: 8px;
        }

        /* 记忆曲线图表 */
        .memory-chart {
            margin-top: 20px;
            padding: 15px;
            background: white;
            border-radius: 10px;
            border: 1px solid #e2e8f0;
        }

        .memory-chart h4 {
            color: #4a5568;
            margin-bottom: 15px;
        }

        .chart-bars {
            display: flex;
            align-items: flex-end;
            gap: 4px;
            height: 100px;
            margin-top: 10px;
        }

        .chart-bar {
            flex: 1;
            background: linear-gradient(180deg, #fbbf24 0%, #f59e0b 100%);
            border-radius: 4px 4px 0 0;
            transition: height 0.3s ease;
            position: relative;
            min-width: 20px;
        }

        .chart-bar:hover {
            opacity: 0.8;
        }

        .chart-bar::after {
            content: attr(data-value) '%';
            position: absolute;
            top: -20px;
            left: 50%;
            transform: translateX(-50%);
            font-size: 10px;
            color: #4a5568;
            white-space: nowrap;
        }

        .chart-labels {
            display: flex;
            justify-content: space-between;
            margin-top: 5px;
            font-size: 10px;
            color: #718096;
        }

        /* 参数网格 */
        .params-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 20px;
        }

        .param-item {
            background: white;
            padding: 10px;
            border-radius: 8px;
            border: 1px solid #e9d8fd;
        }

        .param-label {
            font-size: 12px;
            color: #553c9a;
            margin-bottom: 5px;
        }

        .param-value {
            font-size: 18px;
            font-weight: bold;
            color: #2d3748;
        }

        .param-unit {
            font-size: 12px;
            color: #718096;
            margin-left: 5px;
        }

        /* 抽卡区域 */
        .gacha-container {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin: 20px 0;
        }

        .gacha-pool {
            background: linear-gradient(135deg, #ffffff 0%, #f7fafc 100%);
            border-radius: 15px;
            padding: 20px;
            text-align: center;
            border: 2px solid;
            transition: all 0.3s;
            cursor: pointer;
        }

        .gacha-pool:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.2);
        }

        .pool-icon {
            font-size: 48px;
            margin-bottom: 10px;
        }

        .pool-name {
            font-size: 18px;
            font-weight: bold;
            margin-bottom: 5px;
        }

        .pool-cost {
            font-size: 20px;
            font-weight: bold;
            color: #F59E0B;
            margin: 10px 0;
        }

        .pool-cost small {
            font-size: 14px;
            color: #718096;
        }

        .pool-cards {
            font-size: 12px;
            color: #718096;
            margin-top: 5px;
        }

        /* 抽卡统计 */
        .gacha-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
            gap: 10px;
            margin-top: 20px;
            padding: 15px;
            background: #f7fafc;
            border-radius: 10px;
        }

        .stat-item {
            text-align: center;
        }

        .stat-item .label {
            font-size: 12px;
            color: #718096;
        }

        .stat-item .value {
            font-size: 20px;
            font-weight: bold;
            color: #2d3748;
        }

        .stat-item .value.success { color: #48bb78; }
        .stat-item .value.danger { color: #f56565; }

        /* 抽卡历史 */
        .history-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
            gap: 10px;
            max-height: 200px;
            overflow-y: auto;
            padding: 10px;
            background: #f7fafc;
            border-radius: 10px;
        }

        .history-item {
            background: white;
            border-radius: 8px;
            padding: 10px;
            text-align: center;
            border-left: 4px solid;
            font-size: 12px;
            transition: all 0.3s;
        }

        .history-item .pool-name {
            font-size: 12px;
            font-weight: bold;
            margin-bottom: 5px;
        }

        .history-item .reward {
            font-weight: bold;
            color: #F59E0B;
            font-size: 16px;
        }

        .history-item .no-reward {
            font-weight: bold;
            color: #a0aec0;
            font-size: 14px;
        }

        /* 自定义弹窗 */
        .modal, .confirm-modal, .clean-modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            z-index: 9999;
            justify-content: center;
            align-items: center;
        }

        .modal.active, .confirm-modal.active, .clean-modal.active {
            display: flex;
            animation: fadeIn 0.3s ease;
        }

        .modal-content, .confirm-content, .clean-content {
            background: white;
            border-radius: 20px;
            padding: 30px;
            max-width: 400px;
            width: 90%;
            text-align: center;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            animation: slideUp 0.3s ease;
        }

        .modal-icon {
            font-size: 64px;
            margin-bottom: 20px;
            animation: bounce 1s ease;
        }

        @keyframes bounce {
            0%, 100% { transform: scale(1); }
            50% { transform: scale(1.2); }
        }

        .modal-title {
            font-size: 24px;
            font-weight: bold;
            margin-bottom: 15px;
            color: #2d3748;
        }

        .modal-message {
            font-size: 18px;
            margin-bottom: 20px;
            color: #4a5568;
            line-height: 1.6;
            white-space: pre-line;
        }

        .modal-reward {
            font-size: 32px;
            font-weight: bold;
            color: #F59E0B;
            margin-bottom: 20px;
            padding: 10px;
            background: #fef3c7;
            border-radius: 10px;
        }

        .modal-button {
            padding: 12px 30px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            border-radius: 10px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
        }

        .modal-button:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 20px rgba(102, 126, 234, 0.3);
        }

        .confirm-title, .clean-title {
            font-size: 20px;
            font-weight: bold;
            margin-bottom: 20px;
            color: #2d3748;
        }

        .clean-title {
            color: #ed8936;
        }

        .clean-message {
            font-size: 16px;
            margin-bottom: 20px;
            color: #4a5568;
        }

        .confirm-buttons, .clean-buttons {
            display: flex;
            gap: 10px;
            justify-content: center;
        }

        .confirm-btn, .clean-btn {
            padding: 10px 20px;
            border: none;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            color: white;
        }

        .confirm-btn.yes { background: #48bb78; }
        .confirm-btn.no { background: #f56565; }
        .clean-btn.yes { background: #ed8936; }
        .clean-btn.no { background: #a0aec0; }

        .confirm-btn:hover, .clean-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 10px rgba(0, 0, 0, 0.1);
        }

        @media (max-width: 768px) {
            .stats-container {
                grid-template-columns: 1fr 1fr;
            }

            .button-grid {
                grid-template-columns: 1fr;
            }

            .params-grid {
                grid-template-columns: 1fr;
            }

            .gacha-container {
                grid-template-columns: 1fr;
            }

            h1 {
                font-size: 1.6em;
            }
        }

        @media (max-width: 480px) {
            .stats-container {
                grid-template-columns: 1fr;
            }

            .user-info {
                flex-direction: column;
                align-items: flex-start;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <?php if (!isset($_SESSION['username'])): ?>
            <!-- 登录/注册界面 -->
            <div class="auth-card">
                <h1>趣味学习助手 - 抽卡大乐透</h1>
                <div class="auth-tabs">
                    <div class="auth-tab active" onclick="switchTab('login')">登录</div>
                    <div class="auth-tab" onclick="switchTab('register')">注册</div>
                </div>

                <?php if ($message): ?>
                    <div class="message <?php echo $messageType; ?>">
                        <?php echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?>
                    </div>
                <?php endif; ?>

                <!-- 登录表单 -->
                <div id="login-form" class="auth-form active">
                    <form method="POST" action="">
                        <div class="form-group">
                            <label>用户名</label>
                            <input type="text" name="username" required maxlength="20" placeholder="中文/字母/数字/下划线，2-20位">
                        </div>
                        <div class="form-group">
                            <label>密码</label>
                            <input type="password" name="password" required>
                        </div>
                        <button type="submit" name="login" class="btn btn-primary">登录</button>
                    </form>
                </div>

                <!-- 注册表单 -->
                <div id="register-form" class="auth-form">
                    <form method="POST" action="">
                        <div class="form-group">
                            <label>用户名</label>
                            <input type="text" name="username" required maxlength="20" placeholder="中文/字母/数字/下划线，2-20位">
                        </div>
                        <div class="form-group">
                            <label>密码 (至少6位)</label>
                            <input type="password" name="password" required minlength="6">
                        </div>
                        <div class="form-group">
                            <label>确认密码</label>
                            <input type="password" name="confirm_password" required>
                        </div>
                        <button type="submit" name="register" class="btn btn-primary">注册</button>
                    </form>
                </div>
            </div>
        <?php else:
            $gachaConfig = getGachaConfig();
            $gachaStats = $_SESSION['gachaStats'] ?? defaultUserData('')['gachaStats'];
            $netGain = ($gachaStats['totalWon'] ?? 0) - ($gachaStats['totalSpent'] ?? 0);
            $userData = readUserData($_SESSION['username']);
            $gachaHistory = (is_array($userData) && isset($userData['gachaHistory']) && is_array($userData['gachaHistory'])) ? $userData['gachaHistory'] : [];
            $registered = false;
        ?>
            <!-- 主界面 -->
            <div class="main-card">
                <?php if ($message): ?>
                    <div class="message <?php echo $messageType; ?>">
                        <?php echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?>
                    </div>
                <?php endif; ?>

                <div class="user-info">
                    <span>
                        欢迎回来，<?php echo htmlspecialchars($_SESSION['username'], ENT_QUOTES, 'UTF-8'); ?>
                        <span class="self-admin-badge">个人管理员</span>
                    </span>
                    <div>
                        <a href="admin.php" target="_blank" rel="noopener" class="admin-link">管理员后台</a>
                        <a href="?logout=1" class="logout-btn" onclick="return confirm('确定要退出登录吗？');">退出登录</a>
                    </div>
                </div>

                <h1>趣味学习助手 - 抽卡大乐透</h1>

                <div class="stats-container">
                    <div class="stat-card">
                        <div class="stat-label">累计学习时长</div>
                        <div class="stat-value" id="studyTime">00:00:00</div>
                        <div class="progress-container">
                            <div class="progress-bar">
                                <div class="progress-fill" id="studyProgress" style="width: 0%"></div>
                            </div>
                            <div class="progress-text">
                                <span>目标: 每天 4 小时</span>
                                <span id="studyProgressText">0%</span>
                            </div>
                        </div>
                    </div>

                    <div class="stat-card">
                        <div class="stat-label">当前通量</div>
                        <div class="stat-value" id="restFlux">0</div>
                        <div class="progress-container">
                            <div class="progress-bar">
                                <div class="progress-fill" id="fluxProgress" style="width: 0%"></div>
                            </div>
                            <div class="progress-text">
                                <span>下一级: <span id="nextFluxLevel">1k</span></span>
                                <span id="fluxProgressText">0%</span>
                            </div>
                        </div>
                    </div>

                    <div class="stat-card memory-card">
                        <div class="stat-label">记忆程度</div>
                        <div class="stat-value" id="memoryValue">100%</div>
                        <div class="progress-container">
                            <div class="progress-bar">
                                <div class="progress-fill memory" id="memoryProgress" style="width: 100%"></div>
                            </div>
                            <div class="progress-text">
                                <span>基于艾宾浩斯曲线</span>
                                <span id="memoryLevel">完美</span>
                            </div>
                        </div>
                    </div>

                    <div class="stat-card gacha-card">
                        <div class="stat-label">抽卡统计</div>
                        <div class="stat-value" id="totalPulls"><?php echo (int)($gachaStats['totalPulls'] ?? 0); ?></div>
                        <span class="stat-unit">抽</span>
                        <div class="progress-text">
                            <span>花费: <span id="totalSpent"><?php echo (int)($gachaStats['totalSpent'] ?? 0); ?></span></span>
                            <span>获得: <span id="totalWon"><?php echo (int)($gachaStats['totalWon'] ?? 0); ?></span></span>
                        </div>
                        <div class="progress-text">
                            <span>净收益: </span>
                            <span class="stat-value <?php echo $netGain >= 0 ? 'success' : 'danger'; ?>" id="netGain" style="font-size: 16px;">
                                <?php echo $netGain; ?>
                            </span>
                        </div>
                    </div>
                </div>

                <!-- 抽卡大乐透区域 -->
                <div class="section">
                    <h3>🎰 抽卡大乐透 - 消耗通量赢取更多通量！</h3>

                    <div class="gacha-container">
                        <?php
                        $icons = ['common' => '🎴', 'rare' => '✨', 'epic' => '🌟', 'legendary' => '💫', 'mythic' => '👑'];
                        foreach ($gachaConfig['pools'] as $key => $pool):
                            $pool = array_merge(['name' => '卡池', 'cost' => 10, 'color' => '#9CA3AF', 'min_reward' => 0, 'max_reward' => 0, 'probability' => 0], $pool);
                        ?>
                        <div class="gacha-pool" style="border-color: <?php echo htmlspecialchars($pool['color'], ENT_QUOTES, 'UTF-8'); ?>;" onclick="confirmDraw('<?php echo htmlspecialchars($key, ENT_QUOTES, 'UTF-8'); ?>', '<?php echo htmlspecialchars($pool['name'], ENT_QUOTES, 'UTF-8'); ?>', <?php echo (int)$pool['cost']; ?>)">
                            <div class="pool-icon">
                                <?php echo $icons[$key] ?? '🎴'; ?>
                            </div>
                            <div class="pool-name"><?php echo htmlspecialchars($pool['name'], ENT_QUOTES, 'UTF-8'); ?></div>
                            <div class="pool-cost"><?php echo (int)$pool['cost']; ?> <small>通量</small></div>
                            <div class="pool-cards">奖励范围: <?php echo (int)$pool['min_reward']; ?> - <?php echo (int)$pool['max_reward']; ?> 通量</div>
                            <div class="pool-cards">中奖概率: <?php echo (int)$pool['probability']; ?>%</div>
                            <button type="button" class="btn btn-primary" style="margin-top: 10px; pointer-events: none;">抽一张</button>
                        </div>
                        <?php endforeach; ?>
                    </div>

                    <!-- 抽卡统计 -->
                    <div class="gacha-stats">
                        <div class="stat-item">
                            <div class="label">总抽卡</div>
                            <div class="value"><?php echo (int)($gachaStats['totalPulls'] ?? 0); ?></div>
                        </div>
                        <div class="stat-item">
                            <div class="label">总花费</div>
                            <div class="value"><?php echo (int)($gachaStats['totalSpent'] ?? 0); ?></div>
                        </div>
                        <div class="stat-item">
                            <div class="label">总获得</div>
                            <div class="value"><?php echo (int)($gachaStats['totalWon'] ?? 0); ?></div>
                        </div>
                        <div class="stat-item">
                            <div class="label">净收益</div>
                            <div class="value <?php echo $netGain >= 0 ? 'success' : 'danger'; ?>">
                                <?php echo $netGain; ?>
                            </div>
                        </div>
                    </div>

                    <!-- 特殊事件说明 -->
                    <?php if (!empty($gachaConfig['special_events']['enabled'])): ?>
                    <div class="memory-tip" style="margin-top: 10px; text-align: center;">
                        ✨ 特殊事件：<?php echo (int)($gachaConfig['special_events']['double_probability'] ?? 0); ?>%概率双倍奖励 | 1%概率<?php echo (int)($gachaConfig['special_events']['jackpot_multiplier'] ?? 10); ?>倍大奖
                    </div>
                    <?php endif; ?>

                    <!-- 抽卡历史 -->
                    <?php if (!empty($gachaHistory)): ?>
                    <div style="margin-top: 20px;">
                        <h4>📜 最近抽卡记录</h4>
                        <div class="history-grid">
                            <?php foreach (array_reverse($gachaHistory) as $item):
                                if (!is_array($item)) continue;
                                $poolColor = '#9CA3AF';
                                foreach ($gachaConfig['pools'] as $p) {
                                    if (($p['name'] ?? '') == ($item['pool'] ?? '')) {
                                        $poolColor = $p['color'] ?? '#9CA3AF';
                                        break;
                                    }
                                }
                                $isWin = !empty($item['isWin']);
                                $rewardClass = $isWin ? 'reward' : 'no-reward';
                                $displayText = $isWin ? '+' . (int)($item['reward'] ?? 0) : '😢 未中奖';
                            ?>
                            <div class="history-item" style="border-left-color: <?php echo htmlspecialchars($poolColor, ENT_QUOTES, 'UTF-8'); ?>; <?php echo $isWin ? '' : 'opacity: 0.7;'; ?>">
                                <div class="pool-name"><?php echo htmlspecialchars($item['pool'] ?? '未知卡池', ENT_QUOTES, 'UTF-8'); ?></div>
                                <div class="<?php echo $rewardClass; ?>" style="<?php echo $isWin ? '' : 'color: #a0aec0;'; ?>">
                                    <?php echo $displayText; ?>
                                </div>
                                <div style="font-size: 10px; color: #718096;">
                                    <?php echo isset($item['time']) ? date('H:i', (int)$item['time']) : ''; ?>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- 当前参数显示 -->
                <div class="params-grid">
                    <div class="param-item">
                        <div class="param-label">遗忘速率</div>
                        <div class="param-value" id="forgetRate">0.56</div>
                        <span class="param-unit">/log10(小时)</span>
                    </div>
                    <div class="param-item">
                        <div class="param-label">刷题记忆增益</div>
                        <div class="param-value" id="questionGain">0.5</div>
                        <span class="param-unit">%/题</span>
                    </div>
                    <div class="param-item">
                        <div class="param-label">单纯学习增益</div>
                        <div class="param-value" id="pureStudyGain">0.008</div>
                        <span class="param-unit">%/秒</span>
                    </div>
                    <div class="param-item">
                        <div class="param-label">背题模式增益</div>
                        <div class="param-value" id="memorizeGain">0.012</div>
                        <span class="param-unit">%/秒</span>
                    </div>
                </div>

                <!-- 记忆曲线图表 -->
                <div class="memory-chart">
                    <h4>📈 记忆曲线历史 (最近50次变化)</h4>
                    <div class="chart-bars" id="memoryChart"><span style="color:#718096;font-size:14px;align-self:center;">暂无数据，开始学习后将自动记录</span></div>
                    <div class="chart-labels">
                        <span>较早</span>
                        <span>最近</span>
                    </div>
                </div>

                <div class="section">
                    <h3>📚 刷题助手</h3>
                    <div class="input-group">
                        <label>最低刷题数</label>
                        <input type="number" id="minQuestions" value="1" min="1">
                    </div>
                    <div class="input-group">
                        <label>最高刷题数</label>
                        <input type="number" id="maxQuestions" value="10" min="1">
                    </div>
                    <div class="input-group">
                        <label>倍率</label>
                        <input type="number" id="multiplier" value="1" step="0.1" min="0">
                    </div>
                    <button type="button" id="generateQuestions" class="btn btn-primary">生成题数</button>
                    <div style="text-align: center; margin-top: 15px; font-size: 18px; color: #4a5568;">
                        <span id="generatedQuestions">点击按钮生成题数</span>
                    </div>
                    <div class="memory-tip" style="margin-top: 10px;" id="questionMemoryTip">
                        💡 每刷一题增加0.5%记忆值
                    </div>
                </div>

                <div class="section">
                    <h3>⏰ 学习模式</h3>
                    <div class="button-grid">
                        <button type="button" id="startStudy" class="btn btn-primary">单纯学习</button>
                        <button type="button" id="endStudy" class="btn btn-danger">结束学习</button>
                        <button type="button" id="startMemorization" class="btn btn-success">开始背题</button>
                        <button type="button" id="endMemorization" class="btn btn-danger">结束背题</button>
                    </div>
                    <span id="studyStatus" class="status-badge"></span>
                    <div class="memory-tip" style="margin-top: 10px;" id="studyMemoryTip">
                        💡 单纯学习：每秒+0.8%记忆 | 背题模式：每秒+1.2%记忆
                    </div>
                </div>

                <div class="section">
                    <h3>😴 休息模式</h3>
                    <div class="button-grid">
                        <button type="button" id="startRest" class="btn btn-warning">开始休息</button>
                        <button type="button" id="endRest" class="btn btn-danger">结束休息</button>
                    </div>
                    <span id="restStatus" class="status-badge"></span>
                    <div class="memory-tip" style="margin-top: 10px;">
                        💡 休息时通量按设定速率消耗，记忆值按遗忘曲线自然衰减
                    </div>
                </div>

                <!-- 个人管理面板 -->
                <div class="self-admin-panel">
                    <h3>
                        <span>👤 个人管理面板</span>
                    </h3>

                    <div class="self-admin-tabs">
                        <div class="self-admin-tab active" onclick="switchSelfTab('memory')">记忆参数</div>
                        <div class="self-admin-tab" onclick="switchSelfTab('flux')">通量设置</div>
                        <div class="self-admin-tab" onclick="switchSelfTab('data')">数据管理</div>
                        <div class="self-admin-tab" onclick="switchSelfTab('clean')">清理数据</div>
                        <div class="self-admin-tab" onclick="switchSelfTab('danger')">危险操作</div>
                    </div>

                    <!-- 记忆参数设置标签 -->
                    <div id="self-memory" class="self-admin-content active">
                        <h4>🧠 记忆参数设置</h4>
                        <form method="POST">
                            <div class="input-group">
                                <label>遗忘速率</label>
                                <input type="number" name="forget_rate" value="<?php echo htmlspecialchars((string)($_SESSION['memorySettings']['forgetRate'] ?? 0.56), ENT_QUOTES, 'UTF-8'); ?>" step="0.01" min="0" max="2" required>
                                <small>数值越大遗忘越快 (默认0.56，基于艾宾浩斯曲线)</small>
                            </div>
                            <div class="input-group">
                                <label>刷题记忆增益 (%/题)</label>
                                <input type="number" name="question_memory_gain" value="<?php echo htmlspecialchars((string)($_SESSION['memorySettings']['questionMemoryGain'] ?? 0.5), ENT_QUOTES, 'UTF-8'); ?>" step="0.1" min="0" max="10" required>
                                <small>每刷一题增加的记忆百分比</small>
                            </div>
                            <div class="input-group">
                                <label>单纯学习增益 (%/秒)</label>
                                <input type="number" name="pure_study_memory_gain" value="<?php echo htmlspecialchars((string)($_SESSION['memorySettings']['pureStudyMemoryGain'] ?? 0.008), ENT_QUOTES, 'UTF-8'); ?>" step="0.001" min="0" max="1" required>
                                <small>单纯学习时每秒增加的记忆百分比</small>
                            </div>
                            <div class="input-group">
                                <label>背题模式增益 (%/秒)</label>
                                <input type="number" name="memorize_memory_gain" value="<?php echo htmlspecialchars((string)($_SESSION['memorySettings']['memorizeMemoryGain'] ?? 0.012), ENT_QUOTES, 'UTF-8'); ?>" step="0.001" min="0" max="1" required>
                                <small>背题模式时每秒增加的记忆百分比</small>
                            </div>
                            <button type="submit" name="update_my_settings" class="btn btn-purple">保存记忆参数</button>
                        </form>
                    </div>

                    <!-- 通量设置标签 -->
                    <div id="self-flux" class="self-admin-content">
                        <h4>⚡ 通量设置</h4>
                        <form method="POST">
                            <div class="input-group">
                                <label>背题时每秒增加通量</label>
                                <input type="number" name="rest_increase_rate" value="<?php echo htmlspecialchars((string)($_SESSION['restIncreaseRate'] ?? 0.2), ENT_QUOTES, 'UTF-8'); ?>" step="0.1" min="0" required>
                                <small>背题模式下每秒获得的通量</small>
                            </div>
                            <div class="input-group">
                                <label>休息时每秒消耗通量</label>
                                <input type="number" name="rest_decrease_rate" value="<?php echo htmlspecialchars((string)($_SESSION['restDecreaseRate'] ?? 1.0), ENT_QUOTES, 'UTF-8'); ?>" step="0.1" min="0" required>
                                <small>休息模式下每秒消耗的通量</small>
                            </div>
                            <button type="submit" name="update_my_settings" class="btn btn-purple">保存通量设置</button>
                        </form>
                    </div>

                    <!-- 数据管理标签 -->
                    <div id="self-data" class="self-admin-content">
                        <h4>📊 数据管理</h4>
                        <form method="POST">
                            <div class="input-group">
                                <label>学习时长 (秒)</label>
                                <input type="number" name="study_time" value="<?php echo htmlspecialchars((string)($_SESSION['studyTime'] ?? 0), ENT_QUOTES, 'UTF-8'); ?>" step="1" min="0">
                            </div>
                            <div class="input-group">
                                <label>通量</label>
                                <input type="number" name="rest_flux" value="<?php echo htmlspecialchars((string)($_SESSION['restFlux'] ?? 100), ENT_QUOTES, 'UTF-8'); ?>" step="1" min="0">
                            </div>
                            <div class="input-group">
                                <label>记忆程度 (%)</label>
                                <input type="number" name="memory_value" value="<?php echo htmlspecialchars((string)($_SESSION['memoryValue'] ?? 100), ENT_QUOTES, 'UTF-8'); ?>" step="0.1" min="0" max="100">
                            </div>
                            <button type="submit" name="update_my_settings" class="btn btn-purple">更新数据</button>
                        </form>
                    </div>

                    <!-- 清理数据标签 -->
                    <div id="self-clean" class="self-admin-content">
                        <h4>🧹 清理数据</h4>
                        <div style="display: flex; flex-direction: column; gap: 10px;">
                            <button type="button" onclick="showCleanModal('memory')" class="btn btn-warning btn-sm">清空记忆曲线历史</button>
                            <button type="button" onclick="showCleanModal('gacha')" class="btn btn-warning btn-sm">清空抽卡记录</button>
                            <button type="button" onclick="showCleanModal('stats')" class="btn btn-warning btn-sm">重置抽卡统计</button>
                        </div>
                    </div>

                    <!-- 危险操作标签 -->
                    <div id="self-danger" class="self-admin-content">
                        <div class="danger-zone">
                            <h4>⚠️ 危险操作区</h4>

                            <h5>注销账号</h5>
                            <div class="input-group">
                                <label>请输入 DELETE 确认注销</label>
                                <input type="text" id="deleteConfirm" placeholder="DELETE">
                            </div>
                            <button type="button" onclick="showDeleteModal()" class="btn btn-danger">永久注销账号</button>
                        </div>
                    </div>
                </div>

                <div class="info-box">
                    <h4>📖 使用介绍</h4>
                    <p>• 🎰 抽卡大乐透：消耗通量抽取卡池，根据概率中奖！</p>
                    <?php foreach ($gachaConfig['pools'] as $pool): ?>
                    <p>• <?php echo htmlspecialchars($pool['name'] ?? '卡池', ENT_QUOTES, 'UTF-8'); ?> (<?php echo (int)($pool['cost'] ?? 0); ?>通量)：<?php echo (int)($pool['min_reward'] ?? 0); ?>-<?php echo (int)($pool['max_reward'] ?? 0); ?>通量奖励，<?php echo (int)($pool['probability'] ?? 0); ?>%中奖概率</p>
                    <?php endforeach; ?>
                    <p>• ✨ 特殊事件：中奖后有机会获得双倍或<?php echo (int)($gachaConfig['special_events']['jackpot_multiplier'] ?? 10); ?>倍大奖</p>
                    <p>• 😢 未中奖不会获得任何通量，但消耗的通量不退还</p>
                    <p>• 记忆程度：基于艾宾浩斯遗忘曲线自动计算，学习提升，休息衰减</p>
                    <p>• 注册赠送100通量，快去抽卡试试手气！</p>
                    <p>• 👑 管理员可到 admin.php 调整所有抽卡参数</p>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <!-- 自定义抽卡结果弹窗 -->
    <div id="gachaModal" class="modal">
        <div class="modal-content">
            <div class="modal-icon" id="modalIcon">🎰</div>
            <div class="modal-title" id="modalTitle">抽卡结果</div>
            <div class="modal-message" id="modalMessage"></div>
            <div class="modal-reward" id="modalReward"></div>
            <button class="modal-button" onclick="closeModal()">确定</button>
        </div>
    </div>

    <!-- 自定义确认抽卡弹窗 -->
    <div id="confirmModal" class="confirm-modal">
        <div class="confirm-content">
            <div class="confirm-title" id="confirmTitle">确认抽卡</div>
            <div class="modal-message" id="confirmMessage"></div>
            <div class="confirm-buttons">
                <button class="confirm-btn yes" id="confirmYes">确定</button>
                <button class="confirm-btn no" onclick="closeConfirmModal()">取消</button>
            </div>
        </div>
    </div>

    <!-- 自定义清理数据确认弹窗 -->
    <div id="cleanModal" class="clean-modal">
        <div class="clean-content">
            <div class="clean-title" id="cleanTitle">确认清理</div>
            <div class="clean-message" id="cleanMessage"></div>
            <div class="clean-buttons">
                <button class="clean-btn yes" id="cleanYes">确定</button>
                <button class="clean-btn no" onclick="closeCleanModal()">取消</button>
            </div>
        </div>
    </div>

    <!-- 自定义注销确认弹窗 -->
    <div id="deleteModal" class="clean-modal">
        <div class="clean-content">
            <div class="clean-title" style="color: #f56565;">⚠️ 危险操作</div>
            <div class="clean-message" id="deleteMessage"></div>
            <div class="clean-buttons">
                <button class="clean-btn yes" style="background: #f56565;" id="deleteYes">确定注销</button>
                <button class="clean-btn no" onclick="closeDeleteModal()">取消</button>
            </div>
        </div>
    </div>

    <?php if (isset($_SESSION['username'])): ?>
    <script>
        // 通过服务器注入的初始数据（已 JSON 编码，安全）
        var INITIAL_DATA = <?php
            $initUserData = ensureUserDataComplete(readUserData($_SESSION['username']));
            echo json_encode([
                'studyTime' => $initUserData['studyTime'],
                'restFlux' => $initUserData['restFlux'],
                'memoryValue' => $initUserData['memoryValue'],
                'restIncreaseRate' => $initUserData['restIncreaseRate'],
                'restDecreaseRate' => $initUserData['restDecreaseRate'],
                'memorySettings' => $initUserData['memorySettings'],
            ]);
        ?>;

        let studyTime = parseFloat(INITIAL_DATA.studyTime) || 0;
        let restFlux = parseFloat(INITIAL_DATA.restFlux) || 0;
        let memoryValue = parseFloat(INITIAL_DATA.memoryValue) || 100;
        let currentStudyMode = 'none';
        let currentRestMode = false;
        let restIncreaseRate = parseFloat(INITIAL_DATA.restIncreaseRate) || 0.2;
        let restDecreaseRate = parseFloat(INITIAL_DATA.restDecreaseRate) || 1.0;
        let memorySettings = INITIAL_DATA.memorySettings || {
            forgetRate: 0.56,
            questionMemoryGain: 0.5,
            pureStudyMemoryGain: 0.008,
            memorizeMemoryGain: 0.012
        };
        let isUpdating = false;
        let memoryHistory = [];
        let currentPoolType = '';
        let currentPoolName = '';
        let currentPoolCost = 0;
        let currentCleanAction = '';

        // 格式化时间为 HH:MM:SS
        function formatTime(seconds) {
            seconds = Math.max(0, Math.floor(seconds));
            const hours = Math.floor(seconds / 3600);
            const minutes = Math.floor((seconds % 3600) / 60);
            const secs = seconds % 60;
            return `${hours.toString().padStart(2, '0')}:${minutes.toString().padStart(2, '0')}:${secs.toString().padStart(2, '0')}`;
        }

        // 格式化通量（千进制）
        function formatFlux(seconds) {
            seconds = Math.max(0, seconds);
            if (seconds >= 1e9) return (seconds / 1e9).toFixed(2) + 'G';
            if (seconds >= 1e6) return (seconds / 1e6).toFixed(2) + 'M';
            if (seconds >= 1e3) return (seconds / 1e3).toFixed(2) + 'k';
            return Math.floor(seconds).toString();
        }

        // 获取记忆水平描述
        function getMemoryLevel(value) {
            if (value >= 90) return '完美';
            if (value >= 80) return '优秀';
            if (value >= 70) return '良好';
            if (value >= 60) return '一般';
            if (value >= 50) return '及格';
            if (value >= 40) return '较差';
            if (value >= 30) return '差';
            if (value >= 20) return '很差';
            if (value >= 10) return '极差';
            return '需要复习';
        }

        // 获取下一个千进制等级
        function getNextFluxLevel(seconds) {
            if (seconds < 1e3) return 1e3;
            if (seconds < 1e6) return 1e6;
            if (seconds < 1e9) return 1e9;
            return 1e12;
        }

        // 计算通量进度百分比
        function getFluxProgress(seconds) {
            if (seconds < 1e3) return (seconds / 1e3) * 100;
            if (seconds < 1e6) return ((seconds - 1e3) / (1e6 - 1e3)) * 100;
            if (seconds < 1e9) return ((seconds - 1e6) / (1e9 - 1e6)) * 100;
            return 100;
        }

        // 更新显示
        function updateDisplay() {
            document.getElementById('studyTime').textContent = formatTime(studyTime);
            document.getElementById('restFlux').textContent = formatFlux(restFlux);
            document.getElementById('memoryValue').textContent = parseFloat(memoryValue).toFixed(1) + '%';

            // 更新参数显示
            document.getElementById('forgetRate').textContent = parseFloat(memorySettings.forgetRate || 0).toFixed(2);
            document.getElementById('questionGain').textContent = parseFloat(memorySettings.questionMemoryGain || 0).toFixed(1);
            document.getElementById('pureStudyGain').textContent = (parseFloat(memorySettings.pureStudyMemoryGain || 0) * 100).toFixed(1);
            document.getElementById('memorizeGain').textContent = (parseFloat(memorySettings.memorizeMemoryGain || 0) * 100).toFixed(1);

            // 更新提示信息
            document.getElementById('questionMemoryTip').innerHTML =
                `💡 每刷一题增加 ${parseFloat(memorySettings.questionMemoryGain || 0)}% 记忆值`;
            document.getElementById('studyMemoryTip').innerHTML =
                `💡 单纯学习：每秒+${(parseFloat(memorySettings.pureStudyMemoryGain || 0) * 100).toFixed(1)}%记忆 | 背题模式：每秒+${(parseFloat(memorySettings.memorizeMemoryGain || 0) * 100).toFixed(1)}%记忆`;

            // 更新学习进度条（目标：每天4小时）
            const studyProgress = Math.min(100, (studyTime % 86400) / 14400 * 100);
            document.getElementById('studyProgress').style.width = studyProgress + '%';
            document.getElementById('studyProgressText').textContent = studyProgress.toFixed(1) + '%';

            // 更新通量进度条
            const fluxProgress = getFluxProgress(restFlux);
            const nextLevel = getNextFluxLevel(restFlux);
            document.getElementById('fluxProgress').style.width = Math.min(fluxProgress, 100) + '%';
            document.getElementById('nextFluxLevel').textContent = formatFlux(nextLevel);
            document.getElementById('fluxProgressText').textContent = Math.min(fluxProgress, 100).toFixed(1) + '%';

            // 更新记忆进度条
            document.getElementById('memoryProgress').style.width = Math.max(0, Math.min(100, memoryValue)) + '%';
            document.getElementById('memoryLevel').textContent = getMemoryLevel(memoryValue);

            // 根据通量大小改变进度条颜色
            const progressBar = document.getElementById('fluxProgress');
            if (restFlux < 100) {
                progressBar.className = 'progress-fill danger';
            } else if (restFlux < 1000) {
                progressBar.className = 'progress-fill warning';
            } else {
                progressBar.className = 'progress-fill';
            }
        }

        // 更新记忆曲线图表
        function updateMemoryChart(history) {
            const chart = document.getElementById('memoryChart');
            if (!chart) return;
            if (!history || history.length === 0) {
                chart.innerHTML = '<span style="color:#718096;font-size:14px;align-self:center;">暂无数据，开始学习后将自动记录</span>';
                return;
            }

            let html = '';
            const recentHistory = history.slice(-20);

            recentHistory.forEach(item => {
                if (!item || typeof item.value === 'undefined') return;
                const v = Math.max(0, Math.min(100, parseFloat(item.value) || 0));
                html += `<div class="chart-bar" style="height: ${v}%" data-value="${v.toFixed(1)}"></div>`;
            });

            chart.innerHTML = html || '<span style="color:#718096;font-size:14px;align-self:center;">暂无数据</span>';
        }

        // 从服务器获取数据
        function fetchDataFromServer() {
            if (isUpdating) return;

            fetch('', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'action=getData'
            })
            .then(response => response.json())
            .then(data => {
                if (data.error) {
                    console.error('Server error:', data.error);
                    return;
                }

                studyTime = parseFloat(data.studyTime) || 0;
                restFlux = parseFloat(data.restFlux) || 0;
                memoryValue = parseFloat(data.memoryValue) || 100;
                currentStudyMode = data.studyMode || 'none';
                currentRestMode = !!data.restMode;

                if (data.memorySettings) {
                    memorySettings = data.memorySettings;
                }

                if (data.memoryHistory) {
                    updateMemoryChart(data.memoryHistory);
                }

                if (data.gachaStats) {
                    const stats = data.gachaStats;
                    const totalPulls = parseInt(stats.totalPulls) || 0;
                    const totalSpent = parseInt(stats.totalSpent) || 0;
                    const totalWon = parseInt(stats.totalWon) || 0;
                    document.getElementById('totalPulls').textContent = totalPulls;
                    document.getElementById('totalSpent').textContent = totalSpent;
                    document.getElementById('totalWon').textContent = totalWon;
                    const net = totalWon - totalSpent;
                    const netEl = document.getElementById('netGain');
                    netEl.textContent = net;
                    netEl.className = 'stat-value ' + (net >= 0 ? 'success' : 'danger');
                    // 同步 gacha 统计区块
                    const gachaStatValues = document.querySelectorAll('.gacha-stats .stat-item .value');
                    if (gachaStatValues.length >= 4) {
                        gachaStatValues[0].textContent = totalPulls;
                        gachaStatValues[1].textContent = totalSpent;
                        gachaStatValues[2].textContent = totalWon;
                        gachaStatValues[3].textContent = net;
                        gachaStatValues[3].className = 'value ' + (net >= 0 ? 'success' : 'danger');
                    }
                }

                updateDisplay();
                updateStatusBadges();
            })
            .catch(error => {
                console.error('Fetch error:', error);
            });
        }

        // 更新模式到服务器
        function updateMode(studyMode, restMode) {
            isUpdating = true;
            fetch('', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'action=updateMode&studyMode=' + encodeURIComponent(studyMode) + '&restMode=' + (restMode ? 'true' : 'false')
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    studyTime = parseFloat(data.studyTime) || 0;
                    restFlux = parseFloat(data.restFlux) || 0;
                    memoryValue = parseFloat(data.memoryValue) || 100;
                    updateDisplay();
                }
                isUpdating = false;
            })
            .catch(() => { isUpdating = false; });
        }

        // 添加通量
        function addRestFlux(amount) {
            isUpdating = true;
            fetch('', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'action=addRestFlux&amount=' + encodeURIComponent(amount)
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    studyTime = parseFloat(data.studyTime) || 0;
                    restFlux = parseFloat(data.restFlux) || 0;
                    memoryValue = parseFloat(data.memoryValue) || 100;
                    updateDisplay();
                }
                isUpdating = false;
            })
            .catch(() => { isUpdating = false; });
        }

        // 更新状态徽章
        function updateStatusBadges() {
            if (currentStudyMode == 'pure') {
                document.getElementById('studyStatus').textContent = `单纯学习中... (+${(parseFloat(memorySettings.pureStudyMemoryGain || 0) * 100).toFixed(1)}%/s)`;
                document.getElementById('studyStatus').className = 'status-badge active';
            } else if (currentStudyMode == 'memorize') {
                document.getElementById('studyStatus').textContent = `背题中... (+${(parseFloat(memorySettings.memorizeMemoryGain || 0) * 100).toFixed(1)}%/s)`;
                document.getElementById('studyStatus').className = 'status-badge active';
            } else {
                document.getElementById('studyStatus').textContent = '';
                document.getElementById('studyStatus').className = 'status-badge';
            }

            if (currentRestMode) {
                document.getElementById('restStatus').textContent = '休息中... (通量消耗中)';
                document.getElementById('restStatus').className = 'status-badge warning';
            } else {
                document.getElementById('restStatus').textContent = '';
                document.getElementById('restStatus').className = 'status-badge';
            }
        }

        // 抽卡确认
        function confirmDraw(poolType, poolName, cost) {
            currentPoolType = poolType;
            currentPoolName = poolName;
            currentPoolCost = cost;

            document.getElementById('confirmTitle').textContent = '确认抽卡';
            document.getElementById('confirmMessage').textContent = `确定要消耗 ${cost} 通量抽「${poolName}」吗？\n中奖概率：${getPoolProbability(poolType)}%`;
            document.getElementById('confirmYes').onclick = performDraw;
            document.getElementById('confirmModal').classList.add('active');
        }

        // 获取卡池概率
        function getPoolProbability(poolType) {
            const probabilities = {
                'common': 60, 'rare': 25, 'epic': 10, 'legendary': 4, 'mythic': 1
            };
            return probabilities[poolType] || 0;
        }

        // 执行抽卡
        function performDraw() {
            closeConfirmModal();

            const formData = new FormData();
            formData.append('draw_card', '1');
            formData.append('pool_type', currentPoolType);
            formData.append('ajax', '1');

            fetch('', { method: 'POST', body: formData })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // 更新页面数据
                    fetchDataFromServer();

                    // 显示抽卡结果
                    let icon = data.isWin ? '🎰' : '😢';
                    if (data.isWin) {
                        if ((data.special || '').includes('双倍')) icon = '✨';
                        if ((data.special || '').includes('超级大奖')) icon = '💫';
                    }

                    document.getElementById('modalIcon').textContent = icon;
                    document.getElementById('modalTitle').textContent = data.isWin ? '抽卡成功！' : '很遗憾';
                    document.getElementById('modalMessage').textContent = data.message;
                    document.getElementById('modalReward').textContent = data.isWin ? `+${data.reward} 通量` : '0 通量';
                    document.getElementById('gachaModal').classList.add('active');
                } else {
                    document.getElementById('modalIcon').textContent = '❌';
                    document.getElementById('modalTitle').textContent = '抽卡失败';
                    document.getElementById('modalMessage').textContent = data.message;
                    document.getElementById('modalReward').textContent = '';
                    document.getElementById('gachaModal').classList.add('active');
                }
            })
            .catch(error => {
                console.error('Error:', error);
            });
        }

        // 显示清理数据确认弹窗
        function showCleanModal(action) {
            currentCleanAction = action;
            let title = '', message = '';

            if (action === 'memory') {
                title = '清空记忆曲线历史';
                message = '确定要清空记忆曲线历史吗？此操作不可恢复！';
            } else if (action === 'gacha') {
                title = '清空抽卡记录';
                message = '确定要清空抽卡记录吗？此操作不可恢复！';
            } else if (action === 'stats') {
                title = '重置抽卡统计';
                message = '确定要重置抽卡统计吗？所有抽卡数据将归零！';
            }

            document.getElementById('cleanTitle').textContent = title;
            document.getElementById('cleanMessage').textContent = message;
            document.getElementById('cleanYes').onclick = performClean;
            document.getElementById('cleanModal').classList.add('active');
        }

        // 执行清理操作
        function performClean() {
            closeCleanModal();

            let action = '';
            if (currentCleanAction === 'memory') action = 'clear_memory_history';
            else if (currentCleanAction === 'gacha') action = 'clear_gacha_history';
            else if (currentCleanAction === 'stats') action = 'reset_gacha_stats';

            const formData = new FormData();
            formData.append(action, '1');
            formData.append('ajax', '1');

            fetch('', { method: 'POST', body: formData })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    fetchDataFromServer();
                    document.getElementById('modalIcon').textContent = '✅';
                    document.getElementById('modalTitle').textContent = '操作成功';
                    document.getElementById('modalMessage').textContent = data.message;
                    document.getElementById('modalReward').textContent = '';
                    document.getElementById('gachaModal').classList.add('active');
                }
            })
            .catch(error => {
                console.error('Error:', error);
            });
        }

        // 显示注销确认弹窗
        function showDeleteModal() {
            const confirmText = document.getElementById('deleteConfirm').value;

            if (confirmText !== 'DELETE') {
                document.getElementById('modalIcon').textContent = '❌';
                document.getElementById('modalTitle').textContent = '操作失败';
                document.getElementById('modalMessage').textContent = '请输入 DELETE 确认注销';
                document.getElementById('modalReward').textContent = '';
                document.getElementById('gachaModal').classList.add('active');
                return;
            }

            document.getElementById('deleteMessage').textContent = '确定要永久注销账号吗？所有数据将无法恢复！';
            document.getElementById('deleteYes').onclick = performDelete;
            document.getElementById('deleteModal').classList.add('active');
        }

        // 执行注销
        function performDelete() {
            closeDeleteModal();

            const formData = new FormData();
            formData.append('delete_my_account', '1');
            formData.append('confirm_delete', 'DELETE');
            formData.append('ajax', '1');

            fetch('', { method: 'POST', body: formData })
            .then(response => response.json())
            .then(data => {
                if (data.redirect) {
                    window.location.reload();
                }
            })
            .catch(error => {
                console.error('Error:', error);
            });
        }

        // 关闭弹窗
        function closeModal() { document.getElementById('gachaModal').classList.remove('active'); }
        function closeConfirmModal() { document.getElementById('confirmModal').classList.remove('active'); }
        function closeCleanModal() { document.getElementById('cleanModal').classList.remove('active'); }
        function closeDeleteModal() { document.getElementById('deleteModal').classList.remove('active'); }

        // 点击弹窗外部关闭
        window.onclick = function(event) {
            ['gachaModal', 'confirmModal', 'cleanModal', 'deleteModal'].forEach(id => {
                const modal = document.getElementById(id);
                if (event.target == modal) {
                    modal.classList.remove('active');
                }
            });
        };

        // 页面加载完成后执行
        document.addEventListener('DOMContentLoaded', function() {
            // 刷题助手
            document.getElementById('generateQuestions').onclick = () => {
                const min = parseInt(document.getElementById('minQuestions').value) || 1;
                const max = parseInt(document.getElementById('maxQuestions').value) || 10;
                const multiplier = parseFloat(document.getElementById('multiplier').value) || 1;

                const lo = Math.min(min, max);
                const hi = Math.max(min, max);
                const questionCount = Math.floor(Math.random() * (hi - lo + 1)) + lo;
                const memoryGain = (questionCount * parseFloat(memorySettings.questionMemoryGain || 0)).toFixed(1);
                document.getElementById('generatedQuestions').innerHTML =
                    `📝 建议刷 <strong>${questionCount}</strong> 题 (记忆 +${memoryGain}%)`;

                const addedRestFlux = questionCount * multiplier;
                addRestFlux(addedRestFlux);
            };

            // 背题功能
            document.getElementById('startMemorization').onclick = () => {
                updateMode('memorize', currentRestMode);
                currentStudyMode = 'memorize';
                updateStatusBadges();
            };

            document.getElementById('endMemorization').onclick = () => {
                updateMode('none', currentRestMode);
                currentStudyMode = 'none';
                updateStatusBadges();
            };

            // 单纯学习功能
            document.getElementById('startStudy').onclick = () => {
                updateMode('pure', currentRestMode);
                currentStudyMode = 'pure';
                updateStatusBadges();
            };

            document.getElementById('endStudy').onclick = () => {
                updateMode('none', currentRestMode);
                currentStudyMode = 'none';
                updateStatusBadges();
            };

            // 开始休息功能
            document.getElementById('startRest').onclick = () => {
                updateMode(currentStudyMode, 'true');
                currentRestMode = true;
                updateStatusBadges();
            };

            // 结束休息功能
            document.getElementById('endRest').onclick = () => {
                updateMode(currentStudyMode, 'false');
                currentRestMode = false;
                updateStatusBadges();
            };

            // 定期从服务器获取数据（每秒）
            setInterval(fetchDataFromServer, 1000);

            // 初始加载数据
            fetchDataFromServer();
        });
    </script>
    <?php endif; ?>

    <script>
        // 切换登录/注册标签
        function switchTab(tab) {
            const tabs = document.querySelectorAll('.auth-tab');
            const forms = document.querySelectorAll('.auth-form');

            tabs.forEach(t => t.classList.remove('active'));
            forms.forEach(f => f.classList.remove('active'));

            if (tab === 'login') {
                tabs[0].classList.add('active');
                document.getElementById('login-form').classList.add('active');
            } else {
                tabs[1].classList.add('active');
                document.getElementById('register-form').classList.add('active');
            }
        }

        // 切换个人管理标签
        function switchSelfTab(tab) {
            const tabs = document.querySelectorAll('.self-admin-tab');
            const contents = document.querySelectorAll('.self-admin-content');

            tabs.forEach(t => t.classList.remove('active'));
            contents.forEach(c => c.classList.remove('active'));

            const tabMap = {
                'memory': [0, 'self-memory'],
                'flux': [1, 'self-flux'],
                'data': [2, 'self-data'],
                'clean': [3, 'self-clean'],
                'danger': [4, 'self-danger']
            };
            const target = tabMap[tab] || tabMap['memory'];
            tabs[target[0]].classList.add('active');
            document.getElementById(target[1]).classList.add('active');
        }
    </script>
</body>
</html>
