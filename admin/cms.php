<?php
declare(strict_types=1);

function cmsDirectory(): string
{
    $path = getenv('SPAWNVAULT_CMS_DIR') ?: dirname(__DIR__, 2) . '/.spawnvault-cms';
    $resolved = realpath($path);
    $root = str_replace('\\', '/', (string) realpath(dirname(__DIR__))) . '/';
    if ($resolved !== false && str_starts_with(strtolower(str_replace('\\', '/', $resolved) . '/'), strtolower($root))) {
        throw new RuntimeException('Folder CMS harus berada di luar folder website publik.');
    }
    return $resolved ?: $path;
}

function cmsJson(string $path): array
{
    $data = json_decode((string) file_get_contents($path), true, 64, JSON_THROW_ON_ERROR);
    if (!is_array($data)) {
        throw new RuntimeException('Data CMS tidak valid.');
    }
    return $data;
}

function cmsContent(): array
{
    $defaults = cmsJson(dirname(__DIR__) . '/data/content.default.json');
    $path = cmsDirectory() . '/content.json';
    // Saved overrides survive deployments; new source fields keep their defaults.
    $content = is_file($path) ? array_replace_recursive($defaults, cmsJson($path)) : $defaults;
    foreach ($content['deletedCards'] ?? [] as $key => $deleted) {
        if ($deleted) unset($content['cards'][$key]);
    }
    return $content;
}

function cmsRevision(array $content): string
{
    return hash('sha256', json_encode($content, JSON_THROW_ON_ERROR));
}

function cmsWrite(string $path, array $data): void
{
    $temporary = tempnam(dirname($path), '.cms-');
    if ($temporary === false) {
        throw new RuntimeException('Penyimpanan CMS tidak tersedia.');
    }
    try {
        chmod($temporary, 0600);
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
        if (file_put_contents($temporary, $json) !== strlen($json) || !rename($temporary, $path)) {
            throw new RuntimeException('Perubahan belum tersimpan. Coba lagi.');
        }
    } finally {
        if (is_file($temporary)) {
            unlink($temporary);
        }
    }
}

function cmsLocked(callable $callback): mixed
{
    $lock = fopen(cmsDirectory() . '/write.lock', 'c');
    if ($lock === false || !flock($lock, LOCK_EX)) {
        throw new RuntimeException('Penyimpanan sedang tidak tersedia. Coba lagi.');
    }
    try {
        return $callback();
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

function cmsField(array $input, string $key, int $maximum = 200, bool $required = true): string
{
    $value = $input[$key] ?? '';
    if (!is_string($value)) {
        throw new InvalidArgumentException('Format isian tidak valid.');
    }
    $value = trim($value);
    if (($required && $value === '') || mb_strlen($value) > $maximum) {
        throw new InvalidArgumentException('Lengkapi isian dan periksa batas panjang teks.');
    }
    return $value;
}

function cmsUrl(array $input, string $key): string
{
    $value = cmsField($input, $key, 2048);
    $parts = parse_url($value);
    if (!filter_var($value, FILTER_VALIDATE_URL) || ($parts['scheme'] ?? '') !== 'https' || isset($parts['user']) || isset($parts['pass'])) {
        throw new InvalidArgumentException('Link harus berupa URL https:// yang lengkap.');
    }
    return $value;
}

function cmsRichText(array $input, string $key): string
{
    $value = cmsField($input, $key, 5000);
    if (preg_match('~<(?!/?(?:strong|em|b|br)\s*/?>)~i', $value)) {
        throw new InvalidArgumentException('Format teks hanya boleh memakai <strong>, <em>, <b>, dan <br> tanpa atribut.');
    }
    return $value;
}

function cmsPassword(array $input, string $key): string
{
    $value = $input[$key] ?? '';
    if (!is_string($value) || strlen($value) < 1 || strlen($value) > 72) {
        throw new InvalidArgumentException('Password harus berisi 1–72 karakter.');
    }
    return $value;
}

function cmsNewCard(string $key): array
{
    return [
        'section' => 'ai-trainer',
        'name' => ['id' => '', 'en' => ''],
        'description' => $key . '-desc',
        'payout' => '', 'effort' => 'low', 'effortLabel' => 'effort-low',
        'payouts' => '', 'code' => '',
        'links' => [['url' => '', 'label' => ['id' => 'Daftar', 'en' => 'Visit platform']]],
    ];
}

function cmsUpdate(array $content, array $input): array
{
    $action = cmsField($input, 'action');
    if ($action === 'save-proof') {
        $content['proof']['url'] = cmsUrl($input, 'url');
        foreach (['id', 'en'] as $lang) {
            $content['proof']['label'][$lang] = cmsField($input, 'label_' . $lang, 80);
            $content['proof']['note'][$lang] = cmsField($input, 'note_' . $lang, 300);
        }
    } elseif ($action === 'save-text') {
        $key = cmsField($input, 'key');
        if (!array_key_exists($key, $content['translations']['en'])) {
            throw new InvalidArgumentException('Teks tidak ditemukan.');
        }
        foreach (['id', 'en'] as $lang) {
            $content['translations'][$lang][$key] = cmsRichText($input, 'text_' . $lang);
        }
    } elseif ($action === 'delete-card') {
        $key = cmsField($input, 'key');
        if (!isset($content['cards'][$key])) {
            throw new InvalidArgumentException('Platform tidak ditemukan.');
        }
        if (($input['confirm'] ?? '') !== 'yes') {
            throw new InvalidArgumentException('Konfirmasi penghapusan kartu terlebih dahulu.');
        }
        // Keep a deletion marker so deployment defaults cannot restore the card.
        $content['deletedCards'][$key] = true;
        unset($content['cards'][$key]);
    } elseif ($action === 'save-card' || $action === 'create-card') {
        $key = cmsField($input, 'key');
        if ($action === 'create-card') {
            if (!preg_match('/\Acustom-[a-f0-9]{16}\z/', $key) || isset($content['cards'][$key]) || isset($content['deletedCards'][$key])) {
                throw new InvalidArgumentException('Kartu baru tidak valid atau sudah tersimpan. Muat ulang halaman tambah kartu.');
            }
            $card = cmsNewCard($key);
        } else {
            if (!isset($content['cards'][$key])) {
                throw new InvalidArgumentException('Platform tidak ditemukan.');
            }
            $card = $content['cards'][$key];
        }
        $section = cmsField($input, 'section', 80);
        if (!array_key_exists($section, $content['sections'])) {
            throw new InvalidArgumentException('Pilih kategori yang tersedia.');
        }
        $card['section'] = $section;
        foreach (['id', 'en'] as $lang) {
            $card['name'][$lang] = cmsField($input, 'name_' . $lang, 120);
            $content['translations'][$lang][$card['description']] = cmsRichText($input, 'description_' . $lang);
        }
        $card['payout'] = cmsField($input, 'payout', 150);
        $card['code'] = cmsField($input, 'code', 150, false);
        $previousEffort = $card['effort'];
        $card['effort'] = cmsField($input, 'effort');
        if (!in_array($card['effort'], ['very-low', 'low', 'medium'], true)) {
            throw new InvalidArgumentException('Pilihan usaha tidak valid.');
        }
        if ($previousEffort !== $card['effort']) {
            $card['effortLabel'] = 'effort-' . $card['effort'];
        }
        $payouts = $input['payouts'] ?? [];
        if (!is_array($payouts) || array_diff($payouts, ['paypal', 'idr', 'crypto-pay', 'project', 'salary'])) {
            throw new InvalidArgumentException('Metode pembayaran tidak valid.');
        }
        $card['payouts'] = implode(' ', array_unique($payouts));
        foreach ($card['links'] as $index => &$link) {
            $link['url'] = cmsUrl($input, 'link_url_' . $index);
            foreach (['id', 'en'] as $lang) {
                $link['label'][$lang] = cmsField($input, 'link_label_' . $index . '_' . $lang, 150);
            }
        }
        unset($link);
        $content['cards'][$key] = $card;
    } else {
        throw new InvalidArgumentException('Aksi tidak dikenal.');
    }
    return $content;
}

function cmsStartSession(): void
{
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['SERVER_PORT'] ?? '') === '443';
    $local = PHP_SAPI === 'cli-server' && in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true);
    if (!$secure && !$local) {
        http_response_code(403);
        exit('CMS hanya dapat dibuka melalui HTTPS.');
    }
    $sessions = cmsDirectory() . '/sessions';
    if (!is_dir($sessions) && !mkdir($sessions, 0700, true) && !is_dir($sessions)) {
        throw new RuntimeException('Sesi CMS tidak tersedia.');
    }
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    session_save_path($sessions);
    session_name('spawnvault_cms');
    session_set_cookie_params(['lifetime' => 0, 'path' => '/admin/', 'secure' => $secure, 'httponly' => true, 'samesite' => 'Strict']);
    session_start();
    $_SESSION['csrf'] ??= bin2hex(random_bytes(32));
}

function cmsLogin(array $config, string $username, string $password): bool
{
    $path = cmsDirectory() . '/login-' . hash('sha256', $_SERVER['REMOTE_ADDR'] ?? 'unknown') . '.json';
    $attempts = is_file($path) ? cmsJson($path) : [];
    $attempts = array_values(array_filter($attempts, fn ($time) => is_int($time) && $time > time() - 900));
    if (count($attempts) >= 5) {
        http_response_code(429);
        throw new InvalidArgumentException('Terlalu banyak percobaan login. Coba lagi dalam 15 menit.');
    }
    $validPassword = password_verify($password, $config['password_hash']);
    $valid = hash_equals($config['username'], $username) && $validPassword;
    if ($valid) {
        cmsWrite($path, []);
        session_regenerate_id(true);
        $_SESSION['authenticated'] = hash('sha256', $config['password_hash']);
        $_SESSION['last_active'] = time();
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    } else {
        $attempts[] = time();
        cmsWrite($path, $attempts);
    }
    return $valid;
}
