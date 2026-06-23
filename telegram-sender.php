<?php
declare(strict_types=1);

// ----------------------
// Configuration
// ----------------------
const TELEGRAM_BOT_TOKEN = '8286346056:AAFyzbWH5i_jrPOL8iGFaFZMViDYea7jolY';
const TELEGRAM_CHAT_ID = '-5403710684'; // Channel chat id
// const TELEGRAM_CHAT_ID = '-5248688686'; // TEST Channel chat id
const TELEGRAM_CA_BUNDLE_PATH = __DIR__ . '/cacert.pem';

// ----------------------
// Send an image (photo) to Telegram
// ----------------------
function sendTelegramImage(string $filePath, ?string $description = null): array
{
    $url = "https://api.telegram.org/bot" . TELEGRAM_BOT_TOKEN . "/sendPhoto";

    if (!file_exists($filePath)) {
        return ['success' => false, 'error' => "File does not exist: $filePath"];
    }

    if (!is_readable($filePath)) {
        return ['success' => false, 'error' => "File is not readable: $filePath"];
    }

    // Parse timestamp from filename (e.g. attendance_report_out_YYYYMMDD_HHMMSS.jpg)
    preg_match('/report(?:_out)?_(\d{8})_(\d{6})/', basename($filePath), $matches);
    $timestamp = '';
    if (isset($matches[1], $matches[2])) {
        $date = substr($matches[1], 0, 4) . '-' . substr($matches[1], 4, 2) . '-' . substr($matches[1], 6, 2);
        $time = substr($matches[2], 0, 2) . ':' . substr($matches[2], 2, 2) . ':' . substr($matches[2], 4, 2);
        $timestamp = "\n📅 {$date} {$time}";
    }

    $title = !empty($description) ? htmlspecialchars($description, ENT_XML1) : 'Attendance Report';
    $caption = "📊 <b>{$title}</b>{$timestamp}";

    $postFields = [
        'chat_id' => TELEGRAM_CHAT_ID,
        'photo' => new CURLFile($filePath),
        'caption' => $caption,
        'parse_mode' => 'HTML',
    ];

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type:multipart/form-data']);
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $postFields);
    curl_setopt($ch, CURLOPT_CAINFO, TELEGRAM_CA_BUNDLE_PATH);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);

    if ($httpCode === 200) {
        return ['success' => true];
    }

    error_log("Telegram sendPhoto failed: HTTP $httpCode - cURL: $curlError - Response: " . substr((string) $response, 0, 500));
    return ['success' => false, 'error' => "HTTP $httpCode - cURL: $curlError"];
}

// ----------------------
// Send multiple images to Telegram as one album (2-10 photos per call)
// ----------------------
function sendTelegramMediaGroup(array $filePaths, ?string $caption = null): array
{
    if ($filePaths === []) {
        return ['success' => false, 'error' => 'No files provided.'];
    }

    $url = "https://api.telegram.org/bot" . TELEGRAM_BOT_TOKEN . "/sendMediaGroup";

    $media = [];
    $postFields = ['chat_id' => TELEGRAM_CHAT_ID];

    foreach ($filePaths as $index => $filePath) {
        if (!file_exists($filePath) || !is_readable($filePath)) {
            return ['success' => false, 'error' => "File missing or unreadable: $filePath"];
        }

        $attachName = "photo$index";
        $item = ['type' => 'photo', 'media' => "attach://$attachName"];
        if ($index === 0 && $caption !== null) {
            $item['caption'] = htmlspecialchars($caption, ENT_XML1);
            $item['parse_mode'] = 'HTML';
        }
        $media[] = $item;
        $postFields[$attachName] = new CURLFile($filePath);
    }

    $postFields['media'] = json_encode($media, JSON_THROW_ON_ERROR);

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type:multipart/form-data']);
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $postFields);
    curl_setopt($ch, CURLOPT_CAINFO, TELEGRAM_CA_BUNDLE_PATH);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);

    if ($httpCode === 200) {
        return ['success' => true];
    }

    error_log("Telegram sendMediaGroup failed: HTTP $httpCode - cURL: $curlError - Response: " . substr((string) $response, 0, 500));
    return ['success' => false, 'error' => "HTTP $httpCode - cURL: $curlError"];
}

// ----------------------
// Send a list of images as a Telegram album, batching into groups of <=10
// and falling back to a single photo when there's only one image
// ----------------------
function sendTelegramPhotoAlbum(array $filePaths, ?string $caption = null): array
{
    $count = count($filePaths);

    if ($count === 0) {
        return ['success' => false, 'error' => 'No files provided.'];
    }

    if ($count === 1) {
        return sendTelegramImage($filePaths[0], $caption);
    }

    $errors = [];
    foreach (array_chunk($filePaths, 10) as $batchIndex => $batch) {
        $batchCaption = $batchIndex === 0 ? $caption : null;
        $result = sendTelegramMediaGroup($batch, $batchCaption);
        if (!$result['success']) {
            $errors[] = $result['error'];
        }
    }

    return [
        'success' => $errors === [],
        'error' => $errors === [] ? null : implode(' | ', $errors),
    ];
}

// ----------------------
// Send an Excel/CSV document to Telegram
// ----------------------
function sendTelegramDocument(string $filePath, ?string $description = null, ?string $mimeType = null): array
{
    $url = "https://api.telegram.org/bot" . TELEGRAM_BOT_TOKEN . "/sendDocument";

    if (!file_exists($filePath)) {
        return ['success' => false, 'error' => "File does not exist: $filePath"];
    }

    if (!is_readable($filePath)) {
        return ['success' => false, 'error' => "File is not readable: $filePath"];
    }

    if ($mimeType === null) {
        $mimeType = match (strtolower(pathinfo($filePath, PATHINFO_EXTENSION))) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'csv' => 'text/csv',
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            default => 'application/octet-stream',
        };
    }

    $originalFilename = basename($filePath);
    $caption = $description ?? "📄 Report";
    $caption = "📄 <b>{$caption}</b>\n📁 <code>{$originalFilename}</code>";

    $postFields = [
        'chat_id' => TELEGRAM_CHAT_ID,
        'document' => new CURLFile($filePath, $mimeType, $originalFilename),
        'caption' => $caption,
        'parse_mode' => 'HTML',
    ];

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type:multipart/form-data']);
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $postFields);
    curl_setopt($ch, CURLOPT_CAINFO, TELEGRAM_CA_BUNDLE_PATH);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);

    if ($httpCode === 200) {
        return ['success' => true];
    }

    error_log("Telegram sendDocument failed: HTTP $httpCode - cURL: $curlError - Response: " . substr((string) $response, 0, 500));
    return ['success' => false, 'error' => "HTTP $httpCode - cURL: $curlError"];
}
