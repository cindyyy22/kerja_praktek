# Intelijen Artikel Publik Bawaslu Sleman

Dashboard ini adalah sistem pendukung keputusan untuk memantau artikel publik terkait isu pengawasan pemilu di Kabupaten Sleman. Aplikasi mengambil RSS Google News, memfilter artikel yang relevan dengan Sleman, menyimpannya ke basis data MySQL, lalu menampilkan metrik, sentimen, topik prioritas, tautan sumber, rekomendasi tindak lanjut, serta bukti teknis basis data dan status layanan.

## Cara Menjalankan

1. Letakkan folder proyek di `C:\xampp8.2.12\htdocs\jokiweb-v2`.
2. Jalankan Apache **dan MySQL** melalui XAMPP.
3. Buka `http://localhost/jokiweb-v2/` di browser.
4. Pastikan koneksi internet aktif agar `api/scrape.php` dapat mengambil RSS berita.
5. Basis data `bawaslu_sleman` beserta seluruh tabelnya dibuat otomatis saat endpoint pertama kali dijalankan.

### Impor Basis Data dari Berkas

Proyek ini menyertakan berkas `bawaslu_sleman.sql` di direktori utama. Isinya adalah dump lengkap tujuh tabel beserta datanya, sehingga basis data dapat dipulihkan tanpa perlu mengambilnya langsung dari MySQL.

Impor lewat browser (paling mudah):

1. Pastikan MySQL aktif.
2. Buka `http://localhost/jokiweb-v2/import-database.php`.
3. Halaman akan menampilkan jumlah baris tiap tabel yang berhasil dimuat.

Impor lewat terminal:

```powershell
& "C:\xampp8.2.12\php\php.exe" import-database.php
```

Impor lewat phpMyAdmin: pilih menu **Import**, unggah `bawaslu_sleman.sql`, lalu jalankan.

Skrip `import-database.php` membuat basis data bila belum ada, menimpa bila sudah ada, dan membaca kredensial dari environment variable yang sama seperti aplikasi.

### Konfigurasi Basis Data

Koneksi default: host `127.0.0.1`, port `3306`, user `root`, tanpa password, basis data `bawaslu_sleman`. Nilai ini dapat ditimpa lewat environment variable tanpa mengubah kode:

| Variabel | Default |
| --- | --- |
| `BAWASLU_DB_HOST` | `127.0.0.1` |
| `BAWASLU_DB_PORT` | `3306` |
| `BAWASLU_DB_NAME` | `bawaslu_sleman` |
| `BAWASLU_DB_USER` | `root` |
| `BAWASLU_DB_PASS` | (kosong) |

## Struktur File

- `index.html`: kerangka dashboard, sidebar, header, footer, modal, dan tujuh halaman (`page-view`) yang dipisah per menu.
- `assets/css/base.css`: variabel warna, reset, tipografi, dan komponen dasar (tombol, form, canvas).
- `assets/css/layout.css`: kerangka halaman (app shell, sidebar, topbar, drawer mobile, page-view, footer).
- `assets/css/components.css`: komponen isi (panel, kartu metrik, tabel, modal, dukungan keputusan).
- `assets/css/system.css`: komponen halaman teknis (ringkasan status, kartu tabel, katalog query, log, indikator waktu proses).
- `assets/css/responsive.css`: seluruh media query untuk tablet, Android, dan Apple.
- `app.js`: router halaman berbasis hash, render chart, filter artikel, ekspor laporan, modal keputusan, drawer navigasi, polling data, halaman Basis Data, dan halaman Status Sistem.
- `api/db.php`: lapisan basis data — koneksi PDO, pembuatan skema otomatis, penyimpanan artikel/sumber/topik, snapshot sentimen, dan log.
- `api/scrape.php`: endpoint scraping RSS, klasifikasi sentimen sederhana, pemetaan topik, penyimpanan ke MySQL, dan cache JSON.
- `api/db-explorer.php`: endpoint baca struktur dan isi tabel MySQL untuk halaman Basis Data.
- `api/health.php`: endpoint pemeriksaan kesehatan layanan untuk halaman Status Sistem.
- `bawaslu_sleman.sql`: dump basis data lengkap (skema + data) untuk impor ulang.
- `import-database.php`: skrip impor satu klik, tersedia untuk browser maupun terminal.
- `api/cache/`: cache data terakhir agar dashboard tetap bisa menampilkan data saat RSS atau MySQL gagal diakses.

## Basis Data MySQL

Seluruh data operasional disimpan di MySQL dan dibaca kembali oleh API. Skema dibuat otomatis (auto-migrate) saat `db()` dipanggil pertama kali.

| Tabel | Isi |
| --- | --- |
| `sources` | Master penerbit/media beserta jumlah artikelnya. |
| `articles` | Satu baris per artikel unik: judul, tautan, penerbit, sentimen, waktu terbit. |
| `topics` | Kamus topik prioritas beserta slug. |
| `article_topics` | Relasi banyak-ke-banyak artikel–topik beserta kata kunci pemicu. |
| `sentiment_snapshots` | Potret komposisi sentimen tiap kali sinkronisasi dijalankan. |
| `sync_logs` | Catatan setiap pengambilan data: durasi, jumlah baru/diperbarui, status. |
| `api_request_logs` | Rekam jejak pemanggilan endpoint beserta waktu respons dan ukuran payload. |

Halaman **Basis Data** menampilkan mesin, nama basis data, jumlah tabel/baris, ukuran penyimpanan, daftar kolom dan indeks nyata dari `information_schema`, contoh baris tiap tabel, katalog query yang dapat dijalankan langsung, serta log sinkronisasi dan log permintaan.

## Navigasi Halaman

Setiap menu adalah halaman terpisah yang diakses lewat route hash, bukan lagi scroll ke `#id`.

| Menu | Route |
| --- | --- |
| Ringkasan | `#/ringkasan` |
| Sumber Data | `#/sumber-data` |
| Aduan Publik | `#/aduan-publik` |
| Arsip Berita | `#/arsip-berita` |
| Dukungan Keputusan | `#/dukungan-keputusan` |
| Basis Data | `#/basis-data` |
| Status Sistem | `#/status-sistem` |

- Route default tanpa hash adalah `#/ringkasan`.
- Hanya satu `.page-view` yang terlihat, sisanya diberi atribut `hidden`.
- Tombol back/forward browser bekerja karena setiap perpindahan memakai `history.pushState`.
- Panel dapat dijadikan target anchor lewat format `#/nama-halaman#anchor`, misalnya `#/dukungan-keputusan/decision`.

## Endpoint API

| Endpoint | Metode | Fungsi | Mengembalikan |
| --- | --- | --- | --- |
| `api/scrape.php` | GET | Mengambil RSS berita, memfilter artikel Sleman, menyimpan ke MySQL dan cache JSON. | metrik, sentimen, topik, artikel, arsip |
| `api/db-explorer.php` | GET | Membaca struktur dan isi tabel MySQL. Mendukung `?run=N` untuk menjalankan query katalog ke-`N`. | daftar tabel, jumlah baris, contoh data, katalog query |
| `api/health.php` | GET | Pemeriksaan kesehatan sistem: PHP, MySQL, cache, log sinkronisasi. | status komponen, waktu respons per tahap, log permintaan |

## Alur Data

1. `app.js` memanggil `api/scrape.php`.
2. API mengambil RSS Google News berdasarkan kata kunci seperti `Bawaslu Sleman`, `pemilu Sleman`, dan `politik uang Sleman`.
3. Artikel disaring berdasarkan kata Sleman dan 17 kapanewon.
4. API menghitung sentimen, topik, dan jumlah artikel per penerbit.
5. Artikel unik di-`UPSERT` ke tabel `articles`, penerbit ke `sources`, dan relasi topik ke `article_topics`.
6. Setiap sinkronisasi menulis snapshot sentimen ke `sentiment_snapshots` dan ringkasannya ke `sync_logs`; setiap permintaan API tercatat di `api_request_logs`.
7. Cache JSON diperbarui sebagai cadangan, lalu dashboard menampilkan hasil dalam metrik, grafik, tabel artikel, dan dukungan keputusan.

Jika MySQL tidak dapat dihubungi, API tetap mengembalikan data dari cache JSON (`fromCache`) atau data cadangan (`last-good`) agar dashboard tidak kosong, dan halaman Status Sistem menandai komponen yang bermasalah.

## Batasan Metode

Analisis sentimen masih berbasis aturan kata kunci. Nilai `sentimentConfidence` dan `sentimentReason` membantu menjelaskan alasan klasifikasi, tetapi hasilnya tetap perlu divalidasi manusia sebelum dijadikan keputusan resmi.

Rentang waktu 24 jam, 7 hari, dan 30 hari dihitung dari `publishedAt` pada data artikel yang tersedia. Jika data tanggal tidak tersedia, dashboard memakai ringkasan sumber dari API.

## Saran Pengembangan

- Tambahkan login admin jika dashboard digunakan untuk data internal.
- Tambahkan validasi manual untuk status artikel: baru, diverifikasi, diproses, selesai.
- Integrasikan sumber resmi Bawaslu atau kanal pengaduan internal bila tersedia.
- Tambahkan retensi otomatis untuk `api_request_logs` dan `sync_logs` agar tidak tumbuh tanpa batas.
