# Intelijen Artikel Publik Bawaslu Sleman

Dashboard ini adalah prototipe sistem pendukung keputusan untuk memantau artikel publik terkait isu pengawasan pemilu di Kabupaten Sleman. Aplikasi mengambil RSS Google News, memfilter artikel yang relevan dengan Sleman, lalu menampilkan metrik, sentimen, topik prioritas, tautan sumber, dan rekomendasi tindak lanjut.

## Cara Menjalankan

1. Letakkan folder proyek di `C:\xampp\htdocs\kerja_praktek`.
2. Jalankan Apache melalui XAMPP.
3. Buka `http://localhost/kerja_praktek/` di browser.
4. Pastikan koneksi internet aktif agar `api/scrape.php` dapat mengambil RSS berita.

## Struktur File

- `index.html`: struktur halaman dashboard, modal, tabel artikel, dan kontrol utama.
- `styles.css`: gaya visual dashboard, layout responsif, tabel, chart, dan modal.
- `app.js`: logika render chart, filter artikel, ekspor laporan, modal keputusan, dan polling data.
- `api/scrape.php`: endpoint scraping RSS, klasifikasi sentimen sederhana, pemetaan topik, dan cache JSON.
- `api/cache/`: cache data terakhir agar dashboard tetap bisa menampilkan data saat RSS gagal diakses.

## Alur Data

1. `app.js` memanggil `api/scrape.php`.
2. API mengambil RSS Google News berdasarkan kata kunci seperti `Bawaslu Sleman`, `pemilu Sleman`, dan `politik uang Sleman`.
3. Artikel disaring berdasarkan kata Sleman dan 17 kapanewon.
4. API menghitung sentimen, topik, jumlah artikel per penerbit, dan menyimpan cache.
5. Dashboard menampilkan hasil dalam metrik, grafik, tabel artikel, dan dukungan keputusan.

## Batasan Metode

Analisis sentimen masih berbasis aturan kata kunci. Nilai `sentimentConfidence` dan `sentimentReason` membantu menjelaskan alasan klasifikasi, tetapi hasilnya tetap perlu divalidasi manusia sebelum dijadikan keputusan resmi.

Rentang waktu 24 jam, 7 hari, dan 30 hari dihitung dari `publishedAt` pada data artikel yang tersedia. Jika data tanggal tidak tersedia, dashboard memakai ringkasan sumber dari API.

## Saran Pengembangan

- Tambahkan login admin jika dashboard digunakan untuk data internal.
- Simpan riwayat artikel ke database agar analisis waktu lebih kuat.
- Tambahkan validasi manual untuk status artikel: baru, diverifikasi, diproses, selesai.
- Integrasikan sumber resmi Bawaslu atau kanal pengaduan internal bila tersedia.
