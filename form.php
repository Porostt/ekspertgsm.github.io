<?php
/**
 * FixPoint Rajcza — form.php
 * Obsługuje wysyłkę formularza wyceny naprawy.
 *
 * Wymagania serwera: PHP 7.4+, funkcja mail()
 * Wgraj ten plik obok index.html na hosting (nazwa.pl, home.pl itp.)
 * Ustaw zmienną $TO_EMAIL na adres e-mail właściciela serwisu.
 */

declare(strict_types=1);

/* ─── KONFIGURACJA ─── */
const TO_EMAIL    = 'WSTAW_SWOJ_EMAIL@gmail.com'; // <- wpisz tu swój adres e-mail
const SITE_NAME   = 'EKSPERT GSM Rajcza';
const RATE_LIMIT  = 3;        // maks. zgłoszeń z jednego IP na godzinę
const LOCK_FILE   = '/tmp/fixpoint_rate_'; // prefix ścieżki do pliku rate-limit

/* ─── NAGŁÓWKI ─── */
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *'); // dostosuj do swojej domeny w produkcji
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// Tylko POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(405, 'Metoda niedozwolona.');
}

/* ─── HONEYPOT + RATE LIMIT ─── */
$ip = preg_replace('/[^0-9a-fA-F:.]/', '', $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
checkRateLimit($ip);

/* ─── ODCZYT I WALIDACJA ─── */
$raw  = file_get_contents('php://input');
$data = json_decode($raw, true);

if (!is_array($data)) {
    respond(400, 'Nieprawidłowy format danych.');
}

$name   = sanitize($data['name']   ?? '');
$device = sanitize($data['device'] ?? '');
$issue  = sanitize($data['issue']  ?? '');
$phone  = sanitize($data['phone']  ?? '');

// Honeypot — jeśli pole "website" jest wypełnione, to bot
if (!empty($data['website'])) {
    respond(200, 'OK'); // udajemy sukces, żeby bot nie wiedział
}

$errors = [];

if (mb_strlen($name) < 2)
    $errors['name'] = 'Imię jest wymagane (min. 2 znaki).';

if (mb_strlen($device) < 2)
    $errors['device'] = 'Model urządzenia jest wymagany.';

if (mb_strlen($issue) < 5)
    $errors['issue'] = 'Opis usterki jest za krótki.';

// walidacja numeru telefonu: cyfry, spacje, +, -, min 9 cyfr
$phoneCleaned = preg_replace('/[\s\-\(\)]/', '', $phone);
if (!preg_match('/^\+?\d{9,15}$/', $phoneCleaned))
    $errors['phone'] = 'Nieprawidłowy numer telefonu.';

if (!empty($errors)) {
    respond(422, 'Błąd walidacji.', $errors);
}

/* ─── WYSYŁKA E-MAIL ─── */
$date    = date('d.m.Y H:i');
$subject = "[{$date}] Nowe zapytanie o wycenę — {$device}";

$body = <<<TEXT
Nowe zapytanie o wycenę naprawy z formularza na stronie {$_SERVER['HTTP_HOST']}.

──────────────────────────────────
DANE KLIENTA
──────────────────────────────────
Imię:             {$name}
Telefon:          {$phone}
Model urządzenia: {$device}
Data zgłoszenia:  {$date}

──────────────────────────────────
OPIS USTERKI
──────────────────────────────────
{$issue}

──────────────────────────────────
METADANE (dla bezpieczeństwa)
──────────────────────────────────
IP:         {$ip}
User-Agent: {$_SERVER['HTTP_USER_AGENT']}
──────────────────────────────────

Wiadomość wygenerowana automatycznie przez formularz {$_SERVER['HTTP_HOST']}.
TEXT;

$headers  = "From: formularz@{$_SERVER['HTTP_HOST']}\r\n";
$headers .= "Reply-To: {$phone}\r\n";
$headers .= "X-Mailer: PHP/" . PHP_VERSION . "\r\n";
$headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
$headers .= "MIME-Version: 1.0\r\n";

$sent = mail(TO_EMAIL, $subject, $body, $headers);

if (!$sent) {
    respond(500, 'Nie udało się wysłać e-maila. Zadzwoń do nas bezpośrednio.');
}

incrementRateLimit($ip);
respond(200, 'Zapytanie zostało wysłane. Oddzwonimy wkrótce!');

/* ─── HELPERS ─── */

function sanitize(string $val): string {
    $val = trim($val);
    $val = strip_tags($val);
    $val = htmlspecialchars($val, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    return mb_substr($val, 0, 500); // max 500 znaków
}

function respond(int $code, string $message, array $errors = []): never {
    http_response_code($code);
    $payload = ['message' => $message];
    if (!empty($errors)) $payload['errors'] = $errors;
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Prosty rate-limiting oparty na plikach w /tmp
 * (działa na każdym hostingu bez bazy danych)
 */
function getRateLimitFile(string $ip): string {
    return LOCK_FILE . md5($ip) . '.json';
}

function checkRateLimit(string $ip): void {
    $file = getRateLimitFile($ip);
    if (!file_exists($file)) return;

    $data = json_decode(file_get_contents($file), true);
    if (!is_array($data)) return;

    $hour = date('Y-m-d-H');
    $count = $data[$hour] ?? 0;

    if ($count >= RATE_LIMIT) {
        respond(429, 'Zbyt wiele zapytań. Spróbuj ponownie za godzinę lub zadzwoń do nas.');
    }
}

function incrementRateLimit(string $ip): void {
    $file = getRateLimitFile($ip);
    $hour = date('Y-m-d-H');

    $data = [];
    if (file_exists($file)) {
        $data = json_decode(file_get_contents($file), true) ?? [];
    }

    // usuń stare klucze (starsze niż bieżąca godzina)
    $data = array_filter($data, fn($key) => $key === $hour, ARRAY_FILTER_USE_KEY);
    $data[$hour] = ($data[$hour] ?? 0) + 1;

    file_put_contents($file, json_encode($data), LOCK_EX);
}
