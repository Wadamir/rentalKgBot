<?php
/*
Parsing apartments from lalafo.kg & sending to telegram users
*******************************************
1. Set all variables & constants
2. Get rates from fx.kg
3. Get all chat_id from table users db
4. Parse apartments from lalafo.kg
5. Send messages to telegram
*/



// 1. Set all variables & constants
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/vendor/autoload.php';

use PhpQuery\PhpQuery;
use GuzzleHttp\Client;

require_once __DIR__ . '/config.php';
$log_dir = __DIR__ . '/logs';
$parser_log_file = $log_dir . '/parser.log';
$parser_error_log_file = $log_dir . '/parser_error.log';
file_put_contents($parser_log_file, '[' . date('Y-m-d H:i:s') . '] Start', FILE_APPEND);

$items_total = 0;
$items_added = 0;
$items_updated = 0;
$items_error = 0;

$token = TOKEN;
$fx_token = FX_TOKEN;

$dbhost = MYSQL_HOST;
$dbuser = MYSQL_USER;
$dbpass = MYSQL_PASSWORD;
$dbname = MYSQL_DB;
$table_users = MYSQL_TABLE_USERS;
$table_city = MYSQL_TABLE_CITY;
$table_district = MYSQL_TABLE_DISTRICT;
$table_data = MYSQL_TABLE_DATA;
$table_rates = MYSQL_TABLE_RATES;

// Create connection
$conn = mysqli_connect($dbhost, $dbuser, $dbpass);
if (!$conn) {
    file_put_contents($parser_error_log_file, '[' . date('Y-m-d H:i:s') . '] Connection failed' . PHP_EOL, FILE_APPEND);
    die("Connection failed: " . mysqli_connect_error()) . PHP_EOL;
}
if (!mysqli_select_db($conn, $dbname)) {
    file_put_contents($parser_error_log_file, '[' . date('Y-m-d H:i:s') . '] Database NOT SELECTED' . PHP_EOL, FILE_APPEND);
    die("Connection failed: " . mysqli_connect_error()) . PHP_EOL;
}


// 2. Get rates from fx.kg
file_put_contents($parser_log_file, ' | Get rates from fx.kg', FILE_APPEND);
$rates = [];
$guzzle_client = new Client();
$bearer_token = 'Bearer ' . $fx_token;
$headers = [
    'Authorization' => $bearer_token,
];
$request = $guzzle_client->request('GET', 'https://data.fx.kg/api/v1/central', [
    'headers' => $headers,
]);
$response = $request->getBody()->getContents();
$response = json_decode($response, true);
// put rates to table rates
foreach ($response as $currency => $rate) {
    if ($currency === 'updated_at') continue;
    if ($currency === 'created_at') continue;
    $rates[$currency] = floatval($rate);
}
if (count($rates) > 0) {
    // get last date_updated from table rates
    $sql = "SELECT date_updated FROM $table_rates ORDER BY date_updated DESC LIMIT 1";
    $result = mysqli_query($conn, $sql);
    $last_date_updated = $result->fetch_all(MYSQLI_ASSOC);
    if (count($last_date_updated) === 0) {
        $last_date_updated = '2000-01-01 00:00:00';
    } else {
        $last_date_updated = $last_date_updated[0]['date_updated'];
    }
    $last_date_updated = strtotime($last_date_updated);

    // get current date_updated from fx.kg
    $current_date_updated = $response['updated_at'];
    $current_date_updated = strtotime($current_date_updated);

    if ($current_date_updated > $last_date_updated) {
        // insert new rates
        $insert_sql = "INSERT INTO $table_rates (`usd`, `eur`, `gbp`, `cny`, `rub`, `kzt`, `date_updated`) VALUES ('" . $rates['usd'] . "', '" . $rates['eur'] . "', '" . $rates['gbp'] . "', '" . $rates['cny'] . "', '" . $rates['rub'] . "', '" . $rates['kzt'] . "', '" . $response['updated_at'] . "')";
        if (mysqli_query($conn, $insert_sql)) {
            file_put_contents($parser_log_file, ' | Rates updated', FILE_APPEND);
        } else {
            file_put_contents($parser_error_log_file, '[' . date('Y-m-d H:i:s') . '] Error updating rates: ' . mysqli_error($conn), FILE_APPEND);
        }
    }
} else {
    file_put_contents($parser_error_log_file, '[' . date('Y-m-d H:i:s') . '] No rates found' . PHP_EOL, FILE_APPEND);
}



// 3. Get all chat_id from table users db
$sql = "SELECT `chat_id` FROM $table_users WHERE `is_deleted` IS NULL OR `is_deleted` = 0";
$result = mysqli_query($conn, $sql);
$chat_ids = $result->fetch_all(MYSQLI_ASSOC);
$chat_ids = array_column($chat_ids, 'chat_id');



// 4. Parse apartments from lalafo.kg
$usd_rate_sql = "SELECT usd FROM $table_rates ORDER BY date_updated DESC LIMIT 1";
$usd_rate_result = mysqli_query($conn, $usd_rate_sql);
$usd_rate = $usd_rate_result->fetch_all(MYSQLI_ASSOC);
$usd_rate = floatval($usd_rate[0]['usd']);
if (count($chat_ids) > 0) {
    file_put_contents($parser_log_file, ' | Users found: ' . count($chat_ids), FILE_APPEND);
    $guzzle = new Client();
    $apartments = [];

    for ($i = 1; $i < 2; $i++) {
        $response = $guzzle->get('https://lalafo.kg/bishkek/kvartiry/arenda-kvartir/dolgosrochnaya-arenda-kvartir/whole-room' . '?page=' . $i);
        $content = $response->getBody()->getContents();

        $pq = new PhpQuery;
        $pq->load_str($content);

        $links = $pq->query('.adTile-title');

        foreach ($links as $link) {
            // set timeout
            $rnd_sec = rand(1, 3);
            sleep($rnd_sec);
            $items_total++;
            try {
                // check if apartment exists
                $apartment_link = 'https://lalafo.kg' . $link->getAttribute('href');
                $sql = "SELECT * FROM $table_data WHERE link = '" . $apartment_link . "'";
                $result = mysqli_query($conn, $sql);

                if (count($result->fetch_all()) > 0) {
                    continue;
                }

                $apartment_response = $guzzle->get($apartment_link);
                $apartment_content = $apartment_response->getBody()->getContents();
                file_put_contents('apartment.html', $apartment_content);

                $apartment_pq = new PhpQuery;
                $apartment_pq->load_str($apartment_content);

                $price_kgs = ($apartment_pq->query('.price')->length) ? $apartment_pq->query('.price')[0]->textContent : null;
                if ($price_kgs === null) {
                    continue;
                }
                $price_kgs = intval(preg_replace('/[^0-9]/', '', trim(str_replace('KGS', '', $price_kgs))));
                if ($price_kgs === 0) {
                    continue;
                }
                $price_usd = ceil($price_kgs / $usd_rate);


                $phone = ($apartment_pq->query('.call-button a')->length) ? $apartment_pq->query('.call-button a')[0]->getAttribute('href') : 'n/d';
                $phone = str_replace('tel:', '', $phone);
                $owner_name = ($apartment_pq->query('.userName-text')->length) ? $apartment_pq->query('.userName-text')[0]->textContent : 'n/d';

                $dates = ($apartment_pq->query('.about-ad-info__date')) ? $apartment_pq->query('.about-ad-info__date') : [];
                foreach ($dates as $date) {
                    if (mb_strpos($date->textContent, 'Обновлено') !== false) {
                        $date_updated = trim(str_replace('Обновлено:', '', $date->textContent));
                        continue;
                    }
                    if (mb_strpos($date->textContent, 'Создано') !== false) {
                        $date_created = trim(str_replace('Создано:', '', $date->textContent));
                        continue;
                    }
                }

                $details = $apartment_pq->query('.details-page__params li');

                foreach ($details as $detail) {
                    if (mb_strpos($detail->textContent, 'Район') !== false) {
                        $district = trim(str_replace('Район:', '', $detail->textContent));
                        continue;
                    }
                    if (mb_strpos($detail->textContent, 'Депозит, сом') !== false) {
                        $deposit = trim(str_replace('Депозит, сом:', '', $detail->textContent));
                        continue;
                    }
                    if (mb_strpos($detail->textContent, 'Количество комнат') !== false) {
                        $rooms = intval(preg_replace('/[^0-9]/', '', trim(str_replace('Количество комнат:', '', $detail->textContent))));
                        continue;
                    }
                    if (mb_strpos($detail->textContent, 'Этаж') !== false) {
                        $floor = trim(str_replace('Этаж:', '', $detail->textContent));
                        continue;
                    }
                    if (mb_strpos($detail->textContent, 'Количество этажей') !== false) {
                        $total_floor = trim(str_replace('Количество этажей:', '', $detail->textContent));
                        continue;
                    }
                    if (mb_strpos($detail->textContent, 'Серия') !== false) {
                        $house_type = trim(str_replace('Серия:', '', $detail->textContent));
                        continue;
                    }
                    if (mb_strpos($detail->textContent, 'Подселение') !== false) {
                        $sharing = trim(str_replace('Подселение:', '', $detail->textContent));
                        continue;
                    }
                    if (mb_strpos($detail->textContent, 'Мебель') !== false) {
                        $furniture = trim(str_replace('Мебель:', '', $detail->textContent));
                        continue;
                    }
                    if (mb_strpos($detail->textContent, 'Бытовая техника') !== false) {
                        $appliances = trim(str_replace('Бытовая техника:', '', $detail->textContent));
                        continue;
                    }
                    if (mb_strpos($detail->textContent, 'Состояние') !== false) {
                        $condition = trim(str_replace('Состояние:', '', $detail->textContent));
                        continue;
                    }
                    if (mb_strpos($detail->textContent, 'Ремонт') !== false) {
                        $renovation = trim(str_replace('Ремонт:', '', $detail->textContent));
                        continue;
                    }
                    if (mb_strpos($detail->textContent, 'Животные') !== false) {
                        $animals = trim(str_replace('Животные:', '', $detail->textContent));
                        continue;
                    }
                    if (mb_strpos($detail->textContent, 'Кто сдает') !== false) {
                        $owner = trim(str_replace('Кто сдает:', '', $detail->textContent));
                        continue;
                    }
                }

                $chat_ids_to_send = array_unique($chat_ids);
                foreach ($chat_ids_to_send as $key => $chat_id) {
                    $user_sql = "SELECT * FROM $table_users WHERE chat_id = '$chat_id'";
                    $user_result = mysqli_query($conn, $user_sql);
                    $user = $user_result->fetch_all(MYSQLI_ASSOC);
                    $user = $user[0];
                    $user_price_min = isset($user['price_min']) ? $user['price_min'] : null;
                    $user_price_max = isset($user['price_max']) ? $user['price_max'] : null;
                    $user_price_currency = isset($user['price_currency']) ? strtoupper(trim($user['price_currency'])) : 'USD';
                    $user_rooms_min = isset($user['rooms_min']) ? $user['rooms_min'] : null;
                    $user_rooms_max = isset($user['rooms_max']) ? $user['rooms_max'] : null;
                    $user_preference_city = isset($user['preference_city']) ? $user['preference_city'] : null;
                    $user_preference_district = isset($user['preference_district']) ? $user['preference_district'] : null;

                    if ($user_price_min === null && $user_price_max === null && $user_rooms_min === null && $user_rooms_max === null && $user_preference_city === null && $user_preference_district === null) {
                        continue;
                    }

                    if ($user_price_currency === 'USD') {
                        if ($user_price_max !== null && $price_usd > $user_price_max) {
                            unset($chat_ids_to_send[$key]);
                        }
                        if ($user_price_min !== null && $price_usd < $user_price_min) {
                            unset($chat_ids_to_send[$key]);
                        }
                    }
                    if ($user_price_currency === 'KGS') {
                        if ($user_price_max !== null && $price_kgs > $user_price_max) {
                            unset($chat_ids_to_send[$key]);
                        }
                        if ($user_price_min !== null && $price_kgs < $user_price_min) {
                            unset($chat_ids_to_send[$key]);
                        }
                    }
                    if ($user_rooms_min !== null && $rooms < $user_rooms_min) {
                        unset($chat_ids_to_send[$key]);
                    }
                    if ($user_rooms_max !== null && $rooms > $user_rooms_max) {
                        unset($chat_ids_to_send[$key]);
                    }
                }

                $chat_ids_to_send = array_values($chat_ids_to_send);

                $data = [
                    'title' => mysqli_real_escape_string($conn, $link->textContent),
                    'link' => $apartment_link,
                    'created_at' => isset($date_created) ? mysqli_real_escape_string($conn, $date_created) : 'n/d',
                    'updated_at' => isset($date_updated) ? mysqli_real_escape_string($conn, $date_updated) : 'n/d',
                    'price_kgs' => mysqli_real_escape_string($conn, $price_kgs),
                    'price_usd' => mysqli_real_escape_string($conn, $price_usd),
                    'deposit' => isset($deposit) ? mysqli_real_escape_string($conn, $deposit) : 'n/d',
                    'owner' => isset($owner) ? mysqli_real_escape_string($conn, $owner) : 'n/d',
                    'owner_name' => mysqli_real_escape_string($conn, $owner_name),
                    'phone' => mysqli_real_escape_string($conn, $phone),
                    'district' => isset($district) ? mysqli_real_escape_string($conn, $district) : 'n/d',
                    'rooms' => isset($rooms) ? mysqli_real_escape_string($conn, $rooms) : null,
                    'floor' => (isset($floor) && isset($total_floor)) ? mysqli_real_escape_string($conn, $floor . ' / ' . $total_floor) : 'n/d',
                    'house_type' => isset($house_type) ? mysqli_real_escape_string($conn, $house_type) : 'n/d',
                    'sharing' => isset($sharing) ? mysqli_real_escape_string($conn, $sharing) : 'n/d',
                    'furniture' => isset($furniture) ? mysqli_real_escape_string($conn, $furniture) : 'n/d',
                    'appliances' => isset($appliances) ? mysqli_real_escape_string($conn, $appliances) : 'n/d',
                    'condition' => isset($condition) ? mysqli_real_escape_string($conn, $condition) : 'n/d',
                    'renovation' => isset($renovation) ? mysqli_real_escape_string($conn, $renovation) : 'n/d',
                    'animals' => isset($animals) ? mysqli_real_escape_string($conn, $animals) : 'n/d',
                    'chat_ids_to_send' => json_encode($chat_ids_to_send),
                ];

                $apartments[] = $data;

                $update_sql = "INSERT INTO $table_data (`title`, `link`, `created_at`, `updated_at`, `price_kgs`, `price_usd`, `deposit`, `owner`, `owner_name`, `phone`, `district`, `rooms`, `floor`, `house_type`, `sharing`, `furniture`, `condition`, `renovation`, `animals`, `chat_ids_to_send`) VALUES ('" . $data['title'] . "', '" . $data['link'] . "', '" . $data['created_at'] . "', '" . $data['updated_at'] . "', '" . $data['price_kgs'] . "', '" . $data['price_usd'] . "', '" . $data['deposit'] . "', '" . $data['owner'] . "', '" . $data['owner_name'] . "', '" . $data['phone'] . "', '" . $data['district'] . "', '" . $data['rooms'] . "', '" . $data['floor'] . "', '" . $data['house_type'] . "', '" . $data['sharing'] . "', '" . $data['furniture'] . "', '" . $data['condition'] . "', '" . $data['renovation'] . "', '" . $data['animals'] . "', '" . $data['chat_ids_to_send'] . "')";
                if (mysqli_query($conn, $update_sql)) {
                    $items_added++;
                } else {
                    file_put_contents($parser_log_file, "Error: " . $update_sql . ' | ' . mysqli_error($conn) . PHP_EOL, FILE_APPEND);
                    $items_error++;
                }
            } catch (\Exception $e) {
                file_put_contents($parser_log_file, "Error: " . $e->getMessage() . PHP_EOL, FILE_APPEND);
                $items_error++;
                continue;
            }
        }
        // set timeout
        $rnd_sec = rand(1, 7);
        sleep($rnd_sec);
    }
    file_put_contents($parser_log_file, ' | Total units: ' . $items_total . ' | Added: ' . $items_added . ' | Updated: ' . $items_updated . ' | Error: ' . $items_error . ' | ', FILE_APPEND);
} else {
    file_put_contents($parser_log_file, ' | No users found | ', FILE_APPEND);
}
file_put_contents($parser_log_file, ' End [' . date('Y-m-d H:i:s') . ']' . PHP_EOL, FILE_APPEND);



// 5. Send messages to telegram
file_put_contents($parser_log_file, '[' . date('Y-m-d H:i:s') . '] Start sending to tgm', FILE_APPEND);
$msg_sent = 0;
$msg_error = 0;
// Select users from users table
$sql = "SELECT * FROM $table_users WHERE is_deleted = 0 OR is_deleted IS NULL";
$users_result = mysqli_query($conn, $sql);
if (mysqli_num_rows($users_result)) {
    $users_rows = mysqli_fetch_all($users_result, MYSQLI_ASSOC);
    foreach ($users_rows as $user) {
        $user_id = $user['user_id'];
        $chat_id = $user['chat_id'];
        $username = $user['username'];
        // select ids from data table where user_id is in chat_ids_to_send and not in chat_ids_sent
        $sql = "SELECT id FROM $table_data WHERE JSON_CONTAINS(chat_ids_sent, '\"$user_id\"')";
        $result = mysqli_query($conn, $sql);
        $rows = mysqli_fetch_all($result, MYSQLI_ASSOC);
        $sent_ids_array = array_column($rows, 'id');
        $sent_ids = implode(',', $sent_ids_array);
        $sql = "SELECT * FROM $table_data WHERE JSON_CONTAINS(chat_ids_to_send, '\"$user_id\"') AND done IS NULL";
        if (!empty($sent_ids)) {
            $sql .= " AND id NOT IN ($sent_ids)";
        }
        $result = mysqli_query($conn, $sql);
        $counter = 0;
        if (mysqli_num_rows($result) > 0) {
            $rows = mysqli_fetch_all($result, MYSQLI_ASSOC);
            foreach ($rows as $row) {
                $title = $row['title'];
                $district = $row['district'];
                $link = $row['link'];
                $created_at = $row['created_at'];
                $updated_at = $row['updated_at'];
                $price_kgs = $row['price_kgs'];
                $price_usd = $row['price_usd'];
                $deposit = $row['deposit'];
                $owner = $row['owner'];
                $owner_name = $row['owner_name'];
                $phone = $row['phone'];
                $rooms = $row['rooms'];
                $floor = $row['floor'];
                $house_type = $row['house_type'];
                $sharing = $row['sharing'];
                $furniture = $row['furniture'];
                $condition = $row['condition'];
                $renovation = $row['renovation'];
                $animals = $row['animals'];


                $message = "<b>$title</b>";
                if ($renovation !== 'n/d') {
                    $message .= ", $renovation\n";
                } else {
                    $message .= "\n";
                }
                $message .= "<b>Район:</b> $district\n";
                if ($price_kgs !== 'n/d')   $message .= "<b>Цена:</b> $price_kgs KGS ($price_usd USD)\n";
                if ($deposit !== 'n/d')     $message .= "<b>Депозит:</b> $deposit\n";
                if ($house_type !== 'n/d')  $message .= "<b>Серия:</b> $house_type\n";
                if ($sharing !== 'n/d')     $message .= "<b>Подселение:</b> $sharing\n";
                // if ($rooms !== 'n/d')    $message .= "<b>Комнат:</b> $rooms\n";
                if ($floor !== 'n/d')       $message .= "<b>Этаж:</b> $floor\n";
                // if ($furniture !== 'n/d') $message .= "<b>Мебель:</b> $furniture\n";
                if ($condition !== 'n/d')   $message .= "<b>Состояние:</b> $condition\n";
                // if ($renovation !== 'n/d') $message .= "<b>Ремонт:</b> $renovation\n";
                if ($animals !== 'n/d')     $message .= "<b>Животные:</b> $animals\n";
                if ($owner !== 'n/d' && $owner_name !== 'n/d') {
                    $message .= "<b>Кто сдает:</b> $owner, $owner_name\n";
                } else {
                    if ($owner !== 'n/d')   $message .= "<b>Кто сдает:</b> $owner\n";
                    if ($owner_name !== 'n/d') $message .= "<b>Имя:</b> $owner_name\n";
                }
                if ($phone !== 'n/d')       $message .= "<b>Телефон:</b> $phone\n";
                if ($created_at !== $updated_at) {
                    if ($created_at !== 'n/d') $message .= "<b>Создано:</b> $created_at\n";
                    if ($updated_at !== 'n/d') $message .= "<b>Обновлено:</b> $updated_at\n";
                } else {
                    if ($created_at !== 'n/d') $message .= "<b>Создано:</b> $created_at\n";
                }
                $message .= "$link\n";

                try {
                    if (trim($owner) !== 'Агентство' && trim($owner) !== 'Агентство недвижимости' && trim($owner) !== 'Риэлтор') {
                        $bot = new \TelegramBot\Api\BotApi($token);
                        $bot->sendMessage($chat_id, $message, 'HTML');
                        // Update sent_to_user
                        $chat_ids_sent = [];
                        if ($row['chat_ids_sent'] !== '[]' && $row['chat_ids_sent'] !== '' && $row['chat_ids_sent'] !== null) {
                            $chat_ids_sent = json_decode($row['chat_ids_sent']);
                        }
                        $chat_ids_sent[] = $user_id;
                        $chat_ids_sent = array_unique($chat_ids_sent);
                        $chat_ids_sent = json_encode($chat_ids_sent);
                        $sql = "UPDATE $table_data SET chat_ids_sent = '$chat_ids_sent' WHERE id = " . $row['id'];
                        if (mysqli_query($conn, $sql)) {
                            // file_put_contents($parser_log_file, ' | User: ' . $username . ' | Msg sent: ' . $message . PHP_EOL, FILE_APPEND);
                            $msg_sent++;
                        } else {
                            file_put_contents($parser_log_file, ' | User: ' . $username . ' | Error: ' . mysqli_error($conn) . PHP_EOL, FILE_APPEND);
                            $msg_error++;
                        }
                        $chat_ids_to_send = $row['chat_ids_to_send'];
                        if ($chat_ids_sent === $chat_ids_to_send) {
                            $sql = "UPDATE $table_data SET done = '1' WHERE id = " . $row['id'];
                            if (mysqli_query($conn, $sql)) {
                                // file_put_contents($parser_log_file, ' | User: ' . $username . ' | Msg sent: ' . $message . PHP_EOL, FILE_APPEND);
                                $msg_sent++;
                            } else {
                                file_put_contents($parser_log_file, ' | User: ' . $username . ' | Error: ' . mysqli_error($conn) . PHP_EOL, FILE_APPEND);
                                $msg_error++;
                            }
                        }
                    } else {
                        $sql = "UPDATE $table_data SET done = '1' WHERE id = " . $row['id'];
                        if (mysqli_query($conn, $sql)) {
                            // file_put_contents($parser_log_file, ' | User: ' . $username . ' | Msg sent: ' . $message . PHP_EOL, FILE_APPEND);
                            $msg_sent++;
                        } else {
                            file_put_contents($parser_log_file, ' | User: ' . $username . ' | Error: ' . mysqli_error($conn) . PHP_EOL, FILE_APPEND);
                            $msg_error++;
                        }
                    }
                } catch (\TelegramBot\Api\Exception $e) {
                    $error = $e->getMessage();
                    file_put_contents($parser_log_file, ' | User: ' . $username . ' Error: ' . $e->getMessage(), FILE_APPEND);
                    if ($error === 'Forbidden: bot was blocked by the user') {
                        try {
                            // file_put_contents($parser_log_file, ' | User: ' . $username . ' try to deactivate', FILE_APPEND);
                            deactivateUser($user_id);
                        } catch (Exception $e) {
                            file_put_contents($parser_log_file, ' | Error: ' . $e->getMessage() . PHP_EOL, FILE_APPEND);
                        }
                    }
                    break;
                }
                $counter++;
            }
        }

        file_put_contents($parser_log_file, ' | Msgs for ' . $username . ' sent: ' . $counter, FILE_APPEND);
    }
} else {
    file_put_contents($parser_log_file, ' | No users found', FILE_APPEND);
}
file_put_contents($parser_log_file, ' | End: ' . date('Y-m-d H:i:s') . PHP_EOL, FILE_APPEND);
mysqli_close($conn);
