<?php
/**
 * ==================================================================
 *_dual-platform Assistant Bot (Telegram + WhatsApp)
 * Single-file PHP bot for shared hosting — no Composer required
 * PHP 7.4+ compatible
 * ==================================================================
 *
 * WEBHOOK SETUP INSTRUCTIONS / تعليمات إعداد الـ Webhook
 *
 * 1. Upload this file to your hosting as: bot.php
 * 2. Set Telegram Webhook:
 *    https://api.telegram.org/bot{TOKEN}/setWebhook?url=https://yourdomain.com/bot.php
 *    Optional secret: &secret_token=YOUR_SECRET
 * 3. Set WhatsApp Webhook in your provider dashboard to:
 *    https://yourdomain.com/bot.php?platform=whatsapp
 * 4. Ensure the file has read/write permissions (chmod 755)
 * 5. Ensure bot_log.txt and bot_data.json are writable (chmod 666)
 *
 * ==================================================================
 */

// ==================================================================
// 1. CONFIGURATION / الإعدادات
// ==================================================================

// ===== TELEGRAM CONFIG =====
define('TELEGRAM_BOT_TOKEN', 'YOUR_TOKEN_HERE');           // توكن بوت تيليغرام — احصل عليه من @BotFather
define('TELEGRAM_API_URL', 'https://api.telegram.org/bot' . TELEGRAM_BOT_TOKEN);

// ===== WHATSAPP CONFIG (UltraMsg / CallMeBot) =====
define('WHATSAPP_API_URL', 'https://api.ultramsg.com/instance'); // رابط API واتساب (UltraMsg)
define('WHATSAPP_API_KEY', 'YOUR_API_KEY_HERE');                 // مفتاح API واتساب
define('WHATSAPP_INSTANCE_ID', 'YOUR_INSTANCE_ID');              // معرف الجلسة / Instance ID

// ===== BOT SETTINGS =====
define('BOT_NAME', 'المساعد الذكي');            // اسم البوت
define('ADMIN_CHAT_ID', 'YOUR_ADMIN_ID');        // معرف المشرف الرئيسي (Telegram chat ID)
define('BOT_LANGUAGE', 'ar');                    // اللغة الافتراضية: ar أو en
define('LOG_FILE', __DIR__ . '/bot_log.txt');    // مسار ملف السجلات
define('DB_FILE', __DIR__ . '/bot_data.json');   // مسار ملف قاعدة البيانات المحلية (JSON)
define('WEBHOOK_SECRET', 'YOUR_SECRET');         // مفتاح الأمان للـ Webhook (Telegram secret_token)

// ===== RATE LIMITING =====
define('RATE_LIMIT_MAX', 20);   // الحد الأقصى للرسائل لكل دقيقة لكل مستخدم
define('RATE_LIMIT_WINDOW', 60); // نافذة الوقت بالثواني

// ===== LOG ROTATION =====
define('LOG_MAX_ENTRIES', 1000); // أقصى عدد سجلات يتم الاحتفاظ بها

// ===== ERROR HANDLING =====
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', LOG_FILE);

// ===== PHP 7.4 COMPATIBILITY POLYFILLS =====
if (!function_exists('str_contains')) {
    function str_contains(string $haystack, string $needle): bool
    {
        return $needle === '' || strpos($haystack, $needle) !== false;
    }
}
if (!function_exists('str_starts_with')) {
    function str_starts_with(string $haystack, string $needle): bool
    {
        return strpos($haystack, $needle) === 0;
    }
}

function detectPlatform(): string
{
    $queryPlatform = strtolower(trim($_GET['platform'] ?? ''));
    if ($queryPlatform === 'whatsapp') {
        return 'whatsapp';
    }
    $secretHeader = $_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? '';
    if ($secretHeader !== '' || $queryPlatform === '' || $queryPlatform === 'telegram') {
        return 'telegram';
    }
    return 'telegram';
}

function validateWebhookSecret(): bool
{
    if (WEBHOOK_SECRET === 'YOUR_SECRET' || WEBHOOK_SECRET === '') {
        return true;
    }
    $received = $_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? '';
    return hash_equals(WEBHOOK_SECRET, $received);
}

function respond200(string $body = 'OK'): void
{
    if (!headers_sent()) {
        http_response_code(200);
        header('Content-Type: text/plain; charset=UTF-8');
    }
    echo $body;
    flush();
}

function readIncomingPayload(): ?array
{
    $raw = file_get_contents('php://input');
    if (empty($raw)) {
        return null;
    }
    $data = json_decode($raw, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        logError('JSON parse error: ' . json_last_error_msg() . ' | Raw: ' . substr($raw, 0, 500));
        return null;
    }
    return $data;
}

function parseTelegramMessage(array $payload): ?array
{
    $update = $payload;
    if (isset($update['callback_query'])) {
        $cq = $update['callback_query'];
        return [
            'platform'      => 'telegram',
            'chat_id'       => (string)$cq['message']['chat']['id'],
            'user_id'       => (string)$cq['from']['id'],
            'username'      => $cq['from']['username'] ?? '',
            'first_name'    => $cq['from']['first_name'] ?? '',
            'last_name'     => $cq['from']['last_name'] ?? '',
            'text'          => $cq['data'] ?? '',
            'is_callback'   => true,
            'message_id'    => $cq['message']['message_id'] ?? 0,
            'callback_id'   => $cq['id'] ?? '',
            'raw'           => $update,
        ];
    }
    if (isset($update['message'])) {
        $msg = $update['message'];
        $chat = $msg['chat'] ?? [];
        $from = $msg['from'] ?? [];
        $text = $msg['text'] ?? '';
        if ($text === '' && isset($msg['caption'])) {
            $text = $msg['caption'];
        }
        return [
            'platform'      => 'telegram',
            'chat_id'       => (string)($chat['id'] ?? 0),
            'user_id'       => (string)($from['id'] ?? 0),
            'username'      => $from['username'] ?? '',
            'first_name'    => $from['first_name'] ?? '',
            'last_name'     => $from['last_name'] ?? '',
            'text'          => $text,
            'is_callback'   => false,
            'message_id'    => $msg['message_id'] ?? 0,
            'callback_id'   => '',
            'raw'           => $update,
        ];
    }
    if (isset($update['edited_message'])) {
        return null;
    }
    return null;
}

function parseWhatsAppMessage(array $payload): ?array
{
    $eventType = $payload['event_type'] ?? '';
    $data = $payload['data'] ?? [];
    if ($eventType !== 'message_received' && !isset($data['body'])) {
        return null;
    }
    $from = $data['from'] ?? '';
    $from = str_replace('@c.us', '', $from);
    return [
        'platform'      => 'whatsapp',
        'chat_id'       => $from,
        'user_id'       => $from,
        'username'      => $data['pushname'] ?? '',
        'first_name'    => $data['pushname'] ?? '',
        'last_name'     => '',
        'text'          => $data['body'] ?? '',
        'is_callback'   => false,
        'message_id'    => $data['id'] ?? '',
        'callback_id'   => '',
        'raw'           => $payload,
    ];
}

function parseMessage(array $payload, string $platform): ?array
{
    if ($platform === 'telegram') {
        return parseTelegramMessage($payload);
    } elseif ($platform === 'whatsapp') {
        return parseWhatsAppMessage($payload);
    }
    return null;
}

function loadDB(): array
{
    if (!file_exists(DB_FILE)) {
        $empty = ['users' => [], 'sessions' => [], 'stats' => ['total_messages' => 0, 'total_sessions' => 0, 'active_sessions' => 0, 'broadcasts' => 0, 'banned_users' => []]];
        saveDB($empty);
        return $empty;
    }
    $json = file_get_contents(DB_FILE);
    if ($json === false || empty($json)) {
        return ['users' => [], 'sessions' => [], 'stats' => ['total_messages' => 0, 'total_sessions' => 0, 'active_sessions' => 0, 'broadcasts' => 0, 'banned_users' => []]];
    }
    $data = json_decode($json, true);
    if (!is_array($data)) {
        return ['users' => [], 'sessions' => [], 'stats' => ['total_messages' => 0, 'total_sessions' => 0, 'active_sessions' => 0, 'broadcasts' => 0, 'banned_users' => []]];
    }
    if (!isset($data['stats'])) {
        $data['stats'] = ['total_messages' => 0, 'total_sessions' => 0, 'active_sessions' => 0, 'broadcasts' => 0, 'banned_users' => []];
    }
    if (!isset($data['stats']['banned_users'])) {
        $data['stats']['banned_users'] = [];
    }
    return $data;
}

function saveDB(array $db): bool
{
    $json = json_encode($db, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    if ($json === false) { return false; }
    $fp = fopen(DB_FILE, 'c');
    if ($fp === false) { return false; }
    if (flock($fp, LOCK_EX)) {
        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, $json);
        fflush($fp);
        flock($fp, LOCK_UN);
    }
    fclose($fp);
    return true;
}

function registerUser(array &$db, array $msg): array
{
    $userId = $msg['user_id'];
    $platform = $msg['platform'];
    if (!isset($db['users'][$userId])) {
        $db['users'][$userId] = [
            'user_id' => $userId, 'platform' => $platform,
            'username' => $msg['username'] ?? '', 'first_name' => $msg['first_name'] ?? '',
            'last_name' => $msg['last_name'] ?? '', 'language' => BOT_LANGUAGE,
            'notifications' => true, 'joined_at' => date('Y-m-d H:i:s'),
            'last_seen' => date('Y-m-d H:i:s'), 'message_count' => 0, 'is_banned' => false,
        ];
        $db['stats']['total_sessions']++;
    } else {
        $db['users'][$userId]['last_seen'] = date('Y-m-d H:i:s');
        $db['users'][$userId]['username'] = $msg['username'] ?? $db['users'][$userId]['username'];
        $db['users'][$userId]['first_name'] = $msg['first_name'] ?? $db['users'][$userId]['first_name'];
        $db['users'][$userId]['platform'] = $platform;
    }
    $db['users'][$userId]['message_count']++;
    $db['stats']['total_messages']++;
    return $db['users'][$userId];
}

function getSession(array &$db, string $userId): array
{
    if (!isset($db['sessions'][$userId])) {
        $db['sessions'][$userId] = ['state' => 'idle', 'data' => [], 'platform' => 'telegram', 'chat_id' => '', 'updated_at' => date('Y-m-d H:i:s')];
    }
    return $db['sessions'][$userId];
}

function setSession(array &$db, string $userId, string $state, array $data = [], array $msg = []): void
{
    $db['sessions'][$userId] = [
        'state' => $state, 'data' => $data,
        'platform' => $msg['platform'] ?? ($db['sessions'][$userId]['platform'] ?? 'telegram'),
        'chat_id' => $msg['chat_id'] ?? ($db['sessions'][$userId]['chat_id'] ?? ''),
        'updated_at' => date('Y-m-d H:i:s'),
    ];
}

function clearSession(array &$db, string $userId): void
{
    $db['sessions'][$userId] = [
        'state' => 'idle', 'data' => [],
        'platform' => $db['sessions'][$userId]['platform'] ?? 'telegram',
        'chat_id' => $db['sessions'][$userId]['chat_id'] ?? '',
        'updated_at' => date('Y-m-d H:i:s'),
    ];
}

function countActiveSessions(array $db): int
{
    $count = 0;
    $threshold = date('Y-m-d H:i:s', strtotime('-24 hours'));
    foreach ($db['sessions'] as $session) {
        if (isset($session['updated_at']) && $session['updated_at'] >= $threshold) {
            if ($session['state'] !== 'idle') { $count++; }
        }
    }
    return $count;
}

function handleCommand(array $msg, array &$db): ?string
{
    $text = trim($msg['text'] ?? '');
    $userId = $msg['user_id'];
    $platform = $msg['platform'];
    $session = getSession($db, $userId);
    if ($session['state'] !== 'idle' && $session['state'] !== '') {
        $stateResponse = handleConversationState($msg, $db, $session);
        if ($stateResponse !== null) { return $stateResponse; }
    }
    if ($text === '') {
        return getFormattedMessage($platform, '⚠️ لم أتمكن من قراءة رسالتك. يرجى إرسال نص.');
    }
    if (str_starts_with($text, '/')) { return handleSlashCommand($msg, $db); }
    if (!empty($msg['is_callback'])) { return handleCallback($msg, $db); }
    $intent = detectIntent($text);
    if ($intent !== null) { return handleIntent($intent, $msg, $db); }
    return getFormattedMessage($platform, getFallbackMessage($msg));
}

function handleSlashCommand(array $msg, array &$db): string
{
    $text = trim($msg['text']);
    $parts = explode(' ', $text, 3);
    $command = strtolower($parts[0]);
    $args = array_slice($parts, 1);
    $userId = $msg['user_id'];
    $platform = $msg['platform'];
    switch ($command) {
        case '/start': clearSession($db, $userId); return buildWelcomeMessage($msg);
        case '/help': return buildHelpMessage($platform);
        case '/about': return buildAboutMessage($platform);
        case '/settings': setSession($db, $userId, 'settings_menu', [], $msg); return buildSettingsMessage($platform);
        case '/feedback': setSession($db, $userId, 'awaiting_feedback', [], $msg); return getFormattedMessage($platform, "📝 يرجى كتابة ملاحظاتك أو اقتراحاتك وسأرسلها للمشرف.\n\nاكتب /cancel للإلغاء.");
        case '/cancel': clearSession($db, $userId); return getFormattedMessage($platform, "✅ تم إلغاء العملية الحالية.\n\n" . buildMainMenuText($platform));
        case '/menu': return getFormattedMessage($platform, buildMainMenuText($platform));
        case '/admin': if (isAdmin($userId)) { return buildAdminPanel($platform); } return getFormattedMessage($platform, "⛔ هذا الأمر مخصص للمشرفين فقط.");
        case '/stats': if (isAdmin($userId)) { return buildStatsMessage($db, $platform); } return getFormattedMessage($platform, "⛔ هذا الأمر مخصص للمشرفين فقط.");
        case '/broadcast': if (isAdmin($userId)) { setSession($db, $userId, 'awaiting_broadcast', [], $msg); return getFormattedMessage($platform, "📢 أرسل الرسالة التي تريد بثها لجميع المستخدمين:\n\nاكتب /cancel للإلغاء."); } return getFormattedMessage($platform, "⛔ هذا الأمر مخصص للمشرفين فقط.");
        case '/ban': if (isAdmin($userId)) { $targetId = trim($args[0] ?? ''); if ($targetId === '') { return getFormattedMessage($platform, "⚠️ الاستخدام: /ban [user_id]"); } return banUser($db, $targetId, $platform); } return getFormattedMessage($platform, "⛔ هذا الأمر مخصص للمشرفين فقط.");
        case '/unban': if (isAdmin($userId)) { $targetId = trim($args[0] ?? ''); if ($targetId === '') { return getFormattedMessage($platform, "⚠️ الاستخدام: /unban [user_id]"); } return unbanUser($db, $targetId, $platform); } return getFormattedMessage($platform, "⛔ هذا الأمر مخصص للمشرفين فقط.");
        case '/logs': if (isAdmin($userId)) { return buildLogsMessage($platform); } return getFormattedMessage($platform, "⛔ هذا الأمر مخصص للمشرفين فقط.");
        case '/users': if (isAdmin($userId)) { return buildUsersListMessage($db, $platform); } return getFormattedMessage($platform, "⛔ هذا الأمر مخصص للمشرفين فقط.");
        default: return getFormattedMessage($platform, "❓ أمر غير معروف: {$command}\n\nاكتب /help لعرض جميع الأوامر المتاحة.");
    }
}

function handleCallback(array $msg, array &$db): ?string
{
    $data = $msg['text'] ?? '';
    $userId = $msg['user_id'];
    $platform = $msg['platform'];
    if ($platform === 'telegram' && !empty($msg['callback_id'])) { answerCallbackQuery($msg['callback_id']); }
    $callbackMap = [
        'menu_start' => fn() => buildWelcomeMessage($msg),
        'menu_help' => fn() => buildHelpMessage($platform),
        'menu_about' => fn() => buildAboutMessage($platform),
        'menu_settings' => function() use ($db, $userId, $msg, $platform) { setSession($db, $userId, 'settings_menu', [], $msg); return buildSettingsMessage($platform); },
        'menu_feedback' => function() use ($db, $userId, $msg, $platform) { setSession($db, $userId, 'awaiting_feedback', [], $msg); return getFormattedMessage($platform, "📝 يرجى كتابة ملاحظاتك أو اقتراحاتك وسأرسلها للمشرف.\n\nاكتب /cancel للإلغاء."); },
        'menu_cancel' => function() use ($db, $userId, $platform) { clearSession($db, $userId); return getFormattedMessage($platform, "✅ تم إلغاء العملية.\n\n" . buildMainMenuText($platform)); },
        'set_lang_ar' => function() use ($db, $userId, $platform) { if (isset($db['users'][$userId])) { $db['users'][$userId]['language'] = 'ar'; } return getFormattedMessage($platform, "✅ تم تعيين اللغة إلى العربية."); },
        'set_lang_en' => function() use ($db, $userId, $platform) { if (isset($db['users'][$userId])) { $db['users'][$userId]['language'] = 'en'; } return getFormattedMessage($platform, "✅ Language set to English."); },
        'set_notif_on' => function() use ($db, $userId, $platform) { if (isset($db['users'][$userId])) { $db['users'][$userId]['notifications'] = true; } return getFormattedMessage($platform, "✅ تم تفعيل الإشعارات."); },
        'set_notif_off' => function() use ($db, $userId, $platform) { if (isset($db['users'][$userId])) { $db['users'][$userId]['notifications'] = false; } return getFormattedMessage($platform, "✅ تم إيقاف الإشعارات."); },
        'admin_panel' => fn() => isAdmin($userId) ? buildAdminPanel($platform) : getFormattedMessage($platform, "⛔ غير مصرح."),
        'admin_stats' => fn() => isAdmin($userId) ? buildStatsMessage($db, $platform) : getFormattedMessage($platform, "⛔ غير مصرح."),
        'admin_users' => fn() => isAdmin($userId) ? buildUsersListMessage($db, $platform) : getFormattedMessage($platform, "⛔ غير مصرح."),
        'admin_logs' => fn() => isAdmin($userId) ? buildLogsMessage($platform) : getFormattedMessage($platform, "⛔ غير مصرح."),
    ];
    if (isset($callbackMap[$data])) { return $callbackMap[$data](); }
    return null;
}

function handleConversationState(array $msg, array &$db, array $session): ?string
{
    $text = trim($msg['text'] ?? '');
    $userId = $msg['user_id'];
    $platform = $msg['platform'];
    if (strtolower($text) === '/cancel') { clearSession($db, $userId); return getFormattedMessage($platform, "✅ تم إلغاء العملية الحالية.\n\n" . buildMainMenuText($platform)); }
    switch ($session['state']) {
        case 'awaiting_feedback':
            if ($text !== '') {
                $userName = $msg['first_name'] ?? $msg['username'] ?? $userId;
                $feedbackMsg = "📩 *ملاحظة جديدة من مستخدم*\n\n👤 الاسم: {$userName}\n🆔 المعرف: {$userId}\n📱 المنصة: {$platform}\n💬 الرسالة: {$text}\n🕐 الوقت: " . date('Y-m-d H:i:s');
                if (ADMIN_CHAT_ID !== 'YOUR_ADMIN_ID') { sendTelegramMessage(ADMIN_CHAT_ID, $feedbackMsg, 'Markdown'); }
                logMessage("Feedback from {$userId}: {$text}");
                clearSession($db, $userId);
                return getFormattedMessage($platform, "✅ تم إرسال ملاحظاتك بنجاح! شكراً لك. 💚\n\n" . buildMainMenuText($platform));
            }
            return getFormattedMessage($platform, "⚠️ لا يمكن إرسال رسالة فارغة. اكتب ملاحظتك أو /cancel للإلغاء.");
        case 'awaiting_broadcast':
            if (!isAdmin($userId)) { clearSession($db, $userId); return getFormattedMessage($platform, "⛔ غير مصرح لك بهذا الأمر."); }
            if ($text !== '') { $sent = broadcastMessage($db, $text); $db['stats']['broadcasts']++; clearSession($db, $userId); return getFormattedMessage($platform, "📢 تم بث الرسالة إلى {$sent} مستخدم بنجاح."); }
            return getFormattedMessage($platform, "⚠️ لا يمكن بث رسالة فارغة. اكتب الرسالة أو /cancel للإلغاء.");
        case 'settings_menu':
            $choice = strtolower($text);
            if (in_array($choice, ['1', 'lang_ar', 'arabic', 'العربية'])) { if (isset($db['users'][$userId])) { $db['users'][$userId]['language'] = 'ar'; } return getFormattedMessage($platform, "✅ تم تعيين اللغة إلى العربية.\n\n" . buildSettingsMessage($platform)); }
            elseif (in_array($choice, ['2', 'lang_en', 'english', 'الإنجليزية'])) { if (isset($db['users'][$userId])) { $db['users'][$userId]['language'] = 'en'; } return getFormattedMessage($platform, "✅ Language set to English.\n\n" . buildSettingsMessage($platform)); }
            elseif (in_array($choice, ['3', 'notif_on', 'إشعارات تشغيل'])) { if (isset($db['users'][$userId])) { $db['users'][$userId]['notifications'] = true; } return getFormattedMessage($platform, "✅ تم تفعيل الإشعارات.\n\n" . buildSettingsMessage($platform)); }
            elseif (in_array($choice, ['4', 'notif_off', 'إشعارات إيقاف'])) { if (isset($db['users'][$userId])) { $db['users'][$userId]['notifications'] = false; } return getFormattedMessage($platform, "✅ تم إيقاف الإشعارات.\n\n" . buildSettingsMessage($platform)); }
            clearSession($db, $userId);
            return getFormattedMessage($platform, "✅ تم الخروج من الإعدادات.\n\n" . buildMainMenuText($platform));
        default: clearSession($db, $userId); return null;
    }
}

function buildMainMenuText(string $platform): string
{
    if ($platform === 'whatsapp') {
        return "\n📋 *القائمة الرئيسية*\n\n1️⃣ المساعدة (/help)\n2️⃣ عن البوت (/about)\n3️⃣ الإعدادات (/settings)\n4️⃣ ملاحظات (/feedback)\n5️⃣ إلغاء (/cancel)\n";
    }
    return "📋 *القائمة الرئيسية*\nاستخدم الأزرار أدناه أو اكتب أمراً.";
}

function buildMainMenuKeyboard(): array
{
    return ['inline_keyboard' => [
        [['text' => '❓ مساعدة', 'callback_data' => 'menu_help'], ['text' => 'ℹ️ عن البوت', 'callback_data' => 'menu_about']],
        [['text' => '⚙️ الإعدادات', 'callback_data' => 'menu_settings'], ['text' => '📝 ملاحظات', 'callback_data' => 'menu_feedback']],
        [['text' => '✅ إلغاء', 'callback_data' => 'menu_cancel']],
    ]];
}

function buildSettingsKeyboard(): array
{
    return ['inline_keyboard' => [
        [['text' => '🇸🇦 العربية', 'callback_data' => 'set_lang_ar'], ['text' => '🇬🇧 English', 'callback_data' => 'set_lang_en']],
        [['text' => '🔔 تفعيل الإشعارات', 'callback_data' => 'set_notif_on'], ['text' => '🔕 إيقاف الإشعارات', 'callback_data' => 'set_notif_off']],
        [['text' => '🔙 رجوع', 'callback_data' => 'menu_start']],
    ]];
}

function buildAdminKeyboard(): array
{
    return ['inline_keyboard' => [
        [['text' => '📊 الإحصائيات', 'callback_data' => 'admin_stats'], ['text' => '👥 المستخدمين', 'callback_data' => 'admin_users']],
        [['text' => '📜 السجلات', 'callback_data' => 'admin_logs'], ['text' => '📢 بث رسالة', 'callback_data' => 'menu_feedback']],
        [['text' => '🔙 رجوع للقائمة', 'callback_data' => 'menu_start']],
    ]];
}

function buildWelcomeMessage(array $msg): string
{
    $name = $msg['first_name'] ?? $msg['username'] ?? 'صديقي';
    $platform = $msg['platform'];
    $text = "🌟 *أهلاً وسهلاً بك يا {$name}!*\n\n🤖 أنا " . BOT_NAME . "، مساعدك الذكي.\nيمكنني مساعدتك في العديد من الأمور.\n\n📋 *إليك ما يمكنني فعله:*\n• الرد على أسئلتك وإرشادك\n• إدارة إعداداتك وتفضيلاتك\n• إرسال ملاحظاتك للمشرف\n• والكثير أكثر!\n\nاكتب /help لعرض جميع الأوامر أو استخدم القائمة أدناه.";
    return getFormattedMessage($platform, $text);
}

function buildHelpMessage(string $platform): string
{
    $text = "📖 *دليل الاستخدام*\n\n*الأوامر المتاحة:*\n\n🚀 /start — بدء التحدث مع البوت\n❓ /help — عرض هذا الدليل\nℹ️ /about — معلومات عن البوت\n⚙️ /settings — إعدادات المستخدم\n📝 /feedback — إرسال ملاحظات للمشرف\n✅ /cancel — إلغاء العملية الحالية\n📋 /menu — عرض القائمة الرئيسية\n\n*أوامر المشرف:*\n🔧 /admin — لوحة المشرف\n📊 /stats — إحصائيات البوت\n📢 /broadcast — بث رسالة للجميع\n🚫 /ban [id] — حظر مستخدم\n✅ /unban [id] — رفع الحظر\n📜 /logs — عرض السجلات\n👥 /users — قائمة المستخدمين\n\n*ردود ذكية:*\n👋 قل «مرحبا» أو «hello» للترحيب\n👋 قل «مع السلامة» أو «bye» للوداع\n💚 قل «شكراً» أو «thanks» للشكر";
    return getFormattedMessage($platform, $text);
}

function buildAboutMessage(string $platform): string
{
    $text = "ℹ️ *عن البوت*\n\n🤖 *الاسم:* " . BOT_NAME . "\n📱 *المنصات:* تيليغرام + واتساب\n🌐 *اللغة:* العربية / الإنجليزية\n🔧 *الإصدار:* 1.0.0\n💎 *النوع:* مساعد ذكي متعدد المنصات\n\nالبوت يعمل بنظام Webhook لاستقبال الرسائل ومعالجتها فوراً.\nجميع البيانات محفوظة بأمان.";
    return getFormattedMessage($platform, $text);
}

function buildSettingsMessage(string $platform): string
{
    if ($platform === 'whatsapp') {
        return "⚙️ *الإعدادات*\n\n1️⃣ تعيين اللغة: العربية\n2️⃣ تعيين اللغة: English\n3️⃣ تفعيل الإشعارات\n4️⃣ إيقاف الإشعارات\n\nأرسل رقم الخيار أو /cancel للإلغاء.";
    }
    return "⚙️ *الإعدادات*\n\nاختر من الأزرار أدناه:";
}

function buildAdminPanel(string $platform): string
{
    $text = "🔧 *لوحة المشرف*\n\n📊 /stats — إحصائيات البوت\n👥 /users — قائمة المستخدمين\n📢 /broadcast — بث رسالة\n🚫 /ban [id] — حظر مستخدم\n✅ /unban [id] — رفع الحظر\n📜 /logs — عرض السجلات";
    return getFormattedMessage($platform, $text);
}

function buildStatsMessage(array $db, string $platform): string
{
    $totalUsers = count($db['users']);
    $totalMessages = $db['stats']['total_messages'] ?? 0;
    $totalSessions = $db['stats']['total_sessions'] ?? 0;
    $activeSessions = countActiveSessions($db);
    $broadcasts = $db['stats']['broadcasts'] ?? 0;
    $bannedCount = count($db['stats']['banned_users'] ?? []);
    $text = "📊 *إحصائيات البوت*\n\n👥 إجمالي المستخدمين: *{$totalUsers}*\n💬 إجمالي الرسائل: *{$totalMessages}*\n🔄 إجمالي الجلسات: *{$totalSessions}*\n🟢 جلسات نشطة: *{$activeSessions}*\n📢 رسائل broadcast: *{$broadcasts}*\n🚫 مستخدمين محظورين: *{$bannedCount}*\n\n🕐 آخر تحديث: " . date('Y-m-d H:i:s');
    return getFormattedMessage($platform, $text);
}

function buildUsersListMessage(array $db, string $platform): string
{
    if (empty($db['users'])) { return getFormattedMessage($platform, "👥 لا يوجد مستخدمون مسجلون بعد."); }
    $text = "👥 *قائمة المستخدمين* (" . count($db['users']) . ")\n\n";
    $count = 0;
    foreach ($db['users'] as $user) {
        if ($count >= 50) { $text .= "... و" . (count($db['users']) - 50) . " مستخدم آخر\n"; break; }
        $name = $user['first_name'] ?? $user['username'] ?? 'غير معروف';
        $uid = $user['user_id'];
        $pf = $user['platform'] ?? '?';
        $status = ($user['is_banned'] ?? false) ? ' 🚫' : '';
        $text .= "• {$name} ({$uid}) [{$pf}]{$status}\n";
        $count++;
    }
    return getFormattedMessage($platform, $text);
}

function buildLogsMessage(string $platform): string
{
    if (!file_exists(LOG_FILE)) { return getFormattedMessage($platform, "📜 لا توجد سجلات بعد."); }
    $logs = file(LOG_FILE, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!$logs || count($logs) === 0) { return getFormattedMessage($platform, "📜 لا توجد سجلات بعد."); }
    $recent = array_slice($logs, -20);
    $text = "📜 *آخر السجلات* (آخر 20)\n\n";
    foreach ($recent as $line) { $text .= $line . "\n"; }
    if (strlen($text) > 3500) { $text = substr($text, 0, 3500) . "\n... (مقتطع)"; }
    return getFormattedMessage($platform, $text);
}

function getFallbackMessage(array $msg): string
{
    $name = $msg['first_name'] ?? 'صديقي';
    return "🤔 عذراً، لم أفهم \"$msg[text]\".\n\nيمكنك كتابة /help لعرض جميع الأوامر المتاحة.\nأو استخدم القائمة أدناه للتنقل.";
}

function getFormattedMessage(string $platform, string $text): string
{
    return $text;
}

function isAdmin(string $userId): bool
{
    if (ADMIN_CHAT_ID === 'YOUR_ADMIN_ID' || ADMIN_CHAT_ID === '') { return false; }
    return (string)$userId === (string)ADMIN_CHAT_ID;
}

function banUser(array &$db, string $targetId, string $platform): string
{
    if (isset($db['users'][$targetId])) { $db['users'][$targetId]['is_banned'] = true; }
    if (!in_array($targetId, $db['stats']['banned_users'])) { $db['stats']['banned_users'][] = $targetId; }
    logMessage("User {$targetId} banned by admin");
    return getFormattedMessage($platform, "🚫 تم حظر المستخدم {$targetId} بنجاح.");
}

function unbanUser(array &$db, string $targetId, string $platform): string
{
    if (isset($db['users'][$targetId])) { $db['users'][$targetId]['is_banned'] = false; }
    $db['stats']['banned_users'] = array_values(array_filter($db['stats']['banned_users'], fn($id) => $id !== $targetId));
    logMessage("User {$targetId} unbanned by admin");
    return getFormattedMessage($platform, "✅ تم رفع الحظر عن المستخدم {$targetId} بنجاح.");
}

function broadcastMessage(array $db, string $message): int
{
    $sent = 0;
    foreach ($db['users'] as $user) {
        if ($user['is_banned'] ?? false) { continue; }
        $chatId = $user['user_id'];
        $pf = $user['platform'] ?? 'telegram';
        $fullMsg = "📢 *رسالة من المشرف*\n\n" . $message;
        if ($pf === 'telegram') { $result = sendTelegramMessage($chatId, $fullMsg, 'Markdown'); if ($result) $sent++; }
        elseif ($pf === 'whatsapp') { $result = sendWhatsAppMessage($chatId, $fullMsg); if ($result) $sent++; }
    }
    logMessage("Broadcast sent to {$sent} users");
    return $sent;
}

function sendTelegramMessage(string $chatId, string $text, string $parseMode = 'Markdown', ?array $replyMarkup = null): bool
{
    $params = ['chat_id' => $chatId, 'text' => $text, 'parse_mode' => $parseMode];
    if ($replyMarkup !== null) { $params['reply_markup'] = json_encode($replyMarkup); }
    $url = TELEGRAM_API_URL . '/sendMessage';
    $result = httpRequest($url, 'POST', $params);
    if ($result === false) { logError("Telegram sendMessage failed for chat_id={$chatId}"); return false; }
    $decoded = json_decode($result, true);
    return ($decoded['ok'] ?? false) === true;
}

function answerCallbackQuery(string $callbackId): void
{
    $url = TELEGRAM_API_URL . '/answerCallbackQuery';
    httpRequest($url, 'POST', ['callback_query_id' => $callbackId]);
}

function sendWhatsAppMessage(string $chatId, string $text): bool
{
    if (WHATSAPP_API_KEY === 'YOUR_API_KEY_HERE' || WHATSAPP_INSTANCE_ID === 'YOUR_INSTANCE_ID') { logError("WhatsApp API not configured — cannot send to {$chatId}"); return false; }
    $url = WHATSAPP_API_URL . '/' . WHATSAPP_INSTANCE_ID . '/messages/chat';
    $params = ['token' => WHATSAPP_API_KEY, 'to' => $chatId, 'body' => $text];
    $result = httpRequest($url, 'POST', $params);
    if ($result === false) { logError("WhatsApp sendMessage failed for chat_id={$chatId}"); return false; }
    $decoded = json_decode($result, true);
    return ($decoded['sent'] ?? $decoded['ok'] ?? false) !== false;
}

function httpRequest(string $url, string $method = 'GET', array $params = [])
{
    $method = strtoupper($method);
    $queryString = http_build_query($params);
    if ($method === 'GET' && !empty($params)) { $url .= (strpos($url, '?') !== false ? '&' : '?') . $queryString; }
    if (function_exists('curl_init')) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        if ($method === 'POST') { curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, $queryString); curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/x-www-form-urlencoded']); }
        $response = curl_exec($ch);
        $error = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($response === false) { logError("cURL error: {$error} | URL: {$url}"); return false; }
        if ($httpCode >= 400) { logError("HTTP {$httpCode} from URL: {$url} | Response: " . substr((string)$response, 0, 500)); }
        return $response;
    }
    $opts = ['http' => ['method' => $method, 'timeout' => 30, 'header' => "Content-Type: application/x-www-form-urlencoded\r\n", 'ignore_errors' => true]];
    if ($method === 'POST') { $opts['http']['content'] = $queryString; }
    $context = stream_context_create($opts);
    $response = @file_get_contents($url, false, $context);
    if ($response === false) { logError("Stream request failed for URL: {$url}"); return false; }
    return $response;
}

function logMessage(string $message): void
{
    $entry = '[' . date('Y-m-d H:i:s') . '] [INFO] ' . $message;
    appendLog($entry);
}

function logError(string $message): void
{
    $entry = '[' . date('Y-m-d H:i:s') . '] [ERROR] ' . $message;
    appendLog($entry);
    error_log($message);
}

function appendLog(string $entry): void
{
    $existing = [];
    if (file_exists(LOG_FILE)) { $existing = file(LOG_FILE, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES); if ($existing === false) { $existing = []; } }
    $existing[] = $entry;
    if (count($existing) > LOG_MAX_ENTRIES) { $existing = array_slice($existing, -LOG_MAX_ENTRIES); }
    $fp = fopen(LOG_FILE, 'c');
    if ($fp === false) { return; }
    if (flock($fp, LOCK_EX)) { ftruncate($fp, 0); rewind($fp); fwrite($fp, implode("\n", $existing) . "\n"); fflush($fp); flock($fp, LOCK_UN); }
    fclose($fp);
}

function sanitizeInput(string $input, int $maxLength = 4096): string
{
    $input = trim($input);
    if (strlen($input) > $maxLength) { $input = substr($input, 0, $maxLength); }
    $input = str_replace(["\0", "\r"], '', $input);
    return $input;
}

function isUserBanned(array $db, string $userId): bool
{
    if (isset($db['users'][$userId]['is_banned']) && $db['users'][$userId]['is_banned']) { return true; }
    if (in_array($userId, $db['stats']['banned_users'] ?? [])) { return true; }
    return false;
}

function checkRateLimit(array &$db, string $userId): bool
{
    $now = time();
    if (!isset($db['sessions'][$userId]['rate_limit'])) { $db['sessions'][$userId]['rate_limit'] = []; }
    $timestamps = $db['sessions'][$userId]['rate_limit'];
    $timestamps = array_filter($timestamps, fn($t) => ($now - $t) < RATE_LIMIT_WINDOW);
    if (count($timestamps) >= RATE_LIMIT_MAX) { $db['sessions'][$userId]['rate_limit'] = array_values($timestamps); return false; }
    $timestamps[] = $now;
    $db['sessions'][$userId]['rate_limit'] = array_values($timestamps);
    return true;
}

function detectIntent(string $text): ?string
{
    $lower = strtolower($text);
    $arabicLower = $text;
    $greetings = ['مرحبا', 'مرحباً', 'مرحبتين', 'هلا', 'هاي', 'اهلا', 'أهلا', 'أهلاً', 'السلام عليكم', 'سلام', 'hi', 'hello', 'hey', 'hii', 'yo', 'good morning', 'good evening', 'صباح الخير', 'مساء الخير'];
    foreach ($greetings as $g) { if (str_contains($lower, strtolower($g)) || str_contains($arabicLower, $g)) { return 'greeting'; } }
    $farewells = ['مع السلامة', 'مع السلامه', 'باي', 'bye', 'goodbye', 'see you', 'وداعا', 'وداعاً', 'تصبح على خير', 'good night', 'لالقاء'];
    foreach ($farewells as $f) { if (str_contains($lower, strtolower($f)) || str_contains($arabicLower, $f)) { return 'farewell'; } }
    $thanks = ['شكرا', 'شكراً', 'مشكور', 'ممتاز', 'thanks', 'thank you', 'thx', 'ty', 'appreciate', 'thanks a lot', 'ممنون', 'جزاك الله'];
    foreach ($thanks as $t) { if (str_contains($lower, strtolower($t)) || str_contains($arabicLower, $t)) { return 'thanks'; } }
    $howAreYou = ['كيف حالك', 'كيفك', 'شخبارك', 'اخبارك', 'how are you', 'how r u', 'how do you do'];
    foreach ($howAreYou as $h) { if (str_contains($lower, strtolower($h)) || str_contains($arabicLower, $h)) { return 'how_are_you'; } }
    $capabilities = ['ماذا تستطيع', 'ماذا تفعل', 'وش تسوي', 'what can you do', 'what do you do', 'who are you', 'من انت', 'من أنت', 'عرف نفسك'];
    foreach ($capabilities as $c) { if (str_contains($lower, strtolower($c)) || str_contains($arabicLower, $c)) { return 'capabilities'; } }
    $love = ['احبك', 'أحبك', 'انت رائع', 'أنت رائع', 'i love you', 'you are great', 'you are awesome', 'مبدع', 'ابداع'];
    foreach ($love as $l) { if (str_contains($lower, strtolower($l)) || str_contains($arabicLower, $l)) { return 'compliment'; } }
    return null;
}

function handleIntent(string $intent, array $msg, array $db): string
{
    $platform = $msg['platform'];
    $name = $msg['first_name'] ?? $msg['username'] ?? 'صديقي';
    switch ($intent) {
        case 'greeting':
            $hour = (int)date('H');
            $greeting = $hour < 12 ? "صباح الخير" : "مساء الخير";
            return getFormattedMessage($platform, "👋 {$greeting} يا {$name}! 🌟\n\nأنا " . BOT_NAME . ". كيف يمكنني مساعدتك اليوم؟\nاكتب /help لعرض الأوامر المتاحة.");
        case 'farewell':
            return getFormattedMessage($platform, "👋 مع السلامة يا {$name}! 🌟\n\nكنت سعيداً بالتحدث معك.\nعود في أي وقت! 💚");
        case 'thanks':
            return getFormattedMessage($platform, "💚 العفو يا {$name}! سعيد بخدمتك دائماً.\n\nهل تحتاج مساعدة في شيء آخر؟");
        case 'how_are_you':
            return getFormattedMessage($platform, "😊 أنا بخير والحمد لله! 🤖\n\nأعمل بكفاءة عالية وجاهز لمساعدتك.\nكيف حالك أنت يا {$name}؟");
        case 'capabilities':
            return getFormattedMessage($platform, "🤖 أنا " . BOT_NAME . "!\n\n*إليك ما أستطيع فعله:*\n• الرد على رسائلك بذكاء\n• توفير قوائم تفاعلية للتنقل\n• إدارة إعداداتك وتفضيلاتك\n• استقبال ملاحظاتك وإرسالها للمشرف\n• العمل على تيليغرام وواتساب معاً\n\nاكتب /help لعرض جميع الأوامر.");
        case 'compliment':
            return getFormattedMessage($platform, "😊 شكراً جزيلاً يا {$name}! 🌟\n\nممتن لكلماتك الطيبة. أنا هنا دائماً لمساعدتك! 💚");
        default:
            return getFormattedMessage($platform, getFallbackMessage($msg));
    }
}

function main(): void
{
    respond200('OK');
    if (!validateWebhookSecret()) { logError('Webhook secret validation failed'); return; }
    $payload = readIncomingPayload();
    if ($payload === null) { return; }
    $platform = detectPlatform();
    logMessage("Incoming webhook from platform: {$platform}");
    $msg = parseMessage($payload, $platform);
    if ($msg === null) { logMessage("No parseable message found in payload"); return; }
    $msg['text'] = sanitizeInput($msg['text'] ?? '');
    $userId = $msg['user_id'];
    $chatId = $msg['chat_id'];
    $platform = $msg['platform'];
    if (empty($userId) || $userId === '0') { logError("Invalid user ID in message"); return; }
    $db = loadDB();
    if (isUserBanned($db, $userId)) {
        logMessage("Banned user {$userId} attempted to send message");
        if ($platform === 'telegram') { sendTelegramMessage($chatId, "🚫 تم حظرك من استخدام هذا البوت. للاستفسار، تواصل مع المشرف."); }
        elseif ($platform === 'whatsapp') { sendWhatsAppMessage($chatId, "🚫 تم حظرك من استخدام هذا البوت. للاستعلام، تواصل مع المشرف."); }
        saveDB($db); return;
    }
    if (!checkRateLimit($db, $userId)) {
        logMessage("Rate limit exceeded for user {$userId}");
        if ($platform === 'telegram') { sendTelegramMessage($chatId, "⚠️ لقد أرسلت رسائل كثيرة جداً. يرجى الانتظار دقيقة قبل المحاولة مرة أخرى."); }
        elseif ($platform === 'whatsapp') { sendWhatsAppMessage($chatId, "⚠️ لقد أرسلت رسائل كثيرة جداً. يرجى الانتظار دقيقة قبل المحاولة مرة أخرى."); }
        saveDB($db); return;
    }
    registerUser($db, $msg);
    $displayName = $msg['first_name'] ?? $msg['username'] ?? $userId;
    $textPreview = substr($msg['text'] ?? '', 0, 100);
    logMessage("Message from {$displayName} ({$userId}) [{$platform}]: {$textPreview}");
    try {
        $response = handleCommand($msg, $db);
        if ($response !== null) {
            if ($platform === 'telegram') {
                $keyboard = null;
                if (str_contains($msg['text'] ?? '', '/start') || str_contains($msg['text'] ?? '', '/menu') || !empty($msg['is_callback'])) {
                    if (str_contains($response, 'الإعدادات') && str_contains($response, 'اختر من الأزرار')) { $keyboard = buildSettingsKeyboard(); }
                    elseif (str_contains($response, 'لوحة المشرف')) { $keyboard = buildAdminKeyboard(); }
                    else { $keyboard = buildMainMenuKeyboard(); }
                }
                if (str_contains($msg['text'] ?? '', '/settings')) { $keyboard = buildSettingsKeyboard(); }
                if (str_contains($msg['text'] ?? '', '/admin') && isAdmin($userId)) { $keyboard = buildAdminKeyboard(); }
                if (str_contains($response, 'لم أفهم') || str_contains($response, 'لم أتمكن')) { $keyboard = buildMainMenuKeyboard(); }
                if (str_contains($response, 'أهلاً وسهلاً')) { $keyboard = buildMainMenuKeyboard(); }
                if (str_contains($response, 'القائمة الرئيسية')) { $keyboard = buildMainMenuKeyboard(); }
                sendTelegramMessage($chatId, $response, 'Markdown', $keyboard);
            } elseif ($platform === 'whatsapp') {
                sendWhatsAppMessage($chatId, $response);
            }
        }
    } catch (Throwable $e) {
        logError("Exception in handleCommand: " . $e->getMessage() . " | File: " . $e->getFile() . ":" . $e->getLine());
        if ($platform === 'telegram') { sendTelegramMessage($chatId, "⚠️ حدث خطأ أثناء معالجة طلبك. يرجى المحاولة مرة أخرى أو كتابة /start."); }
        elseif ($platform === 'whatsapp') { sendWhatsAppMessage($chatId, "⚠️ حدث خطأ أثناء معالجة طلبك. يرجى المحاولة مرة أخرى."); }
    }
    saveDB($db);
}

main();
