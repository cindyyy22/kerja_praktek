const colors = {
  red: "#b4232f",
  green: "#247a4b",
  teal: "#1d7f88",
  gold: "#b7791f",
  blue: "#315c9b",
  gray: "#dce5e0",
  ink: "#17201c"
};

const SCRAPE_ENDPOINT = "api/scrape.php";
const topicColors = [colors.red, colors.teal, colors.gold, colors.blue, colors.green];

const state = {
  paused: false,
  dataMode: "article",
  lastMentionCount: 0,
  range: "24h",
  lastErrorMessage: "",
  totalMentions: 0,
  mediaCount: 0,
  sla: 82,
  sources: {
    "Artikel Publik": 0
  },
  sentiment: {
    Positif: 0,
    Netral: 100,
    Negatif: 0
  },
  topics: [
    { name: "Dugaan politik uang di kecamatan", value: 82, color: colors.red },
    { name: "Akurasi daftar pemilih Sleman", value: 68, color: colors.teal },
    { name: "Pelanggaran kampanye digital", value: 61, color: colors.gold },
    { name: "Akses TPS dan logistik", value: 48, color: colors.blue },
    { name: "Netralitas penyelenggara", value: 43, color: colors.green }
  ],
  feedback: [],
  archive: []
};

const sourceChart = document.querySelector("#sourceChart");
const sentimentChart = document.querySelector("#sentimentChart");
const riskGauge = document.querySelector("#riskGauge");
const feedbackRows = document.querySelector("#feedbackRows");
const sourceFilter = document.querySelector("#sourceFilter");
const articleSearch = document.querySelector("#articleSearch");
const archiveRows = document.querySelector("#archiveRows");
const archiveSearch = document.querySelector("#archiveSearch");
const archiveMonthFilter = document.querySelector("#archiveMonthFilter");
const pauseButton = document.querySelector("#pauseButton");
const exportButton = document.querySelector("#exportButton");
const toast = document.querySelector("#toast");
const routeModal = document.querySelector("#routeModal");
const routeModalTitle = document.querySelector("#routeModalTitle");
const routeModalDetail = document.querySelector("#routeModalDetail");
const routePriority = document.querySelector("#routePriority");
const routeSla = document.querySelector("#routeSla");
const routeOwner = document.querySelector("#routeOwner");
const routeSteps = document.querySelector("#routeSteps");
const routeStatus = document.querySelector("#routeStatus");
const routeOutput = document.querySelector("#routeOutput");
const routeMarkButton = document.querySelector("#routeMarkButton");
const routeSummaryButton = document.querySelector("#routeSummaryButton");
const decisionPanel = document.querySelector("#decision");
const policyModal = document.querySelector("#policyModal");
const policyRiskDetail = document.querySelector("#policyRiskDetail");
const policyPostureDetail = document.querySelector("#policyPostureDetail");
const policyTopicDetail = document.querySelector("#policyTopicDetail");
const policyModalDetail = document.querySelector("#policyModalDetail");
const policySteps = document.querySelector("#policySteps");
const policyStageButtons = document.querySelectorAll("[data-policy-stage]");
const policyStageLabel = document.querySelector("#policyStageLabel");
const policyStageTitle = document.querySelector("#policyStageTitle");
const policyStageDetail = document.querySelector("#policyStageDetail");
const policyProgressText = document.querySelector("#policyProgressText");
const policyProgressBar = document.querySelector("#policyProgressBar");
const policySelectedCount = document.querySelector("#policySelectedCount");
const policyCommitButton = document.querySelector("#policyCommitButton");
const policyResetButton = document.querySelector("#policyResetButton");
const navLinks = document.querySelectorAll(".nav-list a[href^='#']");
let activeRoute = null;
let activePolicyStage = "validasi";
let selectedPolicyActions = new Set();

const policyStages = {
  validasi: {
    label: "Validasi bukti",
    title: "Cek artikel dan pola isu",
    detail: "Bandingkan judul, penerbit, waktu publikasi, dan topik utama sebelum membuat rekomendasi."
  },
  koordinasi: {
    label: "Koordinasi wilayah",
    title: "Tentukan tim dan kanal tindak lanjut",
    detail: "Hubungkan isu prioritas dengan penerimaan aduan, pengawasan lapangan, dan humas agar respons tidak terpisah."
  },
  publikasi: {
    label: "Publikasi respons",
    title: "Siapkan pesan publik yang proporsional",
    detail: "Pilih narasi klarifikasi atau edukasi berdasarkan level risiko, lalu dokumentasikan bukti keputusan."
  }
};

function formatNumber(value) {
  return new Intl.NumberFormat("id-ID").format(value);
}

function getRiskScore() {
  const total = Object.values(state.sentiment).reduce((sum, value) => sum + value, 0);
  if (total <= 0) return 0;
  const negativeShare = state.sentiment.Negatif / total;
  const topicPressure = state.topics.slice(0, 3).reduce((sum, topic) => sum + topic.value, 0) / 300;
  return Math.round((negativeShare * 0.65 + topicPressure * 0.35) * 100);
}

function drawBarChart(canvas, values) {
  const ctx = canvas.getContext("2d");
  const dpr = window.devicePixelRatio || 1;
  const rect = canvas.getBoundingClientRect();
  canvas.width = rect.width * dpr;
  canvas.height = 320 * dpr;
  ctx.scale(dpr, dpr);
  ctx.clearRect(0, 0, rect.width, 320);

  const labels = Object.keys(values);
  const data = Object.values(values);
  const max = Math.max(1, ...data) * 1.12;
  const left = 46;
  const bottom = 274;
  const barGap = 18;
  const barWidth = (rect.width - left - 24 - barGap * (data.length - 1)) / data.length;

  ctx.strokeStyle = colors.gray;
  ctx.lineWidth = 1;
  ctx.beginPath();
  ctx.moveTo(left, 22);
  ctx.lineTo(left, bottom);
  ctx.lineTo(rect.width - 18, bottom);
  ctx.stroke();

  data.forEach((value, index) => {
    const height = Math.max(12, (value / max) * 220);
    const x = left + index * (barWidth + barGap);
    const y = bottom - height;
    const gradient = ctx.createLinearGradient(0, y, 0, bottom);
    gradient.addColorStop(0, [colors.red, colors.teal, colors.blue, colors.gold, colors.green][index]);
    gradient.addColorStop(1, "#d9ece7");

    ctx.fillStyle = gradient;
    roundRect(ctx, x, y, barWidth, height, 7);
    ctx.fill();

    ctx.fillStyle = colors.ink;
    ctx.font = "700 13px Inter, sans-serif";
    ctx.textAlign = "center";
    ctx.fillText(formatNumber(value), x + barWidth / 2, y - 10);

    ctx.fillStyle = "#63716b";
    ctx.font = "12px Inter, sans-serif";
    drawWrappedLabel(ctx, labels[index], x + barWidth / 2, bottom + 22, Math.max(64, barWidth + 12), 2);
  });
}

function drawDonutChart(canvas, values) {
  const ctx = canvas.getContext("2d");
  const dpr = window.devicePixelRatio || 1;
  const rect = canvas.getBoundingClientRect();
  canvas.width = rect.width * dpr;
  canvas.height = 300 * dpr;
  ctx.scale(dpr, dpr);
  ctx.clearRect(0, 0, rect.width, 300);

  const palette = [colors.green, colors.gold, colors.red];
  const entries = Object.entries(values);
  const total = entries.reduce((sum, entry) => sum + entry[1], 0);
  if (total <= 0) {
    drawEmptyChart(ctx, rect.width, 300, "Belum ada data");
    document.querySelector("#sentimentLegend").innerHTML = "";
    return;
  }
  const cx = rect.width / 2;
  const cy = 140;
  const radius = Math.min(96, rect.width * 0.28);
  let start = -Math.PI / 2;

  entries.forEach(([label, value], index) => {
    const slice = (value / total) * Math.PI * 2;
    ctx.beginPath();
    ctx.arc(cx, cy, radius, start, start + slice);
    ctx.arc(cx, cy, radius * 0.58, start + slice, start, true);
    ctx.closePath();
    ctx.fillStyle = palette[index];
    ctx.fill();
    start += slice;
  });

  ctx.fillStyle = colors.ink;
  ctx.font = "800 28px Inter, sans-serif";
  ctx.textAlign = "center";
  ctx.fillText(`${Math.round(values.Negatif)}%`, cx, cy + 4);
  ctx.fillStyle = "#63716b";
  ctx.font = "12px Inter, sans-serif";
  ctx.fillText("negatif", cx, cy + 26);

  document.querySelector("#sentimentLegend").innerHTML = entries.map(([label, value], index) => `
    <div class="legend-item">
      <span><i class="swatch" style="background:${palette[index]}"></i>${label}</span>
      <strong>${Math.round(value)}%</strong>
    </div>
  `).join("");
}

function drawGauge(canvas, score) {
  const ctx = canvas.getContext("2d");
  const dpr = window.devicePixelRatio || 1;
  const rect = canvas.getBoundingClientRect();
  canvas.width = rect.width * dpr;
  canvas.height = 140 * dpr;
  ctx.scale(dpr, dpr);
  ctx.clearRect(0, 0, rect.width, 140);

  const cx = rect.width / 2;
  const cy = 122;
  const radius = Math.min(86, rect.width * 0.38);
  const start = Math.PI;
  const end = Math.PI * 2;
  const scoreEnd = start + (score / 100) * Math.PI;

  ctx.lineWidth = 18;
  ctx.lineCap = "round";
  ctx.strokeStyle = "#e5ece8";
  ctx.beginPath();
  ctx.arc(cx, cy, radius, start, end);
  ctx.stroke();

  ctx.strokeStyle = score > 68 ? colors.red : score > 48 ? colors.gold : colors.green;
  ctx.beginPath();
  ctx.arc(cx, cy, radius, start, scoreEnd);
  ctx.stroke();

  ctx.fillStyle = colors.ink;
  ctx.font = "800 26px Inter, sans-serif";
  ctx.textAlign = "center";
  ctx.fillText(`${score}%`, cx, 98);
}

function roundRect(ctx, x, y, width, height, radius) {
  ctx.beginPath();
  ctx.moveTo(x + radius, y);
  ctx.arcTo(x + width, y, x + width, y + height, radius);
  ctx.arcTo(x + width, y + height, x, y + height, radius);
  ctx.arcTo(x, y + height, x, y, radius);
  ctx.arcTo(x, y, x + width, y, radius);
  ctx.closePath();
}

function renderMetrics(delta = 0) {
  const risk = getRiskScore();
  document.querySelector("#totalMentions").textContent = formatNumber(state.totalMentions);
  document.querySelector("#mentionDelta").textContent = `+${delta} dalam menit terakhir`;
  document.querySelector("#riskScore").textContent = `${risk}%`;
  document.querySelector("#riskLabel").textContent = risk > 68 ? "Tekanan eskalasi tinggi" : risk > 48 ? "Tekanan eskalasi sedang" : "Tekanan eskalasi rendah";
  document.querySelector("#slaScore").textContent = `${state.sla}%`;
  document.querySelector("#mediaCount").textContent = formatNumber(state.mediaCount);
}

function renderTopics() {
  document.querySelector("#topicList").innerHTML = state.topics.map(topic => `
    <div>
      <div class="topic-item">
        <span class="topic-label"><i class="swatch" style="background:${topic.color}"></i>${topic.name}</span>
        <strong>${topic.value}</strong>
      </div>
      <div class="topic-meter" aria-hidden="true"><span style="width:${topic.value}%;background:${topic.color}"></span></div>
    </div>
  `).join("");
}

function renderFeedback() {
  const selected = sourceFilter.value;
  const keyword = articleSearch.value.trim().toLowerCase();
  const rows = state.feedback
    .filter(item => selected === "all" || item.source === selected)
    .filter(item => !keyword || getArticleSearchText(item).includes(keyword))
    .slice(0, 8);

  if (rows.length === 0) {
    const isFiltered = Boolean(keyword) || selected !== "all";
    feedbackRows.innerHTML = `
      <div class="table-row empty-row" role="row">
        <span>--</span>
        <span><i class="source-pill">Artikel</i></span>
        <span>${isFiltered
          ? `Tidak ada artikel yang cocok dengan filter${keyword ? ` "${escapeHtml(keyword)}"` : ""}.`
          : "Belum ada artikel publik yang berhasil dimuat. Pastikan XAMPP aktif, koneksi internet tersedia, dan endpoint api/scrape.php bisa dibuka."}</span>
        <span><i class="signal-pill neutral">Netral</i></span>
        <span><i class="evidence-pill">Status</i></span>
      </div>
    `;
    return;
  }

  feedbackRows.innerHTML = rows.map(item => `
    <div class="table-row" role="row">
      <span>${item.time}</span>
      <span><i class="source-pill">${escapeHtml(item.sourceName || item.source)}</i></span>
      <span>${renderFeedbackText(item)}</span>
      <span><i class="signal-pill ${getSignalClass(item.signal)}">${item.signal}</i></span>
      <span><i class="evidence-pill">${item.evidence}</i></span>
    </div>
  `).join("");
}

function renderArchive() {
  if (!archiveRows) return;

  syncArchiveMonthFilter();

  const selectedMonth = archiveMonthFilter?.value || "all";
  const keyword = (archiveSearch?.value || "").trim().toLowerCase();
  const archiveItems = getArchiveItems();
  const staleItems = archiveItems.filter(isStaleArticle);
  const rows = archiveItems
    .filter(item => selectedMonth === "all" || getArchiveMonthKey(item) === selectedMonth)
    .filter(item => !keyword || getArticleSearchText(item).includes(keyword) || getArchiveMonthLabel(item).toLowerCase().includes(keyword))
    .slice(0, 60);

  document.querySelector("#archiveCount").textContent = formatNumber(archiveItems.length);
  document.querySelector("#staleCount").textContent = formatNumber(staleItems.length);
  document.querySelector("#archiveLatest").textContent = archiveItems[0] ? getArchiveMonthLabel(archiveItems[0]) : "-";

  if (rows.length === 0) {
    archiveRows.innerHTML = `
      <div class="archive-empty">
        ${archiveItems.length === 0
          ? "Belum ada arsip berita. Arsip akan terisi otomatis setelah API membaca artikel publik."
          : "Tidak ada arsip yang cocok dengan filter saat ini."}
      </div>
    `;
    return;
  }

  const groups = groupArchiveByMonth(rows);
  archiveRows.innerHTML = groups.map(group => `
    <section class="archive-group" aria-label="Arsip ${escapeAttribute(group.label)}">
      <div class="archive-group-header">
        <strong>${escapeHtml(group.label)}</strong>
        <span>${formatNumber(group.items.length)} artikel</span>
      </div>
      <div class="archive-items">
        ${group.items.map(item => `
          <article class="archive-item">
            <time datetime="${escapeAttribute(item.publishedAt || "")}">${escapeHtml(formatArticleDate(item))}</time>
            <div>
              ${renderFeedbackText(item)}
              <span>${escapeHtml(item.sourceName || item.source)} - ${escapeHtml(item.district || "Kabupaten Sleman")}</span>
            </div>
            <i class="signal-pill ${getSignalClass(item.signal)}">${escapeHtml(item.signal)}</i>
          </article>
        `).join("")}
      </div>
    </section>
  `).join("");
}

function getArchiveItems() {
  const merged = [...state.archive, ...state.feedback];
  const byKey = new Map();

  merged.forEach(item => {
    const key = getArticleKey(item);
    if (!key || byKey.has(key)) return;
    byKey.set(key, item);
  });

  return [...byKey.values()].sort((a, b) => getArticleTime(b) - getArticleTime(a));
}

function getArticleKey(item) {
  return item.url || `${item.text}|${item.publishedAt}`;
}

function getArticleTime(item) {
  const date = item.publishedAt ? new Date(item.publishedAt) : null;
  return date && !Number.isNaN(date.getTime()) ? date.getTime() : 0;
}

function isStaleArticle(item) {
  const publishedTime = getArticleTime(item);
  if (!publishedTime) return false;
  const staleCutoff = Date.now() - 30 * 24 * 60 * 60 * 1000;
  return publishedTime < staleCutoff;
}

function syncArchiveMonthFilter() {
  if (!archiveMonthFilter) return;

  const current = archiveMonthFilter.value;
  const months = groupArchiveByMonth(getArchiveItems()).map(group => ({
    key: group.key,
    label: group.label
  }));

  archiveMonthFilter.innerHTML = `
    <option value="all">Semua periode</option>
    ${months.map(month => `<option value="${escapeAttribute(month.key)}">${escapeHtml(month.label)}</option>`).join("")}
  `;

  archiveMonthFilter.value = months.some(month => month.key === current) ? current : "all";
}

function groupArchiveByMonth(items) {
  const groups = new Map();

  items.forEach(item => {
    const key = getArchiveMonthKey(item);
    const label = getArchiveMonthLabel(item);
    if (!groups.has(key)) {
      groups.set(key, { key, label, items: [] });
    }
    groups.get(key).items.push(item);
  });

  return [...groups.values()];
}

function getArchiveMonthKey(item) {
  const date = getArticleTime(item) ? new Date(getArticleTime(item)) : null;
  if (!date) return "tanpa-tanggal";
  return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, "0")}`;
}

function getArchiveMonthLabel(item) {
  const date = getArticleTime(item) ? new Date(getArticleTime(item)) : null;
  if (!date) return "Tanpa tanggal";
  return date.toLocaleDateString("id-ID", { month: "long", year: "numeric" });
}

function formatArticleDate(item) {
  const date = getArticleTime(item) ? new Date(getArticleTime(item)) : null;
  if (!date) return item.time || "--:--";
  return date.toLocaleDateString("id-ID", { day: "2-digit", month: "short", year: "numeric" });
}

function getArticleSearchText(item) {
  return [
    item.time,
    item.source,
    item.sourceName,
    item.text,
    item.signal,
    item.evidence,
    item.url,
    item.district,
    item.publishedAt
  ].join(" ").toLowerCase();
}

function renderDecisionSupport() {
  const decision = getPolicyDecision();

  document.querySelector("#policyPosture").textContent = decision.posture;
  document.querySelector("#recommendations").innerHTML = decision.recommendations.map(item => `<li>${escapeHtml(item)}</li>`).join("");
  drawGauge(riskGauge, decision.risk);
}

function getPolicyDecision() {
  const risk = getRiskScore();
  const posture = risk > 68 ? "Eskalasi" : risk > 48 ? "Investigasi" : "Pantau";
  const topic = state.topics[0]?.name || "Isu Sleman";
  const readinessBase = risk > 68 ? 72 : risk > 48 ? 58 : 42;
  const recommendations = risk > 68
    ? [
      "Buka telaah prioritas untuk klaster aduan tertinggi dan tugaskan verifikasi lapangan di kecamatan terkait.",
      "Terbitkan klarifikasi publik Bawaslu Sleman dalam jendela respons berikutnya.",
      "Minta bukti pendukung dari unggahan dengan foto, screenshot, dan penanda lokasi berulang."
    ]
    : risk > 48
      ? [
        "Teruskan unggahan berinteraksi tinggi ke tim penerimaan aduan untuk validasi.",
        "Siapkan materi edukasi publik untuk isu yang paling cepat naik di Sleman.",
        "Bandingkan kata kunci berulang dengan catatan kasus resmi sebelum rekomendasi kebijakan dibuat."
      ]
      : [
        "Lanjutkan pemantauan otomatis dan jaga cakupan respons untuk lonjakan baru.",
        "Rangkum masukan netral menjadi catatan perbaikan layanan mingguan.",
        "Simpan feedback positif terverifikasi sebagai bukti efektivitas sosialisasi."
      ];
  const detail = risk > 68
    ? "Risiko sedang menuju tinggi. Keputusan perlu diarahkan ke eskalasi cepat, validasi bukti, dan koordinasi wilayah."
    : risk > 48
      ? "Risiko berada di level investigasi. Tim perlu memvalidasi sumber, melihat pola isu, dan menyiapkan respons pencegahan."
      : "Risiko masih rendah. Dashboard menyarankan pemantauan aktif sambil menyimpan pola isu sebagai bahan evaluasi berkala.";

  return { risk, posture, topic, recommendations, detail, readinessBase };
}

function openPolicyDetail() {
  const decision = getPolicyDecision();
  selectedPolicyActions = new Set();
  activePolicyStage = "validasi";
  policyRiskDetail.textContent = `${decision.risk}%`;
  policyPostureDetail.textContent = decision.posture;
  policyTopicDetail.textContent = decision.topic;
  policyModalDetail.textContent = decision.detail;
  renderPolicyWorkspace(decision);
  policyModal.classList.add("show");
  policyModal.setAttribute("aria-hidden", "false");
  document.body.classList.add("modal-open");
}

function renderPolicyWorkspace(decision = getPolicyDecision()) {
  const activeStage = policyStages[activePolicyStage];
  const readyValue = Math.min(100, decision.readinessBase + selectedPolicyActions.size * 10);

  policyStageButtons.forEach(button => {
    button.classList.toggle("active", button.dataset.policyStage === activePolicyStage);
  });
  policyStageLabel.textContent = activeStage.label;
  policyStageTitle.textContent = activeStage.title;
  policyStageDetail.textContent = activeStage.detail;
  policyProgressText.textContent = `${readyValue}% siap ditindaklanjuti`;
  policyProgressBar.style.width = `${readyValue}%`;
  policySelectedCount.textContent = `${selectedPolicyActions.size} dipilih`;
  policyCommitButton.textContent = selectedPolicyActions.size
    ? `Tetapkan ${selectedPolicyActions.size} Rekomendasi`
    : "Tetapkan Rekomendasi";

  policySteps.innerHTML = decision.recommendations.map((item, index) => {
    const actionId = `policy-action-${index}`;
    const isSelected = selectedPolicyActions.has(actionId);
    return `
      <li class="${isSelected ? "selected" : ""}">
        <button type="button" data-policy-action="${actionId}" aria-pressed="${isSelected}">
          <span>${index + 1}</span>
          <strong>${escapeHtml(item)}</strong>
        </button>
      </li>
    `;
  }).join("");
}

function closePolicyDetail() {
  policyModal.classList.remove("show");
  policyModal.setAttribute("aria-hidden", "true");
  document.body.classList.remove("modal-open");
}

function renderRouting() {
  const routes = [
    {
      team: "Penerimaan Aduan",
      detail: `${Math.round(state.topics[0].value * 0.7)} isu perlu dicek dari artikel`,
      priority: "Tinggi",
      sla: "12 jam",
      owner: "Tim Penerimaan Aduan",
      status: "Menunggu verifikasi",
      output: "Catatan telaah awal",
      steps: [
        "Verifikasi judul, tautan sumber, dan waktu publikasi artikel.",
        "Kelompokkan artikel yang mengarah ke dugaan pelanggaran pemilu.",
        "Siapkan catatan awal untuk registrasi atau klarifikasi lanjutan."
      ]
    },
    {
      team: "Komunikasi Publik",
      detail: `${Math.round(state.sentiment.Negatif + state.sentiment.Netral)} artikel perlu klarifikasi atau monitoring`,
      priority: "Sedang",
      sla: "24 jam",
      owner: "Tim Humas dan Pencegahan",
      status: "Perlu respons narasi",
      output: "Draf klarifikasi publik",
      steps: [
        "Identifikasi narasi publik yang berpotensi menimbulkan salah tafsir.",
        "Susun bahan klarifikasi singkat berbasis data artikel.",
        "Pantau ulang sentimen setelah respons atau publikasi klarifikasi."
      ]
    },
    {
      team: "Pengawasan Kecamatan",
      detail: `${Math.round(state.topics[3].value * 0.5)} sinyal lokasi Sleman perlu ditinjau`,
      priority: "Sedang",
      sla: "1-2 hari",
      owner: "Panwaslu Kapanewon",
      status: "Perlu cek wilayah",
      output: "Laporan pemantauan kapanewon",
      steps: [
        "Cocokkan lokasi artikel dengan 17 kapanewon Sleman.",
        "Kirim ringkasan isu ke petugas wilayah terkait.",
        "Laporkan hasil cek lapangan ke dashboard keputusan."
      ]
    }
  ];

  document.querySelector("#routeList").innerHTML = routes.map((route, index) => `
    <button class="route-item" type="button" data-route-index="${index}" aria-label="Buka detail ${escapeAttribute(route.team)}">
      <div>
        <strong>${escapeHtml(route.team)}</strong>
        <span>${escapeHtml(route.detail)}</span>
      </div>
      <span aria-hidden="true">&gt;</span>
    </button>
  `).join("");

  document.querySelectorAll("[data-route-index]").forEach(button => {
    button.addEventListener("click", () => openRouteDetail(routes[Number(button.dataset.routeIndex)]));
  });
}

function openRouteDetail(route) {
  activeRoute = route;
  routeModalTitle.textContent = route.team;
  routeModalDetail.textContent = route.detail;
  routePriority.textContent = route.priority;
  routeSla.textContent = route.sla;
  routeOwner.textContent = route.owner;
  routeStatus.textContent = route.status;
  routeOutput.textContent = route.output;
  routeMarkButton.textContent = route.status === "Diproses" ? "Sudah Diproses" : "Tandai Diproses";
  routeSteps.innerHTML = route.steps.map(step => `<li>${escapeHtml(step)}</li>`).join("");
  routeModal.classList.add("show");
  routeModal.setAttribute("aria-hidden", "false");
  document.body.classList.add("modal-open");
}

function closeRouteDetail() {
  routeModal.classList.remove("show");
  routeModal.setAttribute("aria-hidden", "true");
  document.body.classList.remove("modal-open");
}

function markRouteProcessed() {
  if (!activeRoute) return;
  activeRoute.status = "Diproses";
  routeStatus.textContent = activeRoute.status;
  routeMarkButton.textContent = "Sudah Diproses";
  showToast(`${activeRoute.team} ditandai diproses`);
}

function exportRouteSummary() {
  if (!activeRoute) return;
  const decision = getPolicyDecision();
  const relatedArticles = getRelatedArticlesForRoute(activeRoute).slice(0, 5);
  const topTopics = state.topics.slice(0, 5);
  const generatedAt = new Date().toLocaleString("id-ID", {
    dateStyle: "long",
    timeStyle: "short"
  });
  const recommendationsMarkup = decision.recommendations.map(item => `
    <li>
      <span>!</span>
      <p>${escapeHtml(item)}</p>
    </li>
  `).join("");
  const topicsMarkup = topTopics.map(topic => `
    <div class="topic">
      <strong>${escapeHtml(topic.name)}</strong>
      <span>${Math.round(topic.value)}%</span>
      <i><b style="width:${Math.round(topic.value)}%"></b></i>
    </div>
  `).join("");
  const articlesMarkup = relatedArticles.length
    ? relatedArticles.map((item, index) => `
      <tr>
        <td>${index + 1}</td>
        <td>${escapeHtml(item.time)}</td>
        <td>${escapeHtml(item.sourceName || item.source)}</td>
        <td>
          <strong>${escapeHtml(item.text)}</strong>
          ${item.url ? `<a href="${escapeAttribute(item.url)}">Buka sumber</a>` : ""}
        </td>
        <td>${escapeHtml(item.signal)}</td>
      </tr>
    `).join("")
    : `
      <tr>
        <td colspan="5">Belum ada artikel yang cocok untuk rute ini.</td>
      </tr>
    `;
  const stepsMarkup = activeRoute.steps.map((step, index) => `
    <li>
      <span>${index + 1}</span>
      <p>${escapeHtml(step)}</p>
    </li>
  `).join("");
  const report = `<!doctype html>
<html lang="id">
  <head>
    <meta charset="utf-8">
    <title>Ringkasan Antrian - ${escapeHtml(activeRoute.team)}</title>
    <style>
      :root {
        --ink: #17201c;
        --muted: #63716b;
        --line: #dce5e0;
        --panel: #ffffff;
        --soft: #f3f6f7;
        --red: #b4232f;
        --green: #247a4b;
        --gold: #b7791f;
      }
      * { box-sizing: border-box; }
      body {
        margin: 0;
        padding: 36px;
        background: var(--soft);
        color: var(--ink);
        font-family: Inter, Arial, sans-serif;
        line-height: 1.5;
      }
      main {
        max-width: 820px;
        margin: 0 auto;
        background: var(--panel);
        border: 1px solid var(--line);
        border-radius: 8px;
        overflow: hidden;
      }
      header {
        padding: 30px;
        background: #17201c;
        color: white;
      }
      .eyebrow {
        margin: 0 0 8px;
        color: rgba(255,255,255,.72);
        font-size: 12px;
        font-weight: 800;
        text-transform: uppercase;
      }
      h1 {
        margin: 0;
        font-size: 28px;
        line-height: 1.2;
      }
      section { padding: 24px 28px; }
      .meta {
        display: grid;
        grid-template-columns: repeat(4, minmax(0, 1fr));
        gap: 12px;
      }
      .meta div,
      .topic,
      .summary-box {
        padding: 14px;
        border: 1px solid var(--line);
        border-radius: 8px;
        background: #f7faf8;
      }
      .meta span {
        display: block;
        color: var(--muted);
        font-size: 12px;
        font-weight: 800;
        text-transform: uppercase;
      }
      .meta strong {
        display: block;
        margin-top: 4px;
        font-size: 16px;
      }
      .badge {
        display: inline-block;
        margin-top: 10px;
        padding: 6px 10px;
        border-radius: 999px;
        background: #fff2f0;
        color: var(--red);
        font-weight: 800;
        font-size: 12px;
      }
      .detail {
        margin: 18px 0 0;
        color: var(--muted);
      }
      h2 {
        margin: 0 0 12px;
        font-size: 18px;
      }
      .split {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 14px;
      }
      .summary-box p {
        margin: 8px 0 0;
        color: var(--muted);
      }
      .topic-grid {
        display: grid;
        gap: 10px;
      }
      .topic {
        display: grid;
        grid-template-columns: minmax(0, 1fr) 52px;
        gap: 10px;
        align-items: center;
      }
      .topic strong,
      .topic span {
        display: block;
      }
      .topic span {
        color: var(--muted);
        text-align: right;
        font-weight: 800;
      }
      .topic i {
        grid-column: 1 / -1;
        height: 8px;
        border-radius: 999px;
        background: #e5ece8;
        overflow: hidden;
      }
      .topic b {
        display: block;
        height: 100%;
        border-radius: inherit;
        background: var(--red);
      }
      ol {
        display: grid;
        gap: 10px;
        margin: 0;
        padding: 0;
        list-style: none;
      }
      li {
        display: grid;
        grid-template-columns: 34px minmax(0, 1fr);
        gap: 12px;
        align-items: start;
        padding: 12px;
        border: 1px solid var(--line);
        border-radius: 8px;
        background: #f7faf8;
      }
      li span {
        display: grid;
        place-items: center;
        width: 28px;
        height: 28px;
        border-radius: 50%;
        background: var(--green);
        color: white;
        font-weight: 800;
      }
      li p { margin: 2px 0 0; }
      table {
        width: 100%;
        border-collapse: collapse;
        font-size: 13px;
      }
      th,
      td {
        padding: 10px;
        border-bottom: 1px solid var(--line);
        text-align: left;
        vertical-align: top;
      }
      th {
        color: var(--muted);
        font-size: 11px;
        text-transform: uppercase;
      }
      td strong,
      td a {
        display: block;
      }
      td a {
        margin-top: 4px;
        color: var(--red);
        font-weight: 800;
        text-decoration: none;
      }
      footer {
        padding: 18px 28px;
        border-top: 1px solid var(--line);
        color: var(--muted);
        font-size: 12px;
      }
      @media (max-width: 760px) {
        body { padding: 18px; }
        .meta,
        .split { grid-template-columns: 1fr; }
        table { font-size: 12px; }
      }
      @media print {
        body { padding: 0; background: white; }
        main { border: 0; }
      }
    </style>
  </head>
  <body>
    <main>
      <header>
        <p class="eyebrow">Bawaslu Sleman - Ringkasan Operasional</p>
        <h1>${escapeHtml(activeRoute.team)}</h1>
        <span class="badge">Prioritas ${escapeHtml(activeRoute.priority)}</span>
      </header>
      <section>
        <div class="meta">
          <div><span>Risiko</span><strong>${decision.risk}%</strong></div>
          <div><span>Sikap</span><strong>${escapeHtml(decision.posture)}</strong></div>
          <div><span>Prioritas</span><strong>${escapeHtml(activeRoute.priority)}</strong></div>
          <div><span>SLA</span><strong>${escapeHtml(activeRoute.sla)}</strong></div>
          <div><span>Penanggung Jawab</span><strong>${escapeHtml(activeRoute.owner)}</strong></div>
          <div><span>Status</span><strong>${escapeHtml(activeRoute.status)}</strong></div>
          <div><span>Keluaran</span><strong>${escapeHtml(activeRoute.output)}</strong></div>
          <div><span>Total Artikel</span><strong>${formatNumber(state.totalMentions)}</strong></div>
        </div>
        <p class="detail">${escapeHtml(activeRoute.detail)}</p>
      </section>
      <section class="split">
        <div class="summary-box">
          <h2>Kesimpulan Dashboard</h2>
          <p>${escapeHtml(decision.detail)}</p>
        </div>
        <div class="summary-box">
          <h2>Sentimen Publik</h2>
          <p>Positif ${Math.round(state.sentiment.Positif)}%, netral ${Math.round(state.sentiment.Netral)}%, negatif ${Math.round(state.sentiment.Negatif)}%.</p>
        </div>
      </section>
      <section>
        <h2>Topik Prioritas</h2>
        <div class="topic-grid">${topicsMarkup}</div>
      </section>
      <section>
        <h2>Rekomendasi Kebijakan</h2>
        <ol>${recommendationsMarkup}</ol>
      </section>
      <section>
        <h2>Langkah Tindak Lanjut</h2>
        <ol>${stepsMarkup}</ol>
      </section>
      <section>
        <h2>Artikel Terkait</h2>
        <table>
          <thead>
            <tr>
              <th>No</th>
              <th>Waktu</th>
              <th>Penerbit</th>
              <th>Artikel</th>
              <th>Sentimen</th>
            </tr>
          </thead>
          <tbody>${articlesMarkup}</tbody>
        </table>
      </section>
      <footer>Diekspor pada ${escapeHtml(generatedAt)} dari dashboard Article Intelligence Bawaslu Sleman.</footer>
    </main>
  </body>
</html>`;
  const blob = new Blob([report], { type: "text/html" });
  const url = URL.createObjectURL(blob);
  const link = document.createElement("a");
  link.href = url;
  link.download = `ringkasan-antrian-${activeRoute.team.toLowerCase().replaceAll(" ", "-")}.html`;
  link.click();
  URL.revokeObjectURL(url);
  showToast("Ringkasan antrian diekspor sebagai HTML");
}

function getRelatedArticlesForRoute(route) {
  const routeText = `${route.team} ${route.detail} ${route.steps.join(" ")}`.toLowerCase();
  const keywords = routeText.includes("komunikasi")
    ? ["klarifikasi", "edukasi", "narasi", "sosialisasi", "hoaks"]
    : routeText.includes("kecamatan") || routeText.includes("wilayah")
      ? ["tps", "logistik", "kapanewon", "kecamatan", "wilayah"]
      : ["dugaan", "pelanggaran", "politik uang", "aduan", "lapor"];

  const matched = state.feedback.filter(item => {
    const text = getArticleSearchText(item);
    return keywords.some(keyword => text.includes(keyword));
  });

  return matched.length ? matched : state.feedback;
}

function renderCharts() {
  drawBarChart(sourceChart, getSourcesForRange());
  drawDonutChart(sentimentChart, state.sentiment);
}

function renderAll(delta = 0) {
  renderMetrics(delta);
  renderTopics();
  renderFeedback();
  renderArchive();
  renderDecisionSupport();
  renderRouting();
  renderCharts();
}

async function loadScrapedData() {
  if (state.paused) return;

  try {
    document.querySelector("#syncStatus").textContent = "Mengambil data publik Sleman...";
    const response = await fetch(`${SCRAPE_ENDPOINT}?t=${Date.now()}`, {
      headers: { Accept: "application/json" },
      cache: "no-store"
    });

    if (!response.ok) {
      throw new Error(`HTTP ${response.status}`);
    }

    const payload = await response.json();
    if (!payload.ok || !Array.isArray(payload.feedback)) {
      throw new Error("Format data scraping tidak valid");
    }

    applyScrapedData(payload);
  } catch (error) {
    state.dataMode = "article";
    const errorMessage = getLoadErrorMessage(error);
    document.querySelector("#syncStatus").textContent = errorMessage;
    if (state.lastErrorMessage !== errorMessage) {
      showToast(errorMessage);
      state.lastErrorMessage = errorMessage;
    }
    renderAll(0);
  }
}

function applyScrapedData(payload) {
  const previous = state.lastMentionCount;
  state.dataMode = payload.mode || "article-scraping";
  state.totalMentions = Number(payload.totalMentions || payload.feedback.length || 0);
  state.lastMentionCount = state.totalMentions;
  state.mediaCount = Number(payload.mediaCount || 0);
  state.sla = Number(payload.sla || state.sla);
  state.sources = normalizeSources(payload.sources || {});
  state.sentiment = normalizeSentiment(payload.sentiment || {});
  state.topics = normalizeTopics(payload.topics || []);
  state.feedback = payload.feedback.map(normalizeFeedbackItem);
  state.archive = Array.isArray(payload.archive) ? payload.archive.map(normalizeFeedbackItem) : state.feedback;
  state.lastErrorMessage = "";

  const delta = Math.max(0, state.totalMentions - previous);
  const scrapedAt = payload.scrapedAt ? new Date(payload.scrapedAt) : new Date();
  const cacheLabel = state.dataMode.includes("last-good") ? "cache terakhir" : "artikel real";
  document.querySelector("#syncStatus").textContent = `${cacheLabel}: ${scrapedAt.toLocaleTimeString("id-ID", { hour: "2-digit", minute: "2-digit" })}`;
  renderAll(delta);
}

function tick() {
  if (!state.paused) loadScrapedData();
}

function clamp(value, min, max) {
  return Math.min(max, Math.max(min, value));
}

function getSignalClass(signal) {
  if (signal === "Positif") return "positive";
  if (signal === "Negatif") return "negative";
  return "neutral";
}

function normalizeSources(sources) {
  const entries = Object.entries(sources)
    .map(([source, value]) => [source || "Sumber publik", Number(value || 0)])
    .filter(([, value]) => value >= 0)
    .slice(0, 6);

  return entries.length > 0 ? Object.fromEntries(entries) : { "Artikel Publik": 0 };
}

function normalizeSentiment(sentiment) {
  const defaults = { Positif: 0, Netral: 0, Negatif: 0 };
  const merged = { ...defaults, ...sentiment };
  const total = Object.values(merged).reduce((sum, value) => sum + Number(value || 0), 0);

  if (total <= 0) {
    return { Positif: 0, Netral: 100, Negatif: 0 };
  }

  return {
    Positif: Number(merged.Positif || 0),
    Netral: Number(merged.Netral || 0),
    Negatif: Number(merged.Negatif || 0)
  };
}

function normalizeTopics(topics) {
  const source = Array.isArray(topics) && topics.length > 0 ? topics : state.topics;
  return source.slice(0, 5).map((topic, index) => ({
    name: topic.name || "Isu Sleman",
    value: clamp(Number(topic.value || 0), 12, 100),
    color: topicColors[index] || colors.teal
  }));
}

function normalizeFeedbackItem(item) {
  return {
    time: item.time || "--:--",
    source: item.source || "Artikel Publik",
    sourceName: item.sourceName || item.source || "Sumber publik",
    text: item.text || "Data scraping tanpa judul",
    signal: item.signal || "Netral",
    evidence: item.evidence || "Link",
    url: item.url || "",
    district: item.district || "Kabupaten Sleman",
    publishedAt: item.publishedAt || ""
  };
}

function getSourcesForRange() {
  const cutoff = getRangeCutoff();
  if (!cutoff || state.feedback.length === 0) return state.sources;

  const counts = state.feedback.reduce((result, item) => {
    const published = item.publishedAt ? new Date(item.publishedAt) : null;
    if (!published || Number.isNaN(published.getTime()) || published < cutoff) return result;
    const label = item.sourceName || item.source || "Sumber publik";
    result[label] = (result[label] || 0) + 1;
    return result;
  }, {});

  return Object.keys(counts).length > 0 ? normalizeSources(counts) : { "Belum ada artikel": 0 };
}

function getRangeCutoff() {
  const hours = state.range === "30d" ? 720 : state.range === "7d" ? 168 : 24;
  return new Date(Date.now() - hours * 60 * 60 * 1000);
}

function getLoadErrorMessage(error) {
  const message = String(error?.message || "");
  if (message.includes("HTTP")) return "API scraping merespons gagal";
  if (message.includes("Format data")) return "Format data API tidak valid";
  return "RSS/API belum dapat diakses";
}

function renderFeedbackText(item) {
  const text = escapeHtml(item.text);
  if (!item.url) return text;
  return `<a class="feedback-link" href="${escapeAttribute(item.url)}" target="_blank" rel="noopener noreferrer">${text}</a>`;
}

function escapeHtml(value) {
  return String(value)
    .replaceAll("&", "&amp;")
    .replaceAll("<", "&lt;")
    .replaceAll(">", "&gt;")
    .replaceAll('"', "&quot;")
    .replaceAll("'", "&#039;");
}

function escapeAttribute(value) {
  return escapeHtml(value).replaceAll("`", "&#096;");
}

function drawEmptyChart(ctx, width, height, label) {
  ctx.clearRect(0, 0, width, height);
  ctx.fillStyle = "#63716b";
  ctx.font = "700 14px Inter, sans-serif";
  ctx.textAlign = "center";
  ctx.fillText(label, width / 2, height / 2);
}

function drawWrappedLabel(ctx, text, x, y, maxWidth, maxLines) {
  const words = String(text).split(/\s+/);
  const lines = [];
  let line = "";

  words.forEach(word => {
    const testLine = line ? `${line} ${word}` : word;
    if (ctx.measureText(testLine).width <= maxWidth) {
      line = testLine;
      return;
    }

    if (line) lines.push(line);
    line = word;
  });

  if (line) lines.push(line);
  lines.slice(0, maxLines).forEach((item, index) => {
    const suffix = index === maxLines - 1 && lines.length > maxLines ? "..." : "";
    ctx.fillText(`${item}${suffix}`, x, y + index * 14);
  });
}

function showToast(message) {
  toast.textContent = message;
  toast.classList.add("show");
  window.setTimeout(() => toast.classList.remove("show"), 2500);
}

function setActiveNav(sectionId) {
  navLinks.forEach(link => {
    link.classList.toggle("active", link.getAttribute("href") === `#${sectionId}`);
  });
}

function setupScrollSpy() {
  const sections = [...navLinks]
    .map(link => document.querySelector(link.getAttribute("href")))
    .filter(Boolean);

  if (!sections.length) return;

  const observer = new IntersectionObserver(entries => {
    const visibleEntry = entries
      .filter(entry => entry.isIntersecting)
      .sort((a, b) => b.intersectionRatio - a.intersectionRatio)[0];

    if (visibleEntry) setActiveNav(visibleEntry.target.id);
  }, {
    root: null,
    rootMargin: "-18% 0px -58% 0px",
    threshold: [0.12, 0.32, 0.56]
  });

  sections.forEach(section => observer.observe(section));
}

document.querySelectorAll("[data-range]").forEach(button => {
  button.addEventListener("click", () => {
    document.querySelectorAll("[data-range]").forEach(item => item.classList.remove("active"));
    button.classList.add("active");
    state.range = button.dataset.range;
    renderCharts();
  });
});

sourceFilter.addEventListener("change", renderFeedback);
articleSearch.addEventListener("input", renderFeedback);
archiveSearch?.addEventListener("input", renderArchive);
archiveMonthFilter?.addEventListener("change", renderArchive);

navLinks.forEach(link => {
  link.addEventListener("click", () => {
    const targetId = link.getAttribute("href").slice(1);
    setActiveNav(targetId);
  });
});

pauseButton.addEventListener("click", () => {
  state.paused = !state.paused;
  pauseButton.setAttribute("aria-label", state.paused ? "Lanjutkan pembaruan langsung" : "Jeda pembaruan langsung");
  pauseButton.setAttribute("title", state.paused ? "Lanjutkan pembaruan langsung" : "Jeda pembaruan langsung");
  pauseButton.querySelector("span").textContent = state.paused ? "Lanjut" : "Jeda";
  showToast(state.paused ? "Pembaruan live dijeda" : "Pembaruan live dilanjutkan");
});

exportButton.addEventListener("click", () => {
  const risk = getRiskScore();
  const decision = getPolicyDecision();
  const generatedAt = new Date().toLocaleString("id-ID", { dateStyle: "long", timeStyle: "short" });
  const sources = Object.entries(getSourcesForRange());
  const articleRows = state.feedback.slice(0, 10).map((item, index) => `
    <tr>
      <td>${index + 1}</td>
      <td>${escapeHtml(item.time)}</td>
      <td>${escapeHtml(item.sourceName || item.source)}</td>
      <td>
        <strong>${escapeHtml(item.text)}</strong>
        ${item.url ? `<a href="${escapeAttribute(item.url)}">Buka sumber</a>` : ""}
      </td>
      <td>${escapeHtml(item.signal)}</td>
      <td>${escapeHtml(item.district)}</td>
    </tr>
  `).join("");
  const topicRows = state.topics.map(topic => `
    <tr>
      <td>${escapeHtml(topic.name)}</td>
      <td>${Math.round(topic.value)}%</td>
      <td><i class="bar"><b style="width:${Math.round(topic.value)}%"></b></i></td>
    </tr>
  `).join("");
  const sourceRows = sources.map(([source, count]) => `
    <tr>
      <td>${escapeHtml(source)}</td>
      <td>${formatNumber(count)}</td>
    </tr>
  `).join("");
  const recommendationRows = decision.recommendations.map((item, index) => `
    <li>
      <span>${index + 1}</span>
      <p>${escapeHtml(item)}</p>
    </li>
  `).join("");
  const report = `<!doctype html>
<html lang="id">
  <head>
    <meta charset="utf-8">
    <title>Laporan Dashboard Bawaslu Sleman</title>
    <style>
      :root {
        --ink: #17201c;
        --muted: #63716b;
        --line: #dce5e0;
        --soft: #f3f6f7;
        --panel: #ffffff;
        --red: #b4232f;
        --green: #247a4b;
      }
      * { box-sizing: border-box; }
      body {
        margin: 0;
        padding: 34px;
        background: var(--soft);
        color: var(--ink);
        font-family: Inter, Arial, sans-serif;
        line-height: 1.5;
      }
      main {
        max-width: 960px;
        margin: 0 auto;
        background: var(--panel);
        border: 1px solid var(--line);
        border-radius: 8px;
        overflow: hidden;
      }
      header {
        padding: 30px;
        background: #17201c;
        color: white;
      }
      .eyebrow {
        margin: 0 0 8px;
        color: rgba(255,255,255,.72);
        font-size: 12px;
        font-weight: 800;
        text-transform: uppercase;
      }
      h1 {
        margin: 0;
        font-size: 30px;
        line-height: 1.15;
      }
      h2 {
        margin: 0 0 12px;
        font-size: 18px;
      }
      section {
        padding: 24px 30px;
        border-top: 1px solid var(--line);
      }
      .meta {
        display: grid;
        grid-template-columns: repeat(4, minmax(0, 1fr));
        gap: 12px;
      }
      .box {
        padding: 14px;
        border: 1px solid var(--line);
        border-radius: 8px;
        background: #f7faf8;
      }
      .box span {
        display: block;
        color: var(--muted);
        font-size: 12px;
        font-weight: 800;
        text-transform: uppercase;
      }
      .box strong {
        display: block;
        margin-top: 5px;
        font-size: 18px;
      }
      .summary {
        margin: 0;
        color: var(--muted);
      }
      .split {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 16px;
      }
      table {
        width: 100%;
        border-collapse: collapse;
        font-size: 13px;
      }
      th,
      td {
        padding: 10px;
        border-bottom: 1px solid var(--line);
        text-align: left;
        vertical-align: top;
      }
      th {
        color: var(--muted);
        font-size: 11px;
        text-transform: uppercase;
      }
      td strong,
      td a {
        display: block;
      }
      td a {
        margin-top: 4px;
        color: var(--red);
        font-weight: 800;
        text-decoration: none;
      }
      .bar {
        display: block;
        height: 8px;
        min-width: 120px;
        border-radius: 999px;
        background: #e5ece8;
        overflow: hidden;
      }
      .bar b {
        display: block;
        height: 100%;
        border-radius: inherit;
        background: var(--red);
      }
      ol {
        display: grid;
        gap: 10px;
        margin: 0;
        padding: 0;
        list-style: none;
      }
      li {
        display: grid;
        grid-template-columns: 34px minmax(0, 1fr);
        gap: 12px;
        padding: 12px;
        border: 1px solid var(--line);
        border-radius: 8px;
        background: #f7faf8;
      }
      li span {
        display: grid;
        place-items: center;
        width: 28px;
        height: 28px;
        border-radius: 50%;
        background: var(--green);
        color: white;
        font-weight: 800;
      }
      li p { margin: 2px 0 0; }
      footer {
        padding: 18px 30px;
        border-top: 1px solid var(--line);
        color: var(--muted);
        font-size: 12px;
      }
      @media (max-width: 760px) {
        body { padding: 16px; }
        .meta,
        .split { grid-template-columns: 1fr; }
        table { font-size: 12px; }
      }
      @media print {
        body { padding: 0; background: white; }
        main { border: 0; }
      }
    </style>
  </head>
  <body>
    <main>
      <header>
        <p class="eyebrow">Laporan Dashboard Pemantauan Kabupaten Sleman</p>
        <h1>Intelijen Artikel Publik Bawaslu Sleman</h1>
      </header>
      <section>
        <div class="meta">
          <div class="box"><span>Total Artikel</span><strong>${formatNumber(state.totalMentions)}</strong></div>
          <div class="box"><span>Risiko Aduan</span><strong>${risk}%</strong></div>
          <div class="box"><span>Sikap Kebijakan</span><strong>${escapeHtml(decision.posture)}</strong></div>
          <div class="box"><span>Sumber</span><strong>${formatNumber(state.mediaCount)}</strong></div>
          <div class="box"><span>SLA Respons</span><strong>${state.sla}%</strong></div>
          <div class="box"><span>Topik Utama</span><strong>${escapeHtml(decision.topic)}</strong></div>
          <div class="box"><span>Rentang Grafik</span><strong>${escapeHtml(state.range)}</strong></div>
          <div class="box"><span>Dibuat</span><strong>${escapeHtml(generatedAt)}</strong></div>
        </div>
      </section>
      <section>
        <h2>Ringkasan Eksekutif</h2>
        <p class="summary">${escapeHtml(decision.detail)}</p>
      </section>
      <section class="split">
        <div>
          <h2>Sentimen Publik</h2>
          <table>
            <tbody>
              <tr><th>Positif</th><td>${Math.round(state.sentiment.Positif)}%</td></tr>
              <tr><th>Netral</th><td>${Math.round(state.sentiment.Netral)}%</td></tr>
              <tr><th>Negatif</th><td>${Math.round(state.sentiment.Negatif)}%</td></tr>
            </tbody>
          </table>
        </div>
        <div>
          <h2>Sumber Data</h2>
          <table>
            <thead><tr><th>Penerbit</th><th>Artikel</th></tr></thead>
            <tbody>${sourceRows}</tbody>
          </table>
        </div>
      </section>
      <section>
        <h2>Topik Prioritas</h2>
        <table>
          <thead><tr><th>Topik</th><th>Skor</th><th>Intensitas</th></tr></thead>
          <tbody>${topicRows}</tbody>
        </table>
      </section>
      <section>
        <h2>Rekomendasi Keputusan</h2>
        <ol>${recommendationRows}</ol>
      </section>
      <section>
        <h2>Artikel Terbaru</h2>
        <table>
          <thead>
            <tr>
              <th>No</th>
              <th>Waktu</th>
              <th>Penerbit</th>
              <th>Judul</th>
              <th>Sentimen</th>
              <th>Wilayah</th>
            </tr>
          </thead>
          <tbody>${articleRows || '<tr><td colspan="6">Belum ada artikel yang tersedia.</td></tr>'}</tbody>
        </table>
      </section>
      <footer>Dokumen diekspor otomatis dari dashboard. Analisis berbasis artikel publik dan tetap memerlukan validasi petugas.</footer>
    </main>
  </body>
</html>`;
  const blob = new Blob([report], { type: "text/html" });
  const url = URL.createObjectURL(blob);
  const link = document.createElement("a");
  link.href = url;
  link.download = "laporan-dashboard-bawaslu-sleman.html";
  link.click();
  URL.revokeObjectURL(url);
  showToast("Laporan dashboard lengkap diekspor sebagai HTML");
});

document.querySelectorAll("[data-close-route]").forEach(button => {
  button.addEventListener("click", closeRouteDetail);
});

routeMarkButton.addEventListener("click", markRouteProcessed);
routeSummaryButton.addEventListener("click", exportRouteSummary);

document.querySelectorAll("[data-close-policy]").forEach(button => {
  button.addEventListener("click", closePolicyDetail);
});

policyStageButtons.forEach(button => {
  button.addEventListener("click", () => {
    activePolicyStage = button.dataset.policyStage;
    renderPolicyWorkspace();
  });
});

policySteps.addEventListener("click", event => {
  const actionButton = event.target.closest("[data-policy-action]");
  if (!actionButton) return;

  const actionId = actionButton.dataset.policyAction;
  if (selectedPolicyActions.has(actionId)) {
    selectedPolicyActions.delete(actionId);
  } else {
    selectedPolicyActions.add(actionId);
  }
  renderPolicyWorkspace();
});

policyCommitButton.addEventListener("click", () => {
  const count = selectedPolicyActions.size;
  showToast(count ? `${count} rekomendasi ditetapkan untuk tindak lanjut` : "Pilih minimal satu prioritas aksi");
});

policyResetButton.addEventListener("click", () => {
  selectedPolicyActions.clear();
  activePolicyStage = "validasi";
  renderPolicyWorkspace();
  showToast("Pilihan dukungan keputusan direset");
});

decisionPanel.addEventListener("click", openPolicyDetail);
decisionPanel.addEventListener("keydown", event => {
  if (event.key === "Enter" || event.key === " ") {
    event.preventDefault();
    openPolicyDetail();
  }
});

window.addEventListener("keydown", event => {
  if (event.key === "Escape" && routeModal.classList.contains("show")) {
    closeRouteDetail();
  }
  if (event.key === "Escape" && policyModal.classList.contains("show")) {
    closePolicyDetail();
  }
});

window.addEventListener("resize", () => renderAll());

renderAll();
setupScrollSpy();
loadScrapedData();
window.setInterval(tick, 60000);
