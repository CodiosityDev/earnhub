# CMS SpawnVault

CMS dapat menambah, mengedit, memindahkan kategori, dan menghapus kartu platform. Konten awal berisi 32 kartu. CMS juga mengedit teks halaman utama dalam bahasa Indonesia dan Inggris serta tombol **Prove** pada bagian AI Trainer. Link awal Prove adalah `https://vt.tiktok.com/ZSpKY6vhf/` sesuai permintaan pemilik.

## Lokal

Jalankan dari root repository:

```bash
php scripts/setup-cms.php --username=spawnvault_admin
php -S 127.0.0.1:8786 -t .
```

Simpan username/password yang ditampilkan setup di password manager. Password tidak ditulis sebagai teks biasa ke file. Jika akun sudah dibuat, perintah setup menolak menimpanya. Login melalui `http://127.0.0.1:8786/admin/`.

## Aktivasi di VPS

Syarat: PHP 8+ dengan ekstensi mbstring dan session, PHP aktif untuk domain ini, serta HTTPS. Workflow mengecek versi PHP CLI dan sintaks sebelum menyalin website. PHP-FPM/Apache pada domain harus menggunakan versi yang sesuai juga; pemeriksaan CLI tidak membuktikan pengaturan web server.

Sesudah kode tersedia di repository VPS, jalankan setup sebagai user yang menjalankan PHP untuk website, misalnya user `el`:

```bash
cd /home/el/web/spawnvault.respawnsociety.web.id/repo
sudo -u el php scripts/setup-cms.php --username=spawnvault_admin
```

Setup menghasilkan password acak baru pada mesin tersebut. Jika ingin memakai password pilihan sendiri, gunakan `--password-stdin` dan kirimkan password lewat stdin; jangan masukkan password ke argumen shell, source code, atau Git. Akun lokal tidak otomatis menjadi akun produksi.

Folder default untuk akun dan data adalah:

```text
/home/el/web/spawnvault.respawnsociety.web.id/.spawnvault-cms/
  config.json     # username dan hash password
  content.json    # konten hasil edit, muncul setelah penyimpanan pertama
  sessions/       # sesi server
  login-*.json    # pembatasan percobaan login
  write.lock      # kunci penyimpanan
```

Folder ini merupakan saudara `public_html` dan `repo`, sehingga tidak ikut `rsync --delete`. User PHP harus memiliki akses baca/tulis ke folder privat; gunakan permission 700 untuk folder dan 600 untuk file privat. `SPAWNVAULT_CMS_DIR` dapat mengubah lokasi, tetapi harus tetap di luar document root. Pastikan folder privat tidak dipublikasikan lewat alias web server.

Buka `https://spawnvault.respawnsociety.web.id/admin/`. CMS menolak HTTP selain server development lokal. Bila memakai reverse proxy, atur web server agar PHP menerima status HTTPS yang benar. CMS tidak mempercayai header forwarded dari pengunjung sebagai bukti HTTPS.

`scripts/`, `tests/`, `.env*`, folder sementara, dan file pengembangan dikecualikan dari deployment. Jalankan setup dari checkout privat. Tidak ada endpoint pendaftaran atau setup lewat browser.

## Mengedit

- **Platform:** pilih kategori dan kartu, lalu edit nama, deskripsi, pembayaran, kode referral, filter, dan link. Kartu platform yang tampil di beberapa kategori diedit masing-masing.
- **Tambah kartu:** klik tombol ini pada daftar platform, pilih kategori, lalu isi nama dan deskripsi dua bahasa, pembayaran, serta link platform. Setelah tersimpan, kartu dapat dilihat dan diedit seperti kartu lain.
- **Pindah kategori:** buka kartu yang sudah ada, ubah pilihan **Kategori**, lalu simpan. Kartu berpindah ke kategori tujuan dan tetap menyimpan deskripsi, referral, serta linknya.
- **Hapus kartu:** klik **Hapus kartu ini** pada editor, lalu konfirmasi dengan tombol **Hapus kartu**. Membuka halaman konfirmasi saja tidak menghapus kartu. Penghapusan hanya berlaku untuk kartu yang dipilih.
- **Bukti pembayaran:** edit link, label tombol, dan keterangan untuk bagian AI Trainer.
- **Teks halaman:** pilih teks halaman utama, lalu edit versi Indonesia dan Inggris. Format yang didukung adalah `<strong>`, `<em>`, `<b>`, dan `<br>` tanpa atribut.
- **Ganti password:** masukkan password sekarang dan password baru minimal 16 karakter. Semua sesi lama menjadi tidak berlaku.

Klik **Simpan perubahan**, kemudian muat ulang halaman publik. Jika dua tab menyimpan versi berbeda, penyimpanan kedua ditolak agar perubahan pertama tidak tertimpa. Salin isian yang belum tersimpan sebelum memuat ulang editor.

Kartu baru, perpindahan kategori, dan penghapusan diterapkan pada halaman publik setelah dimuat ulang. Kategori kosong disembunyikan dan jumlah hasil filter diperbarui. Pilihan kategori mengikuti sembilan kategori yang sudah ada; penambahan kategori dan pengubahan struktur halaman dilakukan melalui source code. Halaman About/FAQ tidak termasuk editor ini. Nama dan deskripsi yang dikelola CMS dimuat setelah HTML; metadata SEO dan JSON-LD bawaan tetap dikelola dalam source.

## Penyimpanan dan keamanan

- `/api/content.php` hanya membaca konten publik. Data akun, hash password, dan sesi tidak masuk respons ini.
- Semua aksi penyimpanan memerlukan sesi login di server dan token CSRF. Cookie sesi memakai HttpOnly, SameSite Strict, dan Secure pada HTTPS. Sesi berakhir setelah satu jam tanpa aktivitas.
- Password diverifikasi dengan `password_verify`; setup dan penggantian password memakai `password_hash(PASSWORD_DEFAULT)`. Lima kegagalan login dari satu alamat IP membatasi percobaan selama 15 menit, termasuk ketika cookie browser dihapus.
- URL yang disimpan harus `https://`. Nama, kode, dan label ditampilkan sebagai teks; deskripsi dibatasi pada format yang didukung.
- Penulisan data dikunci dan dilakukan melalui file sementara sebelum rename. Data rusak tidak ditimpa diam-diam dengan default.
- `data/content.default.json` berisi konten publik bawaan. Browser memakai data bawaan dan HTML jika PHP tidak tersedia, sehingga direktori masih dapat dibuka sebagai situs statis. Penyimpanan CMS tetap memerlukan PHP.
- Kartu baru disimpan dengan ID unik dan deskripsi masing-masing. Penghapusan menyimpan penanda `deletedCards`, sehingga kartu bawaan yang sudah dihapus tidak muncul kembali ketika data digabung dengan default source pada deployment berikutnya. Jangan menghapus penanda ini saat merawat backup.

Backup `config.json` dan `content.json` dari folder privat ke tempat privat. Jangan memasukkan keduanya ke Git atau folder publik. Konten tersimpan diprioritaskan terhadap default source ketika deployment berikutnya menambahkan field.

## Reset password

Melalui terminal privat dengan user PHP yang sama:

```bash
php scripts/setup-cms.php --username=spawnvault_admin --reset-password
```

Perintah menghasilkan password baru dan membatalkan sesi dengan hash lama tanpa menghapus konten.

## Pemeriksaan

```bash
node --test tests/cms.test.cjs
php -l admin/cms.php
php -l admin/index.php
php -l api/content.php
node --check content.js
```

Test memakai server PHP dan folder privat sementara terisolasi. Cakupannya meliputi login, cookie, CSRF, akses anonim, penyimpanan publik, validasi URL/HTML/kategori, konflik edit, referral, tambah/pindah/hapus kartu, penanda penghapusan setelah default dimuat ulang, kondisi semua kartu dihapus, rotasi password, logout, serta pembatasan login lintas sesi. Test DOM tiruan menjalankan `content.js` untuk memeriksa penambahan, pemindahan, penghapusan, dan pergantian bahasa tanpa kartu ganda. Test tersebut tidak menggantikan pemeriksaan visual browser. Hasil lokal tidak membuktikan bahwa PHP/HTTPS dan permission pada VPS sudah dikonfigurasi.
