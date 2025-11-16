<?php
// Bot configuration
$bot_token = getenv('BOT_TOKEN') ?: 'Place_Your_Token_Here';
define('BOT_TOKEN', $bot_token);
define('API_URL', 'https://api.telegram.org/bot' . BOT_TOKEN . '/');
define('USERS_FILE', 'users.json');
define('ERROR_LOG', 'error.log');

// Error logging
function logError($message) {
    $timestamp = date('Y-m-d H:i:s');
    file_put_contents(ERROR_LOG, "[$timestamp] $message\n", FILE_APPEND);
    error_log($message); // Also log to system
}

// Data management
function loadUsers() {
    try {
        if (!file_exists(USERS_FILE)) {
            file_put_contents(USERS_FILE, json_encode([]));
        }
        return json_decode(file_get_contents(USERS_FILE), true) ?: [];
    } catch (Exception $e) {
        logError("Load users failed: " . $e->getMessage());
        return [];
    }
}

function saveUsers($users) {
    try {
        file_put_contents(USERS_FILE, json_encode($users, JSON_PRETTY_PRINT));
        return true;
    } catch (Exception $e) {
        logError("Save users failed: " . $e->getMessage());
        return false;
    }
}

// Message sending with inline keyboard
function sendMessage($chat_id, $text, $keyboard = null) {
    try {
        $params = [
            'chat_id' => $chat_id,
            'text' => $text,
            'parse_mode' => 'HTML'
        ];
        
        if ($keyboard) {
            $params['reply_markup'] = json_encode([
                'inline_keyboard' => $keyboard
            ]);
        }
        
        $url = API_URL . 'sendMessage';
        $options = [
            'http' => [
                'header'  => "Content-type: application/x-www-form-urlencoded\r\n",
                'method'  => 'POST',
                'content' => http_build_query($params)
            ]
        ];
        $context = stream_context_create($options);
        file_get_contents($url, false, $context);
        return true;
    } catch (Exception $e) {
        logError("Send message failed: " . $e->getMessage());
        return false;
    }
}

// Answer callback query to remove loading state
function answerCallbackQuery($callback_query_id) {
    try {
        $params = [
            'callback_query_id' => $callback_query_id
        ];
        
        $url = API_URL . 'answerCallbackQuery';
        $options = [
            'http' => [
                'header'  => "Content-type: application/x-www-form-urlencoded\r\n",
                'method'  => 'POST',
                'content' => http_build_query($params)
            ]
        ];
        $context = stream_context_create($options);
        file_get_contents($url, false, $context);
        return true;
    } catch (Exception $e) {
        logError("Answer callback failed: " . $e->getMessage());
        return false;
    }
}

// Main keyboard
function getMainKeyboard() {
    return [
        [['text' => '💰 Earn', 'callback_data' => 'earn'], ['text' => '💳 Balance', 'callback_data' => 'balance']],
        [['text' => '🏆 Leaderboard', 'callback_data' => 'leaderboard'], ['text' => '👥 Referrals', 'callback_data' => 'referrals']],
        [['text' => '🏧 Withdraw', 'callback_data' => 'withdraw'], ['text' => '❓ Help', 'callback_data' => 'help']]
    ];
}

// Process commands and callbacks
function processUpdate($update) {
    $users = loadUsers();
    
    if (isset($update['message'])) {
        $chat_id = $update['message']['chat']['id'];
        $text = trim($update['message']['text'] ?? '');
        
        // Create new user if doesn't exist
        if (!isset($users[$chat_id])) {
            $users[$chat_id] = [
                'balance' => 0,
                'last_earn' => 0,
                'referrals' => 0,
                'ref_code' => substr(md5($chat_id . time()), 0, 8),
                'referred_by' => null
            ];
        }
        
        if (strpos($text, '/start') === 0) {
            $ref = explode(' ', $text)[1] ?? null;
            if ($ref && !$users[$chat_id]['referred_by']) {
                foreach ($users as $id => $user) {
                    if ($user['ref_code'] === $ref && $id != $chat_id) {
                        $users[$chat_id]['referred_by'] = $id;
                        $users[$id]['referrals']++;
                        $users[$id]['balance'] += 50; // Referral bonus
                        sendMessage($id, "🎉 New referral! +50 points bonus!");
                        break;
                    }
                }
            }
            
            $msg = "Welcome to Earning Bot!\nEarn points, invite friends, and withdraw your earnings!\nYour referral code: <b>{$users[$chat_id]['ref_code']}</b>";
            sendMessage($chat_id, $msg, getMainKeyboard());
        }
        
    } elseif (isset($update['callback_query'])) {
        $callback_query = $update['callback_query'];
        $chat_id = $callback_query['message']['chat']['id'];
        $data = $callback_query['data'];
        $callback_query_id = $callback_query['id'];
        
        // Answer callback query first
        answerCallbackQuery($callback_query_id);
        
        if (!isset($users[$chat_id])) {
            $users[$chat_id] = [
                'balance' => 0,
                'last_earn' => 0,
                'referrals' => 0,
                'ref_code' => substr(md5($chat_id . time()), 0, 8),
                'referred_by' => null
            ];
        }
        
        switch ($data) {
            case 'earn':
                $time_diff = time() - $users[$chat_id]['last_earn'];
                if ($time_diff < 60) {
                    $remaining = 60 - $time_diff;
                    $msg = "⏳ Please wait $remaining seconds before earning again!";
                } else {
                    $earn = 10;
                    $users[$chat_id]['balance'] += $earn;
                    $users[$chat_id]['last_earn'] = time();
                    $msg = "✅ You earned $earn points!\nNew balance: {$users[$chat_id]['balance']}";
                }
                break;
                
            case 'balance':
                $msg = "💳 Your Balance\nPoints: {$users[$chat_id]['balance']}\nReferrals: {$users[$chat_id]['referrals']}";
                break;
                
            case 'leaderboard':
                $sorted = array_column($users, 'balance');
                arsort($sorted);
                $top = array_slice($sorted, 0, 5, true);
                $msg = "🏆 Top Earners\n";
                $i = 1;
                foreach ($top as $id => $bal) {
                    $msg .= "$i. User $id: $bal points\n";
                    $i++;
                }
                break;
                
            case 'referrals':
                $msg = "👥 Referral System\nYour code: <b>{$users[$chat_id]['ref_code']}</b>\nReferrals: {$users[$chat_id]['referrals']}\nInvite link: https://t.me/" . explode(':', BOT_TOKEN)[0] . "?start={$users[$chat_id]['ref_code']}\n50 points per referral!";
                break;
                
            case 'withdraw':
                $min = 100;
                if ($users[$chat_id]['balance'] < $min) {
                    $msg = "🏧 Withdrawal\nMinimum: $min points\nYour balance: {$users[$chat_id]['balance']}\nNeed " . ($min - $users[$chat_id]['balance']) . " more points!";
                } else {
                    $amount = $users[$chat_id]['balance'];
                    $users[$chat_id]['balance'] = 0;
                    $msg = "🏧 Withdrawal of $amount points requested!\nOur team will process it soon.";
                    // Add actual withdrawal processing here
                }
                break;
                
            case 'help':
                $msg = "❓ Help\n💰 Earn: Get 10 points/min\n👥 Refer: 50 points/ref\n🏧 Withdraw: Min 100 points\nUse buttons below to navigate!";
                break;
                
            default:
                $msg = "Unknown command. Please use the buttons below.";
                break;
        }
        
        sendMessage($chat_id, $msg, getMainKeyboard());
    }
    
    saveUsers($users);
}

// Webhook setup function
function setWebhook($webhook_url) {
    try {
        $url = API_URL . 'setWebhook?url=' . urlencode($webhook_url);
        $result = file_get_contents($url);
        logError("Webhook set: " . $result);
        return $result;
    } catch (Exception $e) {
        logError("Webhook setup failed: " . $e->getMessage());
        return false;
    }
}

// Webhook removal function
function deleteWebhook() {
    try {
        $url = API_URL . 'deleteWebhook';
        $result = file_get_contents($url);
        logError("Webhook deleted: " . $result);
        return $result;
    } catch (Exception $e) {
        logError("Webhook deletion failed: " . $e->getMessage());
        return false;
    }
}

// Get bot info function
function getBotInfo() {
    try {
        $url = API_URL . 'getMe';
        $result = file_get_contents($url);
        $data = json_decode($result, true);
        if ($data['ok']) {
            return $data['result'];
        }
        return false;
    } catch (Exception $e) {
        logError("Get bot info failed: " . $e->getMessage());
        return false;
    }
}

// Handle webhook request
function handleWebhook() {
    $input = file_get_contents('php://input');
    $update = json_decode($input, true);
    
    if ($update) {
        processUpdate($update);
    }
    
    // Return OK to Telegram
    header('Content-Type: application/json');
    echo json_encode(['status' => 'ok']);
}

// Handle setup page
function handleSetupPage() {
    $webhook_url = "https://" . $_SERVER['HTTP_HOST'] . "/";
    
    echo "<!DOCTYPE html>
    <html>
    <head>
        <title>Telegram Bot Setup</title>
        <style>
            body { font-family: Arial, sans-serif; max-width: 800px; margin: 0 auto; padding: 20px; }
            .container { background: #f5f5f5; padding: 20px; border-radius: 10px; }
            .button { display: inline-block; padding: 10px 20px; margin: 10px; background: #0088cc; color: white; text-decoration: none; border-radius: 5px; }
            .button:hover { background: #006699; }
            .info { background: white; padding: 15px; border-radius: 5px; margin: 10px 0; }
        </style>
    </head>
    <body>
        <div class='container'>
            <h1>🤖 Telegram Bot Setup</h1>
            
            <div class='info'>
                <h3>Bot Information:</h3>";
    
    $bot_info = getBotInfo();
    if ($bot_info) {
        echo "<p><strong>Bot Name:</strong> @" . $bot_info['username'] . "</p>";
        echo "<p><strong>Bot ID:</strong> " . $bot_info['id'] . "</p>";
        echo "<p><strong>First Name:</strong> " . $bot_info['first_name'] . "</p>";
    } else {
        echo "<p style='color: red;'><strong>Error:</strong> Could not connect to bot. Check your BOT_TOKEN.</p>";
    }
    
    echo "      </div>
            
            <div class='info'>
                <h3>Webhook URL:</h3>
                <p><code>" . htmlspecialchars($webhook_url) . "</code></p>
            </div>
            
            <div class='info'>
                <h3>Actions:</h3>
                <a href='?setup_webhook=1' class='button'>Set Webhook</a>
                <a href='?delete_webhook=1' class='button' style='background: #cc0000;'>Delete Webhook</a>
                <a href='?check_webhook=1' class='button' style='background: #00cc00;'>Check Webhook</a>
            </div>";
    
    // Handle actions
    if (isset($_GET['setup_webhook'])) {
        echo "<div class='info'>";
        $result = setWebhook($webhook_url);
        echo "<h3>Webhook Setup Result:</h3>";
        echo "<pre>" . htmlspecialchars($result) . "</pre>";
        echo "</div>";
    }
    
    if (isset($_GET['delete_webhook'])) {
        echo "<div class='info'>";
        $result = deleteWebhook();
        echo "<h3>Webhook Deletion Result:</h3>";
        echo "<pre>" . htmlspecialchars($result) . "</pre>";
        echo "</div>";
    }
    
    if (isset($_GET['check_webhook'])) {
        echo "<div class='info'>";
        try {
            $url = API_URL . 'getWebhookInfo';
            $result = file_get_contents($url);
            echo "<h3>Webhook Info:</h3>";
            echo "<pre>" . htmlspecialchars($result) . "</pre>";
        } catch (Exception $e) {
            echo "<p style='color: red;'>Error checking webhook: " . htmlspecialchars($e->getMessage()) . "</p>";
        }
        echo "</div>";
    }
    
    echo "  </div>
        </body>
        </html>";
}

// Main request handler
try {
    // Check if it's a setup request or webhook update
    if (empty(file_get_contents('php://input')) && (isset($_GET['setup_webhook']) || isset($_GET['delete_webhook']) || isset($_GET['check_webhook']) || $_SERVER['REQUEST_METHOD'] === 'GET')) {
        // Show setup page for GET requests with parameters or when no input data
        handleSetupPage();
    } else {
        // Handle webhook update from Telegram
        handleWebhook();
    }
} catch (Exception $e) {
    logError("Fatal error in main handler: " . $e->getMessage());
    header('Content-Type: application/json');
    echo json_encode(['status' => 'error', 'message' => 'Internal server error']);
}
?>
