<?php
/**
 * Gestión de Pagos - Telegram Notification Script
 *
 * Run via cron every minute:
 * * * * * www-data php /opt/gestion-pagos/api/telegram_notify.php >> /opt/gestion-pagos/data/telegram_cron.log 2>&1
 *
 * Checks if any enabled notification matches the current day/time (±2 min window)
 * and sends it via the Telegram Bot API. Prevents duplicate sends per day.
 */

date_default_timezone_set('Europe/Madrid');

$telegramFile = __DIR__ . '/../data/telegram.json';
$sentFile     = __DIR__ . '/../data/telegram_sent.json';

// ── Load config ───────────────────────────────────────────────────────────────
if (!file_exists($telegramFile)) {
    echo date('Y-m-d H:i:s') . " [INFO] No telegram.json found — nothing to do.\n";
    exit(0);
}

$config = json_decode(file_get_contents($telegramFile), true);

if (empty($config['token']) || empty($config['chat_id'])) {
    echo date('Y-m-d H:i:s') . " [INFO] Missing token or chat_id — skipping.\n";
    exit(0);
}

$notifications = $config['notifications'] ?? [];
if (empty($notifications)) {
    echo date('Y-m-d H:i:s') . " [INFO] No notifications configured.\n";
    exit(0);
}

// ── Load sent log ─────────────────────────────────────────────────────────────
$sent = [];
if (file_exists($sentFile)) {
    $sent = json_decode(file_get_contents($sentFile), true) ?: [];
}

// ── Current time ──────────────────────────────────────────────────────────────
$now     = new DateTime('now');
$today   = (int)$now->format('N'); // 1=Mon … 7=Sun
$dateKey = $now->format('Y-m-d');

$changed = false;

// ── Check each notification ───────────────────────────────────────────────────
foreach ($notifications as $notif) {
    if (empty($notif['enabled'])) continue;
    if (empty($notif['id']))      continue;

    // Day match
    $days = array_map('intval', $notif['days'] ?? []);
    if (!in_array($today, $days, true)) continue;

    // Time match: allow ±2 minutes
    $notifTime = $notif['time'] ?? '09:00';
    [$hh, $mm] = explode(':', $notifTime);
    $target = (clone $now)->setTime((int)$hh, (int)$mm, 0);
    $diffMin = abs(($now->getTimestamp() - $target->getTimestamp()) / 60);
    if ($diffMin > 2) continue;

    // Duplicate check
    $sentKey = $notif['id'] . '_' . $dateKey;
    if (isset($sent[$sentKey])) {
        echo date('Y-m-d H:i:s') . " [SKIP] Already sent today: {$notif['id']}\n";
        continue;
    }

    // Send
    $message = $notif['message'] ?? '📋 Recordatorio de Gestión de Pagos';
    $result  = sendTelegramMessage($config['token'], $config['chat_id'], $message);

    if (!empty($result['ok'])) {
        $sent[$sentKey] = date('Y-m-d H:i:s');
        $changed = true;
        echo date('Y-m-d H:i:s') . " [SENT] {$notif['id']} — {$message}\n";
    } else {
        $err = $result['description'] ?? json_encode($result);
        echo date('Y-m-d H:i:s') . " [ERROR] {$notif['id']} — {$err}\n";
    }
}

// ── Purge sent entries older than 60 days ────────────────────────────────────
$cutoff = date('Y-m-d', strtotime('-60 days'));
foreach (array_keys($sent) as $key) {
    // Key format: {uuid}_{YYYY-MM-DD}
    $parts   = explode('_', $key);
    $keyDate = end($parts);
    if ($keyDate < $cutoff) {
        unset($sent[$key]);
        $changed = true;
    }
}

if ($changed) {
    file_put_contents($sentFile, json_encode($sent, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

echo date('Y-m-d H:i:s') . " [DONE]\n";

// ── Helper ────────────────────────────────────────────────────────────────────
/**
 * Send a message via the Telegram Bot API.
 *
 * @param  string $token  Bot token
 * @param  string $chatId Chat or group ID
 * @param  string $text   Message text (supports basic HTML)
 * @return array          Decoded API response
 */
function sendTelegramMessage(string $token, string $chatId, string $text): array
{
    $url     = "https://api.telegram.org/bot{$token}/sendMessage";
    $payload = json_encode([
        'chat_id'    => $chatId,
        'text'       => $text,
        'parse_mode' => 'HTML',
    ]);

    // Method 1: cURL
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
        ]);
        $resp    = curl_exec($ch);
        $curlErr = curl_error($ch);
        curl_close($ch);
        if ($resp === false) return ['ok' => false, 'description' => "curl: {$curlErr}"];
        return json_decode($resp, true) ?? ['ok' => false, 'description' => 'Invalid JSON'];
    }

    // Method 2: file_get_contents fallback
    if (ini_get('allow_url_fopen')) {
        $ctx  = stream_context_create([
            'http' => [
                'method'        => 'POST',
                'header'        => "Content-Type: application/json\r\n",
                'content'       => $payload,
                'timeout'       => 10,
                'ignore_errors' => true,
            ],
            'ssl' => ['verify_peer' => false, 'verify_peer_name' => false],
        ]);
        $resp = @file_get_contents($url, false, $ctx);
        if ($resp === false) return ['ok' => false, 'description' => 'file_get_contents failed'];
        return json_decode($resp, true) ?? ['ok' => false, 'description' => 'Invalid JSON'];
    }

    return ['ok' => false, 'description' => 'cURL not available. Install: apt-get install php8.2-curl'];
}
