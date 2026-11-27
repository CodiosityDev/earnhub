<?php
declare(strict_types=1);
require __DIR__ . '/cms.php';

header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: same-origin');
header('X-Robots-Tag: noindex, nofollow');
header("Content-Security-Policy: default-src 'none'; style-src 'self'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'");

function e(string $value): string { return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function field(string $name, string $label, string $value, string $type = 'text', bool $required = true): void
{
    $submitted = $_POST[$name] ?? $value;
    $value = is_string($submitted) ? $submitted : $value;
    echo '<label for="' . e($name) . '">' . e($label) . '</label>';
    if ($type === 'textarea') {
        echo '<textarea id="' . e($name) . '" name="' . e($name) . '" rows="5" maxlength="5000" required>' . e($value) . '</textarea>';
    } else {
        $autocomplete = $type === 'password' ? ($name === 'current_password' || $name === 'password' ? 'current-password' : 'new-password') : ($name === 'username' ? 'username' : 'off');
        echo '<input id="' . e($name) . '" name="' . e($name) . '" type="' . e($type) . '" value="' . ($type === 'password' ? '' : e($value)) . '" autocomplete="' . $autocomplete . '" maxlength="' . ($type === 'url' ? '2048' : '200') . '"' . ($required ? ' required' : '') . '>';
    }
}

$error = '';
$unavailable = false;
$authenticated = false;
try {
    if (!is_file(cmsDirectory() . '/config.json')) {
        throw new RuntimeException('CMS belum diaktifkan di server. Hubungi pengelola website untuk mengaktifkannya.');
    }
    cmsStartSession();
    $config = cmsJson(cmsDirectory() . '/config.json');
    $authenticated = isset($_SESSION['authenticated'], $_SESSION['last_active'])
        && hash_equals(hash('sha256', $config['password_hash']), (string) $_SESSION['authenticated'])
        && $_SESSION['last_active'] > time() - 3600;
    if (!$authenticated) unset($_SESSION['authenticated']);
    else $_SESSION['last_active'] = time();

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $csrf = $_POST['csrf'] ?? '';
        if (!is_string($csrf) || !hash_equals($_SESSION['csrf'], $csrf)) {
            http_response_code(403);
            throw new InvalidArgumentException('Sesi formulir sudah berubah. Muat ulang halaman lalu coba lagi.');
        }
        $action = cmsField($_POST, 'action');
        if ($action === 'login') {
            $valid = cmsLocked(fn () => cmsLogin($config, cmsField($_POST, 'username', 64), cmsPassword($_POST, 'password')));
            if (!$valid) {
                http_response_code(401);
                throw new InvalidArgumentException('Username atau password salah. Periksa lalu coba lagi.');
            }
            header('Location: ./', true, 303);
            exit;
        }
        if (!$authenticated) {
            http_response_code(401);
            throw new InvalidArgumentException('Sesi login berakhir. Masuk kembali untuk menyimpan perubahan.');
        }
        if ($action === 'logout') {
            $_SESSION = [];
            session_destroy();
            setcookie(session_name(), '', ['expires' => time() - 3600, 'path' => '/admin/', 'secure' => session_get_cookie_params()['secure'], 'httponly' => true, 'samesite' => 'Strict']);
            header('Location: ./', true, 303);
            exit;
        }
        if ($action === 'password') {
            $current = cmsPassword($_POST, 'current_password');
            $password = cmsPassword($_POST, 'new_password');
            if (!password_verify($current, $config['password_hash']) || strlen($password) < 16 || $password !== cmsPassword($_POST, 'confirm_password')) {
                throw new InvalidArgumentException('Periksa password saat ini. Password baru minimal 16 karakter dan konfirmasi harus sama.');
            }
            cmsLocked(function () use ($config, $password): void {
                if (cmsJson(cmsDirectory() . '/config.json') !== $config) {
                    throw new InvalidArgumentException('Akun sudah berubah. Masuk kembali lalu coba lagi.');
                }
                cmsWrite(cmsDirectory() . '/config.json', ['username' => $config['username'], 'password_hash' => password_hash($password, PASSWORD_DEFAULT)]);
            });
            $_SESSION = [];
            session_regenerate_id(true);
            $_SESSION['flash'] = 'Password berhasil diganti. Masuk dengan password baru.';
            header('Location: ./', true, 303);
            exit;
        }
        cmsLocked(function (): void {
            $content = cmsContent();
            if (!hash_equals(cmsRevision($content), cmsField($_POST, 'revision'))) {
                http_response_code(409);
                throw new InvalidArgumentException('Konten sudah diubah dari tab lain. Salin perubahanmu, muat ulang, lalu simpan kembali.');
            }
            cmsWrite(cmsDirectory() . '/content.json', cmsUpdate($content, $_POST));
        });
        $_SESSION['flash'] = match ($action) {
            'create-card' => 'Kartu baru berhasil ditambahkan.',
            'delete-card' => 'Kartu berhasil dihapus.',
            default => 'Perubahan tersimpan. Muat ulang website untuk melihat hasilnya.',
        };
        $destination = match ($action) {
            'save-proof' => '?view=proof',
            'save-text' => '?view=texts&key=' . rawurlencode(cmsField($_POST, 'key')),
            'delete-card' => '',
            default => '?key=' . rawurlencode(cmsField($_POST, 'key')),
        };
        header('Location: ./' . $destination, true, 303);
        exit;
    }
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        header('Allow: GET, POST');
        http_response_code(405);
        exit('Method not allowed');
    }
    $content = cmsContent();
} catch (InvalidArgumentException $exception) {
    $error = $exception->getMessage();
    if (http_response_code() === 200) http_response_code(422);
    $content = cmsContent();
} catch (Throwable $exception) {
    error_log('SpawnVault CMS: ' . $exception->getMessage());
    $unavailable = true;
    http_response_code(503);
    $error = 'CMS belum tersedia. Pengelola perlu menyelesaikan pengaturan server.';
}
$flash = $_SESSION['flash'] ?? '';
unset($_SESSION['flash']);
$view = is_string($_GET['view'] ?? null) ? $_GET['view'] : 'platforms';
if (!in_array($view, ['platforms', 'new', 'delete', 'proof', 'texts', 'password'], true)) $view = 'platforms';
$key = is_string($_GET['key'] ?? null) ? $_GET['key'] : '';
$csrf = $_SESSION['csrf'] ?? '';
?>
<!doctype html>
<html lang="id">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex,nofollow">
  <title>CMS · SpawnVault</title>
  <link rel="stylesheet" href="cms.css">
</head>
<body>
<header class="topbar">
  <a class="brand" href="./">SpawnVault <span>CMS</span></a>
  <div class="topbar-actions">
    <a href="/" target="_blank" rel="noopener">Lihat website</a>
    <?php if ($authenticated): ?>
    <form method="post"><input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="action" value="logout"><button class="button secondary" type="submit">Keluar</button></form>
    <?php endif ?>
  </div>
</header>
<?php if (!$authenticated || $unavailable): ?>
<main class="login">
  <h1>Masuk ke CMS</h1>
  <p>Edit platform, link, dan konten SpawnVault.</p>
  <?php if ($error): ?><p class="notice error" role="alert"><?= e($error) ?></p><?php endif ?>
  <?php if ($flash): ?><p class="notice" role="status"><?= e($flash) ?></p><?php endif ?>
  <?php if (!$unavailable): ?>
  <form method="post">
    <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
    <input type="hidden" name="action" value="login">
    <?php field('username', 'Username', ''); field('password', 'Password', '', 'password'); ?>
    <button class="button" type="submit">Masuk</button>
  </form>
  <?php endif ?>
</main>
<?php else:
    $cards = $content['cards'];
    if ($view === 'texts') {
        if (!isset($content['translations']['en'][$key])) $key = 'hero-h1';
    } elseif ($view === 'new') {
        $key = is_string($_POST['key'] ?? null) ? $_POST['key'] : 'custom-' . bin2hex(random_bytes(8));
    } elseif ($view === 'delete' && !isset($cards[$key])) {
        $error = 'Kartu tidak ditemukan. Pilih kartu yang masih tersedia.';
        $view = 'platforms';
        $key = array_key_first($cards) ?? '';
    } elseif (!isset($cards[$key])) $key = array_key_first($cards) ?? '';
    $cardView = in_array($view, ['platforms', 'new', 'delete'], true);
?>
<a class="skip" href="#editor">Lewati ke editor</a>
<nav class="tabs" aria-label="Bagian CMS">
  <?php foreach (['platforms' => 'Platform', 'proof' => 'Bukti pembayaran', 'texts' => 'Teks halaman', 'password' => 'Ganti password'] as $tab => $label): ?>
  <a href="?view=<?= e($tab) ?>" <?= ($view === $tab || ($tab === 'platforms' && $cardView)) ? 'aria-current="page"' : '' ?>><?= e($label) ?></a>
  <?php endforeach ?>
</nav>
<div class="workspace <?= $cardView ? '' : 'single' ?>">
  <?php if ($cardView): ?>
  <nav class="platforms" aria-label="Pilih platform">
    <h2>Platform</h2>
    <p><?= count($cards) ?> kartu di website</p>
    <a class="button secondary add-card" href="?view=new">Tambah kartu</a>
    <?php foreach ($content['sections'] as $sectionId => $sectionName): ?>
    <details <?= ($cards[$key]['section'] ?? '') === $sectionId ? 'open' : '' ?>>
      <summary><?= e($sectionName) ?></summary>
      <?php foreach ($cards as $id => $item): if ($item['section'] !== $sectionId) continue; ?>
      <a href="?key=<?= e($id) ?>" <?= $key === $id ? 'aria-current="page"' : '' ?>><?= e($item['name']['id']) ?></a>
      <?php endforeach ?>
    </details>
    <?php endforeach ?>
  </nav>
  <?php endif ?>
  <main id="editor" class="editor">
    <?php if ($error): ?><p class="notice error" role="alert"><?= e($error) ?></p><?php endif ?>
    <?php if ($flash): ?><p class="notice" role="status"><?= e($flash) ?></p><?php endif ?>
    <?php if ($view === 'texts'): ?>
    <h1>Teks halaman</h1>
    <p>Edit judul, pengantar, dan teks lain di halaman utama.</p>
    <form method="get" class="chooser">
      <input type="hidden" name="view" value="texts">
      <label for="text-choice">Pilih teks</label>
      <select id="text-choice" name="key">
        <?php foreach ($content['translations']['id'] as $textKey => $value): if (in_array($textKey, ['halo-name', 'halo-cta'], true)) continue; ?>
        <option value="<?= e($textKey) ?>" <?= $key === $textKey ? 'selected' : '' ?>><?= e(mb_strimwidth(strip_tags($value), 0, 95, '…')) ?></option>
        <?php endforeach ?>
      </select><button class="button secondary" type="submit">Buka teks</button>
    </form>
    <?php endif ?>
    <?php if ($view === 'platforms' && !$cards): ?>
    <h1>Belum ada kartu</h1>
    <p>Tambahkan platform pertama dan pilih kategorinya.</p>
    <a class="button" href="?view=new">Tambah kartu</a>
    <?php else: ?>
    <form method="post" class="edit-form">
      <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
      <input type="hidden" name="revision" value="<?= e(cmsRevision($content)) ?>">
      <input type="hidden" name="key" value="<?= e($key) ?>">
      <?php if ($view === 'proof'): ?>
      <input type="hidden" name="action" value="save-proof">
      <h1>Bukti pembayaran AI Trainer</h1>
      <p>Tombol ini muncul di bagian AI Trainer pada website.</p>
      <?php field('url', 'Link bukti pembayaran', $content['proof']['url'], 'url'); ?>
      <div class="columns">
        <div><?php field('label_id', 'Teks tombol (Indonesia)', $content['proof']['label']['id']); field('note_id', 'Keterangan (Indonesia)', $content['proof']['note']['id']); ?></div>
        <div><?php field('label_en', 'Button text (English)', $content['proof']['label']['en']); field('note_en', 'Description (English)', $content['proof']['note']['en']); ?></div>
      </div>
      <?php elseif ($view === 'texts'): ?>
      <input type="hidden" name="action" value="save-text">
      <div class="columns">
        <div><?php field('text_id', 'Teks (Indonesia)', $content['translations']['id'][$key], 'textarea'); ?></div>
        <div><?php field('text_en', 'Text (English)', $content['translations']['en'][$key], 'textarea'); ?></div>
      </div>
      <p class="help">Format opsional: &lt;strong&gt;tebal&lt;/strong&gt;, &lt;em&gt;miring&lt;/em&gt;, atau &lt;br&gt; untuk baris baru.</p>
      <?php elseif ($view === 'delete'): ?>
      <input type="hidden" name="action" value="delete-card">
      <input type="hidden" name="confirm" value="yes">
      <h1>Hapus kartu?</h1>
      <p><strong><?= e($cards[$key]['name']['id']) ?></strong> akan dihapus dari kategori <?= e($content['sections'][$cards[$key]['section']]) ?> dan halaman publik.</p>
      <?php elseif ($view === 'password'): ?>
      <input type="hidden" name="action" value="password">
      <h1>Ganti password</h1>
      <p>Gunakan minimal 16 karakter. Setelah disimpan, semua sesi login berakhir.</p>
      <?php field('current_password', 'Password saat ini', '', 'password'); field('new_password', 'Password baru', '', 'password'); field('confirm_password', 'Ulangi password baru', '', 'password'); ?>
      <?php else:
          $creating = $view === 'new';
          $card = $creating ? cmsNewCard($key) : $cards[$key];
          $retry = ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($_POST['action'] ?? '', ['save-card', 'create-card'], true));
          $selectedEffort = $retry && is_string($_POST['effort'] ?? null) ? $_POST['effort'] : $card['effort'];
          $selectedPayouts = $retry ? (is_array($_POST['payouts'] ?? null) ? $_POST['payouts'] : []) : explode(' ', $card['payouts']);
          $selectedSection = $retry && is_string($_POST['section'] ?? null) ? $_POST['section'] : $card['section'];
      ?>
      <input type="hidden" name="action" value="<?= $creating ? 'create-card' : 'save-card' ?>">
      <h1><?= $creating ? 'Tambah kartu' : e($card['name']['id']) ?></h1>
      <p><?= $creating ? 'Isi detail platform baru dan pilih kategori tempat kartu ditampilkan.' : 'Ubah kategori untuk memindahkan kartu. Kartu di kategori lain diedit masing-masing.' ?></p>
      <label for="section">Kategori</label>
      <select id="section" name="section" required>
        <?php foreach ($content['sections'] as $sectionId => $sectionName): ?>
        <option value="<?= e($sectionId) ?>" <?= $selectedSection === $sectionId ? 'selected' : '' ?>><?= e($sectionName) ?></option>
        <?php endforeach ?>
      </select>
      <div class="columns">
        <div><?php field('name_id', 'Nama platform (Indonesia)', $card['name']['id']); field('description_id', 'Deskripsi (Indonesia)', $content['translations']['id'][$card['description']] ?? '', 'textarea'); ?></div>
        <div><?php field('name_en', 'Platform name (English)', $card['name']['en']); field('description_en', 'Description (English)', $content['translations']['en'][$card['description']] ?? '', 'textarea'); ?></div>
      </div>
      <fieldset><legend>Pembayaran dan referral</legend>
        <?php field('payout', 'Keterangan pembayaran', $card['payout']); field('code', 'Kode referral (opsional)', $card['code'], 'text', false); ?>
        <label for="effort">Level usaha</label>
        <select id="effort" name="effort">
          <?php foreach (['very-low' => 'Sangat rendah', 'low' => 'Rendah', 'medium' => 'Menengah'] as $value => $label): ?>
          <option value="<?= e($value) ?>" <?= $selectedEffort === $value ? 'selected' : '' ?>><?= e($label) ?></option>
          <?php endforeach ?>
        </select>
        <p class="field-label">Metode pembayaran untuk filter</p>
        <div class="checks">
          <?php foreach (['paypal' => 'PayPal', 'idr' => 'Rupiah', 'crypto-pay' => 'Crypto', 'project' => 'Per proyek', 'salary' => 'Gaji'] as $value => $label): ?>
          <label><input type="checkbox" name="payouts[]" value="<?= e($value) ?>" <?= in_array($value, $selectedPayouts, true) ? 'checked' : '' ?>><?= e($label) ?></label>
          <?php endforeach ?>
        </div>
      </fieldset>
      <?php foreach ($card['links'] as $index => $link): ?>
      <fieldset><legend>Link <?= count($card['links']) > 1 ? $index + 1 : 'platform' ?></legend>
        <?php field('link_url_' . $index, 'URL tujuan', $link['url'], 'url'); ?>
        <div class="columns">
          <div><?php field('link_label_' . $index . '_id', 'Teks tombol (Indonesia)', $link['label']['id']); ?></div>
          <div><?php field('link_label_' . $index . '_en', 'Button text (English)', $link['label']['en']); ?></div>
        </div>
      </fieldset>
      <?php endforeach; endif ?>
      <div class="savebar">
        <button class="button <?= $view === 'delete' ? 'danger' : '' ?>" type="submit"><?= match ($view) { 'password' => 'Ganti password', 'new' => 'Tambah kartu', 'delete' => 'Hapus kartu', default => 'Simpan perubahan' } ?></button>
        <?php if ($view === 'new' || $view === 'delete'): ?><a href="./<?= $view === 'delete' ? '?key=' . e($key) : '' ?>">Batal</a><?php endif ?>
        <span><?= $view === 'password' ? 'Kamu akan diminta masuk kembali.' : 'Perubahan tampil setelah website dimuat ulang.' ?></span>
      </div>
    </form>
    <?php if ($view === 'platforms'): ?><p class="delete-card"><a href="?view=delete&amp;key=<?= e($key) ?>">Hapus kartu ini</a></p><?php endif ?>
    <?php endif ?>
  </main>
</div>
<?php endif ?>
</body>
</html>
