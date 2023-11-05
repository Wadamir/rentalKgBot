<?php
/*
Sending statistics to users
*******************************************
1. Set all variables & constants
2. Send messages to telegram
*/



// 1. Set all variables & constants
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/vendor/autoload.php';

require_once __DIR__ . '/config.php';
$log_dir = __DIR__ . '/logs';
$statistics_log_file = $log_dir . '/statistics.log';
$statistics_error_log_file = $log_dir . '/statistics_error.log';
file_put_contents($statistics_log_file, '[' . date('Y-m-d H:i:s') . '] Start', FILE_APPEND);

$users_total = 0;
$users_active = 0;
$msg_sent = 0;
$msg_error = 0;

$token = TOKEN;

$dbhost = MYSQL_HOST;
$dbuser = MYSQL_USER;
$dbpass = MYSQL_PASSWORD;
$dbname = MYSQL_DB;
$table_user = MYSQL_TABLE_USER;
$table_city = MYSQL_TABLE_CITY;
$table_district = MYSQL_TABLE_DISTRICT;
$table_data = MYSQL_TABLE_DATA;
$table_rate = MYSQL_TABLE_RATE;

// Create connection
$conn = mysqli_connect($dbhost, $dbuser, $dbpass);
if (!$conn) {
    file_put_contents($statistics_error_log_file, '[' . date('Y-m-d H:i:s') . '] Connection failed' . PHP_EOL, FILE_APPEND);
    die("Connection failed: " . mysqli_connect_error()) . PHP_EOL;
}
if (!mysqli_select_db($conn, $dbname)) {
    file_put_contents($statistics_error_log_file, '[' . date('Y-m-d H:i:s') . '] Database NOT SELECTED' . PHP_EOL, FILE_APPEND);
    die("Connection failed: " . mysqli_connect_error()) . PHP_EOL;
}



// 2. Send messages to telegram where is_statistics = 1
$sql = "SELECT * FROM $table_user WHERE (is_deleted = 0 OR is_deleted IS NULL) AND is_statistics = 1";
$users_result = mysqli_query($conn, $sql);
if (mysqli_num_rows($users_result)) {
    file_put_contents($statistics_log_file, ' | Users: ' . mysqli_num_rows($users_result), FILE_APPEND);
    $users_rows = mysqli_fetch_all($users_result, MYSQLI_ASSOC);
    foreach ($users_rows as $user) {
        $tgm_user_id = $user['tgm_user_id'];
        $chat_id = $user['chat_id'];
        $username = $user['username'];
        $user_language = $user['language_code'];

        // Get statistics
        $statistics = getStatisticsByChatId($chat_id);

        if (!empty($statistics) && $statistics['total'] > 1) {
            $message = ($user_language === 'ru' || $user_language === 'kg') ? "<b>📊 Ваша статистика за последние 24 часа:</b>\n\n" : "<b>📊 Your statistics for the last 24 hours:</b>\n\n";
            $message .= ($user_language === 'ru' || $user_language === 'kg') ? "<b>✅ Всего объявлений по Вашим критериям:</b> " . $statistics['total'] : "<b>Total ads for your criteria:</b> " . $statistics['total'];
            $message .= "\n";
            $message .= ($user_language === 'ru' || $user_language === 'kg') ? "<b>💵 Минимальная цена:</b> " . $statistics['min_price_usd'] . ' USD' : "<b>Minimum price:</b> " . $statistics['min_price_usd'] . ' USD';
            $message .= "\n";
            $message .= ($user_language === 'ru' || $user_language === 'kg') ? "<b>💵 Максимальная цена:</b> " . $statistics['max_price_usd'] . ' USD' : "<b>Maximum price:</b> " . $statistics['max_price_usd'] . ' USD';
        } elseif (!empty($statistics) && $statistics['total'] > 0) {
            $message = ($user_language === 'ru' || $user_language === 'kg') ? "<b>📊 Ваша статистика за последние 24 часа:</b>\n\n" : "<b>📊 Your statistics for the last 24 hours:</b>\n\n";
            $message .= ($user_language === 'ru' || $user_language === 'kg') ? "<b>✅ Всего объявлений по Вашим критериям:</b> " . $statistics['total'] : "<b>Total ads for your criteria:</b> " . $statistics['total'];
            $message .= "\n";
            $message .= ($user_language === 'ru' || $user_language === 'kg') ? "<b>💵 Цена:</b> " . $statistics['min_price_usd'] . ' USD' : "<b>Price:</b> " . $statistics['min_price_usd'] . ' USD';
        } else {
            $message = ($user_language === 'ru' || $user_language === 'kg') ? "⭕ За последние 24 часа по Вашим критериям объявлений не найдено." : "⭕ No ads found for your criteria in the last 24 hours.";
        }

        $msg_footer = getMsgFooter($user_language);
        $message .= $msg_footer;

        $payment_array = getPayment($user_language);
        $inline_keyboard = $payment_array[1];
        if (!empty($payment_array[0])) {
            $message .= $payment_array[0];
        }

        try {
            $bot = new \TelegramBot\Api\BotApi($token);
            $bot->sendMessage($chat_id, $message, 'HTML', false, null, $inline_keyboard);
        } catch (\TelegramBot\Api\Exception $e) {
            $error = $e->getMessage();
            file_put_contents($statistics_error_log_file, ' | User: ' . $username . ' Error: ' . $e->getMessage(), FILE_APPEND);
            if ($error === 'Forbidden: bot was blocked by the user') {
                try {
                    deactivateUser($tgm_user_id, $chat_id);
                } catch (Exception $e) {
                    file_put_contents($statistics_error_log_file, ' | Error: ' . $e->getMessage() . PHP_EOL, FILE_APPEND);
                }
            }
            break;
        }

        file_put_contents($statistics_log_file, ' | Statistic msg for ' . $username . ' sent', FILE_APPEND);
    }
} else {
    file_put_contents($statistics_log_file, ' | No active users found', FILE_APPEND);
}

file_put_contents($statistics_log_file, ' | End: ' . date('Y-m-d H:i:s') . PHP_EOL, FILE_APPEND);
mysqli_close($conn);



function deactivateUser($tgm_user_id, $chat_id)
{
    global $statistics_log_file;
    global $statistics_error_log_file;

    $dbhost = MYSQL_HOST;
    $dbuser = MYSQL_USER;
    $dbpass = MYSQL_PASSWORD;
    $dbname = MYSQL_DB;
    $table_user = MYSQL_TABLE_USER;
    $table_data = MYSQL_TABLE_DATA;

    // Create connection
    $conn = mysqli_connect($dbhost, $dbuser, $dbpass, $dbname);
    if (!$conn) {
        file_put_contents($statistics_error_log_file, ' | deactivateUser - connection failed', FILE_APPEND);
        throw new Exception("Connection failed: " . mysqli_connect_error()) . PHP_EOL;
    }
    $sql = "UPDATE $table_user SET is_deleted = 1 WHERE tgm_user_id = " . $tgm_user_id;
    $result = mysqli_query($conn, $sql);
    if ($result === false) {
        file_put_contents($statistics_error_log_file, " | deactivateUser - error: " . $sql . ' | ' . mysqli_error($conn), FILE_APPEND);
        throw new Exception("Error: " . $sql . ' | ' . mysqli_error($conn));
        return false;
    }

    // remove  from chat_ids_to_send
    $sql = "SELECT * FROM $table_data WHERE JSON_CONTAINS(chat_ids_to_send, '\"$chat_id\"') AND done IS NULL";
    $result = mysqli_query($conn, $sql);
    if ($result === false) {
        file_put_contents($statistics_error_log_file, " | deactivateUser - error: " . $sql . ' | ' . mysqli_error($conn), FILE_APPEND);
        throw new Exception("Error: " . $sql . ' | ' . mysqli_error($conn));
        return false;
    } else {
        if (mysqli_num_rows($result) > 0) {
            $rows = mysqli_fetch_all($result, MYSQLI_ASSOC);
            foreach ($rows as $row) {
                $chat_ids_to_send = $row['chat_ids_to_send'];
                $chat_ids_to_send = json_decode($chat_ids_to_send);
                $chat_ids_to_send = array_map('strval', $chat_ids_to_send);
                $chat_ids_to_send = array_unique($chat_ids_to_send);
                $chat_ids_to_send = array_values($chat_ids_to_send);
                sort($chat_ids_to_send);
                $chat_id_key = array_search($chat_id, $chat_ids_to_send);
                if ($chat_id_key !== false) {
                    unset($chat_ids_to_send[$chat_id_key]);
                }
                $chat_ids_to_send = json_encode($chat_ids_to_send);
                $sql = "UPDATE $table_data SET chat_ids_to_send = '$chat_ids_to_send' WHERE id = " . $row['id'];
                if (mysqli_query($conn, $sql)) {
                    file_put_contents($statistics_log_file, ' | Chat id: ' . $chat_id . ' removed', FILE_APPEND);
                } else {
                    file_put_contents($statistics_error_log_file, ' | Error: ' . mysqli_error($conn) . PHP_EOL, FILE_APPEND);
                    throw new Exception("Error: " . $sql . ' | ' . mysqli_error($conn));
                    return false;
                }
            }
        }
    }

    // Close connection
    mysqli_close($conn);

    return true;
}


function getStatisticsByChatId($chat_id, $period = '1 day')
{
    global $statistics_log_file;
    global $statistics_error_log_file;

    $response = [];

    $dbhost = MYSQL_HOST;
    $dbuser = MYSQL_USER;
    $dbpass = MYSQL_PASSWORD;
    $dbname = MYSQL_DB;
    $table_user = MYSQL_TABLE_USER;
    $table_data = MYSQL_TABLE_DATA;

    // Create connection
    $conn = mysqli_connect($dbhost, $dbuser, $dbpass, $dbname);
    if (!$conn) {
        file_put_contents($statistics_error_log_file, ' | getStatisticsByChatId - connection failed', FILE_APPEND);
        throw new Exception("Connection failed: " . mysqli_connect_error()) . PHP_EOL;
    }

    $now_minus_24_hours = date('Y-m-d H:i:s', strtotime('-24 hours'));
    $sql = "SELECT * FROM $table_data WHERE JSON_CONTAINS(chat_ids_sent, '\"$chat_id\"')" . " AND date_added >= '$now_minus_24_hours'";
    $result = mysqli_query($conn, $sql);
    $total_sent = 0;
    $prices_usd = [];
    $min_price_usd = 0;
    $max_price_usd = 0;
    if ($result && mysqli_num_rows($result) > 0) {
        $total_sent = mysqli_num_rows($result);
        foreach ($result as $row) {
            $prices_usd[] = $row['price_usd'];
        }
        $min_price_usd = min($prices_usd);
        $max_price_usd = max($prices_usd);
    }

    $response = [
        'total' => $total_sent,
        'min_price_usd' => $min_price_usd,
        'max_price_usd' => $max_price_usd
    ];

    // Close connection
    mysqli_close($conn);

    return $response;
}


function getMsgFooter($user_language)
{
    $message = "\n";
    $message .= "\n";
    $message .= ($user_language === 'ru' || $user_language === 'kg') ? "⚙ Если Вы хотите изменить настройки воспользуйтесь командой /settings" : "⚙ If you want to change the settings, use the /settings command";
    $message .= "\n";
    $message .= "\n";
    $message .= ($user_language === 'ru' || $user_language === 'kg') ? '📫 Для обратной связи напишите боту сообщение с хештегом #feedback' : '📫 To give feedback, send a message to the bot with the hashtag #feedback';

    return  $message;
}

function getPayment($user_language)
{

    global $start_error_log_file;

    $message = null;

    $payments = [];

    $dbhost = MYSQL_HOST;
    $dbuser = MYSQL_USER;
    $dbpass = MYSQL_PASSWORD;
    $dbname = MYSQL_DB;
    $table_payment = MYSQL_TABLE_PAYMENT;

    // Create connection
    $conn = mysqli_connect($dbhost, $dbuser, $dbpass, $dbname);
    if (!$conn) {
        file_put_contents($start_error_log_file, ' | getPayment - connection failed', FILE_APPEND);
        throw new Exception("Connection failed: " . mysqli_connect_error()) . PHP_EOL;
    }

    $sql = "SELECT * FROM $table_payment WHERE is_active = 1 ORDER BY payment_id ASC";
    $result = mysqli_query($conn, $sql);
    if ($result && mysqli_num_rows($result) > 0) {
        $rows = mysqli_fetch_all($result, MYSQLI_ASSOC);
        foreach ($rows as $row) {
            $payments[] = [
                'text' => $row['payment_icon'] . ' ' . $row['payment_name_' . $user_language],
                'callback_data' => 'payment_' . $row['payment_id']
            ];
        }
    }

    if (!empty($payments)) {
        $inline_keyboard_array = [];
        foreach ($payments as $key => $value) {
            if ($key % 2 === 0) {
                $inline_keyboard_array[] = [$value];
            } else {
                $inline_keyboard_array[count($inline_keyboard_array) - 1][] = $value;
            }
        }

        $inline_keyboard = new \TelegramBot\Api\Types\Inline\InlineKeyboardMarkup($inline_keyboard_array);

        $message = "\n";
        $message .= getPremiumSubscriptionBenefit($user_language);
    } else {
        $inline_keyboard = null;
    }

    return [$message, $inline_keyboard];
}

function getPremiumSubscriptionBenefit($user_language)
{
    return ($user_language === 'ru' || $user_language === 'kg') ? "💪 Преимущества премиум подписки:\n1. Ускоренное уведомление о новых объявлениях.\n2. Полный набор фотографий.\n3. Расширенное описание.\n\n👑 Стоимость премиум подписки на 3 дня - 200 сом (220 руб | 1 TonCoin)\n👑 Стоимость премиум подписки на 7 дней - 300 сом (330 руб | 1.5 TonCoin)\n👑 Стоимость премиум подписки на 14 дней - 500 сом (550 руб | 2.5 TonCoin)" : "💪 Benefits of premium subscription:\n1. Expedited notification of new announcements.\n2. Full set of photos.\n3. Extended description.\n\n👑 The cost of premium subscription for 3 days is 200 soms (220 rubles | 1 TonCoin)\n👑 The cost of premium subscription for 7 days is 300 soms (330 rubles | 1.5 TonCoin)\n👑 The cost of premium subscription for 14 days is 500 soms (550 rubles | 2.5 TonCoin)";
}
