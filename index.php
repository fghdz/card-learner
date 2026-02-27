<?php

/**
 * =====================================================
 * 趣味学习助手 - 抽卡大乐透 (Study Gacha)
 * =====================================================
 *
 * @author      fghdz
 * @version     1.0.0
 * @date        2026.02.27
 * @license     MIT
 *
 * =====================================================
 * 开发致谢
 * =====================================================
 *
 * 本项目的开发得到了以下工具和资源的支持：
 * 
 * 1. AI 辅助开发：
 *    - 使用了 DeepSeek AI 助手进行代码生成、优化建议
 *      和问题排查 (https://www.deepseek.com/)
 *    - AI 辅助范围：架构设计、代码优化、文档编写
 *
 * 2. 技术基础：
 *    - 基于 PHP 7.4+ 原生开发
 *    - 前端使用原生 HTML/CSS/JavaScript
 *    - 数据存储采用 JSON 文件系统
 *
 * 3. 理论支持：
 *    - 艾宾浩斯遗忘曲线理论
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

// 定义数据存储目录
define('DATA_DIR', __DIR__ . '/data/');
define('USERS_FILE', DATA_DIR . 'users.php');
define('USER_DATA_DIR', DATA_DIR . 'user_data/');
define('ADMIN_FILE', 'admin_config.php');

// 创建数据目录
if (!file_exists(DATA_DIR)) {
    mkdir(DATA_DIR, 0777, true);
}
if (!file_exists(USER_DATA_DIR)) {
    mkdir(USER_DATA_DIR, 0777, true);
}

// 初始化用户数据文件
if (!file_exists(USERS_FILE)) {
    file_put_contents(USERS_FILE, "<?php exit; ?>\n" . json_encode([]));
}

// 安全的读取用户数据
function readUsers() {
    $content = file_get_contents(USERS_FILE);
    $content = preg_replace('/^<\?php exit; \?>\n/', '', $content);
    return json_decode($content, true) ?: [];
}

// 安全的写入用户数据
function writeUsers($data) {
    $content = "<?php exit; ?>\n" . json_encode($data, JSON_PRETTY_PRINT);
    file_put_contents(USERS_FILE, $content);
}

// 安全的读取用户数据文件
function readUserData($username) {
    $file = USER_DATA_DIR . $username . '.php';
    if (!file_exists($file)) {
        return null;
    }
    $content = file_get_contents($file);
    $content = preg_replace('/^<\?php exit; \?>\n/', '', $content);
    return json_decode($content, true);
}

// 安全的写入用户数据文件
function writeUserData($username, $data) {
    $file = USER_DATA_DIR . $username . '.php';
    $content = "<?php exit; ?>\n" . json_encode($data, JSON_PRETTY_PRINT);
    file_put_contents($file, $content);
}

// 读取抽卡配置
function getGachaConfig() {
    if (!file_exists(ADMIN_FILE)) {
        return [
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
        ];
    }
    $config = include(ADMIN_FILE);
    return $config['gacha'];
}

// 艾宾浩斯遗忘曲线计算函数
function calculateForgettingCurve($hours, $initialMemory = 100, $forgetRate = 0.56) {
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
    $pool = $gachaConfig['pools'][$poolType];
    $special = $gachaConfig['special_events'];
    
    // 首先根据卡池概率决定是否中奖
    $drawRand = mt_rand(1, 100);
    
    // 如果没有中奖（概率之外）
    if ($drawRand > $pool['probability']) {
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
    
    // 中奖了，计算奖励
    $baseReward = rand($pool['min_reward'], $pool['max_reward']);
    $finalReward = $baseReward;
    $message = "获得 {$baseReward} 通量";
    $specialEvent = '';
    
    // 特殊事件 - 只有中奖后才触发
    if ($special['enabled']) {
        // 使用更精确的随机数生成
        $rand = mt_rand(1, 10000) / 100; // 生成0.01到100.00之间的数
        
        // 双倍奖励（精确到小数点后两位）
        if ($rand <= $special['double_probability']) {
            $finalReward = $baseReward * 2;
            $specialEvent = '✨ 双倍奖励！';
            $message = "✨ 双倍奖励！获得 {$finalReward} 通量";
        }
        
        // 大奖（真正的1%概率，使用更精确的判定）
        if ($rand <= 1.00 && mt_rand(1, 100) == 1) { // 双重随机，更难抽到
            $finalReward = $baseReward * $special['jackpot_multiplier'];
            $specialEvent = '🎰 超级大奖！';
            $message = "🎰 超级大奖！获得 {$finalReward} 通量";
        }
    }
    
    // 保底（只有中奖后才应用保底）
    if ($finalReward < $special['consolation_prize']) {
        $finalReward = $special['consolation_prize'];
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

// 处理用户请求
$message = '';
$messageType = '';

// 注册处理
if (isset($_POST['register'])) {
    $username = trim($_POST['username']);
    $password = $_POST['password'];
    $confirmPassword = $_POST['confirm_password'];
    
    if (empty($username) || empty($password)) {
        $message = '用户名和密码不能为空';
        $messageType = 'error';
    } elseif ($password !== $confirmPassword) {
        $message = '两次输入的密码不一致';
        $messageType = 'error';
    } elseif (strlen($password) < 6) {
        $message = '密码长度至少6位';
        $messageType = 'error';
    } else {
        $users = readUsers();
        
        if (isset($users[$username])) {
            $message = '用户名已存在';
            $messageType = 'error';
        } else {
            $users[$username] = [
                'password' => password_hash($password, PASSWORD_DEFAULT),
                'created_at' => date('Y-m-d H:i:s')
            ];
            writeUsers($users);
            
            // 创建用户数据文件
            $userData = [
                'studyTime' => 0,
                'restFlux' => 100, // 初始给100通量用于抽卡
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
                ]
            ];
            writeUserData($username, $userData);
            
            $message = '注册成功，赠送100通量用于抽卡！';
            $messageType = 'success';
        }
    }
}

// 登录处理
if (isset($_POST['login'])) {
    $username = trim($_POST['username']);
    $password = $_POST['password'];
    
    if (empty($username) || empty($password)) {
        $message = '请输入用户名和密码';
        $messageType = 'error';
    } else {
        $users = readUsers();
        
        if (isset($users[$username]) && password_verify($password, $users[$username]['password'])) {
            $_SESSION['username'] = $username;
            
            // 加载并更新用户数据
            $userData = readUserData($username);
            if ($userData) {
                // 确保所有必要的键都存在
                $defaultSettings = [
                    'forgetRate' => 0.56,
                    'questionMemoryGain' => 0.5,
                    'pureStudyMemoryGain' => 0.008,
                    'memorizeMemoryGain' => 0.012
                ];
                
                if (!isset($userData['memorySettings'])) {
                    $userData['memorySettings'] = $defaultSettings;
                } else {
                    $userData['memorySettings'] = array_merge($defaultSettings, $userData['memorySettings']);
                }
                
                if (!isset($userData['gachaStats'])) {
                    $userData['gachaStats'] = [
                        'totalPulls' => 0,
                        'totalSpent' => 0,
                        'totalWon' => 0,
                        'lastPull' => null,
                        'commonPulls' => 0,
                        'rarePulls' => 0,
                        'epicPulls' => 0,
                        'legendaryPulls' => 0,
                        'mythicPulls' => 0
                    ];
                }
                
                if (!isset($userData['gachaHistory'])) {
                    $userData['gachaHistory'] = [];
                }
                
                $defaultData = [
                    'studyTime' => 0,
                    'restFlux' => 100,
                    'memoryValue' => 100,
                    'lastMemoryUpdate' => time(),
                    'totalStudySessions' => 0,
                    'totalQuestions' => 0,
                    'lastActive' => time(),
                    'studyMode' => 'none',
                    'restMode' => false,
                    'restIncreaseRate' => 0.2,
                    'restDecreaseRate' => 1.0,
                    'memoryHistory' => []
                ];
                $userData = array_merge($defaultData, $userData);
                
                // 计算离线期间的时间
                $currentTime = time();
                $lastActive = $userData['lastActive'] ?? $currentTime;
                $lastMemoryUpdate = $userData['lastMemoryUpdate'] ?? $currentTime;
                $timeDiff = $currentTime - $lastActive;
                $memoryTimeDiff = $currentTime - $lastMemoryUpdate;
                $hoursDiff = $memoryTimeDiff / 3600;
                
                // 根据离线前的模式更新数据
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
}

// 抽卡处理
if (isset($_POST['draw_card']) && isset($_SESSION['username'])) {
    $poolType = $_POST['pool_type'] ?? 'common';
    $gachaConfig = getGachaConfig();
    $pool = $gachaConfig['pools'][$poolType];
    $cost = $pool['cost'];
    
    $username = $_SESSION['username'];
    $userData = readUserData($username);
    
    if ($userData['restFlux'] >= $cost) {
        // 扣除通量
        $userData['restFlux'] -= $cost;
        
        // 抽卡
        $result = drawCard($poolType, $gachaConfig);
        
        // 只有中奖才应用奖励
        if ($result['isWin']) {
            $userData['restFlux'] += $result['reward'];
        }
        
        // 更新抽卡统计
        $userData['gachaStats']['totalPulls']++;
        $userData['gachaStats']['totalSpent'] += $cost;
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
        
        // 返回JSON响应用于自定义弹窗
        if (isset($_POST['ajax'])) {
            header('Content-Type: application/json');
            echo json_encode([
                'success' => true,
                'message' => $result['isWin'] ? "🎉 抽卡成功！{$result['special']} {$result['message']}" : "😢 {$result['message']}",
                'reward' => $result['isWin'] ? $result['reward'] : 0,
                'pool' => $pool['name'],
                'special' => $result['special'],
                'isWin' => $result['isWin']
            ]);
            exit;
        } else {
            $message = $result['isWin'] ? "🎉 抽卡成功！{$result['special']} {$result['message']}" : "😢 {$result['message']}";
            $messageType = $result['isWin'] ? 'success' : 'error';
        }
    } else {
        if (isset($_POST['ajax'])) {
            header('Content-Type: application/json');
            echo json_encode([
                'success' => false,
                'message' => "❌ 通量不足，需要 {$cost} 通量！"
            ]);
            exit;
        } else {
            $message = "❌ 通量不足，需要 {$cost} 通量！";
            $messageType = 'error';
        }
    }
}

// ========== 修复：用户修改自己的设置 ==========
if (isset($_POST['update_my_settings']) && isset($_SESSION['username'])) {
    $username = $_SESSION['username'];
    
    // 读取现有用户数据
    $userData = readUserData($username);
    if ($userData) {
        // 只更新提交的字段，保留其他所有现有数据
        // 记忆参数标签
        if (isset($_POST['forget_rate'])) {
            $userData['memorySettings']['forgetRate'] = floatval($_POST['forget_rate']);
        }
        if (isset($_POST['question_memory_gain'])) {
            $userData['memorySettings']['questionMemoryGain'] = floatval($_POST['question_memory_gain']);
        }
        if (isset($_POST['pure_study_memory_gain'])) {
            $userData['memorySettings']['pureStudyMemoryGain'] = floatval($_POST['pure_study_memory_gain']);
        }
        if (isset($_POST['memorize_memory_gain'])) {
            $userData['memorySettings']['memorizeMemoryGain'] = floatval($_POST['memorize_memory_gain']);
        }
        
        // 通量设置标签
        if (isset($_POST['rest_increase_rate'])) {
            $userData['restIncreaseRate'] = floatval($_POST['rest_increase_rate']);
        }
        if (isset($_POST['rest_decrease_rate'])) {
            $userData['restDecreaseRate'] = floatval($_POST['rest_decrease_rate']);
        }
        
        // 数据管理标签 - 只有当这些字段被提交时才更新
        if (isset($_POST['study_time'])) {
            $userData['studyTime'] = floatval($_POST['study_time']);
        }
        if (isset($_POST['rest_flux'])) {
            $userData['restFlux'] = floatval($_POST['rest_flux']);
        }
        if (isset($_POST['memory_value'])) {
            $userData['memoryValue'] = min(100, max(0, floatval($_POST['memory_value'])));
        }
        
        // 更新时间戳
        $userData['lastActive'] = time();
        $userData['lastMemoryUpdate'] = time();
        
        // 保存数据
        writeUserData($username, $userData);
        
        // 更新SESSION
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

// 清空记忆曲线历史
if (isset($_POST['clear_memory_history']) && isset($_SESSION['username'])) {
    $username = $_SESSION['username'];
    $userData = readUserData($username);
    if ($userData) {
        $userData['memoryHistory'] = [];
        writeUserData($username, $userData);
        
        if (isset($_POST['ajax'])) {
            header('Content-Type: application/json');
            echo json_encode(['success' => true, 'message' => '记忆曲线历史已清空']);
            exit;
        } else {
            $message = '记忆曲线历史已清空';
            $messageType = 'success';
        }
    }
}

// 清空抽卡记录
if (isset($_POST['clear_gacha_history']) && isset($_SESSION['username'])) {
    $username = $_SESSION['username'];
    $userData = readUserData($username);
    if ($userData) {
        $userData['gachaHistory'] = [];
        writeUserData($username, $userData);
        
        if (isset($_POST['ajax'])) {
            header('Content-Type: application/json');
            echo json_encode(['success' => true, 'message' => '抽卡记录已清空']);
            exit;
        } else {
            $message = '抽卡记录已清空';
            $messageType = 'success';
        }
    }
}

// 重置抽卡统计
if (isset($_POST['reset_gacha_stats']) && isset($_SESSION['username'])) {
    $username = $_SESSION['username'];
    $userData = readUserData($username);
    if ($userData) {
        $userData['gachaStats'] = [
            'totalPulls' => 0,
            'totalSpent' => 0,
            'totalWon' => 0,
            'lastPull' => null,
            'commonPulls' => 0,
            'rarePulls' => 0,
            'epicPulls' => 0,
            'legendaryPulls' => 0,
            'mythicPulls' => 0
        ];
        writeUserData($username, $userData);
        $_SESSION['gachaStats'] = $userData['gachaStats'];
        
        if (isset($_POST['ajax'])) {
            header('Content-Type: application/json');
            echo json_encode(['success' => true, 'message' => '抽卡统计已重置']);
            exit;
        } else {
            $message = '抽卡统计已重置';
            $messageType = 'success';
        }
    }
}

// 用户注销账号
if (isset($_POST['delete_my_account']) && isset($_SESSION['username'])) {
    $username = $_SESSION['username'];
    $confirm = $_POST['confirm_delete'] ?? '';
    
    if ($confirm === 'DELETE') {
        $users = readUsers();
        
        $userDataFile = USER_DATA_DIR . $username . '.php';
        if (file_exists($userDataFile)) {
            unlink($userDataFile);
        }
        
        unset($users[$username]);
        writeUsers($users);
        
        session_destroy();
        
        if (isset($_POST['ajax'])) {
            header('Content-Type: application/json');
            echo json_encode(['success' => true, 'redirect' => true]);
            exit;
        } else {
            header('Location: ' . $_SERVER['PHP_SELF']);
            exit;
        }
    } else {
        if (isset($_POST['ajax'])) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => '请输入 DELETE 确认注销']);
            exit;
        } else {
            $message = '请输入 DELETE 确认注销';
            $messageType = 'error';
        }
    }
}

// 登出处理
if (isset($_GET['logout'])) {
    if (isset($_SESSION['username'])) {
        $userData = readUserData($_SESSION['username']);
        if ($userData) {
            $currentTime = time();
            $lastActive = $userData['lastActive'] ?? $currentTime;
            $lastMemoryUpdate = $userData['lastMemoryUpdate'] ?? $currentTime;
            $timeDiff = $currentTime - $lastActive;
            $memoryTimeDiff = $currentTime - $lastMemoryUpdate;
            $hoursDiff = $memoryTimeDiff / 3600;
            
            $settings = $userData['memorySettings'] ?? [
                'forgetRate' => 0.56,
                'questionMemoryGain' => 0.5,
                'pureStudyMemoryGain' => 0.008,
                'memorizeMemoryGain' => 0.012
            ];
            
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
            
            writeUserData($_SESSION['username'], $userData);
        }
    }
    session_destroy();
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

// 处理AJAX请求
if (isset($_POST['action']) && isset($_SESSION['username'])) {
    header('Content-Type: application/json');
    $username = $_SESSION['username'];
    $userData = readUserData($username);
    
    if (!$userData) {
        echo json_encode(['error' => 'User data not found']);
        exit;
    }
    
    $defaultSettings = [
        'forgetRate' => 0.56,
        'questionMemoryGain' => 0.5,
        'pureStudyMemoryGain' => 0.008,
        'memorizeMemoryGain' => 0.012
    ];
    
    if (!isset($userData['memorySettings'])) {
        $userData['memorySettings'] = $defaultSettings;
    } else {
        $userData['memorySettings'] = array_merge($defaultSettings, $userData['memorySettings']);
    }
    
    $defaultData = [
        'studyTime' => 0,
        'restFlux' => 100,
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
        'gachaHistory' => []
    ];
    $userData = array_merge($defaultData, $userData);
    
    $currentTime = time();
    $lastActive = $userData['lastActive'] ?? $currentTime;
    $lastMemoryUpdate = $userData['lastMemoryUpdate'] ?? $currentTime;
    $timeDiff = $currentTime - $lastActive;
    $memoryTimeDiff = $currentTime - $lastMemoryUpdate;
    $hoursDiff = $memoryTimeDiff / 3600;
    
    $settings = $userData['memorySettings'];
    
    switch ($_POST['action']) {
        case 'updateMode':
            $studyMode = $_POST['studyMode'] ?? 'none';
            $restMode = $_POST['restMode'] ?? 'false';
            $restMode = ($restMode === 'true');
            
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
            $amount = floatval($_POST['amount'] ?? 0);
            
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
            $userData['totalQuestions'] += $amount;
            
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
                'studyTime' => $studyTime,
                'restFlux' => $restFlux,
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

// 设置默认的SESSION值
if (!isset($_SESSION['studyTime'])) $_SESSION['studyTime'] = 0;
if (!isset($_SESSION['restFlux'])) $_SESSION['restFlux'] = 100;
if (!isset($_SESSION['memoryValue'])) $_SESSION['memoryValue'] = 100;
if (!isset($_SESSION['restIncreaseRate'])) $_SESSION['restIncreaseRate'] = 0.2;
if (!isset($_SESSION['restDecreaseRate'])) $_SESSION['restDecreaseRate'] = 1.0;
if (!isset($_SESSION['memorySettings'])) {
    $_SESSION['memorySettings'] = [
        'forgetRate' => 0.56,
        'questionMemoryGain' => 0.5,
        'pureStudyMemoryGain' => 0.008,
        'memorizeMemoryGain' => 0.012
    ];
}
if (!isset($_SESSION['gachaStats'])) {
    $_SESSION['gachaStats'] = [
        'totalPulls' => 0,
        'totalSpent' => 0,
        'totalWon' => 0,
        'lastPull' => null,
        'commonPulls' => 0,
        'rarePulls' => 0,
        'epicPulls' => 0,
        'legendaryPulls' => 0,
        'mythicPulls' => 0
    ];
}
?>
<!DOCTYPE html>
<html lang="zh">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>趣味学习助手</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
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
            background: rgba(255, 255, 255, 0.95);
            border-radius: 20px;
            padding: 30px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            margin-bottom: 20px;
            animation: slideDown 0.5s ease;
        }

        @keyframes slideDown {
            from {
                opacity: 0;
                transform: translateY(-20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .auth-tabs {
            display: flex;
            margin-bottom: 20px;
            border-bottom: 2px solid #e0e0e0;
        }

        .auth-tab {
            flex: 1;
            padding: 10px;
            text-align: center;
            cursor: pointer;
            font-weight: 600;
            color: #666;
            transition: all 0.3s;
        }

        .auth-tab.active {
            color: #667eea;
            border-bottom: 2px solid #667eea;
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
        }

        .btn-primary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 20px rgba(102, 126, 234, 0.3);
        }

        .btn-success {
            background: linear-gradient(135deg, #48bb78 0%, #38a169 100%);
            color: white;
        }

        .btn-danger {
            background: linear-gradient(135deg, #f56565 0%, #e53e3e 100%);
            color: white;
        }

        .btn-warning {
            background: linear-gradient(135deg, #ed8936 0%, #dd6b20 100%);
            color: white;
        }

        .btn-info {
            background: linear-gradient(135deg, #4299e1 0%, #3182ce 100%);
            color: white;
        }

        .btn-purple {
            background: linear-gradient(135deg, #9f7aea 0%, #805ad5 100%);
            color: white;
        }

        .btn-gacha {
            background: linear-gradient(135deg, #fbbf24 0%, #f59e0b 100%);
            color: white;
            font-size: 18px;
            padding: 15px;
            animation: pulse 2s infinite;
        }

        .btn-gacha:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 20px rgba(245, 158, 11, 0.3);
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
            background: rgba(255, 255, 255, 0.95);
            border-radius: 20px;
            padding: 30px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            animation: slideUp 0.5s ease;
        }

        @keyframes slideUp {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        h1 {
            text-align: center;
            color: #333;
            margin-bottom: 30px;
            font-size: 2.5em;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
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

        .stat-value.success {
            color: #48bb78;
        }

        .stat-value.danger {
            color: #f56565;
        }

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

        /* 记忆值提示 */
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
            padding: 5px 10px;
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

        .stat-item .value.success {
            color: #48bb78;
        }

        .stat-item .value.danger {
            color: #f56565;
        }

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
        .modal {
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

        .modal.active {
            display: flex;
            animation: fadeIn 0.3s ease;
        }

        .modal-content {
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

        /* 确认弹窗 */
        .confirm-modal {
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

        .confirm-modal.active {
            display: flex;
            animation: fadeIn 0.3s ease;
        }

        .confirm-content {
            background: white;
            border-radius: 20px;
            padding: 30px;
            max-width: 400px;
            width: 90%;
            text-align: center;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
        }

        .confirm-title {
            font-size: 20px;
            font-weight: bold;
            margin-bottom: 20px;
            color: #2d3748;
        }

        .confirm-buttons {
            display: flex;
            gap: 10px;
            justify-content: center;
        }

        .confirm-btn {
            padding: 10px 20px;
            border: none;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
        }

        .confirm-btn.yes {
            background: #48bb78;
            color: white;
        }

        .confirm-btn.no {
            background: #f56565;
            color: white;
        }

        .confirm-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 10px rgba(0, 0, 0, 0.1);
        }

        /* 清理数据确认弹窗 */
        .clean-modal {
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

        .clean-modal.active {
            display: flex;
            animation: fadeIn 0.3s ease;
        }

        .clean-content {
            background: white;
            border-radius: 20px;
            padding: 30px;
            max-width: 400px;
            width: 90%;
            text-align: center;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
        }

        .clean-title {
            font-size: 20px;
            font-weight: bold;
            margin-bottom: 15px;
            color: #ed8936;
        }

        .clean-message {
            font-size: 16px;
            margin-bottom: 20px;
            color: #4a5568;
        }

        .clean-buttons {
            display: flex;
            gap: 10px;
            justify-content: center;
        }

        .clean-btn {
            padding: 10px 20px;
            border: none;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
        }

        .clean-btn.yes {
            background: #ed8936;
            color: white;
        }

        .clean-btn.no {
            background: #a0aec0;
            color: white;
        }

        .clean-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 10px rgba(0, 0, 0, 0.1);
        }

        @media (max-width: 768px) {
            .stats-container {
                grid-template-columns: 1fr;
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
                        <?php echo htmlspecialchars($message); ?>
                    </div>
                <?php endif; ?>
                
                <!-- 登录表单 -->
                <div id="login-form" class="auth-form active">
                    <form method="POST" action="">
                        <div class="form-group">
                            <label>用户名</label>
                            <input type="text" name="username" required>
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
                            <input type="text" name="username" required>
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
            $gachaStats = $_SESSION['gachaStats'] ?? [
                'totalPulls' => 0,
                'totalSpent' => 0,
                'totalWon' => 0,
                'commonPulls' => 0,
                'rarePulls' => 0,
                'epicPulls' => 0,
                'legendaryPulls' => 0,
                'mythicPulls' => 0
            ];
            $netGain = ($gachaStats['totalWon'] ?? 0) - ($gachaStats['totalSpent'] ?? 0);
            $userData = readUserData($_SESSION['username']);
            $gachaHistory = isset($userData['gachaHistory']) && is_array($userData['gachaHistory']) ? $userData['gachaHistory'] : [];
        ?>
            <!-- 主界面 -->
            <div class="main-card">
                <?php if ($message): ?>
                    <div class="message <?php echo $messageType; ?>">
                        <?php echo htmlspecialchars($message); ?>
                    </div>
                <?php endif; ?>
                
                <div class="user-info">
                    <span>
                        欢迎回来，<?php echo htmlspecialchars($_SESSION['username']); ?>
                        <span class="self-admin-badge">个人管理员</span>
                    </span>
                    <div>
                        <a href="admin.php" target="_blank" class="admin-link">管理员后台</a>
                        <a href="?logout=1" class="logout-btn">退出登录</a>
                    </div>
                </div>
                
                <h1>趣味学习助手 - 抽卡大乐透</h1>
                
                <div class="stats-container">
                    <div class="stat-card">
                        <div class="stat-label">学习时长</div>
                        <div class="stat-value" id="studyTime">00:00:00</div>
                        <div class="progress-container">
                            <div class="progress-bar">
                                <div class="progress-fill" id="studyProgress" style="width: 0%"></div>
                            </div>
                            <div class="progress-text">
                                <span>今日目标: 24:00:00</span>
                                <span id="studyProgressText">0%</span>
                            </div>
                        </div>
                    </div>
                    
                    <div class="stat-card">
                        <div class="stat-label">休息时间通量</div>
                        <div class="stat-value" id="restFlux">0</div>
                        <span class="stat-unit" id="restFluxUnit">s</span>
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
                        <div class="stat-value"><?php echo $gachaStats['totalPulls']; ?></div>
                        <span class="stat-unit">抽</span>
                        <div class="progress-text">
                            <span>花费: <?php echo $gachaStats['totalSpent']; ?></span>
                            <span>获得: <?php echo $gachaStats['totalWon']; ?></span>
                        </div>
                        <div class="progress-text">
                            <span>净收益: </span>
                            <span class="stat-value <?php echo $netGain >= 0 ? 'success' : 'danger'; ?>" style="font-size: 16px;">
                                <?php echo $netGain; ?>
                            </span>
                        </div>
                    </div>
                </div>

                <!-- 抽卡大乐透区域 -->
                <div class="section">
                    <h3>🎰 抽卡大乐透 - 消耗通量赢取更多通量！</h3>
                    
                    <div class="gacha-container">
                        <?php foreach ($gachaConfig['pools'] as $key => $pool): 
                            $icons = ['common' => '🎴', 'rare' => '✨', 'epic' => '🌟', 'legendary' => '💫', 'mythic' => '👑'];
                        ?>
                        <div class="gacha-pool" style="border-color: <?php echo $pool['color']; ?>;" onclick="confirmDraw('<?php echo $key; ?>', '<?php echo $pool['name']; ?>', <?php echo $pool['cost']; ?>)">
                            <div class="pool-icon">
                                <?php echo $icons[$key] ?? '🎴'; ?>
                            </div>
                            <div class="pool-name"><?php echo htmlspecialchars($pool['name']); ?></div>
                            <div class="pool-cost"><?php echo $pool['cost']; ?> <small>通量</small></div>
                            <div class="pool-cards">奖励范围: <?php echo $pool['min_reward']; ?> - <?php echo $pool['max_reward']; ?> 通量</div>
                            <div class="pool-cards">中奖概率: <?php echo $pool['probability']; ?>%</div>
                            <button class="btn btn-primary" style="margin-top: 10px; pointer-events: none;">抽一张</button>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    
                    <!-- 抽卡统计 -->
                    <div class="gacha-stats">
                        <div class="stat-item">
                            <div class="label">总抽卡</div>
                            <div class="value"><?php echo $gachaStats['totalPulls'] ?? 0; ?></div>
                        </div>
                        <div class="stat-item">
                            <div class="label">总花费</div>
                            <div class="value"><?php echo $gachaStats['totalSpent'] ?? 0; ?></div>
                        </div>
                        <div class="stat-item">
                            <div class="label">总获得</div>
                            <div class="value"><?php echo $gachaStats['totalWon'] ?? 0; ?></div>
                        </div>
                        <div class="stat-item">
                            <div class="label">净收益</div>
                            <div class="value <?php echo $netGain >= 0 ? 'success' : 'danger'; ?>">
                                <?php echo $netGain; ?>
                            </div>
                        </div>
                    </div>
                    
                    <!-- 特殊事件说明 -->
                    <?php if ($gachaConfig['special_events']['enabled']): ?>
                    <div class="memory-tip" style="margin-top: 10px; text-align: center;">
                        ✨ 特殊事件：<?php echo $gachaConfig['special_events']['double_probability']; ?>%概率双倍奖励 | 1%概率<?php echo $gachaConfig['special_events']['jackpot_multiplier']; ?>倍大奖
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
                                foreach($gachaConfig['pools'] as $p) {
                                    if ($p['name'] == ($item['pool'] ?? '')) {
                                        $poolColor = $p['color'];
                                        break;
                                    }
                                }
                                $rewardClass = isset($item['isWin']) && $item['isWin'] ? 'reward' : 'no-reward';
                                $displayText = isset($item['isWin']) && $item['isWin'] ? '+' . ($item['reward'] ?? 0) : '😢 未中奖';
                            ?>
                            <div class="history-item" style="border-left-color: <?php echo $poolColor; ?>; <?php echo (!isset($item['isWin']) || !$item['isWin']) ? 'opacity: 0.7;' : ''; ?>">
                                <div class="pool-name"><?php echo htmlspecialchars($item['pool'] ?? '未知卡池'); ?></div>
                                <div class="<?php echo $rewardClass; ?>" style="<?php echo (!isset($item['isWin']) || !$item['isWin']) ? 'color: #a0aec0;' : ''; ?>">
                                    <?php echo $displayText; ?>
                                </div>
                                <div style="font-size: 10px; color: #718096;">
                                    <?php echo isset($item['time']) ? date('H:i', $item['time']) : ''; ?>
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
                    <div class="chart-bars" id="memoryChart"></div>
                    <div class="chart-labels">
                        <span>较早</span>
                        <span>最近</span>
                    </div>
                </div>

                <div class="section">
                    <h3>📚 刷题助手</h3>
                    <div class="input-group">
                        <label>最低刷题数</label>
                        <input type="number" id="minQuestions" value="1">
                    </div>
                    <div class="input-group">
                        <label>最高刷题数</label>
                        <input type="number" id="maxQuestions" value="10">
                    </div>
                    <div class="input-group">
                        <label>倍率</label>
                        <input type="number" id="multiplier" value="1" step="0.1">
                    </div>
                    <button id="generateQuestions" class="btn btn-primary">生成题数</button>
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
                        <button id="startStudy" class="btn btn-primary">单纯学习</button>
                        <button id="endStudy" class="btn btn-danger">结束学习</button>
                        <button id="startMemorization" class="btn btn-success">开始背题</button>
                        <button id="endMemorization" class="btn btn-danger">结束背题</button>
                    </div>
                    <span id="studyStatus" class="status-badge"></span>
                    <div class="memory-tip" style="margin-top: 10px;" id="studyMemoryTip">
                        💡 单纯学习：每秒+0.8%记忆 | 背题模式：每秒+1.2%记忆
                    </div>
                </div>

                <div class="section">
                    <h3>😴 休息模式</h3>
                    <div class="button-grid">
                        <button id="startRest" class="btn btn-warning">开始休息</button>
                        <button id="endRest" class="btn btn-danger">结束休息</button>
                    </div>
                    <span id="restStatus" class="status-badge"></span>
                    <div class="memory-tip" style="margin-top: 10px;">
                        💡 休息时记忆值会按遗忘曲线自然衰减
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
                                <input type="number" name="forget_rate" value="<?php echo $_SESSION['memorySettings']['forgetRate']; ?>" step="0.01" min="0" max="2" required>
                                <small>数值越大遗忘越快 (默认0.56，基于艾宾浩斯曲线)</small>
                            </div>
                            <div class="input-group">
                                <label>刷题记忆增益 (%/题)</label>
                                <input type="number" name="question_memory_gain" value="<?php echo $_SESSION['memorySettings']['questionMemoryGain']; ?>" step="0.1" min="0" max="10" required>
                                <small>每刷一题增加的记忆百分比</small>
                            </div>
                            <div class="input-group">
                                <label>单纯学习增益 (%/秒)</label>
                                <input type="number" name="pure_study_memory_gain" value="<?php echo $_SESSION['memorySettings']['pureStudyMemoryGain']; ?>" step="0.001" min="0" max="1" required>
                                <small>单纯学习时每秒增加的记忆百分比</small>
                            </div>
                            <div class="input-group">
                                <label>背题模式增益 (%/秒)</label>
                                <input type="number" name="memorize_memory_gain" value="<?php echo $_SESSION['memorySettings']['memorizeMemoryGain']; ?>" step="0.001" min="0" max="1" required>
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
                                <label>每秒增加通量 (秒)</label>
                                <input type="number" name="rest_increase_rate" value="<?php echo $_SESSION['restIncreaseRate']; ?>" step="0.1" min="0" required>
                                <small>背题时每秒增加的通量</small>
                            </div>
                            <div class="input-group">
                                <label>每秒消耗通量 (秒)</label>
                                <input type="number" name="rest_decrease_rate" value="<?php echo $_SESSION['restDecreaseRate']; ?>" step="0.1" min="0" required>
                                <small>休息时每秒消耗的通量</small>
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
                                <input type="number" name="study_time" value="<?php echo $_SESSION['studyTime']; ?>" step="1" min="0">
                            </div>
                            <div class="input-group">
                                <label>休息时间通量 (秒)</label>
                                <input type="number" name="rest_flux" value="<?php echo $_SESSION['restFlux']; ?>" step="1" min="0">
                            </div>
                            <div class="input-group">
                                <label>记忆程度 (%)</label>
                                <input type="number" name="memory_value" value="<?php echo $_SESSION['memoryValue']; ?>" step="0.1" min="0" max="100">
                            </div>
                            <button type="submit" name="update_my_settings" class="btn btn-purple">更新数据</button>
                        </form>
                    </div>
                    
                    <!-- 清理数据标签 -->
                    <div id="self-clean" class="self-admin-content">
                        <h4>🧹 清理数据</h4>
                        <div style="display: flex; flex-direction: column; gap: 10px;">
                            <button onclick="showCleanModal('memory')" class="btn btn-warning btn-sm">清空记忆曲线历史</button>
                            <button onclick="showCleanModal('gacha')" class="btn btn-warning btn-sm">清空抽卡记录</button>
                            <button onclick="showCleanModal('stats')" class="btn btn-warning btn-sm">重置抽卡统计</button>
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
                            <button onclick="showDeleteModal()" class="btn btn-danger">永久注销账号</button>
                        </div>
                    </div>
                </div>

                <div class="info-box">
                    <h4>📖 使用介绍</h4>
                    <p>• 🎰 抽卡大乐透：消耗通量抽取卡池，根据概率中奖！</p>
                    <p>• 普通卡池 (10通量)：1-5通量奖励，60%中奖概率</p>
                    <p>• 稀有卡池 (50通量)：6-20通量奖励，25%中奖概率</p>
                    <p>• 史诗卡池 (200通量)：21-50通量奖励，10%中奖概率</p>
                    <p>• 传说卡池 (500通量)：51-200通量奖励，4%中奖概率</p>
                    <p>• 神话卡池 (1000通量)：201-500通量奖励，1%中奖概率</p>
                    <p>• ✨ 特殊事件：中奖后有机会获得双倍或10倍大奖</p>
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
        let studyTime = <?php echo $_SESSION['studyTime']; ?>;
        let restFlux = <?php echo $_SESSION['restFlux']; ?>;
        let memoryValue = <?php echo $_SESSION['memoryValue']; ?>;
        let currentStudyMode = 'none';
        let currentRestMode = false;
        let restIncreaseRate = <?php echo $_SESSION['restIncreaseRate']; ?>;
        let restDecreaseRate = <?php echo $_SESSION['restDecreaseRate']; ?>;
        let memorySettings = <?php echo json_encode($_SESSION['memorySettings']); ?>;
        let isUpdating = false;
        let memoryHistory = [];
        let currentPoolType = '';
        let currentPoolName = '';
        let currentPoolCost = 0;
        let currentCleanAction = '';

        // 格式化时间为 HH:MM:SS
        function formatTime(seconds) {
            const hours = Math.floor(seconds / 3600);
            const minutes = Math.floor((seconds % 3600) / 60);
            const secs = Math.floor(seconds % 60);
            
            return `${hours.toString().padStart(2, '0')}:${minutes.toString().padStart(2, '0')}:${secs.toString().padStart(2, '0')}`;
        }

        // 格式化通量（千进制）
        function formatFlux(seconds) {
            if (seconds >= 1e9) {
                return (seconds / 1e9).toFixed(2) + 'G';
            } else if (seconds >= 1e6) {
                return (seconds / 1e6).toFixed(2) + 'M';
            } else if (seconds >= 1e3) {
                return (seconds / 1e3).toFixed(2) + 'k';
            } else {
                return Math.floor(seconds).toString();
            }
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
            if (seconds < 1e3) return 1000;
            if (seconds < 1e6) return 1e6;
            if (seconds < 1e9) return 1e9;
            return 1e12;
        }

        // 计算通量进度百分比
        function getFluxProgress(seconds) {
            if (seconds < 1e3) {
                return (seconds / 1e3) * 100;
            } else if (seconds < 1e6) {
                return ((seconds - 1e3) / (1e6 - 1e3)) * 100;
            } else if (seconds < 1e9) {
                return ((seconds - 1e6) / (1e9 - 1e6)) * 100;
            } else {
                return 100;
            }
        }

        // 更新显示
        function updateDisplay() {
            document.getElementById('studyTime').textContent = formatTime(studyTime);
            document.getElementById('restFlux').textContent = formatFlux(restFlux);
            document.getElementById('memoryValue').textContent = memoryValue.toFixed(1) + '%';
            
            // 更新参数显示
            document.getElementById('forgetRate').textContent = memorySettings.forgetRate.toFixed(2);
            document.getElementById('questionGain').textContent = memorySettings.questionMemoryGain.toFixed(1);
            document.getElementById('pureStudyGain').textContent = (memorySettings.pureStudyMemoryGain * 100).toFixed(1);
            document.getElementById('memorizeGain').textContent = (memorySettings.memorizeMemoryGain * 100).toFixed(1);
            
            // 更新提示信息
            document.getElementById('questionMemoryTip').innerHTML = 
                `💡 每刷一题增加 ${memorySettings.questionMemoryGain}% 记忆值`;
            document.getElementById('studyMemoryTip').innerHTML = 
                `💡 单纯学习：每秒+${(memorySettings.pureStudyMemoryGain * 100).toFixed(1)}%记忆 | 背题模式：每秒+${(memorySettings.memorizeMemoryGain * 100).toFixed(1)}%记忆`;
            
            // 更新学习进度条
            const studyProgress = (studyTime % 86400) / 86400 * 100;
            document.getElementById('studyProgress').style.width = studyProgress + '%';
            document.getElementById('studyProgressText').textContent = studyProgress.toFixed(1) + '%';
            
            // 更新通量进度条
            const fluxProgress = getFluxProgress(restFlux);
            const nextLevel = getNextFluxLevel(restFlux);
            document.getElementById('fluxProgress').style.width = Math.min(fluxProgress, 100) + '%';
            document.getElementById('nextFluxLevel').textContent = formatFlux(nextLevel);
            document.getElementById('fluxProgressText').textContent = Math.min(fluxProgress, 100).toFixed(1) + '%';
            
            // 更新记忆进度条
            document.getElementById('memoryProgress').style.width = memoryValue + '%';
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
            if (!chart || !history || history.length === 0) return;
            
            let html = '';
            const recentHistory = history.slice(-20);
            
            recentHistory.forEach(item => {
                const height = item.value + '%';
                html += `<div class="chart-bar" style="height: ${height}" data-value="${item.value.toFixed(1)}"></div>`;
            });
            
            chart.innerHTML = html;
        }

        // 从服务器获取数据
        function fetchDataFromServer() {
            if (isUpdating) return;
            
            fetch('', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: 'action=getData'
            })
            .then(response => response.json())
            .then(data => {
                if (data.error) {
                    console.error('Server error:', data.error);
                    return;
                }
                
                studyTime = data.studyTime || 0;
                restFlux = data.restFlux || 0;
                memoryValue = data.memoryValue || 100;
                currentStudyMode = data.studyMode || 'none';
                currentRestMode = data.restMode || false;
                
                if (data.memorySettings) {
                    memorySettings = data.memorySettings;
                }
                
                if (data.memoryHistory) {
                    updateMemoryChart(data.memoryHistory);
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
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: 'action=updateMode&studyMode=' + studyMode + '&restMode=' + restMode
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    studyTime = data.studyTime;
                    restFlux = data.restFlux;
                    memoryValue = data.memoryValue;
                    updateDisplay();
                }
                isUpdating = false;
            })
            .catch(() => {
                isUpdating = false;
            });
        }

        // 添加通量
        function addRestFlux(amount) {
            isUpdating = true;
            fetch('', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: 'action=addRestFlux&amount=' + amount
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    studyTime = data.studyTime;
                    restFlux = data.restFlux;
                    memoryValue = data.memoryValue;
                    updateDisplay();
                }
                isUpdating = false;
            })
            .catch(() => {
                isUpdating = false;
            });
        }

        // 更新状态徽章
        function updateStatusBadges() {
            if (currentStudyMode == 'pure') {
                document.getElementById('studyStatus').textContent = `单纯学习中... (+${(memorySettings.pureStudyMemoryGain * 100).toFixed(1)}%/s)`;
                document.getElementById('studyStatus').className = 'status-badge active';
            } else if (currentStudyMode == 'memorize') {
                document.getElementById('studyStatus').textContent = `背题中... (+${(memorySettings.memorizeMemoryGain * 100).toFixed(1)}%/s)`;
                document.getElementById('studyStatus').className = 'status-badge active';
            } else {
                document.getElementById('studyStatus').textContent = '';
                document.getElementById('studyStatus').className = 'status-badge';
            }
            
            if (currentRestMode) {
                document.getElementById('restStatus').textContent = '休息中... (记忆衰减)';
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
            document.getElementById('confirmMessage').textContent = `确定要消耗 ${cost} 通量抽 ${poolName} 吗？\n中奖概率：${getPoolProbability(poolType)}%`;
            document.getElementById('confirmYes').onclick = performDraw;
            document.getElementById('confirmModal').classList.add('active');
        }

        // 获取卡池概率
        function getPoolProbability(poolType) {
            const probabilities = {
                'common': 60,
                'rare': 25,
                'epic': 10,
                'legendary': 4,
                'mythic': 1
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
            
            fetch('', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // 更新页面数据
                    fetchDataFromServer();
                    
                    // 显示抽卡结果
                    let icon = data.isWin ? '🎰' : '😢';
                    if (data.isWin) {
                        if (data.special.includes('双倍')) icon = '✨';
                        if (data.special.includes('超级大奖')) icon = '💫';
                    }
                    
                    document.getElementById('modalIcon').textContent = icon;
                    document.getElementById('modalTitle').textContent = data.isWin ? '抽卡成功！' : '很遗憾';
                    document.getElementById('modalMessage').textContent = data.message;
                    document.getElementById('modalReward').textContent = data.isWin ? `+${data.reward} 通量` : '0 通量';
                    document.getElementById('gachaModal').classList.add('active');
                } else {
                    // 显示错误
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
            if (currentCleanAction === 'memory') {
                action = 'clear_memory_history';
            } else if (currentCleanAction === 'gacha') {
                action = 'clear_gacha_history';
            } else if (currentCleanAction === 'stats') {
                action = 'reset_gacha_stats';
            }
            
            const formData = new FormData();
            formData.append(action, '1');
            formData.append('ajax', '1');
            
            fetch('', {
                method: 'POST',
                body: formData
            })
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
            
            fetch('', {
                method: 'POST',
                body: formData
            })
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
        function closeModal() {
            document.getElementById('gachaModal').classList.remove('active');
        }

        // 关闭确认弹窗
        function closeConfirmModal() {
            document.getElementById('confirmModal').classList.remove('active');
        }

        // 关闭清理弹窗
        function closeCleanModal() {
            document.getElementById('cleanModal').classList.remove('active');
        }

        // 关闭注销弹窗
        function closeDeleteModal() {
            document.getElementById('deleteModal').classList.remove('active');
        }

        // 点击弹窗外部关闭
        window.onclick = function(event) {
            const modals = ['gachaModal', 'confirmModal', 'cleanModal', 'deleteModal'];
            modals.forEach(id => {
                const modal = document.getElementById(id);
                if (event.target == modal) {
                    modal.classList.remove('active');
                }
            });
        }

        // 页面加载完成后执行
        document.addEventListener('DOMContentLoaded', function() {
            // 刷题助手
            document.getElementById('generateQuestions').onclick = () => {
                const min = parseInt(document.getElementById('minQuestions').value);
                const max = parseInt(document.getElementById('maxQuestions').value);
                const multiplier = parseFloat(document.getElementById('multiplier').value) || 1;

                const questionCount = Math.floor(Math.random() * (max - min + 1)) + min;
                document.getElementById('generatedQuestions').innerHTML = 
                    `📝 建议刷 <strong>${questionCount}</strong> 题 (记忆 +${(questionCount * memorySettings.questionMemoryGain).toFixed(1)}%)`;
                
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
            
            if (tab === 'memory') {
                tabs[0].classList.add('active');
                document.getElementById('self-memory').classList.add('active');
            } else if (tab === 'flux') {
                tabs[1].classList.add('active');
                document.getElementById('self-flux').classList.add('active');
            } else if (tab === 'data') {
                tabs[2].classList.add('active');
                document.getElementById('self-data').classList.add('active');
            } else if (tab === 'clean') {
                tabs[3].classList.add('active');
                document.getElementById('self-clean').classList.add('active');
            } else {
                tabs[4].classList.add('active');
                document.getElementById('self-danger').classList.add('active');
            }
        }
    </script>
</body>
</html>
