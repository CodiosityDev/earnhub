<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require dirname(__DIR__) . '/admin/cms.php';

try {
    $options = getopt('', ['username:', 'password-stdin', 'reset-password']);
    $username = $options['username'] ?? 'spawnvault_admin';
    if (!is_string($username) || !preg_match('/\A[a-zA-Z0-9_]{3,64}\z/', $username)) {
        throw new RuntimeException('Username harus 3–64 huruf, angka, atau underscore.');
    }
    $directory = cmsDirectory();
    if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
        throw new RuntimeException('Folder CMS tidak dapat dibuat.');
    }
    cmsDirectory();
    $path = $directory . '/config.json';
    if (is_file($path) && !isset($options['reset-password'])) {
        throw new RuntimeException('Akun sudah ada. Gunakan --reset-password untuk mengganti password.');
    }
    $password = isset($options['password-stdin']) ? rtrim((string) fgets(STDIN), "\r\n") : bin2hex(random_bytes(16));
    if (strlen($password) < 16 || strlen($password) > 72) {
        throw new RuntimeException('Password harus 16–72 karakter.');
    }
    cmsLocked(function () use ($path, $username, $password): void {
        cmsWrite($path, ['username' => $username, 'password_hash' => password_hash($password, PASSWORD_DEFAULT)]);
    });
    echo 'Username: ' . $username . "\n";
    if (!isset($options['password-stdin'])) {
        echo 'Password: ' . $password . "\n";
    }
    echo "Akun tersimpan di luar folder publik. Simpan password di password manager.\n";
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . "\n");
    exit(1);
}
