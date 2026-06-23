<?php
declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

date_default_timezone_set('Asia/Manila');

require_once __DIR__ . '/telegram-sender.php';

const CREDENTIALS_PATH = __DIR__ . '/BigQueryCredentials.JSON';
const QUERY_PATH = __DIR__ . '/query.sql';
const CA_BUNDLE_PATH = __DIR__ . '/cacert.pem';
const BIGQUERY_SCOPE = 'https://www.googleapis.com/auth/bigquery';
const WKHTMLTOIMAGE_PATH = 'C:\Program Files\wkhtmltopdf\bin\wkhtmltoimage.exe';
const IMAGES_DIR = __DIR__ . '/images';
const TELEGRAM_CHUNK_ROW_COUNT = 40;

const MODE_REGULAR = 'regular';
const MODE_OUT = 'out';

const DIRECTION_FILTER_SENTINEL = '/*__DIRECTION_FILTER__*/';

const DIRECTION_FILTER_REGULAR = <<<'SQL'
AND (
    dm.Direction = 'IN'
    OR dm.Direction = 'OUT'
    OR (dm.Direction = 'IN_OUT' AND t.TK_AtL_LogType = 0)
    OR (dm.Direction IS NULL AND t.TK_AtL_LogType = 0)
  )
SQL;

const DIRECTION_FILTER_OUT = <<<'SQL'
AND (
    dm.Direction = 'IN_OUT'
    OR dm.Direction = 'OUT'
  )
  AND t.TK_AtL_LogType = 1
SQL;

function saveHtmlAsImage(string $html, string $outputPath): bool
{
    $tempHtmlFile = tempnam(sys_get_temp_dir(), 'report_') . '.html';
    if (file_put_contents($tempHtmlFile, $html) === false) {
        return false;
    }

    $command = sprintf(
        '%s --format jpg --quality 85 %s %s 2>&1',
        escapeshellarg(WKHTMLTOIMAGE_PATH),
        escapeshellarg($tempHtmlFile),
        escapeshellarg($outputPath)
    );

    exec($command, $output, $exitCode);
    unlink($tempHtmlFile);

    if ($exitCode !== 0) {
        error_log('wkhtmltoimage failed: ' . implode("\n", $output));
        return false;
    }

    return is_file($outputPath);
}

function cliFail(string $message): never
{
    fwrite(STDERR, "$message\n");
    exit(1);
}

function parseTimeOfDay(string $value, string $flagName): array
{
    if (!preg_match('/^([0-9]{1,2}):([0-9]{2})$/', $value, $matches)) {
        cliFail("Invalid --$flagName value \"$value\": expected HH:MM (24-hour).");
    }

    $hour = (int) $matches[1];
    $minute = (int) $matches[2];
    if ($hour > 23 || $minute > 59) {
        cliFail("Invalid --$flagName value \"$value\": expected HH:MM (24-hour).");
    }

    return [$hour, $minute];
}

function parseCliOptions(): ?array
{
    if (PHP_SAPI !== 'cli') {
        return null;
    }

    $opts = getopt('', ['start:', 'end::', 'mode::']);

    if (!isset($opts['start']) || $opts['start'] === '') {
        cliFail('Missing required --start=HH:MM argument.');
    }

    $mode = $opts['mode'] ?? MODE_REGULAR;
    if ($mode !== MODE_REGULAR && $mode !== MODE_OUT) {
        cliFail("Invalid --mode value \"$mode\": expected \"regular\" or \"out\".");
    }

    $end = $opts['end'] ?? 'now';
    if ($end !== 'now') {
        parseTimeOfDay($end, 'end');
    }

    parseTimeOfDay($opts['start'], 'start');

    return ['start' => $opts['start'], 'end' => $end, 'mode' => $mode];
}

function resolveStartOfDay(DateTime $reference, string $hhmm): DateTime
{
    [$hour, $minute] = parseTimeOfDay($hhmm, 'start');
    $result = clone $reference;
    $result->setTime($hour, $minute, 0);
    return $result;
}

function resolveReportWindow(?array $cliOptions, DateTime $now): array
{
    if ($cliOptions !== null) {
        $start = resolveStartOfDay($now, $cliOptions['start']);
        $end = $cliOptions['end'] === 'now' ? clone $now : resolveStartOfDay($now, $cliOptions['end']);
        return [$start, $end, $cliOptions['mode'], true];
    }

    $hour = (int) $now->format('H');
    if ($hour >= 7 && $hour < 19) {
        $start = resolveStartOfDay($now, '07:00');
    } else {
        $start = resolveStartOfDay($now, '19:00');
        if ($hour < 7) {
            $start->modify('-1 day');
        }
    }

    return [$start, clone $now, MODE_REGULAR, false];
}

function applyDirectionFilter(string $sql, string $mode): string
{
    if (!str_contains($sql, DIRECTION_FILTER_SENTINEL)) {
        throw new RuntimeException('query.sql is missing the direction filter sentinel.');
    }

    $fragment = match ($mode) {
        MODE_REGULAR => DIRECTION_FILTER_REGULAR,
        MODE_OUT => DIRECTION_FILTER_OUT,
        default => throw new InvalidArgumentException("Unknown mode: $mode"),
    };

    return str_replace(DIRECTION_FILTER_SENTINEL, $fragment, $sql);
}

function bigQueryDatetimeParam(string $name, string $value): array
{
    return [
        'name' => $name,
        'parameterType' => ['type' => 'DATETIME'],
        'parameterValue' => ['value' => $value],
    ];
}

function base64UrlEncode(string $data): string
{
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function getAccessToken(array $credentials): string
{
    $now = time();
    $headerB64 = base64UrlEncode(json_encode(['alg' => 'RS256', 'typ' => 'JWT'], JSON_THROW_ON_ERROR));
    $claimsB64 = base64UrlEncode(json_encode([
        'iss' => $credentials['client_email'],
        'scope' => BIGQUERY_SCOPE,
        'aud' => $credentials['token_uri'],
        'iat' => $now,
        'exp' => $now + 3600,
    ], JSON_THROW_ON_ERROR));

    $signingInput = "$headerB64.$claimsB64";

    $privateKey = openssl_pkey_get_private($credentials['private_key']);
    if ($privateKey === false) {
        throw new RuntimeException('Unable to load private key from credentials file.');
    }

    $signature = '';
    if (!openssl_sign($signingInput, $signature, $privateKey, OPENSSL_ALGO_SHA256)) {
        throw new RuntimeException('Failed to sign JWT for BigQuery authentication.');
    }

    $jwt = $signingInput . '.' . base64UrlEncode($signature);

    $ch = curl_init($credentials['token_uri']);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query([
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion' => $jwt,
        ]),
        CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
        CURLOPT_TIMEOUT => 30,
        CURLOPT_CAINFO => CA_BUNDLE_PATH,
    ]);
    $body = curl_exec($ch);
    if ($body === false) {
        $error = curl_error($ch);
        throw new RuntimeException("Token request failed: $error");
    }

    $data = json_decode($body, true);
    if (!is_array($data) || !isset($data['access_token'])) {
        $message = $data['error_description'] ?? $data['error'] ?? 'unknown error';
        throw new RuntimeException("Failed to obtain access token: $message");
    }

    return $data['access_token'];
}

function bigQueryApiRequest(string $url, string $accessToken, ?array $jsonBody = null): array
{
    $ch = curl_init($url);
    $headers = ['Authorization: Bearer ' . $accessToken];
    $options = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_CAINFO => CA_BUNDLE_PATH,
    ];
    if ($jsonBody !== null) {
        $headers[] = 'Content-Type: application/json';
        $options[CURLOPT_POST] = true;
        $options[CURLOPT_POSTFIELDS] = json_encode($jsonBody, JSON_THROW_ON_ERROR);
    }
    $options[CURLOPT_HTTPHEADER] = $headers;
    curl_setopt_array($ch, $options);

    $body = curl_exec($ch);
    if ($body === false) {
        $error = curl_error($ch);
        throw new RuntimeException("BigQuery request failed: $error");
    }

    $decoded = json_decode($body, true);
    if (!is_array($decoded)) {
        throw new RuntimeException('BigQuery returned an unexpected response.');
    }
    if (isset($decoded['error'])) {
        $message = is_array($decoded['error']) ? ($decoded['error']['message'] ?? 'unknown error') : $decoded['error'];
        throw new RuntimeException("BigQuery API error: $message");
    }

    return $decoded;
}

function runBigQueryQuery(string $accessToken, string $projectId, string $sql, array $queryParameters = []): array
{
    $base = 'https://bigquery.googleapis.com/bigquery/v2/projects/' . rawurlencode($projectId);

    $requestBody = [
        'query' => $sql,
        'useLegacySql' => false,
        'timeoutMs' => 30000,
    ];
    if ($queryParameters !== []) {
        $requestBody['parameterMode'] = 'NAMED';
        $requestBody['queryParameters'] = $queryParameters;
    }

    $response = bigQueryApiRequest("$base/queries", $accessToken, $requestBody);

    $jobId = $response['jobReference']['jobId'] ?? null;
    $location = $response['jobReference']['location'] ?? null;
    if ($jobId === null) {
        throw new RuntimeException('BigQuery did not return a job reference.');
    }

    $attempts = 0;
    while (empty($response['jobComplete'])) {
        if (++$attempts > 30) {
            throw new RuntimeException('Timed out waiting for the BigQuery job to complete.');
        }
        sleep(1);
        $params = ['timeoutMs' => 30000];
        if ($location !== null) {
            $params['location'] = $location;
        }
        $response = bigQueryApiRequest("$base/queries/" . rawurlencode($jobId) . '?' . http_build_query($params), $accessToken);
    }

    $fieldNames = array_map(
        static fn(array $field): string => $field['name'],
        $response['schema']['fields'] ?? []
    );

    $rows = $response['rows'] ?? [];
    $pageToken = $response['pageToken'] ?? null;

    while ($pageToken !== null) {
        $params = ['pageToken' => $pageToken];
        if ($location !== null) {
            $params['location'] = $location;
        }
        $page = bigQueryApiRequest("$base/queries/" . rawurlencode($jobId) . '?' . http_build_query($params), $accessToken);
        $rows = array_merge($rows, $page['rows'] ?? []);
        $pageToken = $page['pageToken'] ?? null;
    }

    $tableRows = [];
    foreach ($rows as $row) {
        $values = [];
        foreach ($row['f'] as $index => $cell) {
            $values[$fieldNames[$index]] = $cell['v'];
        }
        $tableRows[] = $values;
    }

    return ['fields' => $fieldNames, 'rows' => $tableRows];
}

function renderReportHtml(
    array $fields,
    array $rows,
    DateTime $now,
    DateTime $windowStart,
    DateTime $windowEnd,
    string $mode,
    ?string $errorMessage,
    ?int $partNumber = null,
    ?int $totalParts = null
): string {
    ob_start();
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Attendance Report</title>
<style>
    body { font-family: Arial, Helvetica, sans-serif; margin: 2rem; color: #222; }
    h1 { font-size: 1.4rem; }
    .meta { color: #666; margin-bottom: 1rem; font-size: 0.9rem; }
    table { border-collapse: collapse; width: 100%; }
    th, td { border: 1px solid #ccc; padding: 6px 10px; text-align: left; font-size: 0.9rem; }
    th { background: #2c3e50; color: #fff; position: sticky; top: 0; }
    tr:nth-child(even) { background: #f5f5f5; }
    .error { color: #b00020; font-weight: bold; }
    .empty { color: #666; font-style: italic; }
</style>
</head>
<body>
<h1>Today's Attendance Report</h1>
<div class="meta">Generated at <?= htmlspecialchars($now->format('Y-m-d H:i:s')) ?></div>
<div class="meta">Window: <?= htmlspecialchars($windowStart->format('Y-m-d H:i:s')) ?> &ndash; <?= htmlspecialchars($windowEnd->format('Y-m-d H:i:s')) ?> (<?= htmlspecialchars($mode) ?>)</div>
<?php if ($totalParts !== null && $totalParts > 1): ?>
<div class="meta">Part <?= $partNumber ?> of <?= $totalParts ?></div>
<?php endif; ?>

<?php if ($errorMessage !== null): ?>
    <p class="error"><?= htmlspecialchars($errorMessage) ?></p>
<?php elseif (empty($rows)): ?>
    <p class="empty">No data returned for today.</p>
<?php else: ?>
    <table>
        <thead>
            <tr>
                <?php foreach ($fields as $field): ?>
                    <th><?= htmlspecialchars($field) ?></th>
                <?php endforeach; ?>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($rows as $row): ?>
                <tr>
                    <?php foreach ($fields as $field): ?>
                        <td><?= htmlspecialchars((string) ($row[$field] ?? '')) ?></td>
                    <?php endforeach; ?>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <div class="meta"><?= count($rows) ?> row(s)</div>
<?php endif; ?>

</body>
</html>
    <?php
    return ob_get_clean();
}

$now = new DateTime('now');
$cliOptions = parseCliOptions();
[$windowStart, $windowEnd, $mode, $isAutomated] = resolveReportWindow($cliOptions, $now);

$result = null;
$errorMessage = null;

try {
    $credentialsJson = file_get_contents(CREDENTIALS_PATH);
    if ($credentialsJson === false) {
        throw new RuntimeException('Unable to read BigQuery credentials file.');
    }
    $credentials = json_decode($credentialsJson, true, 512, JSON_THROW_ON_ERROR);

    $sql = file_get_contents(QUERY_PATH);
    if ($sql === false) {
        throw new RuntimeException('Unable to read query.sql.');
    }
    $sql = applyDirectionFilter($sql, $mode);

    $queryParameters = [
        bigQueryDatetimeParam('start_time', $windowStart->format('Y-m-d H:i:s')),
        bigQueryDatetimeParam('end_time', $windowEnd->format('Y-m-d H:i:s')),
    ];

    $accessToken = getAccessToken($credentials);
    $result = runBigQueryQuery($accessToken, $credentials['project_id'], $sql, $queryParameters);
} catch (Throwable $e) {
    error_log('BigQuery report error: ' . $e->getMessage());
    $errorMessage = 'Unable to load the report. Please check the server error log for details.';
}

$pageHtml = renderReportHtml($result['fields'] ?? [], $result['rows'] ?? [], $now, $windowStart, $windowEnd, $mode, $errorMessage);

if ($isAutomated) {
    if (!is_dir(IMAGES_DIR)) {
        mkdir(IMAGES_DIR, 0775, true);
    }
    $modeLabel = $mode === MODE_OUT ? '_out' : '';
    $imagePath = IMAGES_DIR . '/attendance_report' . $modeLabel . '_' . $now->format('Ymd_His') . '.jpg';

    // Archival save - unchanged behavior, independent of Telegram chunking below.
    saveHtmlAsImage($pageHtml, $imagePath);

    $description = ($mode === MODE_OUT ? 'Out-Only ' : '') . 'Attendance Report: '
        . $windowStart->format('Y-m-d H:i:s') . ' to ' . $windowEnd->format('Y-m-d H:i:s');

    $rowChunks = empty($result['rows']) ? [[]] : array_chunk($result['rows'], TELEGRAM_CHUNK_ROW_COUNT);
    $totalChunks = count($rowChunks);

    $chunkImagePaths = [];
    foreach ($rowChunks as $i => $chunkRows) {
        $chunkHtml = renderReportHtml($result['fields'] ?? [], $chunkRows, $now, $windowStart, $windowEnd, $mode, $errorMessage, $i + 1, $totalChunks);
        $chunkImagePath = tempnam(sys_get_temp_dir(), 'tg_chunk_') . '.jpg';
        if (saveHtmlAsImage($chunkHtml, $chunkImagePath)) {
            $chunkImagePaths[] = $chunkImagePath;
        } else {
            error_log("Failed to render Telegram chunk image (part " . ($i + 1) . "/$totalChunks).");
            @unlink($chunkImagePath);
        }
    }

    if ($chunkImagePaths !== []) {
        $telegramResult = sendTelegramPhotoAlbum($chunkImagePaths, $description);
        if (!$telegramResult['success']) {
            error_log('Telegram album send failed: ' . $telegramResult['error']);
        }
    } else {
        error_log('Telegram album send skipped: no chunk images were successfully rendered.');
    }

    foreach ($chunkImagePaths as $path) {
        @unlink($path);
    }
}

echo $pageHtml;
