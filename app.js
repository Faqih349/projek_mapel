const {
  courses,
  tasks,
  lessons,
  student,
  csrfToken,
  completedLessons,
  leaderboard,
  studentDirectory = [],
  pageContents: initialPageContents,
  classGroups: initialClassGroups,
  libraryItems: initialLibraryItems,
} = window.LMS_DATA;

let libraryItems = Array.isArray(initialLibraryItems)
  ? initialLibraryItems
  : [];
let pageContents =
  initialPageContents && typeof initialPageContents === "object"
    ? initialPageContents
    : {};
let classGroups = Array.isArray(initialClassGroups) ? initialClassGroups : [];
let activeClassGroup = null;
let activeClassTab = "materi";
const isTeacher = student.role === "teacher";
function initGlobalSearch() {
  const actions = document.querySelector(".top-actions");
  const topbar = document.querySelector(".topbar");
  if (!actions || !topbar || document.getElementById("globalSearch")) return;
  const wrap = document.createElement("div");
  wrap.className = "global-search-wrap";
  wrap.innerHTML =
    '<label class="sr-only" for="globalSearch">Pencarian</label><span class="global-search-icon" aria-hidden="true">⌕</span><input id="globalSearch" class="global-search" type="search" autocomplete="off" placeholder="Cari materi, tugas, atau kelas" aria-label="Cari materi, tugas, atau kelas" aria-controls="globalSearchResults"><div id="globalSearchResults" class="global-search-results" hidden></div>';
  topbar.insertBefore(wrap, actions);
  const input = wrap.querySelector("#globalSearch");
  const results = wrap.querySelector("#globalSearchResults");
  const entries = [
    ...classGroups.map((item) => ({
      title: item.className,
      meta: "Kelas · " + item.teacherName,
      page: "kelas",
    })),
    ...lessons.map((item) => ({
      title: item[0],
      meta: "Materi · " + item[2],
      page: "materi",
    })),
    ...tasks.map((item) => ({
      title: item.title,
      meta:
        (item.type === "quiz" ? "Kuis" : "Tugas") + " · " + item.courseTitle,
      page: "tugas",
      id: item.id,
    })),
    ...libraryItems.map((item) => ({
      title: item.title,
      meta: "Perpustakaan",
      page: "perpustakaan",
    })),
  ];
  const close = () => {
    results.hidden = true;
    input.setAttribute("aria-expanded", "false");
  };
  input.addEventListener("focus", () => {
    input.setAttribute("aria-expanded", "true");
  });
  input.addEventListener("input", () => {
    const query = input.value.trim().toLowerCase();
    if (!query) {
      close();
      return;
    }
    const matches = entries
      .filter((item) =>
        `${item.title} ${item.meta}`.toLowerCase().includes(query),
      )
      .slice(0, 7);
    results.innerHTML = matches.length
      ? matches
          .map(
            (item) =>
              `<button type="button" class="global-search-result" data-search-page="${item.page}" ${item.id ? `data-search-task="${escapeHtml(item.id)}"` : ""}><span class="global-search-result-icon" aria-hidden="true">${item.page === "materi" ? "▣" : item.page === "tugas" ? "☑" : item.page === "kelas" ? "▦" : "▤"}</span><span><b>${escapeHtml(item.title)}</b><small>${escapeHtml(item.meta)}</small></span></button>`,
          )
          .join("")
      : '<p class="global-search-empty">Tidak ada hasil yang cocok.</p>';
    results.hidden = false;
    input.setAttribute("aria-expanded", "true");
  });
  results.addEventListener("click", (event) => {
    const result = event.target.closest("[data-search-page]");
    if (!result) return;
    const pageKey = result.dataset.searchPage;
    close();
    input.value = "";
    render(pageKey);
    if (result.dataset.searchTask)
      setTimeout(() => openAssignment(result.dataset.searchTask), 0);
  });
  document.addEventListener("click", (event) => {
    if (!wrap.contains(event.target)) close();
  });
}
window.addEventListener("load", initGlobalSearch);
const page = document.getElementById("pageContent");
const toast = document.getElementById("toast");
let timer;
let currentPage = "beranda";
let activeExamTaskId = null;
let examTabWasHidden = false;
function notify(msg) {
  toast.textContent = msg;
  toast.classList.add("show");
  clearTimeout(timer);
  timer = setTimeout(() => toast.classList.remove("show"), 2600);
}
function escapeHtml(value) {
  return String(value ?? "").replace(
    /[&<>"']/g,
    (char) =>
      ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" })[
        char
      ],
  );
}
function pageContentSection(pageKey) {
  const items =
    pageKey === "informasi" && !isTeacher
      ? [
          ...(pageContents.informasi || []).map((item) => ({
            ...item,
            className: "Semua kelas",
          })),
          ...classGroups.flatMap((group) =>
            (pageContents[`class_${group.id}_informasi`] || []).map((item) => ({
              ...item,
              className: group.className,
            })),
          ),
        ]
      : Array.isArray(pageContents[pageKey])
        ? pageContents[pageKey]
        : [];
  const titles = {
    beranda: "Info beranda",
    kelas: "Info kelas",
    materi: "Info materi belajar",
    tugas: "Info tugas & kuis",
    informasi: "Informasi kelas",
    leaderboard: "Info leaderboard",
    bantuan: "Panduan tambahan",
  };
  if (pageKey.endsWith("_informasi")) titles[pageKey] = "Informasi kelas";
  if (!items.length && !isTeacher && pageKey !== "informasi") return "";
  return `<section class="panel editable-page-content"><div class="panel-heading"><div><h2>${titles[pageKey] || "Konten halaman"}</h2><p>${isTeacher ? "Guru dapat menambah, mengubah, atau menghapus isi pada halaman ini." : "Informasi dan catatan dari guru."}</p></div>${isTeacher ? `<button class="primary-button" type="button" data-add-page-content="${pageKey}">＋ Tambah isi</button>` : ""}</div>${items.length ? `<div class="editable-content-list">${items.map((item) => `<article class="editable-content-card">${item.category || item.className ? `<span class="information-category">${escapeHtml(item.category || item.className)}</span>` : ""}<h3>${escapeHtml(item.title)}</h3><p>${escapeHtml(item.body).replace(/\n/g, "<br>")}</p>${isTeacher ? `<div class="editable-content-actions"><button class="outline-button" type="button" data-edit-page-content="${pageKey}" data-content-id="${escapeHtml(item.id)}">Ubah</button><button class="content-delete-button" type="button" data-delete-page-content="${pageKey}" data-content-id="${escapeHtml(item.id)}">Hapus</button></div>` : ""}</article>`).join("")}</div>` : `<p class="empty-note">Belum ada isi tambahan pada halaman ini.</p>`}</section>`;
}
function openPageContentEditor(pageKey, id = "") {
  if (!isTeacher) return;
  const item = (pageContents[pageKey] || []).find(
    (entry) => entry.id === id,
  ) || { category: "", title: "", body: "" };
  document.getElementById("dialogContent").innerHTML =
    `<div class="eyebrow">ISI HALAMAN · ${escapeHtml(pageKey.toUpperCase())}</div><h2>${id ? "Ubah isi" : "Tambah isi"}</h2><label class="editor-label" for="contentCategory">Kategori (opsional)</label><input class="editor-input" id="contentCategory" maxlength="40" value="${escapeHtml(item.category || "")}" placeholder="Contoh: PENGUMUMAN"><label class="editor-label" for="contentTitle">Judul</label><input class="editor-input" id="contentTitle" maxlength="120" value="${escapeHtml(item.title)}" required><label class="editor-label" for="contentBody">Isi</label><textarea class="editor-input editor-textarea" id="contentBody" maxlength="10000" required placeholder="Tuliskan isi yang akan dilihat murid">${escapeHtml(item.body)}</textarea><div class="editor-actions"><button type="button" class="outline-button" id="cancelContentEdit">Batal</button><button type="button" class="primary-button" id="savePageContent" data-content-page="${pageKey}" data-content-id="${escapeHtml(id)}">Simpan isi</button></div>`;
  document.getElementById("lessonDialog").showModal();
}
function courseCard(c) {
  return `<article class="course-card clickable-card" data-courseopen="${c.id}" tabindex="0" role="button" aria-label="Buka kelas ${escapeHtml(c.title)}"><div class="course-cover ${c.cover}"><span class="tag">${escapeHtml(c.title)}</span><span class="cover-mark">${c.mark}</span></div><div class="course-body"><h3>${escapeHtml(c.title)}</h3><p>${escapeHtml(c.unit)}</p><div class="course-meta"><span>${escapeHtml(c.teacher)}</span><span>${c.lessons} materi</span></div><div class="progress-track"><div class="progress-fill" style="width:${c.progress}%"></div></div><div class="course-footer"><span>${c.done} dari ${c.lessons} materi selesai</span><b>${c.progress}%</b></div><div class="course-open-label">Buka kelas <span>→</span></div></div></article>`;
}
function taskRows() {
  return tasks
    .map((t) =>
      isTeacher
        ? `<div class="task-row"><span class="task-icon">${t.icon}</span><span class="task-text"><b>${escapeHtml(t.title)}</b><small>${escapeHtml(t.meta)}</small></span><span class="task-status">${t.type === "quiz" ? "Kuis" : "Tugas"}</span></div>`
        : `<button class="task-row task-row-button" data-task="${t.id}"><span class="task-icon">${t.icon}</span><span class="task-text"><b>${escapeHtml(t.title)}</b><small>${escapeHtml(t.meta)}</small></span><span class="task-status ${t.kind === "done" ? "done" : ""}">${escapeHtml(t.status)}</span></button>`,
    )
    .join("");
}
function classTaskRow(t) {
  return isTeacher
    ? `<div class="task-row"><span class="task-icon">${t.icon}</span><span class="task-text"><b>${escapeHtml(t.title)}</b><small>${t.type === "quiz" ? "Kuis" : "Tugas"} · ${escapeHtml(t.due)}</small></span><span class="task-status">Dikelola guru</span></div>`
    : `<button class="task-row task-row-button" data-task="${escapeHtml(t.id)}"><span class="task-icon">${t.icon}</span><span class="task-text"><b>${escapeHtml(t.title)}</b><small>${t.type === "quiz" ? "Kuis" : "Tugas"} · ${escapeHtml(t.due)}</small></span><span class="task-status ${t.kind === "done" ? "done" : ""}">${escapeHtml(t.status)}</span></button>`;
}
function classJoinForm() {
  return `<div class="class-join-form"><label class="sr-only" for="joinClassCode">Kode kelas dari guru</label><input id="joinClassCode" class="editor-input" maxlength="8" autocomplete="off" placeholder="Contoh: A1B2C3D4" aria-label="Kode kelas dari guru"><button type="button" class="primary-button" id="joinClass">Gabung kelas</button></div>`;
}
function classAccessPanel() {
  if (isTeacher)
    return `<section class="panel class-access-panel"><div><div class="eyebrow">RUANG GURU</div><h2>Kelas belajar</h2><p>Buat kelas, lalu bagikan kode undangannya kepada siswa.</p></div><button type="button" class="primary-button" data-page="kelas">Kelola ${classGroups.length} kelas &#8594;</button></section>`;
  return `<section class="panel class-access-panel student-join-panel"><div><div class="eyebrow">KELAS BELAJAR</div><h2>${classGroups.length ? "Gabung kelas lain" : "Mulai dari kelasmu"}</h2><p>Minta kode 8 karakter kepada guru, lalu masukkan di sini.</p>${classJoinForm()}</div><button type="button" class="outline-button" data-page="kelas">${classGroups.length ? `Lihat ${classGroups.length} kelas` : "Kelas saya"}</button></section>`;
}
function classGroupCard(group) {
  return `<article class="class-group-card"><div class="class-group-icon">▤</div><div class="class-group-details"><h3>${escapeHtml(group.className)}</h3><p>${escapeHtml(group.courseTitle)} · Guru: ${escapeHtml(group.teacherName)}</p>${isTeacher ? `<div class="class-code-line"><span>Kode kelas</span><code>${escapeHtml(group.joinCode)}</code><button type="button" class="outline-button" data-copy-code="${escapeHtml(group.joinCode)}">Salin kode</button><small>${group.memberCount} siswa</small></div>` : ""}</div><div class="class-group-actions"><button type="button" class="primary-button" data-classopen="${group.id}">Buka kelas →</button>${isTeacher ? `<button type="button" class="content-delete-button" data-delete-class="${group.id}" data-class-name="${escapeHtml(group.className)}">Hapus kelas</button>` : `<button type="button" class="content-delete-button" data-leave-class="${group.id}" data-class-name="${escapeHtml(group.className)}">Keluar kelas</button>`}</div></article>`;
}
function openClassEditor() {
  if (!isTeacher) return;
  document.getElementById("dialogContent").innerHTML =
    `<div class="eyebrow">RUANG GURU</div><h2>Buat kelas baru</h2><label class="editor-label" for="newClassName">Nama kelas</label><input class="editor-input" id="newClassName" maxlength="100" placeholder="Contoh: Pendidikan Agama &middot; XI A" required><p class="account-help">Kode undangan akan dibuat otomatis setelah kelas disimpan.</p><div class="editor-actions"><button type="button" class="outline-button" id="cancelClassCreate">Batal</button><button type="button" class="primary-button" id="saveClass">Buat kelas</button></div>`;
  document.getElementById("lessonDialog").showModal();
}
function home() {
  if (isTeacher) return teacherHome();
  const date = new Date();
  const pendingTasks = studentTasks().filter((task) => !task.submitted);
  return `<div class="welcome-row student-welcome"><div><div class="eyebrow">${date.toLocaleDateString("id-ID", { weekday: "long", day: "numeric", month: "long", year: "numeric" }).toUpperCase()}</div><h1>Assalamu'alaikum, ${escapeHtml(student.name)} <span style="font-size:21px">&#10022;</span></h1><p>Siap belajar dan bertumbuh hari ini? Lanjutkan dari kelas yang kamu ikuti.</p></div><div class="date-chip">${classGroups.length} kelas diikuti</div></div><section class="metrics student-metrics"><div class="metric"><div class="metric-icon">&#9638;</div><div><strong>${classGroups.length}</strong><span>Kelas diikuti</span></div></div><div class="metric"><div class="metric-icon">&#9745;</div><div><strong>${pendingTasks.length}</strong><span>Tugas menunggu</span></div></div><div class="metric"><div class="metric-icon">&#9635;</div><div><strong>${completedLessons.length}</strong><span>Materi selesai</span></div></div><div class="metric"><div class="metric-icon">&#10003;</div><div><strong>${studentTasks().filter((task) => task.type === "quiz" && task.submitted).length}</strong><span>Kuis selesai</span></div></div></section>${classAccessPanel()}<div class="section-heading"><div><h2>Kelas yang kamu ikuti</h2><p>Guru dan materi belajar kelasmu ada di sini.</p></div><button class="text-link" data-page="kelas">Lihat semua kelas &nbsp; &#8594;</button></div>${classGroups.length ? `<section class="class-group-list student-home-classes">${classGroups.map((group) => classGroupCard(group)).join("")}</section>` : `<section class="panel student-empty-classes"><span class="student-empty-icon">&#9638;</span><div><b>Belum ada kelas yang diikuti</b><p>Gabung dengan kode kelas dari guru untuk melihat materi dan tugasmu.</p></div><button class="outline-button" data-page="kelas">Masukkan kode kelas</button></section>`}<div class="lower-grid student-home-lower"><section class="panel"><div class="panel-heading"><div><h2>Tugas berikutnya</h2><p>${pendingTasks.length ? `${pendingTasks.length} tugas belum selesai` : "Semua tugas sudah dikerjakan"}</p></div><button data-page="tugas">Lihat semua &#8594;</button></div>${
    pendingTasks
      .slice(0, 4)
      .map(
        (task) =>
          `<button class="task-row task-row-button" data-task="${escapeHtml(task.id)}"><span class="task-icon">${escapeHtml(task.icon)}</span><span class="task-text"><b>${escapeHtml(task.title)}</b><small>${escapeHtml(task.meta)}</small></span><span class="task-status">Kerjakan &#8594;</span></button>`,
      )
      .join("") ||
    '<p class="empty-note">Belum ada tugas baru dari kelasmu.</p>'
  }</section><section class="panel student-home-tip"><div class="quote-label">&#10022; &nbsp; PENGINGAT BELAJAR</div><blockquote>Belajar sedikit demi sedikit, lalu amalkan ilmunya dalam kebaikan.</blockquote><button class="outline-button" data-page="materi">Buka materi belajar &#8594;</button></section></div>`;
}
function studentCourseIds() {
  return new Set(
    activeClassGroup
      ? [String(activeClassGroup.courseId)]
      : classGroups.map((group) => String(group.courseId)),
  );
}
function studentTasks() {
  const visible = isTeacher
    ? tasks
    : tasks.filter((task) =>
        classGroups.some(
          (group) => Number(group.id) === Number(task.classGroupId),
        ),
      );
  return activeClassGroup
    ? visible.filter(
        (task) => Number(task.classGroupId) === Number(activeClassGroup.id),
      )
    : visible;
}
function teacherHome() {
  const reflections = [
    "Ilmu yang diamalkan menumbuhkan kebaikan.",
    "Langkah kecil yang konsisten membawa perubahan.",
    "Sabar dalam belajar adalah bagian dari keberhasilan.",
    "Jadikan setiap ilmu sebagai jalan untuk memberi manfaat.",
    "Hari ini kesempatan baru untuk memperbaiki diri.",
    "Akhlak yang baik membuat ilmu semakin bermakna.",
    "Terus belajar, lalu bagikan kebaikan kepada sekitar.",
  ];
  const date = new Date();
  const key = new Date(
    date.getFullYear(),
    date.getMonth(),
    date.getDate(),
  ).getTime();
  const reflection =
    reflections[Math.floor(key / 86400000) % reflections.length];
  return `<div class="welcome-row"><div><div class="eyebrow">RUANG GURU</div><h1>Assalamu'alaikum, ${escapeHtml(student.name)} <span style="font-size:21px">&#10022;</span></h1><p>Kelola kelas dan dampingi perjalanan belajar siswa.</p></div><div class="date-chip">${classGroups.length} kelas aktif</div></div>${classAccessPanel()}<section class="teacher-home-grid"><article class="panel teacher-home-card"><div class="teacher-home-icon">&#9635;</div><div><span>Materi belajar</span><b>${lessons.length}</b><button type="button" data-page="materi">Kelola materi</button></div></article><article class="panel teacher-home-card"><div class="teacher-home-icon">&#10003;</div><div><span>Tugas & kuis</span><b>${tasks.length}</b><button type="button" data-page="tugas">Kelola tugas</button></div></article><article class="panel teacher-home-card"><div class="teacher-home-icon">&#9830;</div><div><span>Perpustakaan</span><b>${libraryItems.length}</b><button type="button" data-page="perpustakaan">Buka perpustakaan</button></div></article></section><section class="panel quote-panel teacher-reflection"><div class="quote-label">&#10022; &nbsp; RENUNGAN HARI INI</div><blockquote>${reflection}</blockquote><div class="quote-source"><b>Pengingat harian</b> &middot; ${date.toLocaleDateString("id-ID", { weekday: "long", day: "numeric", month: "long", year: "numeric" })}</div></section>`;
}
function libraryPage() {
  const books = libraryItems.filter((item) => item.category === "textbook");
  return `<div class="library-page"><div class="page-head library-page-head"><div><div class="eyebrow">BAHAN BACAAN</div><h1 class="page-title">Perpustakaan</h1><p class="page-subtitle">Al-Qur'an digital dan koleksi buku paket untuk kelas 10, 11, dan 12.</p></div>${isTeacher ? '<button type="button" class="primary-button" id="addLibraryItem">&#43; Unggah buku</button>' : ""}</div><section class="panel quran-digital-card"><div class="quran-digital-art" aria-hidden="true">&#10022;</div><div class="quran-digital-copy"><span class="eyebrow">MUSHAF DIGITAL</span><h2>Al-Qur'an Digital</h2><p>Baca teks Arab dan terjemahan Bahasa Indonesia dari 114 surah.</p><button type="button" class="primary-button" id="openQuranDigital">Mulai membaca <span>&#8594;</span></button></div><div class="quran-digital-count"><b>114</b><span>surah tersedia</span></div></section><div class="library-section-heading"><div><span class="eyebrow">KOLEKSI BUKU PAKET</span><h2>Buku berdasarkan kelas</h2></div>${isTeacher ? '<span class="library-upload-hint">Unggah buku untuk dibaca siswa</span>' : ""}</div><div class="library-grade-grid">${[
    10, 11, 12,
  ]
    .map((grade) => {
      const gradeBooks = books.filter(
        (item) => Number(item.gradeLevel) === grade,
      );
      return `<section class="panel library-shelf"><div class="library-shelf-head"><span class="library-grade-badge">${grade}</span><div class="library-shelf-title"><h2>Kelas ${grade}</h2><p>Buku paket</p></div><span class="library-book-count">${gradeBooks.length} buku</span></div><div class="library-shelf-books">${gradeBooks.map(libraryItemCard).join("") || '<p class="library-empty">Belum ada buku untuk kelas ini.</p>'}</div></section>`;
    })
    .join("")}</div></div>`;
}
const quranApiBase = "https://api.alquran.cloud/v1";
let quranChapters = null;
let activeQuranSurah = 1;
function quranCacheRead(key) {
  try {
    const value = localStorage.getItem("mutqin-quran-" + key);
    return value ? JSON.parse(value) : null;
  } catch (error) {
    return null;
  }
}
function quranCacheWrite(key, value) {
  try {
    localStorage.setItem("mutqin-quran-" + key, JSON.stringify(value));
  } catch (error) {}
}
async function quranFetch(url) {
  const response = await fetch(url, {
    headers: { Accept: "application/json" },
  });
  if (!response.ok)
    throw new Error("Sumber Al-Qur'an sedang tidak dapat dihubungi.");
  const result = await response.json();
  if (result.code !== 200 || !result.data)
    throw new Error("Data Al-Qur'an belum berhasil dimuat.");
  return result.data;
}
const quranBasmalaWords = [
  "\u0628\u0633\u0645",
  "\u0627\u0644\u0644\u0647",
  "\u0627\u0644\u0631\u062d\u0645\u0646",
  "\u0627\u0644\u0631\u062d\u064a\u0645",
];
function normalizeQuranArabicWord(word) {
  return String(word)
    .normalize("NFD")
    .replace(/[\u064b-\u065f\u0670\u06d6-\u06ed]/g, "")
    .replace(/[\u0640\u200f\u200e]/g, "")
    .replace(/\u0671/g, "\u0627")
    .replace(/[^\u0600-\u06ff]/g, "");
}
function stripLeadingBasmala(text) {
  const words = String(text).trimStart().split(/\s+/);
  if (words.length < 4) return text;
  for (let i = 0; i < 4; i++)
    if (normalizeQuranArabicWord(words[i]) !== quranBasmalaWords[i])
      return text;
  return words.slice(4).join(" ");
}
async function loadQuranChapters() {
  if (quranChapters) return quranChapters;
  quranChapters = quranCacheRead("chapters");
  if (quranChapters) return quranChapters;
  const chapters = await quranFetch(`${quranApiBase}/surah`);
  quranChapters = chapters;
  quranCacheWrite("chapters", chapters);
  return chapters;
}
function quranReaderShell(selected = activeQuranSurah) {
  return `<div class="page-head quran-reader-head"><div><button type="button" class="text-link back-link" data-page="perpustakaan">&#8592; Kembali ke perpustakaan</button><div class="eyebrow">MUSHAF DIGITAL</div><h1 class="page-title">Al-Qur'an Digital</h1><p class="page-subtitle">Teks Arab dan terjemahan Bahasa Indonesia.</p></div></div><section class="panel quran-reader-panel"><div class="quran-reader-controls"><label for="quranSurahSelect">Pilih surah</label><select id="quranSurahSelect" class="editor-input"><option value="${selected}">Memuat daftar surah...</option></select><button type="button" class="outline-button" id="quranPrev" ${selected <= 1 ? "disabled" : ""}>&#8592; Sebelumnya</button><button type="button" class="outline-button" id="quranNext" ${selected >= 114 ? "disabled" : ""}>Berikutnya &#8594;</button></div><div id="quranReadingArea" aria-live="polite"><p class="empty-note">Memuat surah...</p></div><p class="quran-attribution">Data Al-Qur'an dan terjemahan disediakan oleh <a href="https://alquran.cloud" target="_blank" rel="noopener">Al Quran Cloud</a>.</p></section>`;
}
async function openQuranDigital(surahNumber = 1) {
  activeQuranSurah = Math.max(1, Math.min(114, Number(surahNumber) || 1));
  currentPage = "perpustakaan";
  currentCourseId = null;
  page.innerHTML = quranReaderShell(activeQuranSurah);
  document.getElementById("crumb").textContent = "Al-Qur'an Digital";
  document
    .querySelectorAll(".nav-item[data-page]")
    .forEach((item) =>
      item.classList.toggle("active", item.dataset.page === "perpustakaan"),
    );
  window.scrollTo({ top: 0, behavior: "smooth" });
  const select = document.getElementById("quranSurahSelect");
  const area = document.getElementById("quranReadingArea");
  try {
    const chapters = await loadQuranChapters();
    if (!select || !area) return;
    select.innerHTML = chapters
      .map(
        (chapter) =>
          `<option value="${Number(chapter.number)}" ${Number(chapter.number) === activeQuranSurah ? "selected" : ""}>${Number(chapter.number)}. ${escapeHtml(chapter.name)} - ${escapeHtml(chapter.englishName)} (${Number(chapter.numberOfAyahs)} ayat)</option>`,
      )
      .join("");
    let surah = quranCacheRead("surah-" + activeQuranSurah);
    if (!surah) {
      surah = await quranFetch(
        `${quranApiBase}/surah/${activeQuranSurah}/editions/quran-uthmani,id.indonesian`,
      );
      if (!Array.isArray(surah) || surah.length < 2)
        throw new Error("Teks Arab dan terjemahan surah belum tersedia.");
      quranCacheWrite("surah-" + activeQuranSurah, surah);
    }
    if (!area.isConnected) return;
    const arabic = surah[0],
      translation = surah[1];
    const translations = new Map(
      translation.ayahs.map((ayah) => [Number(ayah.numberInSurah), ayah.text]),
    );
    const surahNumber = Number(arabic.number);
    const showBasmala = surahNumber !== 1 && surahNumber !== 9;
    const verses = arabic.ayahs.map((ayah, index) =>
      index === 0 && showBasmala
        ? { ...ayah, text: stripLeadingBasmala(ayah.text) }
        : ayah,
    );
    const basmala = showBasmala
      ? `<div class="quran-basmala" lang="ar" dir="rtl">&#1576;&#1616;&#1587;&#1618;&#1605;&#1616; &#1575;&#1604;&#1604;&#1617;&#1614;&#1607;&#1616; &#1575;&#1604;&#1585;&#1617;&#1614;&#1581;&#1618;&#1605;&#1614;&#1648;&#1606;&#1616; &#1575;&#1604;&#1585;&#1617;&#1614;&#1581;&#1616;&#1610;&#1605;&#1616;</div>`
      : "";
    area.innerHTML = `<header class="quran-surah-title"><span>Surah ${Number(arabic.number)}</span><h2>${escapeHtml(arabic.name)}</h2><p>${escapeHtml(arabic.englishName)} &middot; ${Number(arabic.numberOfAyahs)} ayat &middot; ${escapeHtml(arabic.revelationType === "Meccan" ? "Makkiyah" : "Madaniyah")}</p></header>${basmala}<div class="quran-ayah-list">${verses.map((ayah) => `<article class="quran-ayah"><div class="quran-ayah-number">${Number(ayah.numberInSurah)}</div><p class="quran-arabic" lang="ar" dir="rtl">${escapeHtml(ayah.text)}</p><p class="quran-translation">${escapeHtml(translations.get(Number(ayah.numberInSurah)) || "Terjemahan belum tersedia.")}</p></article>`).join("")}</div>`;
    document.getElementById("quranPrev").disabled = activeQuranSurah <= 1;
    document.getElementById("quranNext").disabled =
      activeQuranSurah >= chapters.length;
  } catch (error) {
    if (area?.isConnected)
      area.innerHTML = `<div class="quran-load-error"><p>${escapeHtml(error.message || "Al-Qur'an tidak dapat dimuat.")}</p><button type="button" class="outline-button" id="retryQuran">Coba lagi</button></div>`;
  }
}
function libraryItemCard(item) {
  return `<article class="library-book"><span class="library-file-icon">${item.mime === "application/pdf" ? "PDF" : "FILE"}</span><div class="library-book-copy"><b>${escapeHtml(item.title)}</b><small>${escapeHtml(item.originalName || "Bahan perpustakaan")}</small></div><a class="outline-button" href="library_file.php?id=${encodeURIComponent(item.id)}" target="_blank" rel="noopener">Lihat</a>${isTeacher ? `<button type="button" class="content-delete-button" data-delete-library="${escapeHtml(item.id)}">Hapus</button>` : ""}</article>`;
}
function openLibraryEditor() {
  if (!isTeacher) return;
  document.getElementById("dialogContent").innerHTML =
    `<div class="eyebrow">PERPUSTAKAAN DIGITAL</div><h2>Unggah buku paket</h2><label class="editor-label" for="libraryGrade">Kelas</label><select class="editor-input" id="libraryGrade"><option value="10">Kelas 10</option><option value="11">Kelas 11</option><option value="12">Kelas 12</option></select><label class="editor-label" for="libraryTitle">Judul buku</label><input class="editor-input" id="libraryTitle" maxlength="150" required placeholder="Judul buku paket"><label class="editor-label" for="libraryFile">File PDF, foto, atau video (maks. 35 MB)</label><input class="editor-input" id="libraryFile" type="file" accept="application/pdf,image/jpeg,image/png,image/webp,video/mp4,video/webm" required><div class="editor-actions"><button type="button" class="outline-button" id="cancelLibrary">Batal</button><button type="button" class="primary-button" id="saveLibrary">Unggah buku</button></div>`;
  document.getElementById("lessonDialog").showModal();
}
function assignmentPage() {
  const visibleTasks = studentTasks();
  return `<div class="page-head"><div><div class="eyebrow">KEGIATAN BELAJAR</div><h1 class="page-title">Tugas & kuis</h1><p class="page-subtitle">${isTeacher ? "Buat tugas dan kuis, atur soal, lalu bagikan kepada murid." : "Semua tugas dari kelas yang kamu ikuti. Pilih status untuk menemukan kegiatan dengan cepat."}</p></div>${isTeacher ? '<button class="primary-button" id="addAssignment">&#43; Buat tugas atau kuis</button>' : `<div class="date-chip">${visibleTasks.filter((task) => !task.submitted).length} belum selesai</div>`}</div>${!isTeacher ? `<div class="filter-row" role="group" aria-label="Filter tugas"><button class="filter-pill active" data-filter="all">Semua <span>${visibleTasks.length}</span></button><button class="filter-pill" data-filter="due">Belum selesai <span>${visibleTasks.filter((task) => !task.submitted).length}</span></button><button class="filter-pill" data-filter="done">Sudah dikerjakan <span>${visibleTasks.filter((task) => task.submitted).length}</span></button></div>${!classGroups.length ? '<section class="panel student-empty-classes"><b>Tugas akan tampil setelah kamu masuk kelas</b><p>Gunakan kode undangan yang diberikan guru.</p><button class="outline-button" data-page="kelas">Masuk kelas &#8594;</button></section>' : ""}` : ""}<div class="assignment-list">${visibleTasks.map((t) => `<article class="assignment" data-kind="${t.submitted ? "done" : "due"}"><div class="task-icon">${escapeHtml(t.icon)}</div><div class="assignment-info"><b>${escapeHtml(t.title)}</b><small>${escapeHtml(t.meta)} &middot; ${t.type === "quiz" ? "Kuis pilihan ganda" : "Tugas esai"}</small></div>${isTeacher ? `<div class="teacher-task-actions"><button type="button" class="outline-button" data-view-submissions="${escapeHtml(t.id)}">Pengumpulan (${(t.submissions || []).length})</button><button type="button" class="outline-button" data-edit-assignment="${escapeHtml(t.id)}">Ubah</button><button type="button" class="content-delete-button" data-delete-assignment="${escapeHtml(t.id)}">Hapus</button></div>` : `<span class="task-status ${t.submitted ? "done" : ""}">${escapeHtml(t.status)}</span><button type="button" data-task="${escapeHtml(t.id)}">${t.submitted ? "Lihat hasil" : "Kerjakan"} &#8594;</button>`}</article>`).join("") || `<div class="empty-note">${isTeacher ? "Belum ada tugas. Buat tugas atau kuis pertama untuk murid." : "Belum ada tugas atau kuis dari kelasmu."}</div>`}<p class="empty-note assignment-filter-empty" hidden>Tidak ada tugas pada filter ini.</p></div>`;
}
function lessonPage() {
  const visibleLessons = lessons
    .map((lesson, index) => ({ lesson, index }))
    .filter(
      ({ lesson }) => isTeacher || studentCourseIds().has(String(lesson[3])),
    );
  return `<div class="page-head"><div><div class="eyebrow">PERPUSTAKAAN DIGITAL</div><h1 class="page-title">Materi belajar</h1><p class="page-subtitle">${isTeacher ? "Kelola dan perbarui materi yang dipelajari murid." : "Materi dari kelas yang kamu ikuti, tersusun agar mudah dilanjutkan."}</p></div>${isTeacher ? '<button class="primary-button" id="addLesson">&#43; Tambah materi</button>' : ""}</div>${!isTeacher && !classGroups.length ? '<section class="panel student-empty-classes"><b>Materi kelasmu akan muncul di sini</b><p>Gabung kelas menggunakan kode dari guru terlebih dahulu.</p><button class="outline-button" data-page="kelas">Masuk kelas &#8594;</button></section>' : ""}<div class="lesson-list">${visibleLessons.map(({ lesson: l, index: i }) => `<article class="lesson" data-course="${escapeHtml(l[3])}"><div class="lesson-number">${String(i + 1).padStart(2, "0")}</div><div class="lesson-copy"><b>${escapeHtml(l[0])}</b><small>${escapeHtml(l[2])} &middot; ${escapeHtml(l[1])}</small></div>${isTeacher ? `<button class="outline-button" data-editlesson="${i}">Ubah</button><button class="content-delete-button" data-delete-lesson="${i}">Hapus</button>` : ""}<button class="outline-button" data-lesson="${i}">${isTeacher ? "Pratinjau" : "Buka materi"} &#8594;</button></article>`).join("") || '<p class="empty-note">Belum ada materi untuk kelas yang kamu ikuti.</p>'}</div>`;
}
function classPage() {
  return `<div class="page-head"><div><div class="eyebrow">RUANG KELAS DIGITAL</div><h1 class="page-title">Kelas saya</h1><p class="page-subtitle">${isTeacher ? "Kelola kelas dan bagikan kode undangan kepada siswa." : "Kelas yang kamu ikuti dan guru pengampunya."}</p></div>${isTeacher ? '<button type="button" class="primary-button" id="addClass">&#43; Buat kelas</button>' : ""}</div>${!isTeacher ? `<section class="panel student-join-inline"><div><div class="eyebrow">KODE UNDANGAN</div><b>Gabung kelas dengan kode dari guru</b><p>Masukkan kode 8 karakter yang diberikan oleh guru.</p></div>${classJoinForm()}</section>` : ""}${classGroups.length ? `<div class="class-group-list">${classGroups.map(classGroupCard).join("")}</div>` : `<section class="panel class-empty-state"><h2>${isTeacher ? "Belum ada kelas" : "Belum ada kelas yang kamu ikuti"}</h2><p>${isTeacher ? "Buat kelas dan bagikan kode undangannya kepada siswa." : "Minta kode undangan 8 karakter kepada guru untuk bergabung ke kelas."}</p>${isTeacher ? '<button type="button" class="primary-button" id="addClass">&#43; Buat kelas</button>' : ""}</section>`}`;
}
function classPage() {
  return `<div class="page-head"><div><div class="eyebrow">RUANG KELAS DIGITAL</div><h1 class="page-title">Kelas saya</h1><p class="page-subtitle">${isTeacher ? "Kelola kelas dan bagikan kode undangan kepada siswa." : "Daftar kelas yang kamu ikuti."}</p></div>${isTeacher ? '<button type="button" class="primary-button" id="addClass">＋ Buat kelas</button>' : `<button type="button" class="outline-button" data-page="beranda">Masukkan kode kelas di beranda</button>`}</div>${classGroups.length ? `<div class="class-group-list">${classGroups.map(classGroupCard).join("")}</div>` : `<section class="panel class-empty-state"><h2>${isTeacher ? "Belum ada kelas" : "Kamu belum bergabung ke kelas"}</h2><p>${isTeacher ? "Buat kelas dan bagikan kode undangannya kepada siswa." : "Masukkan kode yang diberikan guru pada bagian Masuk kelas di beranda."}</p>${isTeacher ? '<button type="button" class="primary-button" id="addClass">＋ Buat kelas</button>' : '<button type="button" class="primary-button" data-page="beranda">Ke beranda</button>'}</section>`}`;
}
function classMembersPanel(group) {
  const members = Array.isArray(group.students) ? group.students : [];
  return `<aside class="panel class-members-panel"><div class="panel-heading"><div><h2>Daftar siswa</h2><p>${members.length} siswa bergabung</p></div>${isTeacher ? '<button class="outline-button" type="button" id="addClassStudent">&#43; Tambah siswa</button>' : ""}</div>${members.length ? `<div class="class-member-list">${members.map((member, index) => `<div class="class-member-row"><span class="top-avatar">${escapeHtml(member.initials || member.name.slice(0, 1))}</span><span><b>${escapeHtml(member.name)}</b><small>Siswa ${index + 1}</small></span>${isTeacher ? `<button class="content-delete-button" type="button" data-remove-class-student="${escapeHtml(member.username)}" data-class-id="${group.id}" aria-label="Keluarkan ${escapeHtml(member.name)}">Hapus</button>` : ""}</div>`).join("")}</div>` : '<p class="empty-note">Belum ada siswa yang bergabung.</p>'}</aside>`;
}
function openClassStudentEditor(groupId) {
  const group = classGroups.find((item) => Number(item.id) === Number(groupId));
  if (!group || !isTeacher) return;
  const enrolled = new Set((group.students || []).map((item) => item.username));
  const options = leaderboard
    .filter((item) => !enrolled.has(item.username))
    .map(
      (item) =>
        `<option value="${escapeHtml(item.username)}">${escapeHtml(item.name)} &middot; ${escapeHtml(item.username)}</option>`,
    )
    .join("");
  document.getElementById("dialogContent").innerHTML =
    `<div class="eyebrow">KELOLA ANGGOTA KELAS</div><h2>Tambah siswa</h2>${options ? `<label class="editor-label" for="classStudentUsername">Pilih siswa</label><select class="editor-input" id="classStudentUsername">${options}</select><div class="editor-actions"><button type="button" class="outline-button" id="cancelClassStudent">Batal</button><button type="button" class="primary-button" id="saveClassStudent" data-class-id="${group.id}">Tambahkan</button></div>` : '<p class="empty-note">Semua akun siswa sudah menjadi anggota kelas ini.</p><div class="editor-actions"><button type="button" class="outline-button" id="cancelClassStudent">Tutup</button></div>'}`;
  document.getElementById("lessonDialog").showModal();
}
function showClassGroup(groupId, tab = "materi") {
  const group = classGroups.find((item) => Number(item.id) === Number(groupId));
  if (!group) return;
  const tabs = [
    ["materi", "Materi belajar"],
    ["tugas", "Tugas & kuis"],
    ["informasi", "Ruang informasi"],
    ["leaderboard", "Leaderboard nilai siswa"],
    ["siswa", "Daftar siswa"],
  ];
  const active = tabs.some(([key]) => key === tab) ? tab : "materi";
  activeClassGroup = group;
  activeClassTab = active;
  const names = {
    materi: "Materi belajar",
    tugas: "Tugas & kuis",
    informasi: "Ruang informasi",
    leaderboard: "Leaderboard nilai siswa",
    siswa: "Daftar siswa",
  };
  const renderer = renderers[active];
  const classInfoKey = `class_${group.id}_informasi`;
  page.innerHTML = `<div class="page-head"><div><button type="button" class="text-link back-link" data-page="kelas">&#8592; Kembali ke kelas saya</button><div class="eyebrow">${escapeHtml(group.courseTitle).toUpperCase()}</div><h1 class="page-title">${escapeHtml(group.className)}</h1><p class="page-subtitle">${isTeacher ? "Kelola materi dan kegiatan untuk kelas ini." : "Materi dan kegiatan belajar kelas ini."}</p></div>${isTeacher ? `<span class="date-chip">Kode: ${escapeHtml(group.joinCode)}</span>` : ""}</div><nav class="class-section-tabs" aria-label="Menu kelas">${tabs.map(([key, label]) => `<button type="button" class="class-section-tab ${key === active ? "active" : ""}" data-class-tab="${key}" data-class-id="${group.id}">${label}</button>`).join("")}</nav><div id="classTabContent">${active === "informasi" ? `${informationPage()}${pageContentSection(classInfoKey)}` : active === "siswa" ? classMembersPanel(group) : renderer ? renderer() : ""}</div>`;
  currentPage = "kelas";
  currentCourseId = null;
  document.getElementById("crumb").textContent = names[active];
  document
    .querySelectorAll(".nav-item[data-page]")
    .forEach((item) =>
      item.classList.toggle("active", item.dataset.page === "kelas"),
    );
  document.querySelector(".sidebar").classList.remove("open");
}
function progressPage() {
  return `<div class="page-head"><div><div class="eyebrow">PERJALANAN BELAJAR</div><h1 class="page-title">Progres belajar</h1><p class="page-subtitle">Materi yang sudah kamu selesaikan akan tersimpan di sini.</p></div></div><section class="metrics"><div class="metric"><div class="metric-icon">▤</div><div><strong>${courses.length}</strong><span>Kelas aktif</span></div></div><div class="metric"><div class="metric-icon">☑</div><div><strong>${completedLessons.length}</strong><span>Materi selesai</span></div></div><div class="metric"><div class="metric-icon">✎</div><div><strong>${tasks.filter((t) => t.submitted).length}/${tasks.length}</strong><span>Tugas dikumpulkan</span></div></div><div class="metric"><div class="metric-icon">✦</div><div><strong>${tasks.filter((t) => t.type === "quiz" && t.submitted).length}</strong><span>Kuis selesai</span></div></div></section><div class="section-heading"><div><h2>Progres tiap kelas</h2><p>Klik kelas untuk lanjut belajar</p></div></div><section class="courses">${courses.map(courseCard).join("")}</section>`;
}
function informationPage() {
  const infoKey = activeClassGroup
    ? `class_${activeClassGroup.id}_informasi`
    : "informasi";
  const count = activeClassGroup
    ? (pageContents[infoKey] || []).length
    : isTeacher
      ? (pageContents[infoKey] || []).length
      : (pageContents.informasi || []).length +
        classGroups.reduce(
          (total, group) =>
            total + (pageContents[`class_${group.id}_informasi`] || []).length,
          0,
        );
  return `<div class="page-head"><div><div class="eyebrow">PUSAT PENGUMUMAN</div><h1 class="page-title">Ruang informasi</h1><p class="page-subtitle">${activeClassGroup ? `Pengumuman untuk ${escapeHtml(activeClassGroup.className)}.` : isTeacher ? "Kelola pengumuman untuk kelas dan kabar terbaru MutqinSpace." : "Pengumuman dari guru di kelas yang kamu ikuti."}</p></div><span class="date-chip">${count} informasi</span></div><section class="information-overview panel"><span class="information-overview-icon">&#10022;</span><div><b>${activeClassGroup ? escapeHtml(activeClassGroup.className) : "Informasi kelasmu"}</b><p>${isTeacher ? "Bagikan pengumuman yang perlu diketahui siswa." : "Baca pengumuman penting dari guru kelasmu."}</p></div></section>`;
}
function leaderboardPage() {
  const scopedLeaderboard = isTeacher
    ? leaderboard
    : leaderboard.filter((entry) => {
        const groups = activeClassGroup ? [activeClassGroup] : classGroups;
        return groups.some((group) =>
          (group.students || []).some(
            (member) => member.username === entry.username,
          ),
        );
      });
  return `<div class="page-head"><div><div class="eyebrow">APRESIASI BELAJAR</div><h1 class="page-title">Leaderboard nilai siswa</h1><p class="page-subtitle">Peringkat dihitung dari nilai kuis dan materi yang telah diselesaikan.</p></div><span class="date-chip">${scopedLeaderboard.length} siswa</span></div><section class="leaderboard-intro panel"><span>&#127942;</span><div><b>Apresiasi untuk usaha belajar</b><p>Peringkat berdasarkan nilai kuis dan materi yang selesai.</p></div></section><section class="panel leaderboard-panel"><div class="panel-heading"><h2>${isTeacher ? "Peringkat siswa" : "Peringkat kelas yang kamu ikuti"}</h2><span class="tag">Nilai kuis</span></div><div class="leaderboard-head"><span>Peringkat & siswa</span><span>Materi selesai</span><span>Nilai kuis</span></div>${scopedLeaderboard.map((s, i) => `<div class="leaderboard-row ${s.username === student.username ? "leaderboard-self" : ""}"><div class="leaderboard-person"><span class="leader-rank ${i < 3 ? "leader-rank-top" : ""}">${i + 1 < 10 ? "0" : ""}${i + 1}</span><span class="top-avatar">${escapeHtml(s.initials)}</span><span class="leader-name"><b>${escapeHtml(s.name)}${s.username === student.username ? " (Kamu)" : ""}</b><small>${s.username === student.username ? (student.role === "teacher" ? "Guru" : "Kelas " + escapeHtml(student.class)) : "Siswa"}</small></span></div><span class="leader-completed">${s.completed}</span><div class="leader-score"><b>${s.score}%</b><small>${s.quizScore}/${s.quizMaximum} benar</small></div></div>`).join("") || '<div class="empty-note">Belum ada data nilai siswa.</div>'}<p class="leaderboard-note">Nilai akan diperbarui setelah siswa mengerjakan kuis.</p></section>`;
}
function helpPage() {
  return `<div class="page-head"><div><div class="eyebrow">KAMI SIAP MEMBANTU</div><h1 class="page-title">Pusat bantuan</h1><p class="page-subtitle">Panduan singkat untuk memulai kegiatan belajarmu.</p></div></div><section class="panel"><div class="panel-heading"><h2>Mulai belajar di MutqinSpace</h2></div><div class="lesson-list"><div class="lesson"><div class="lesson-number">01</div><div class="lesson-copy"><b>Buka kelas yang kamu ikuti</b><small>Pilih menu Kelas saya untuk membuka materi, tugas, informasi, dan nilai di kelas yang kamu ikuti.</small></div></div><div class="lesson"><div class="lesson-number">02</div><div class="lesson-copy"><b>Pelajari materi secara berurutan</b><small>Baca ringkasan dan tujuan belajar pada tiap topik.</small></div></div><div class="lesson"><div class="lesson-number">03</div><div class="lesson-copy"><b>Kumpulkan tugas dan ikuti diskusi</b><small>Periksa tenggat tugas dan sampaikan pendapat dengan santun.</small></div></div></div></section>`;
}
function accountPage() {
  const isStudent = student.role !== "teacher";
  return `<div class="page-head"><div><div class="eyebrow">PREFERENSI & KEAMANAN</div><h1 class="page-title">Pengaturan akun</h1><p class="page-subtitle">Perbarui profil dan keamanan akun MutqinSpace-mu.</p></div></div><section class="panel account-panel"><div class="account-intro"><div class="top-avatar account-avatar">${escapeHtml(student.initials)}</div><div><b>${escapeHtml(student.name)}</b><small>${isStudent ? "Siswa &middot; " + escapeHtml(student.class || "Kelas belum diisi") : "Guru Pendidikan Agama"}</small></div></div><form id="accountForm" class="account-form"><h2>Profil ${isStudent ? "siswa" : "guru"}</h2><label for="accountName">Nama ${isStudent ? "siswa" : "guru"}</label><input id="accountName" type="text" maxlength="150" value="${escapeHtml(student.name)}" autocomplete="name" required>${isStudent ? `<label for="accountNisn">NISN</label><input id="accountNisn" type="text" inputmode="numeric" maxlength="30" value="${escapeHtml(student.nisn || "")}" placeholder="Masukkan NISN"><label for="accountClass">Kelas sekolah</label><input id="accountClass" type="text" maxlength="40" value="${escapeHtml(student.class || "")}" placeholder="Contoh: XI IPA 2">` : ""}<div class="settings-divider"></div><label for="accountEmail">Alamat email</label><input id="accountEmail" type="email" value="${escapeHtml(student.email || "")}" autocomplete="email" required><div class="settings-divider"></div><h2>Ganti kata sandi</h2><p class="account-help">Kosongkan kata sandi baru jika hanya ingin memperbarui profil.</p><label for="currentPassword">Kata sandi saat ini <span>Wajib untuk menyimpan perubahan</span></label><input id="currentPassword" type="password" autocomplete="current-password" required><div class="password-fields"><div><label for="newPassword">Kata sandi baru</label><input id="newPassword" type="password" autocomplete="new-password" minlength="8" placeholder="Minimal 8 karakter"></div><div><label for="confirmPassword">Konfirmasi kata sandi</label><input id="confirmPassword" type="password" autocomplete="new-password" minlength="8" placeholder="Ulangi kata sandi baru"></div></div><button class="primary-button account-save" type="submit">Simpan perubahan <span>&#8594;</span></button></form></section>`;
}
const renderers = {
  beranda: home,
  kelas: classPage,
  materi: lessonPage,
  tugas: assignmentPage,
  perpustakaan: libraryPage,
  leaderboard: leaderboardPage,
  informasi: informationPage,
  bantuan: helpPage,
  pengaturan: accountPage,
};
const names = {
  beranda: "Beranda",
  kelas: "Kelas saya",
  materi: "Materi belajar",
  tugas: "Tugas & kuis",
  leaderboard: "Leaderboard nilai siswa",
  informasi: "Ruang informasi",
  bantuan: "Pusat bantuan",
  pengaturan: "Pengaturan akun",
  perpustakaan: "Perpustakaan",
};
let currentCourseId = null;
function refreshCurrentPage() {
  if (activeClassGroup) {
    showClassGroup(activeClassGroup.id, activeClassTab);
    return;
  }
  if (currentPage === "kelas" && currentCourseId) {
    showClass(currentCourseId);
    return;
  }
  render(currentPage);
}
function render(key) {
  currentPage = key;
  currentCourseId = null;
  page.innerHTML =
    (renderers[key] || home)() +
    ([
      "beranda",
      "materi",
      "tugas",
      "informasi",
      "leaderboard",
      "bantuan",
    ].includes(key)
      ? pageContentSection(key)
      : "");
  document.getElementById("crumb").textContent = names[key] || "Beranda";
  document
    .querySelectorAll(".nav-item[data-page]")
    .forEach((x) => x.classList.toggle("active", x.dataset.page === key));
  document.querySelector(".sidebar").classList.remove("open");
  window.scrollTo({ top: 0, behavior: "smooth" });
}
function materialAttachmentMarkup(asset) {
  if (!asset || !asset.file) return "";
  const url = `material_file.php?file=${encodeURIComponent(asset.file)}`;
  if (String(asset.mime || "").startsWith("image/"))
    return `<section class="material-attachment"><h3>Lampiran materi</h3><a href="${url}" target="_blank" rel="noopener"><img class="material-image-preview" src="${url}" alt="${escapeHtml(asset.name || "Foto materi")}"></a><a class="material-file-link" href="${url}" target="_blank" rel="noopener">Buka foto ukuran penuh</a></section>`;
  if (
    String(asset.mime || "").startsWith("video/") ||
    String(asset.file).endsWith(".ogv")
  )
    return `<section class="material-attachment"><h3>Video materi</h3><video class="material-video-preview" controls preload="metadata"><source src="${url}" type="${escapeHtml(asset.mime)}">Browser tidak dapat memutar video ini.</video><a class="material-file-link" href="${url}" target="_blank" rel="noopener">Buka video di tab baru</a></section>`;
  return `<section class="material-attachment"><h3>Dokumen materi</h3><a class="material-file-link" href="${url}" target="_blank" rel="noopener">Buka PDF: ${escapeHtml(asset.name || "Dokumen PDF")}</a></section>`;
}
function openLesson(idx) {
  const item = lessons[idx];
  const isDone = completedLessons.includes(idx);
  document.getElementById("dialogContent").innerHTML =
    `<div class="eyebrow">${escapeHtml(item[2]).toUpperCase()} · MATERI ${String(idx + 1).padStart(2, "0")}</div><h2>${escapeHtml(item[0])}</h2><p>${escapeHtml(item[1])}</p><div class="callout"><b>Tujuan belajar</b><br>Memahami pokok bahasan dan menghubungkannya dengan perilaku sehari-hari.</div><h3>Isi materi</h3><p>${escapeHtml(item[4] || item[1]).replace(/\n/g, "<br>")}</p>${materialAttachmentMarkup(item[5])}${isTeacher ? "" : `<button type="button" class="primary-button" id="markDone" data-lessonid="${idx}" ${isDone ? "disabled" : ""}>${isDone ? "✓ Sudah selesai" : "Tandai selesai ✓"}</button>`}`;
  document.getElementById("lessonDialog").showModal();
}
function openLessonEditor(idx = null) {
  if (!isTeacher) return;
  const item = idx === null ? ["", "", "", "", ""] : lessons[idx];
  const selectedCourse =
    idx === null ? activeClassGroup?.courseId || "quran" : item[3];
  const currentAsset =
    item[5] && item[5].file
      ? `<div class="current-material-file"><span>Lampiran saat ini:</span> <a href="material_file.php?file=${encodeURIComponent(item[5].file)}" target="_blank" rel="noopener">${escapeHtml(item[5].name || "Buka lampiran")}</a><label class="remove-material-file"><input type="checkbox" id="removeAttachment"> Hapus lampiran saat menyimpan</label></div>`
      : "";
  document.getElementById("dialogContent").innerHTML =
    `<div class="eyebrow">RUANG GURU · EDITOR MATERI</div><h2>${idx === null ? "Tambah materi" : "Ubah materi"}</h2><label class="editor-label" for="editTitle">Judul materi</label><input class="editor-input" id="editTitle" maxlength="150" value="${escapeHtml(item[0])}" placeholder="Judul materi" required><input type="hidden" id="editCourse" value="${escapeHtml(selectedCourse)}"><label class="editor-label" for="editDescription">Ringkasan</label><input class="editor-input" id="editDescription" maxlength="300" value="${escapeHtml(item[1])}" placeholder="Ringkasan singkat" required><label class="editor-label" for="editBody">Isi materi</label><textarea class="editor-input editor-textarea" id="editBody" maxlength="20000" placeholder="Tuliskan isi materi di sini" required>${escapeHtml(item[4] || "")}</textarea><label class="editor-label" for="editAttachment">Lampiran PDF, foto, atau video (maks. 35 MB)</label><input class="editor-input material-file-input" id="editAttachment" type="file" accept=".pdf,.jpg,.jpeg,.png,.webp,.gif,.mp4,.webm,.ogv,.ogg,application/pdf,image/jpeg,image/png,image/webp,image/gif,video/mp4,video/webm,video/ogg">${currentAsset}<div class="editor-actions"><button class="outline-button" type="button" id="cancelEdit">Batal</button><button class="primary-button" type="button" id="saveLesson" data-id="${idx === null ? "" : idx}">Simpan materi</button></div>`;
  document.getElementById("lessonDialog").showModal();
}
function showClass(id) {
  currentPage = "kelas";
  currentCourseId = id;
  const course = courses.find((c) => c.id === id);
  if (!course) return;
  const courseLessons = lessons
    .map((item, index) => ({ item, index }))
    .filter((x) => x.item[3] === id);
  const courseTasks = tasks.filter(
    (t) => Number(t.classGroupId) === Number(activeClassGroup?.id),
  );
  page.innerHTML = `<div class="page-head"><div><button class="text-link back-link" data-page="kelas">← Kembali ke kelas</button><div class="eyebrow">KELAS SAYA</div><h1 class="page-title">${escapeHtml(course.title)}</h1><p class="page-subtitle">${escapeHtml(course.desc)}</p></div><span class="date-chip">${course.done}/${course.lessons} materi selesai</span></div><div class="class-overview"><div><span class="eyebrow">SEDANG DIPELAJARI</span><h2>${escapeHtml(course.unit)}</h2><p>Guru pengampu: ${escapeHtml(course.teacher)}</p></div><div class="progress-track"><div class="progress-fill" style="width:${course.progress}%"></div></div><b>${course.progress}%</b></div><div class="class-columns"><section class="panel"><div class="panel-heading"><h2>Materi kelas</h2><button data-page="materi">Semua materi →</button></div>${courseLessons.map(({ item, index }) => `<button class="task-row task-row-button" data-lesson="${index}"><span class="lesson-number">${String(index + 1).padStart(2, "0")}</span><span class="task-text"><b>${escapeHtml(item[0])}</b><small>${escapeHtml(item[1])}</small></span><span class="task-status ${completedLessons.includes(index) ? "done" : ""}">${completedLessons.includes(index) ? "Selesai" : "Buka"}</span></button>`).join("") || '<p class="admin-empty">Belum ada materi untuk kelas ini.</p>'}</section><section class="panel"><div class="panel-heading"><h2>Tugas & kuis</h2><button data-page="tugas">Semua tugas →</button></div>${courseTasks.map(classTaskRow).join("") || '<p class="admin-empty">Belum ada tugas untuk kelas ini.</p>'}</section></div>`;
  page.insertAdjacentHTML("beforeend", pageContentSection("kelas"));
  document.getElementById("crumb").textContent = course.title;
  document
    .querySelectorAll(".nav-item[data-page]")
    .forEach((x) => x.classList.toggle("active", x.dataset.page === "kelas"));
  window.scrollTo({ top: 0, behavior: "smooth" });
}
function openTaskSubmissions(taskId) {
  if (!isTeacher) return;
  const task = tasks.find((item) => item.id === taskId);
  if (!task) return;
  const submissions = Array.isArray(task.submissions) ? task.submissions : [];
  const rows = submissions
    .map(
      (entry) =>
        `<article class="submission-card submission-list-row"><div class="submission-student"><span class="top-avatar">${escapeHtml(entry.name.slice(0, 1))}</span><div><b>${escapeHtml(entry.name)}</b><small>${escapeHtml(entry.className || "Siswa")}</small></div><button type="button" class="outline-button" data-view-answer="${escapeHtml(task.id)}" data-student-username="${escapeHtml(entry.username)}">Lihat</button></div></article>`,
    )
    .join("");
  document.getElementById("dialogContent").innerHTML =
    `<div class="eyebrow">PENGUMPULAN ${task.type === "quiz" ? "KUIS" : "TUGAS"}</div><h2>${escapeHtml(task.title)}</h2><p>${submissions.length} siswa sudah mengerjakan.</p>${rows || '<p class="empty-note">Belum ada siswa yang mengumpulkan jawaban.</p>'}`;
  document.getElementById("lessonDialog").showModal();
}
function openSubmissionAnswer(taskId, username) {
  if (!isTeacher) return;
  const task = tasks.find((item) => item.id === taskId);
  const entry = task?.submissions?.find((item) => item.username === username);
  if (!task || !entry) return;
  const date = entry.submittedAt
    ? new Date(entry.submittedAt).toLocaleString("id-ID")
    : "Waktu pengumpulan tidak tersedia";
  const answer =
    task.type === "quiz"
      ? task.questions
          .map((question, index) => {
            const choice = Number(entry.answers?.[index]);
            const chosen = Number.isInteger(choice)
              ? question.options[choice]
              : "Tidak dijawab";
            const correct = Number(question.answer) === choice;
            return `<div class="submission-answer"><b>${index + 1}. ${escapeHtml(question.question)}</b><p>Jawaban siswa: <strong>${escapeHtml(chosen || "Tidak dijawab")}</strong> <span class="submission-correctness ${correct ? "correct" : "incorrect"}">${correct ? "Benar" : "Salah"}</span></p></div>`;
          })
          .join("")
      : `<div class="submission-answer"><b>Jawaban esai</b><p class="submission-essay">${escapeHtml(entry.response || "Tidak ada jawaban teks").replace(/\n/g, "<br>")}</p></div>`;
  const attachment = entry.attachment?.file
    ? `<div class="submission-answer"><b>Lampiran tugas</b><p><a class="material-file-link" href="submission_file.php?task=${encodeURIComponent(task.id)}&username=${encodeURIComponent(entry.username)}" target="_blank" rel="noopener">Lihat ${escapeHtml(entry.attachment.name || "foto/video")}</a></p></div>`
    : "";
  document.getElementById("dialogContent").innerHTML =
    `<button type="button" class="text-link" data-back-submissions="${escapeHtml(task.id)}">&#8592; Kembali ke daftar pengumpulan</button><div class="eyebrow">JAWABAN SISWA</div><h2>${escapeHtml(entry.name)}</h2><p>${escapeHtml(entry.className || "Siswa")} &middot; ${escapeHtml(date)}</p>${task.type === "quiz" ? `<div class="quiz-score">Nilai: <b>${Number(entry.score) || 0}/${task.questions.length}</b></div>` : ""}${answer}${attachment}`;
}
function requestExamFullscreen() {
  if (!document.fullscreenElement && document.documentElement.requestFullscreen)
    document.documentElement
      .requestFullscreen()
      .catch(() =>
        notify(
          "Mode ujian memerlukan layar penuh. Tekan tombol layar penuh untuk mencoba lagi.",
        ),
      );
  else if (!document.documentElement.requestFullscreen)
    notify("Browser ini tidak mendukung mode layar penuh untuk ujian.");
}
function endExamFocus() {
  activeExamTaskId = null;
  examTabWasHidden = false;
  if (document.fullscreenElement) document.exitFullscreen?.().catch(() => {});
}
function openAssignment(id) {
  if (isTeacher) return;
  const task = tasks.find((t) => t.id === id);
  if (!task) return;
  const existing = task.submission || {};
  const locked = task.type === "quiz" && task.submitted;
  const body =
    task.type === "quiz"
      ? task.questions
          .map(
            (q, i) =>
              `<fieldset class="quiz-question" ${locked ? "disabled" : ""}><legend>${i + 1}. ${escapeHtml(q.question)}</legend>${q.options.map((option, j) => `<label class="quiz-option"><input type="radio" name="answer-${i}" value="${j}" ${existing.answers?.[i] === j ? "checked" : ""} ${locked ? "disabled" : ""}><span>${escapeHtml(option)}</span></label>`).join("")}</fieldset>`,
          )
          .join("")
      : `<fieldset class="essay-answer-choice"><legend>Pilih satu cara mengumpulkan jawaban</legend><label><input type="radio" name="essayAnswerMode" value="text" ${existing.attachment?.file && !existing.response ? "" : "checked"}> Tulis jawaban</label><label><input type="radio" name="essayAnswerMode" value="photo" ${existing.attachment?.file && !existing.response ? "checked" : ""}> Unggah foto/video</label></fieldset><div id="essayTextAnswer" ${existing.attachment?.file && !existing.response ? "hidden" : ""}><label class="editor-label" for="assignmentResponse">Jawaban teks</label><textarea class="editor-input editor-textarea" id="assignmentResponse" maxlength="5000" placeholder="Tuliskan jawabanmu di sini...">${escapeHtml(existing.response || "")}</textarea></div><div id="essayPhotoAnswer" ${existing.attachment?.file && !existing.response ? "" : "hidden"}><label class="editor-label" for="assignmentSubmissionFile">Pilih foto atau video (maks. 35 MB)</label><input class="editor-input material-file-input" id="assignmentSubmissionFile" type="file" accept="image/*,video/*">${existing.attachment?.file ? `<p class="account-help">Lampiran tersimpan: ${escapeHtml(existing.attachment.name || "foto/video")}</p>` : ""}</div>`;
  activeExamTaskId = task.examMode && !task.submitted ? task.id : null;
  examTabWasHidden = false;
  document.getElementById("dialogContent").innerHTML =
    `<div class="eyebrow">${escapeHtml(task.courseTitle)} &middot; ${task.type === "quiz" ? "KUIS" : "TUGAS"}</div><h2>${escapeHtml(task.title)}</h2><p>${escapeHtml(task.description)}</p>${task.examMode && !task.submitted ? `<section class="exam-mode-notice"><b>Mode ujian aktif</b><p>Ujian berjalan dalam layar penuh. Jika kamu keluar layar penuh atau berpindah tab, akan muncul peringatan.</p><button type="button" class="outline-button" id="reenterExamFullscreen">Masuk layar penuh</button></section>` : ""}${locked ? `<div class="quiz-score">Jawaban sudah dikumpulkan dan dikunci. Nilai: <b>${task.score}/${task.questions.length}</b></div>` : ""}${body}${task.type === "essay" && existing.attachment?.file ? `<p class="account-help">Lampiran terakhir: <a href="submission_file.php?task=${encodeURIComponent(task.id)}" target="_blank" rel="noopener">Lihat lampiran</a></p>` : ""}${materialAttachmentMarkup(task.attachment)}${locked ? '<p class="account-help">Jawaban pilihan ganda tidak dapat diubah atau dikirim ulang.</p>' : `<button type="button" class="primary-button submit-assignment" id="submitAssignment" data-taskid="${escapeHtml(task.id)}">Kumpulkan jawaban</button>`}`;
  document.getElementById("lessonDialog").showModal();
  if (activeExamTaskId) requestExamFullscreen();
}
function quizQuestionEditor(question = {}, index = null) {
  const number =
    index === null
      ? document.querySelectorAll(".quiz-editor-question").length + 1
      : index + 1;
  const options = Array.isArray(question.options) ? question.options : [];
  const answer = Number.isInteger(Number(question.answer))
    ? Number(question.answer)
    : 0;
  return `<article class="quiz-editor-question"><div class="quiz-editor-question-head"><b>Soal ${number}</b><button type="button" class="content-delete-button" data-remove-quiz-question>Hapus soal</button></div><label class="editor-label">Pertanyaan</label><textarea class="editor-input editor-textarea quiz-question-text" maxlength="500" placeholder="Tulis pertanyaan pilihan ganda" required>${escapeHtml(question.question || "")}</textarea><div class="quiz-option-fields">${[0, 1, 2, 3].map((optionIndex) => `<label><span>Pilihan ${String.fromCharCode(65 + optionIndex)}</span><input class="editor-input quiz-option-input" maxlength="300" value="${escapeHtml(options[optionIndex] || "")}" placeholder="Isi pilihan ${String.fromCharCode(65 + optionIndex)}" required></label>`).join("")}</div><label class="editor-label">Kunci jawaban</label><select class="editor-input quiz-answer-key">${[0, 1, 2, 3].map((optionIndex) => `<option value="${optionIndex}" ${answer === optionIndex ? "selected" : ""}>Pilihan ${String.fromCharCode(65 + optionIndex)}</option>`).join("")}</select></article>`;
}
function openAssignmentEditor(id = "") {
  if (!isTeacher) return;
  const task = tasks.find((item) => item.id === id) || {
    title: "",
    courseId: "quran",
    due: "",
    description: "",
    prompt: "",
    type: "essay",
    questions: [],
  };
  const quizQuestions =
    task.type === "quiz" && task.questions.length
      ? task.questions.map(quizQuestionEditor).join("")
      : quizQuestionEditor();
  const currentAsset =
    task.attachment && task.attachment.file
      ? `<div class="current-material-file"><span>Lampiran saat ini:</span> <a href="material_file.php?file=${encodeURIComponent(task.attachment.file)}" target="_blank" rel="noopener">${escapeHtml(task.attachment.name || "Buka lampiran")}</a><label class="remove-material-file"><input type="checkbox" id="removeAttachment"> Hapus lampiran saat menyimpan</label></div>`
      : "";
  document.getElementById("dialogContent").innerHTML =
    `<div class="eyebrow">RUANG GURU · KELOLA KEGIATAN</div><h2>${id ? "Ubah tugas atau kuis" : "Buat tugas atau kuis"}</h2><label class="editor-label" for="assignmentType">Jenis kegiatan</label><select class="editor-input" id="assignmentType"><option value="essay" ${task.type === "essay" ? "selected" : ""}>Tugas esai</option><option value="quiz" ${task.type === "quiz" && !task.examMode ? "selected" : ""}>Kuis pilihan ganda</option><option value="exam" ${task.type === "quiz" && task.examMode ? "selected" : ""}>Ujian pilihan ganda (layar penuh)</option></select><label class="editor-label" for="assignmentClass">Kelas penerima</label><select class="editor-input" id="assignmentClass" required>${classGroups.map((group) => `<option value="${group.id}" data-course="${escapeHtml(group.courseId)}" ${Number(task.classGroupId) === Number(group.id) ? "selected" : ""}>${escapeHtml(group.className)}</option>`).join("")}</select><input type="hidden" id="assignmentCourse" value="${escapeHtml(task.courseId || classGroups[0]?.courseId || "quran")}"><label class="editor-label" for="assignmentTitle">Judul</label><input class="editor-input" id="assignmentTitle" maxlength="150" value="${escapeHtml(task.title)}" required><label class="editor-label" for="assignmentDue">Tenggat</label><input class="editor-input" id="assignmentDue" maxlength="80" value="${escapeHtml(task.due)}" placeholder="Contoh: 30 Okt 2026" required><label class="editor-label" for="assignmentDescription">Deskripsi singkat</label><input class="editor-input" id="assignmentDescription" maxlength="300" value="${escapeHtml(task.description)}" required><label class="editor-label" for="assignmentPrompt">Petunjuk untuk murid</label><textarea class="editor-input editor-textarea" id="assignmentPrompt" maxlength="5000" required>${escapeHtml(task.prompt)}</textarea><label class="editor-label" for="assignmentAttachment">Lampiran PDF, foto, atau video (maks. 35 MB)</label><input class="editor-input material-file-input" id="assignmentAttachment" type="file" accept=".pdf,.jpg,.jpeg,.png,.webp,.gif,.mp4,.webm,.ogv,.ogg,application/pdf,image/jpeg,image/png,image/webp,image/gif,video/mp4,video/webm,video/ogg">${currentAsset}<div id="quizEditor" ${task.type === "quiz" ? "" : "hidden"}><label class="exam-mode-option"><input type="checkbox" id="assignmentExamMode" ${task.examMode ? "checked" : ""}> Jadikan ujian mode fokus (layar penuh)</label><div class="quiz-editor-list">${quizQuestions}</div><button type="button" class="outline-button quiz-add-question" id="addQuizQuestion">＋ Tambah soal</button><p class="account-help">Isi empat pilihan untuk setiap soal lalu tentukan kunci jawabannya.</p></div><div class="editor-actions"><button type="button" class="outline-button" id="cancelAssignmentEdit">Batal</button><button type="button" class="primary-button" id="saveAssignment" data-assignment-id="${escapeHtml(id)}">Simpan</button></div>`;
  document.getElementById("addQuizQuestion").addEventListener("click", () => {
    document
      .querySelector(".quiz-editor-list")
      .insertAdjacentHTML("beforeend", quizQuestionEditor());
  });
  document.getElementById("lessonDialog").showModal();
}
async function submitAssignment(button) {
  if (isTeacher) return;
  const task = tasks.find((t) => t.id === button.dataset.taskid);
  if (!task) return;
  if (task.type === "quiz" && task.submitted) {
    notify("Jawaban kuis sudah dikunci dan tidak dapat diubah.");
    return;
  }
  if (task.examMode && !document.fullscreenElement) {
    notify(
      "Ujian harus dikerjakan dalam layar penuh. Tekan tombol Masuk layar penuh.",
    );
    return;
  }
  const payload = { csrfToken, taskId: task.id };
  if (task.type === "quiz") {
    payload.answers = task.questions.map((_, i) => {
      const selected = document.querySelector(
        `input[name="answer-${i}"]:checked`,
      );
      return selected ? Number(selected.value) : null;
    });
    if (payload.answers.includes(null)) {
      notify("Jawab semua pertanyaan kuis terlebih dahulu.");
      return;
    }
  } else {
    payload.answerMode =
      document.querySelector('input[name="essayAnswerMode"]:checked')?.value ||
      "text";
    if (payload.answerMode === "text") {
      payload.response = document.getElementById("assignmentResponse").value;
      if (!payload.response.trim()) {
        notify("Tuliskan jawaban teks terlebih dahulu.");
        return;
      }
    }
  }
  const formData = new FormData();
  formData.append("csrfToken", csrfToken);
  formData.append("taskId", task.id);
  if (task.type === "quiz")
    formData.append("answers", JSON.stringify(payload.answers));
  else {
    formData.append("answerMode", payload.answerMode);
    if (payload.answerMode === "text")
      formData.append("response", payload.response);
  }
  const file =
    task.type === "essay" && payload.answerMode === "photo"
      ? document.getElementById("assignmentSubmissionFile")?.files[0]
      : null;
  if (
    task.type === "essay" &&
    payload.answerMode === "photo" &&
    !file &&
    !task.submission?.attachment?.file
  ) {
    notify("Pilih foto atau video terlebih dahulu.");
    return;
  }
  if (file) formData.append("attachment", file);
  button.disabled = true;
  button.textContent = "Mengirim...";
  try {
    const response = await fetch("assignment_submit.php", {
      method: "POST",
      body: formData,
    });
    const result = await response.json();
    if (!response.ok || !result.ok)
      throw new Error(result.message || "Jawaban belum terkirim.");
    task.submitted = true;
    task.kind = "done";
    task.status = result.status;
    task.score = result.score;
    task.submission =
      task.type === "quiz"
        ? {
            answers: payload.answers,
            score: result.score,
            attachment: result.attachment,
          }
        : { response: payload.response || "", attachment: result.attachment };
    if (task.examMode) endExamFocus();
    document.getElementById("lessonDialog").close();
    refreshCurrentPage();
    notify(
      task.type === "quiz"
        ? `Kuis selesai. Nilaimu ${result.score}/${result.total}.`
        : "Tugas berhasil dikumpulkan.",
    );
  } catch (error) {
    notify(error.message);
    button.disabled = false;
    button.textContent = "Kumpulkan jawaban";
  }
}

function closeAssignmentEditor() {
  const dialog = document.getElementById("lessonDialog");
  const saveButton = document.getElementById("saveAssignment");
  if (!saveButton) {
    dialog.close();
    return;
  }
  const textHasContent = [
    "assignmentTitle",
    "assignmentDue",
    "assignmentDescription",
    "assignmentPrompt",
  ].some((id) => (document.getElementById(id)?.value || "").trim() !== "");
  const quizHasContent = [
    ...document.querySelectorAll(".quiz-editor-question"),
  ].some(
    (row) =>
      row.querySelector(".quiz-question-text").value.trim() !== "" ||
      [...row.querySelectorAll(".quiz-option-input")].some(
        (input) => input.value.trim() !== "",
      ),
  );
  if (!textHasContent && !quizHasContent) {
    dialog.close();
    return;
  }
  if (
    window.confirm(
      "Simpan perubahan sebelum keluar? Pilih OK untuk menyimpan, atau Cancel untuk keluar tanpa menyimpan.",
    )
  )
    saveButton.click();
  else dialog.close();
}
document.addEventListener("change", (e) => {
  if (e.target.id === "assignmentType") {
    const quizBox = document.getElementById("quizEditor");
    const isQuiz = ["quiz", "exam"].includes(e.target.value);
    quizBox.hidden = !isQuiz;
    const examMode = document.getElementById("assignmentExamMode");
    if (e.target.value === "exam") examMode.checked = true;
    if (e.target.value === "essay") examMode.checked = false;
    if (isQuiz && !quizBox.querySelector(".quiz-editor-question"))
      quizBox.querySelector(".quiz-editor-list").innerHTML =
        quizQuestionEditor();
  }
});
document.addEventListener("click", async (e) => {
  const removeQuestion = e.target.closest("[data-remove-quiz-question]");
  if (removeQuestion) {
    removeQuestion.closest(".quiz-editor-question").remove();
    return;
  }
  if (e.target.closest("#cancelAssignmentEdit")) {
    closeAssignmentEditor();
    return;
  }
  const addAssignment = e.target.closest("#addAssignment");
  if (addAssignment) {
    openAssignmentEditor();
    return;
  }
  const editAssignment = e.target.closest("[data-edit-assignment]");
  if (editAssignment) {
    openAssignmentEditor(editAssignment.dataset.editAssignment);
    return;
  }
  const deleteAssignment = e.target.closest("[data-delete-assignment]");
  if (deleteAssignment) {
    if (
      !window.confirm(
        "Hapus tugas atau kuis ini? Pengumpulan murid untuk kegiatan ini juga akan dihapus.",
      )
    )
      return;
    deleteAssignment.disabled = true;
    try {
      const response = await fetch("assignment_manage.php", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
          csrfToken,
          action: "delete",
          id: deleteAssignment.dataset.deleteAssignment,
        }),
      });
      const result = await response.json();
      if (!response.ok || !result.ok)
        throw new Error(result.message || "Tugas belum terhapus.");
      const index = tasks.findIndex((task) => task.id === result.id);
      if (index >= 0) tasks.splice(index, 1);
      refreshCurrentPage();
      notify("Tugas atau kuis berhasil dihapus.");
    } catch (error) {
      notify(error.message);
      deleteAssignment.disabled = false;
    }
    return;
  }
  const saveAssignment = e.target.closest("#saveAssignment");
  if (saveAssignment) {
    const selectedType = document.getElementById("assignmentType").value;
    const type = selectedType === "essay" ? "essay" : "quiz";
    const examMode =
      selectedType === "exam" ||
      (selectedType === "quiz" &&
        document.getElementById("assignmentExamMode")?.checked === true);
    const questions =
      type === "quiz"
        ? [...document.querySelectorAll(".quiz-editor-question")].map(
            (row) => ({
              question: row.querySelector(".quiz-question-text").value,
              options: [...row.querySelectorAll(".quiz-option-input")].map(
                (input) => input.value,
              ),
              answer: Number(row.querySelector(".quiz-answer-key").value),
            }),
          )
        : [];
    if (
      type === "quiz" &&
      (questions.length < 1 ||
        questions.some(
          (question) =>
            !question.question.trim() ||
            question.options.some((option) => !option.trim()),
        ))
    ) {
      notify(
        "Isi pertanyaan, empat pilihan, dan kunci jawaban untuk setiap soal.",
      );
      return;
    }
    const payload = {
      csrfToken,
      action: "save",
      id: saveAssignment.dataset.assignmentId,
      type,
      examMode,
      title: document.getElementById("assignmentTitle").value,
      courseId:
        document.getElementById("assignmentClass").selectedOptions[0]?.dataset
          .course || "",
      classGroupId: Number(document.getElementById("assignmentClass").value),
      due: document.getElementById("assignmentDue").value,
      description: document.getElementById("assignmentDescription").value,
      prompt: document.getElementById("assignmentPrompt").value,
      questions,
    };
    saveAssignment.disabled = true;
    saveAssignment.textContent = "Menyimpan…";
    try {
      const formData = new FormData();
      Object.entries(payload).forEach(([key, value]) =>
        formData.append(
          key,
          key === "questions"
            ? JSON.stringify(value)
            : value === null
              ? ""
              : String(value),
        ),
      );
      const attachment = document.getElementById("assignmentAttachment")
        .files[0];
      if (attachment) formData.append("attachment", attachment);
      formData.append(
        "removeAttachment",
        document.getElementById("removeAttachment")?.checked ? "1" : "0",
      );
      const response = await fetch("assignment_manage.php", {
        method: "POST",
        body: formData,
      });
      const result = await response.json();
      if (!response.ok || !result.ok)
        throw new Error(result.message || "Tugas belum tersimpan.");
      const item = result.assignment;
      const task = {
        ...item,
        meta: `${item.courseTitle} · Dikumpulkan ${item.due}`,
        status: "Belum dikerjakan",
        kind: "due",
        submitted: false,
        score: null,
        submission: null,
      };
      const taskIndex = tasks.findIndex((existing) => existing.id === item.id);
      if (taskIndex < 0) tasks.push(task);
      else tasks[taskIndex] = task;
      document.getElementById("lessonDialog").close();
      refreshCurrentPage();
      notify("Tugas atau kuis berhasil disimpan.");
    } catch (error) {
      notify(error.message);
      saveAssignment.disabled = false;
      saveAssignment.textContent = "Simpan";
    }
    return;
  }
  const add = e.target.closest("[data-add-page-content]");
  if (add) {
    openPageContentEditor(add.dataset.addPageContent);
    return;
  }
  const edit = e.target.closest("[data-edit-page-content]");
  if (edit) {
    openPageContentEditor(edit.dataset.editPageContent, edit.dataset.contentId);
    return;
  }
  if (e.target.closest("#cancelContentEdit")) {
    document.getElementById("lessonDialog").close();
    return;
  }
  const save = e.target.closest("#savePageContent");
  if (save) {
    const payload = {
      csrfToken,
      page: save.dataset.contentPage,
      id: save.dataset.contentId,
      action: "save",
      category: document.getElementById("contentCategory").value,
      title: document.getElementById("contentTitle").value,
      body: document.getElementById("contentBody").value,
    };
    save.disabled = true;
    save.textContent = "Menyimpan…";
    try {
      const response = await fetch("page_content_save.php", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify(payload),
      });
      const result = await response.json();
      if (!response.ok || !result.ok)
        throw new Error(result.message || "Isi halaman belum tersimpan.");
      pageContents[payload.page] = result.items;
      document.getElementById("lessonDialog").close();
      refreshCurrentPage();
      notify("Isi halaman berhasil disimpan.");
    } catch (error) {
      notify(error.message);
      save.disabled = false;
      save.textContent = "Simpan isi";
    }
    return;
  }
  const remove = e.target.closest("[data-delete-page-content]");
  if (remove) {
    if (!window.confirm("Hapus isi halaman ini?")) return;
    const payload = {
      csrfToken,
      page: remove.dataset.deletePageContent,
      id: remove.dataset.contentId,
      action: "delete",
    };
    remove.disabled = true;
    try {
      const response = await fetch("page_content_save.php", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify(payload),
      });
      const result = await response.json();
      if (!response.ok || !result.ok)
        throw new Error(result.message || "Isi halaman belum terhapus.");
      pageContents[payload.page] = result.items;
      refreshCurrentPage();
      notify("Isi halaman berhasil dihapus.");
    } catch (error) {
      notify(error.message);
      remove.disabled = false;
    }
  }
});
document.addEventListener("change", (e) => {
  if (e.target.id === "quranSurahSelect")
    openQuranDigital(Number(e.target.value));
});
document.addEventListener("click", (e) => {
  if (e.target.closest("#openQuranDigital")) {
    openQuranDigital(1);
    return;
  }
  if (e.target.closest("#quranPrev")) {
    openQuranDigital(activeQuranSurah - 1);
    return;
  }
  if (e.target.closest("#quranNext")) {
    openQuranDigital(activeQuranSurah + 1);
    return;
  }
  if (e.target.closest("#retryQuran")) openQuranDigital(activeQuranSurah);
});
document.addEventListener("click", async (e) => {
  if (e.target.closest("#addLibraryItem")) {
    openLibraryEditor();
    return;
  }
  if (e.target.closest("#cancelLibrary")) {
    document.getElementById("lessonDialog").close();
    return;
  }
  const save = e.target.closest("#saveLibrary");
  if (save) {
    const form = new FormData();
    form.append("csrfToken", csrfToken);
    form.append("category", "textbook");
    form.append("gradeLevel", document.getElementById("libraryGrade").value);
    form.append("title", document.getElementById("libraryTitle").value);
    const file = document.getElementById("libraryFile").files[0];
    if (!file) {
      notify("Pilih file yang akan diunggah.");
      return;
    }
    form.append("attachment", file);
    save.disabled = true;
    save.textContent = "Mengunggah...";
    try {
      const response = await fetch("library_manage.php", {
        method: "POST",
        body: form,
      });
      const result = await response.json();
      if (!response.ok || !result.ok)
        throw new Error(result.message || "File belum dapat diunggah.");
      libraryItems = result.items;
      document.getElementById("lessonDialog").close();
      render("perpustakaan");
      notify("Bahan perpustakaan berhasil diunggah.");
    } catch (error) {
      notify(error.message);
      save.disabled = false;
      save.textContent = "Unggah";
    }
    return;
  }
  const remove = e.target.closest("[data-delete-library]");
  if (remove) {
    if (!confirm("Hapus bahan perpustakaan ini?")) return;
    remove.disabled = true;
    try {
      const response = await fetch("library_manage.php", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
          csrfToken,
          action: "delete",
          id: remove.dataset.deleteLibrary,
        }),
      });
      const result = await response.json();
      if (!response.ok || !result.ok)
        throw new Error(result.message || "Bahan belum terhapus.");
      libraryItems = result.items;
      render("perpustakaan");
      notify("Bahan berhasil dihapus.");
    } catch (error) {
      notify(error.message);
      remove.disabled = false;
    }
  }
});
document.addEventListener("click", (e) => {
  const closeButton = e.target.closest("#dialogClose");
  if (closeButton) {
    if (activeExamTaskId) {
      if (!window.confirm("Ujian masih berlangsung. Keluar dari ujian?"))
        return;
      endExamFocus();
    }
    if (document.getElementById("saveAssignment")) closeAssignmentEditor();
    else document.getElementById("lessonDialog").close();
    return;
  }
  const toggle = e.target.closest("#profileMenuToggle");
  const menu = document.getElementById("profileMenu");
  if (toggle) {
    const open = toggle.getAttribute("aria-expanded") === "true";
    toggle.setAttribute("aria-expanded", String(!open));
    menu.hidden = open;
    return;
  }
  if (!e.target.closest(".profile-menu-wrap")) {
    menu.hidden = true;
    document
      .getElementById("profileMenuToggle")
      .setAttribute("aria-expanded", "false");
  }
  const nav = e.target.closest("[data-page]");
  if (nav) {
    menu.hidden = true;
    document
      .getElementById("profileMenuToggle")
      .setAttribute("aria-expanded", "false");
    activeClassGroup = null;
    activeClassTab = "materi";
    render(nav.dataset.page);
    return;
  }
  const course = e.target.closest("[data-courseopen]");
  if (course) {
    showClass(course.dataset.courseopen);
    return;
  }
  const op = e.target.closest("[data-open]");
  if (op) {
    const idx = lessons.findIndex((item) => item[3] === op.dataset.open);
    if (idx >= 0) openLesson(idx);
    else render("materi");
    return;
  }
  const l = e.target.closest("[data-lesson]");
  if (l) {
    openLesson(Number(l.dataset.lesson));
    return;
  }
  const f = e.target.closest("[data-filter]");
  if (f) {
    document
      .querySelectorAll("[data-filter]")
      .forEach((x) => x.classList.toggle("active", x === f));
    let shown = 0;
    document.querySelectorAll(".assignment").forEach((x) => {
      x.hidden =
        f.dataset.filter !== "all" && x.dataset.kind !== f.dataset.filter;
      if (!x.hidden) shown++;
    });
    const empty = document.querySelector(".assignment-filter-empty");
    if (empty) empty.hidden = shown > 0;
    return;
  }
  const cf = e.target.closest("[data-coursefilter]");
  if (cf) {
    document
      .querySelectorAll("[data-coursefilter]")
      .forEach((x) => x.classList.toggle("active", x === cf));
    document
      .querySelectorAll(".lesson[data-course]")
      .forEach(
        (x) =>
          (x.hidden =
            cf.dataset.coursefilter !== "all" &&
            x.dataset.course !== cf.dataset.coursefilter),
      );
    return;
  }
  const task = e.target.closest("[data-task]");
  if (task) {
    openAssignment(task.dataset.task);
    return;
  }
  if (e.target.id === "notifications") openNotifications();
});
document.addEventListener("click", async (e) => {
  const submit = e.target.closest("#submitAssignment");
  if (submit) {
    await submitAssignment(submit);
    return;
  }

  const mark = e.target.closest("#markDone");
  if (mark) {
    mark.disabled = true;
    mark.textContent = "Menyimpan…";
    try {
      const response = await fetch("progress_save.php", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
          csrfToken,
          lessonId: Number(mark.dataset.lessonid),
        }),
      });
      const result = await response.json();
      if (!response.ok || !result.ok)
        throw new Error(result.message || "Progres belum tersimpan.");
      completedLessons.splice(
        0,
        completedLessons.length,
        ...result.completedLessons,
      );
      const course = courses.find(
        (c) => c.id === lessons[Number(mark.dataset.lessonid)][3],
      );
      if (course) {
        course.done = lessons.filter(
          (lesson, index) =>
            lesson[3] === course.id && completedLessons.includes(index),
        ).length;
        course.progress = course.lessons
          ? Math.round((course.done / course.lessons) * 100)
          : 0;
      }
      document.getElementById("lessonDialog").close();
      refreshCurrentPage();
      notify("Progres belajarmu tersimpan.");
    } catch (error) {
      notify(error.message);
      mark.disabled = false;
      mark.textContent = "Coba lagi";
    }
  }
  const add = e.target.closest("#addLesson");
  if (add) {
    openLessonEditor();
    return;
  }
  const edit = e.target.closest("[data-editlesson]");
  if (edit) {
    e.preventDefault();
    openLessonEditor(Number(edit.dataset.editlesson));
    return;
  }
  if (e.target.closest("#cancelEdit")) {
    document.getElementById("lessonDialog").close();
    return;
  }
  const save = e.target.closest("#saveLesson");
  if (save) {
    const payload = {
      csrfToken,
      id: save.dataset.id === "" ? null : Number(save.dataset.id),
      title: document.getElementById("editTitle").value,
      description: document.getElementById("editDescription").value,
      courseId: document.getElementById("editCourse").value,
      body: document.getElementById("editBody").value,
    };
    save.disabled = true;
    save.textContent = "Menyimpan…";
    try {
      const formData = new FormData();
      Object.entries(payload).forEach(([key, value]) =>
        formData.append(key, value === null ? "" : String(value)),
      );
      const attachment = document.getElementById("editAttachment").files[0];
      if (attachment) formData.append("attachment", attachment);
      formData.append(
        "removeAttachment",
        document.getElementById("removeAttachment")?.checked ? "1" : "0",
      );
      const response = await fetch("material_save.php", {
        method: "POST",
        body: formData,
      });
      const result = await response.json();
      if (!response.ok || !result.ok)
        throw new Error(result.message || "Materi belum bisa disimpan.");
      if (payload.id === null) lessons.push(result.material);
      else lessons[payload.id] = result.material;
      document.getElementById("lessonDialog").close();
      refreshCurrentPage();
      notify("Materi berhasil disimpan.");
    } catch (error) {
      notify(error.message);
      save.disabled = false;
      save.textContent = "Simpan materi";
    }
  }
});
document.addEventListener("click", async (e) => {
  const remove = e.target.closest("[data-delete-lesson]");
  if (!remove) return;
  if (!isTeacher) return;
  if (
    !window.confirm(
      "Hapus materi ini? Progres selesai pada materi tersebut juga akan dihapus.",
    )
  )
    return;
  remove.disabled = true;
  try {
    const response = await fetch("material_save.php", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({
        csrfToken,
        action: "delete",
        id: Number(remove.dataset.deleteLesson),
      }),
    });
    const result = await response.json();
    if (!response.ok || !result.ok)
      throw new Error(result.message || "Materi belum terhapus.");
    const id = Number(result.id);
    lessons.splice(id, 1);
    completedLessons.splice(
      0,
      completedLessons.length,
      ...completedLessons
        .filter((index) => index !== id)
        .map((index) => (index > id ? index - 1 : index)),
    );
    refreshCurrentPage();
    notify("Materi berhasil dihapus.");
  } catch (error) {
    notify(error.message);
    remove.disabled = false;
  }
});
document.addEventListener("keydown", (e) => {
  if (e.key === "Enter" && e.target.id === "joinClassCode") {
    e.preventDefault();
    document.getElementById("joinClass")?.click();
  }
});
document.addEventListener("change", (e) => {
  if (e.target.name === "essayAnswerMode") {
    const textMode = e.target.value === "text";
    document.getElementById("essayTextAnswer").hidden = !textMode;
    document.getElementById("essayPhotoAnswer").hidden = textMode;
  }
});
document.addEventListener("click", (e) => {
  if (e.target.closest("#reenterExamFullscreen")) {
    requestExamFullscreen();
    return;
  }
});
document.addEventListener("visibilitychange", () => {
  if (!activeExamTaskId) return;
  if (document.hidden) {
    examTabWasHidden = true;
    return;
  }
  if (examTabWasHidden) {
    examTabWasHidden = false;
    notify(
      "Peringatan ujian: kamu berpindah tab. Kembali ke soal dan lanjutkan dalam layar penuh.",
    );
  }
});
document.addEventListener("fullscreenchange", () => {
  if (activeExamTaskId && !document.fullscreenElement)
    notify(
      "Peringatan ujian: layar penuh telah ditutup. Masuk layar penuh kembali untuk mengumpulkan jawaban.",
    );
});
window.addEventListener("beforeunload", (e) => {
  if (activeExamTaskId) {
    e.preventDefault();
    e.returnValue = "";
  }
});
document.getElementById("lessonDialog").addEventListener("cancel", (e) => {
  if (!activeExamTaskId) return;
  e.preventDefault();
  if (window.confirm("Ujian masih berlangsung. Keluar dari ujian?")) {
    endExamFocus();
    document.getElementById("lessonDialog").close();
  }
});
document.addEventListener("click", async (e) => {
  const addClass = e.target.closest("#addClass");
  if (addClass) {
    openClassEditor();
    return;
  }
  if (e.target.closest("#cancelClassCreate")) {
    document.getElementById("lessonDialog").close();
    return;
  }
  const saveClass = e.target.closest("#saveClass");
  if (saveClass) {
    const className = document.getElementById("newClassName").value.trim();
    if (!className) {
      notify("Masukkan nama kelas terlebih dahulu.");
      return;
    }
    saveClass.disabled = true;
    saveClass.textContent = "Menyimpan…";
    try {
      const response = await fetch("class_manage.php", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ csrfToken, className }),
      });
      const result = await response.json();
      if (!response.ok || !result.ok)
        throw new Error(result.message || "Kelas belum dapat dibuat.");
      classGroups.unshift(result.classGroup);
      const classCount = document.querySelector(".nav-count");
      if (classCount) classCount.textContent = classGroups.length;
      document.getElementById("lessonDialog").close();
      render("kelas");
      notify(
        `Kelas dibuat. Bagikan kode ${result.classGroup.joinCode} kepada siswa.`,
      );
    } catch (error) {
      notify(error.message);
      saveClass.disabled = false;
      saveClass.textContent = "Buat kelas";
    }
    return;
  }
  const joinClass = e.target.closest("#joinClass");
  if (joinClass) {
    const codeInput = document.getElementById("joinClassCode");
    const code = codeInput.value.trim().toUpperCase();
    if (!code) {
      notify("Masukkan kode kelas dari guru.");
      return;
    }
    joinClass.disabled = true;
    joinClass.textContent = "Memeriksa…";
    try {
      const response = await fetch("class_join.php", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ csrfToken, code }),
      });
      const result = await response.json();
      if (!response.ok || !result.ok)
        throw new Error(result.message || "Belum dapat masuk kelas.");
      if (!classGroups.some((group) => group.id === result.classGroup.id))
        classGroups.unshift(result.classGroup);
      render("beranda");
      notify(
        result.alreadyJoined
          ? `Kamu sudah tergabung di ${result.classGroup.className}.`
          : `Berhasil masuk kelas ${result.classGroup.className}.`,
      );
    } catch (error) {
      notify(error.message);
      joinClass.disabled = false;
      joinClass.textContent = "Gabung kelas →";
    }
    return;
  }
  const copy = e.target.closest("[data-copy-code]");
  if (copy) {
    try {
      await navigator.clipboard.writeText(copy.dataset.copyCode);
      notify("Kode kelas berhasil disalin.");
    } catch (error) {
      window.prompt("Salin kode kelas ini:", copy.dataset.copyCode);
    }
    return;
  }
});
document.addEventListener("keydown", (e) => {
  if (
    (e.key === "Enter" || e.key === " ") &&
    e.target.matches("[data-courseopen]")
  ) {
    e.preventDefault();
    showClass(e.target.dataset.courseopen);
  }
});

document.addEventListener("click", async (e) => {
  const leave = e.target.closest("[data-leave-class]");
  if (leave && !isTeacher) {
    if (
      !window.confirm(
        `Keluar dari kelas \"${leave.dataset.className || "ini"}\"? Tugas dan materi kelas tersebut tidak lagi tampil.`,
      )
    )
      return;
    leave.disabled = true;
    try {
      const response = await fetch("class_leave.php", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
          csrfToken,
          classId: Number(leave.dataset.leaveClass),
        }),
      });
      const result = await response.json();
      if (!response.ok || !result.ok)
        throw new Error(result.message || "Belum dapat keluar dari kelas.");
      classGroups = classGroups.filter(
        (group) => Number(group.id) !== Number(result.classId),
      );
      activeClassGroup = null;
      render("kelas");
      notify("Kamu berhasil keluar dari kelas.");
    } catch (error) {
      notify(error.message);
      leave.disabled = false;
    }
    return;
  }
  const remove = e.target.closest("[data-delete-class]");
  if (!remove || !isTeacher) return;
  const className = remove.dataset.className || "kelas ini";
  if (
    !window.confirm(
      `Hapus kelas "${className}"? Siswa hanya akan dikeluarkan dari kelas ini; keanggotaan di kelas lain tetap ada.`,
    )
  )
    return;
  remove.disabled = true;
  try {
    const response = await fetch("class_delete.php", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({
        csrfToken,
        classId: Number(remove.dataset.deleteClass),
      }),
    });
    const result = await response.json();
    if (!response.ok || !result.ok)
      throw new Error(result.message || "Kelas belum dapat dihapus.");
    classGroups = classGroups.filter(
      (group) => Number(group.id) !== Number(result.classId),
    );
    const classCount = document.querySelector(".nav-count");
    if (classCount) classCount.textContent = classGroups.length;
    if (
      activeClassGroup &&
      Number(activeClassGroup.id) === Number(result.classId)
    )
      activeClassGroup = null;
    render("kelas");
    notify("Kelas berhasil dihapus.");
  } catch (error) {
    notify(error.message);
    remove.disabled = false;
  }
});
document.addEventListener("submit", async (e) => {
  if (!e.target.matches("#accountForm")) return;
  e.preventDefault();
  const form = e.target;
  const button = form.querySelector('button[type="submit"]');
  const payload = {
    csrfToken,
    name: document.getElementById("accountName")?.value || student.name,
    nisn: document.getElementById("accountNisn")?.value || "",
    className:
      document.getElementById("accountClass")?.value || student.class || "",
    email: document.getElementById("accountEmail").value,
    currentPassword: document.getElementById("currentPassword").value,
    newPassword: document.getElementById("newPassword").value,
    confirmPassword: document.getElementById("confirmPassword").value,
  };
  button.disabled = true;
  button.textContent = "Menyimpan…";
  try {
    const response = await fetch("account_update.php", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify(payload),
    });
    const result = await response.json();
    if (!response.ok || !result.ok)
      throw new Error(result.message || "Pengaturan akun belum tersimpan.");
    student.email = result.email;
    student.nisn = result.nisn || "";
    student.class = result.className || "";
    if (result.name) {
      student.name = result.name;
      student.initials = result.initials || student.initials;
      document.querySelector(".profile-trigger .top-user b").textContent =
        result.name;
      document.querySelector(".profile-menu-heading").textContent = result.name;
      const introName = document.querySelector(".account-intro b");
      if (introName) introName.textContent = result.name;
      const introAvatar = document.querySelector(".account-avatar");
      if (introAvatar)
        introAvatar.textContent =
          result.initials || result.name.slice(0, 2).toUpperCase();
    }
    form.reset();
    document.getElementById("accountEmail").value = result.email;
    if (document.getElementById("accountName"))
      document.getElementById("accountName").value =
        result.name || student.name;
    if (document.getElementById("accountNisn"))
      document.getElementById("accountNisn").value = result.nisn || "";
    if (document.getElementById("accountClass"))
      document.getElementById("accountClass").value = result.className || "";
    document.querySelector(".profile-trigger .top-user small").textContent =
      student.role === "teacher"
        ? "Guru Pendidikan Agama"
        : "Siswa &middot; Kelas " + (student.class || "belum diisi");
    notify("Pengaturan akun berhasil diperbarui.");
  } catch (error) {
    notify(error.message);
  } finally {
    button.disabled = false;
    button.innerHTML = "Simpan perubahan <span>→</span>";
  }
});
document
  .getElementById("mobileMenu")
  .addEventListener("click", () =>
    document.querySelector(".sidebar").classList.toggle("open"),
  );
render("beranda");

document.addEventListener("click", (e) => {
  const classCard = e.target.closest("[data-classopen]");
  if (classCard) {
    showClassGroup(classCard.dataset.classopen);
    return;
  }
  const tab = e.target.closest("[data-class-tab]");
  if (tab) {
    showClassGroup(tab.dataset.classId, tab.dataset.classTab);
  }
});

document.addEventListener("click", async (e) => {
  const add = e.target.closest("#addClassStudent");
  if (add) {
    openClassStudentEditor(activeClassGroup?.id);
    return;
  }
  if (e.target.closest("#cancelClassStudent")) {
    document.getElementById("lessonDialog").close();
    return;
  }
  const save = e.target.closest("#saveClassStudent");
  const remove = e.target.closest("[data-remove-class-student]");
  if (!save && !remove) return;
  const classId = Number(save?.dataset.classId || remove?.dataset.classId);
  const username = save
    ? document.getElementById("classStudentUsername").value
    : remove.dataset.removeClassStudent;
  const action = save ? "add" : "remove";
  if (remove && !window.confirm("Keluarkan siswa ini dari kelas?")) return;
  const button = save || remove;
  button.disabled = true;
  try {
    const response = await fetch("class_members_manage.php", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ csrfToken, classId, username, action }),
    });
    const result = await response.json();
    if (!response.ok || !result.ok)
      throw new Error(result.message || "Daftar siswa belum dapat diperbarui.");
    const group = classGroups.find((item) => Number(item.id) === classId);
    if (group) {
      group.students = result.students;
      group.memberCount = result.memberCount;
    }
    if (save) document.getElementById("lessonDialog").close();
    showClassGroup(classId, "leaderboard");
    notify(
      action === "add"
        ? "Siswa berhasil ditambahkan ke kelas."
        : "Siswa berhasil dikeluarkan dari kelas.",
    );
  } catch (error) {
    notify(error.message);
    button.disabled = false;
  }
});

document.addEventListener("click", (e) => {
  const back = e.target.closest("[data-back-submissions]");
  if (back) {
    openTaskSubmissions(back.dataset.backSubmissions);
    return;
  }
  const answer = e.target.closest("[data-view-answer]");
  if (answer) {
    openSubmissionAnswer(
      answer.dataset.viewAnswer,
      answer.dataset.studentUsername,
    );
    return;
  }
  const button = e.target.closest("[data-view-submissions]");
  if (button) openTaskSubmissions(button.dataset.viewSubmissions);
});

// Materi selalu dimiliki satu kelas. Kelas baru tidak mewarisi materi kelas lain.
const previousShowClassGroup = showClassGroup;
showClassGroup = function (groupId, tab = "materi") {
  previousShowClassGroup(groupId, tab);
  if (tab === "materi")
    document.querySelectorAll(".lesson[data-course]").forEach((row) => {
      const index = Number(row.querySelector("[data-lesson]")?.dataset.lesson);
      if (Number(lessons[index]?.[6]) !== Number(groupId)) row.remove();
    });
};

// Bobot soal disimpan sebagai persentase dari total nilai 100.
const weightedQuizQuestionEditor = quizQuestionEditor;
quizQuestionEditor = function (question = {}, index = null) {
  const html = weightedQuizQuestionEditor(question, index);
  const weight = Number.isFinite(Number(question.weight))
    ? Number(question.weight)
    : document.querySelectorAll(".quiz-editor-question").length
      ? 0
      : 100;
  return html.replace(
    "</article>",
    `<label class="editor-label">Bobot nilai (%)</label><input class="editor-input quiz-question-weight" type="number" min="0" max="100" step="1" value="${Math.round(weight)}" required></article>`,
  );
};
classMembersPanel = function (group) {
  const members = Array.isArray(group.students) ? group.students : [];
  return `<aside class="panel class-members-panel"><div class="panel-heading"><div><h2>Daftar siswa</h2><p>${members.length} siswa bergabung</p></div>${isTeacher ? '<button class="outline-button" type="button" id="addClassStudent">&#43; Tambah siswa</button>' : ""}</div>${members.length ? `<label class="class-member-search"><span class="sr-only">Cari siswa</span><input id="classMemberSearch" class="editor-input" type="search" placeholder="Cari nama atau username siswa..." autocomplete="off"></label><div class="class-member-list">${members.map((member, index) => `<div class="class-member-row" data-member-search="${escapeHtml(`${member.name} ${member.username}`.toLowerCase())}"><span class="top-avatar">${escapeHtml(member.initials || member.name.slice(0, 1))}</span><span><b>${escapeHtml(member.name)}</b><small>Siswa ${index + 1} · ${escapeHtml(member.username)}</small></span>${isTeacher ? `<button class="content-delete-button" type="button" data-remove-class-student="${escapeHtml(member.username)}" data-class-id="${group.id}" aria-label="Keluarkan ${escapeHtml(member.name)}">Hapus</button>` : ""}</div>`).join("")}<p class="empty-note class-member-search-empty" hidden>Siswa tidak ditemukan.</p></div>` : '<p class="empty-note">Belum ada siswa yang bergabung.</p>'}</aside>`;
};
document.addEventListener("input", (e) => {
  if (e.target.id !== "classMemberSearch") return;
  const query = e.target.value.trim().toLowerCase();
  let shown = 0;
  document.querySelectorAll("[data-member-search]").forEach((row) => {
    const visible = !query || row.dataset.memberSearch.includes(query);
    row.hidden = !visible;
    if (visible) shown++;
  });
  const empty = document.querySelector(".class-member-search-empty");
  if (empty) empty.hidden = shown > 0;
});
const previousOpenLessonEditor = openLessonEditor;
openLessonEditor = function (idx = null) {
  previousOpenLessonEditor(idx);
  const item = idx === null ? null : lessons[idx];
  const selected = Number(item?.[6] || activeClassGroup?.id || 0);
  const anchor = document.getElementById("editCourse");
  if (!anchor) return;
  anchor.insertAdjacentHTML(
    "afterend",
    `<label class="editor-label" for="editClass">Kelas penerima</label><select class="editor-input" id="editClass" required>${classGroups.map((group) => `<option value="${group.id}" ${Number(group.id) === selected ? "selected" : ""}>${escapeHtml(group.className)}</option>`).join("")}</select>`,
  );
  if (activeClassGroup) {
    const label = document.querySelector('label[for="editClass"]');
    const select = document.getElementById("editClass");
    if (select) select.value = String(activeClassGroup.id);
    if (label) label.hidden = true;
    if (select) {
      select.hidden = true;
      select.disabled = false;
    }
  }
};
document.addEventListener(
  "click",
  async (e) => {
    const save = e.target.closest("#saveLesson");
    if (!save) return;
    e.preventDefault();
    e.stopImmediatePropagation();
    const classGroupId = Number(
      document.getElementById("editClass")?.value || 0,
    );
    if (!classGroupId) {
      notify("Pilih kelas penerima materi terlebih dahulu.");
      return;
    }
    save.disabled = true;
    save.textContent = "Menyimpan…";
    try {
      const formData = new FormData();
      [
        ["csrfToken", csrfToken],
        ["id", save.dataset.id === "" ? "" : save.dataset.id],
        ["title", document.getElementById("editTitle").value],
        ["description", document.getElementById("editDescription").value],
        ["courseId", document.getElementById("editCourse").value],
        ["classGroupId", String(classGroupId)],
        ["body", document.getElementById("editBody").value],
      ].forEach(([key, value]) => formData.append(key, value));
      const attachment = document.getElementById("editAttachment").files[0];
      if (attachment) formData.append("attachment", attachment);
      formData.append(
        "removeAttachment",
        document.getElementById("removeAttachment")?.checked ? "1" : "0",
      );
      const response = await fetch("material_save.php", {
        method: "POST",
        body: formData,
      });
      const result = await response.json();
      if (!response.ok || !result.ok)
        throw new Error(result.message || "Materi belum bisa disimpan.");
      const id = save.dataset.id === "" ? null : Number(save.dataset.id);
      if (id === null) lessons.push(result.material);
      else lessons[id] = result.material;
      document.getElementById("lessonDialog").close();
      refreshCurrentPage();
      notify("Materi berhasil disimpan.");
    } catch (error) {
      notify(error.message);
      save.disabled = false;
      save.textContent = "Simpan materi";
    }
  },
  { capture: true },
);

// Vanuit een geopende klas is de doelklas al bekend; verberg de overbodige keuze.
const previousOpenAssignmentEditor = openAssignmentEditor;
openAssignmentEditor = function (id = "") {
  previousOpenAssignmentEditor(id);
  if (!activeClassGroup) return;
  const select = document.getElementById("assignmentClass");
  if (!select) return;
  select.value = String(activeClassGroup.id);
  select.hidden = true;
  select.disabled = false;
  const label = document.querySelector('label[for="assignmentClass"]');
  if (label) label.hidden = true;
  const due = document.getElementById("assignmentDue");
  if (due) {
    due.type = "datetime-local";
    due.step = "60";
    const task = tasks.find((item) => item.id === id);
    if (task?.due && /^\\d{4}-\\d{2}-\\d{2}T\\d{2}:\\d{2}$/.test(task.due))
      due.value = task.due;
  }
};

const previousOpenPageContentEditor = openPageContentEditor;
openPageContentEditor = function (pageKey, id = "") {
  previousOpenPageContentEditor(pageKey, id);
  const category = document.getElementById("contentCategory");
  if (category) {
    category.value = "";
    category.hidden = true;
    const label = document.querySelector('label[for="contentCategory"]');
    if (label) label.hidden = true;
  }
};

// Tenggat dibuat sebagai tanggal dan jam saat kegiatan dibuat dari halaman umum maupun kelas.
const previousOpenAssignmentEditorWithDeadline = openAssignmentEditor;
openAssignmentEditor = function (id = "") {
  previousOpenAssignmentEditorWithDeadline(id);
  const due = document.getElementById("assignmentDue");
  if (due) {
    due.type = "datetime-local";
    due.step = "60";
    const task = tasks.find((item) => item.id === id);
    if (task?.due && /^\\d{4}-\\d{2}-\\d{2}T\\d{2}:\\d{2}$/.test(task.due))
      due.value = task.due;
  }
};

const openAssignmentEditorWithDateTime = openAssignmentEditor;
openAssignmentEditor = function (id = "") {
  openAssignmentEditorWithDateTime(id);
  const due = document.getElementById("assignmentDue");
  const task = tasks.find((item) => item.id === id);
  if (due) {
    due.type = "datetime-local";
    due.step = "60";
    if (task?.due) due.value = String(task.due).replace(" ", "T").slice(0, 16);
  }
};

// Leaderboard pada tab kelas harus selalu memakai anggota kelas aktif, termasuk untuk guru.
function classScopedLeaderboardPage() {
  const groups = activeClassGroup ? [activeClassGroup] : classGroups;
  const members = new Set(
    groups.flatMap((group) =>
      (group.students || []).map((member) => member.username),
    ),
  );
  const scopedLeaderboard = leaderboard.filter((entry) =>
    members.has(entry.username),
  );
  return `<div class="page-head"><div><div class="eyebrow">APRESIASI BELAJAR</div><h1 class="page-title">Leaderboard nilai siswa</h1><p class="page-subtitle">Peringkat dihitung dari nilai kuis dan materi yang telah diselesaikan.</p></div><span class="date-chip">${scopedLeaderboard.length} siswa</span></div><section class="leaderboard-intro panel"><span>♛</span><div><b>Apresiasi untuk usaha belajar</b><p>Peringkat berdasarkan nilai kuis dan materi yang selesai.</p></div></section><section class="panel leaderboard-panel"><div class="panel-heading"><h2>${isTeacher ? "Peringkat siswa" : "Peringkat kelas yang kamu ikuti"}</h2><span class="tag">Nilai kuis</span></div><div class="leaderboard-head"><span>Peringkat & siswa</span><span>Materi selesai</span><span>Nilai kuis</span></div>${scopedLeaderboard.map((s, i) => `<div class="leaderboard-row ${s.username === student.username ? "leaderboard-self" : ""}"><div class="leaderboard-person"><span class="leader-rank ${i < 3 ? "leader-rank-top" : ""}">${i < 1 ? "0" : ""}${i + 1}</span><span class="top-avatar">${escapeHtml(s.initials)}</span><span class="leader-name"><b>${escapeHtml(s.name)}${s.username === student.username ? " (Kamu)" : ""}</b><small>${s.username === student.username ? "Kamu" : "Siswa"}</small></span></div><span class="leader-completed">${s.completed}</span><div class="leader-score"><b>${s.score}%</b><small>${s.quizScore}/${s.quizMaximum} benar</small></div></div>`).join("") || '<div class="empty-note">Belum ada data nilai siswa.</div>'}<p class="leaderboard-note">Nilai akan diperbarui setelah siswa mengerjakan kuis.</p></section>`;
}
renderers.leaderboard = classScopedLeaderboardPage;

// Fitur belajar tambahan: kalender, notifikasi, dan ringkasan progress.
function taskDate(task) {
  const raw = String(task?.due || "").trim();
  if (!raw) return null;
  const normalized = raw.replace(" ", "T");
  const date = new Date(normalized);
  return Number.isNaN(date.getTime()) ? null : date;
}
function formatTaskDate(task) {
  const date = taskDate(task);
  return date
    ? date.toLocaleDateString("id-ID", {
        day: "numeric",
        month: "short",
        year: "numeric",
      })
    : String(task?.due || "Tanggal belum diatur");
}
function scopedTasks() {
  return isTeacher ? tasks : studentTasks();
}
function calendarPage() {
  const visible = scopedTasks();
  const now = new Date();
  const monthStart = new Date(now.getFullYear(), now.getMonth(), 1);
  const monthEnd = new Date(now.getFullYear(), now.getMonth() + 1, 0);
  const firstDay = (monthStart.getDay() + 6) % 7;
  const cells = [];
  for (let i = 0; i < firstDay; i++)
    cells.push('<div class="calendar-day calendar-empty"></div>');
  for (let day = 1; day <= monthEnd.getDate(); day++) {
    const dateKey = `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, "0")}-${String(day).padStart(2, "0")}`;
    const dayTasks = visible.filter((task) => {
      const date = taskDate(task);
      return (
        date &&
        date.getFullYear() === now.getFullYear() &&
        date.getMonth() === now.getMonth() &&
        date.getDate() === day
      );
    });
    cells.push(
      `<div class="calendar-day ${day === now.getDate() ? "calendar-today" : ""}"><b>${day}</b>${dayTasks.map((task) => `<button type="button" class="calendar-task ${task.submitted ? "calendar-task-done" : ""}" data-task="${escapeHtml(task.id)}"><span>${task.type === "quiz" ? "☑" : "✎"}</span>${escapeHtml(task.title)}</button>`).join("") || '<span class="calendar-no-task">-</span>'}</div>`,
    );
  }
  return `<div class="page-head"><div><div class="eyebrow">PERENCANAAN BELAJAR</div><h1 class="page-title">Kalender tugas</h1><p class="page-subtitle">Lihat tugas dan kuis berdasarkan tanggal pengumpulan.</p></div><span class="date-chip">${visible.filter((task) => !task.submitted).length} belum selesai</span></div><section class="panel calendar-panel"><div class="calendar-heading"><button type="button" class="outline-button" data-page="tugas">Lihat daftar tugas</button><h2>${now.toLocaleDateString("id-ID", { month: "long", year: "numeric" })}</h2></div><div class="calendar-weekdays">${["Sen", "Sel", "Rab", "Kam", "Jum", "Sab", "Min"].map((day) => `<span>${day}</span>`).join("")}</div><div class="calendar-grid">${cells.join("")}</div></section><section class="panel upcoming-panel"><div class="panel-heading"><div><h2>Deadline terdekat</h2><p>Tugas yang perlu segera diselesaikan.</p></div></div>${
    visible
      .filter((task) => !task.submitted)
      .sort(
        (a, b) =>
          (taskDate(a)?.getTime() || Infinity) -
          (taskDate(b)?.getTime() || Infinity),
      )
      .slice(0, 5)
      .map(
        (task) =>
          `<button type="button" class="task-row task-row-button" data-task="${escapeHtml(task.id)}"><span class="task-icon">${task.type === "quiz" ? "☑" : "✎"}</span><span class="task-text"><b>${escapeHtml(task.title)}</b><small>${escapeHtml(task.courseTitle || "Kegiatan belajar")} · ${escapeHtml(formatTaskDate(task))}</small></span><span class="task-status">Buka →</span></button>`,
      )
      .join("") ||
    '<p class="empty-note">Belum ada deadline yang perlu dikerjakan.</p>'
  }</section>`;
}
function progressPage() {
  const visible = scopedTasks();
  const submitted = visible.filter((task) => task.submitted);
  const quiz = visible.filter((task) => task.type === "quiz" && task.submitted);
  const points = quiz.map((task) => Number(task.score) || 0);
  const average = points.length
    ? Math.round(points.reduce((sum, value) => sum + value, 0) / points.length)
    : 0;
  const totalLessons = isTeacher
    ? lessons.length
    : lessons.filter((lesson) => studentCourseIds().has(String(lesson[3])))
        .length;
  const progressPercent = totalLessons
    ? Math.round((completedLessons.length / totalLessons) * 100)
    : 0;
  const courseCards = courses
    .filter((course) => isTeacher || studentCourseIds().has(String(course.id)))
    .map(courseCard)
    .join("");
  return `<div class="page-head"><div><div class="eyebrow">PERJALANAN BELAJAR</div><h1 class="page-title">Progres belajar</h1><p class="page-subtitle">Pantau materi yang selesai, tugas yang dikumpulkan, dan hasil kuis.</p></div><span class="date-chip">${progressPercent}% materi selesai</span></div><section class="metrics progress-metrics"><div class="metric"><div class="metric-icon">▣</div><div><strong>${completedLessons.length}/${totalLessons}</strong><span>Materi selesai</span></div></div><div class="metric"><div class="metric-icon">✎</div><div><strong>${submitted}/${visible.length}</strong><span>Kegiatan terkumpul</span></div></div><div class="metric"><div class="metric-icon">✦</div><div><strong>${average}%</strong><span>Rata-rata kuis</span></div></div><div class="metric"><div class="metric-icon">✓</div><div><strong>${quiz.length}</strong><span>Kuis selesai</span></div></div></section><section class="panel progress-overview"><div class="panel-heading"><div><h2>Perkembangan keseluruhan</h2><p>${progressPercent}% dari materi yang tersedia sudah selesai.</p></div><b class="progress-percent">${progressPercent}%</b></div><div class="progress-track progress-track-large"><div class="progress-fill" style="width:${progressPercent}%"></div></div></section><div class="section-heading"><div><h2>Progres tiap kelas</h2><p>Lanjutkan materi yang belum selesai.</p></div></div><section class="courses">${courseCards || '<p class="empty-note">Belum ada kelas atau materi yang tersedia.</p>'}</section></div>`;
}
function notificationItems() {
  const list = [];
  const now = Date.now();
  scopedTasks().forEach((task) => {
    const date = taskDate(task);
    if (
      !task.submitted &&
      date &&
      date.getTime() >= now - 86400000 &&
      date.getTime() - now <= 7 * 86400000
    )
      list.push({
        icon: "◷",
        title: `Deadline ${task.title}`,
        body: `Dikumpulkan ${formatTaskDate(task)}.`,
        page: "kalender",
      });
    if (
      task.submitted &&
      task.type === "quiz" &&
      task.score !== null &&
      task.score !== undefined
    )
      list.push({
        icon: "✓",
        title: `Nilai kuis tersedia: ${task.title}`,
        body: `Nilai ${task.score}/${task.questions?.length || 0}.`,
        page: "progress",
      });
  });
  const infos = isTeacher
    ? pageContents.informasi || []
    : [
        ...(pageContents.informasi || []),
        ...classGroups.flatMap(
          (group) => pageContents[`class_${group.id}_informasi`] || [],
        ),
      ];
  infos
    .slice(-5)
    .forEach((item) =>
      list.push({
        icon: "✦",
        title: item.title,
        body: item.body,
        page: "informasi",
      }),
    );
  return list.slice(0, 10);
}
function openNotifications() {
  const items = notificationItems();
  document.getElementById("dialogContent").innerHTML =
    `<div class="eyebrow">PEMBARUAN TERBARU</div><h2>Notifikasi</h2><p>Informasi penting tentang kegiatan belajarmu.</p><div class="notification-list">${items.map((item) => `<button type="button" class="notification-item" data-page="${item.page}"><span class="notification-icon">${item.icon}</span><span><b>${escapeHtml(item.title)}</b><small>${escapeHtml(item.body)}</small></span></button>`).join("") || '<p class="empty-note">Belum ada notifikasi baru.</p>'}</div>`;
  document.getElementById("lessonDialog").showModal();
}
function teacherInsights() {
  const students = [
    ...new Map(
      classGroups.flatMap((group) =>
        (group.students || []).map((student) => [student.username, student]),
      ),
    ).values(),
  ];
  const pending = tasks.reduce(
    (total, task) =>
      total +
      Math.max(
        0,
        students.filter((student) =>
          classGroups.some(
            (group) =>
              (group.students || []).some(
                (member) => member.username === student.username,
              ) && Number(task.classGroupId) === Number(group.id),
          ),
        ).length - (task.submissions || []).length,
      ),
    0,
  );
  const scores = leaderboard.map((entry) => Number(entry.score) || 0);
  const average = scores.length
    ? Math.round(scores.reduce((sum, value) => sum + value, 0) / scores.length)
    : 0;
  const attention = leaderboard
    .filter((entry) => entry.score < 60 || entry.submitted === 0)
    .slice(0, 5);
  return { students, pending, average, attention };
}
const previousTeacherHome = teacherHome;
teacherHome = function () {
  const insights = teacherInsights();
  const base = previousTeacherHome();
  const dashboard = `<section class="teacher-insight-grid"><article class="panel teacher-insight-card"><span class="teacher-home-icon">♙</span><div><span>Total siswa</span><b>${insights.students.length}</b><small>${classGroups.length} kelas aktif</small></div></article><article class="panel teacher-insight-card"><span class="teacher-home-icon">◷</span><div><span>Belum terkumpul</span><b>${insights.pending}</b><small>Pengumpulan yang perlu dipantau</small></div></article><article class="panel teacher-insight-card"><span class="teacher-home-icon">✦</span><div><span>Rata-rata nilai</span><b>${insights.average}%</b><small>Berdasarkan nilai kuis</small></div></article></section><section class="panel teacher-attention-panel"><div class="panel-heading"><div><h2>Siswa yang perlu diperhatikan</h2><p>Siswa dengan nilai rendah atau belum mengumpulkan kegiatan.</p></div><button type="button" data-page="leaderboard">Lihat leaderboard →</button></div>${insights.attention.map((item) => `<div class="teacher-attention-row"><span class="top-avatar">${escapeHtml(item.initials)}</span><span><b>${escapeHtml(item.name)}</b><small>${item.submitted} kegiatan terkumpul · nilai ${item.score}%</small></span></div>`).join("") || '<p class="empty-note">Belum ada siswa yang perlu diperhatikan.</p>'}</section>`;
  return base.replace(
    '<section class="teacher-home-grid">',
    dashboard + '<section class="teacher-home-grid">',
  );
};
renderers.kalender = calendarPage;
renderers.progress = progressPage;
names.kalender = "Kalender tugas";
names.progress = "Progres belajar";
progressPage = function () {
  const visibleLessons = lessons
    .map((lesson, index) => ({ lesson, index }))
    .filter(
      ({ lesson }) => isTeacher || studentCourseIds().has(String(lesson[3])),
    );
  const relevantCompleted = visibleLessons.filter((item) =>
    completedLessons.includes(item.index),
  ).length;
  const visible = scopedTasks();
  const submittedCount = visible.filter((task) => task.submitted).length;
  const quiz = visible.filter((task) => task.type === "quiz" && task.submitted);
  const points = quiz.map((task) => Number(task.score) || 0);
  const average = points.length
    ? Math.round(points.reduce((sum, value) => sum + value, 0) / points.length)
    : 0;
  const totalLessons = visibleLessons.length;
  const progressPercent = totalLessons
    ? Math.min(100, Math.round((relevantCompleted / totalLessons) * 100))
    : 0;
  const courseCards = courses
    .filter((course) => isTeacher || studentCourseIds().has(String(course.id)))
    .map(courseCard)
    .join("");
  return `<div class="page-head"><div><div class="eyebrow">PERJALANAN BELAJAR</div><h1 class="page-title">Progres belajar</h1><p class="page-subtitle">Pantau materi yang selesai, tugas yang dikumpulkan, dan hasil kuis.</p></div><span class="date-chip">${progressPercent}% materi selesai</span></div><section class="metrics progress-metrics"><div class="metric"><div class="metric-icon">▣</div><div><strong>${relevantCompleted}/${totalLessons}</strong><span>Materi selesai</span></div></div><div class="metric"><div class="metric-icon">✎</div><div><strong>${submittedCount}/${visible.length}</strong><span>Kegiatan terkumpul</span></div></div><div class="metric"><div class="metric-icon">✦</div><div><strong>${average}%</strong><span>Rata-rata kuis</span></div></div><div class="metric"><div class="metric-icon">✓</div><div><strong>${quiz.length}</strong><span>Kuis selesai</span></div></div></section><section class="panel progress-overview"><div class="panel-heading"><div><h2>Perkembangan keseluruhan</h2><p>${progressPercent}% dari materi yang tersedia sudah selesai.</p></div><b class="progress-percent">${progressPercent}%</b></div><div class="progress-track progress-track-large"><div class="progress-fill" style="width:${progressPercent}%"></div></div></section><div class="section-heading"><div><h2>Progres tiap kelas</h2><p>Lanjutkan materi yang belum selesai.</p></div></div><section class="courses">${courseCards || '<p class="empty-note">Belum ada kelas atau materi yang tersedia.</p>'}</section></div>`;
};
renderers.progress = progressPage;
window.addEventListener("load", () => {
  const nav = document.querySelector(".sidebar .nav-list");
  if (!nav) return;
  [
    ["progress", "Progres belajar", "▤"],
    ["kalender", "Kalender tugas", "◷"],
  ].forEach(([pageKey, label, icon]) => {
    if (document.querySelector(`[data-page="${pageKey}"]`)) return;
    const button = document.createElement("button");
    button.className = "nav-item";
    button.dataset.page = pageKey;
    button.innerHTML = `<span class="nav-icon">${icon}</span> ${label}`;
    nav.append(button);
  });
  if (isTeacher && currentPage === "beranda") render("beranda");
});

// Daftar kandidat anggota tetap berasal dari semua akun siswa, bukan dari leaderboard.
openClassStudentEditor = function (groupId) {
  const group = classGroups.find((item) => Number(item.id) === Number(groupId));
  if (!group || !isTeacher) return;
  const enrolled = new Set((group.students || []).map((item) => item.username));
  const options = (studentDirectory.length ? studentDirectory : leaderboard)
    .filter((item) => !enrolled.has(item.username))
    .map(
      (item) =>
        `<option value="${escapeHtml(item.username)}">${escapeHtml(item.name)} · ${escapeHtml(item.username)}</option>`,
    )
    .join("");
  document.getElementById("dialogContent").innerHTML =
    `<div class="eyebrow">KELOLA ANGGOTA KELAS</div><h2>Tambah siswa</h2>${options ? `<label class="editor-label" for="classStudentUsername">Pilih siswa</label><select class="editor-input" id="classStudentUsername">${options}</select><div class="editor-actions"><button type="button" class="outline-button" id="cancelClassStudent">Batal</button><button type="button" class="primary-button" id="saveClassStudent" data-class-id="${group.id}">Tambahkan</button></div>` : '<p class="empty-note">Semua akun siswa sudah menjadi anggota kelas ini.</p><div class="editor-actions"><button type="button" class="outline-button" id="cancelClassStudent">Tutup</button></div>'}`;
  document.getElementById("lessonDialog").showModal();
};

// Sisipkan bobot dari editor ke payload lama sebelum dikirim ke server.
const nativeFetch = window.fetch.bind(window);
window.fetch = (input, init) => {
  const url = typeof input === "string" ? input : input?.url || "";
  if (init?.body instanceof FormData) {
    if (url.includes("assignment_manage.php")) {
      const form = init.body;
      const raw = form.get("questions");
      if (raw) {
        try {
          const questions = JSON.parse(raw);
          questions.forEach((question, index) => {
            const field = document.querySelectorAll(".quiz-question-weight")[
              index
            ];
            if (field) question.weight = Number(field.value || 0);
            const kind = document.querySelectorAll(".quiz-question-kind")[
              index
            ];
            if (kind) question.kind = kind.value;
          });
          form.set("questions", JSON.stringify(questions));
        } catch (error) {}
      }
      const selectedType = document.getElementById("assignmentType")?.value;
      if (selectedType === "essayQuiz") {
        form.set("type", "essay_quiz");
        form.set(
          "examMode",
          document.getElementById("assignmentExamMode")?.checked ? "1" : "0",
        );
      }
    }
    if (url.includes("assignment_submit.php")) {
      init.body.set("fullscreen", document.fullscreenElement ? "1" : "0");
      const essays = [...document.querySelectorAll(".mixed-essay-answer")];
      if (essays.length)
        init.body.set(
          "essayResponses",
          JSON.stringify(
            Object.fromEntries(
              essays.map((field) => [field.dataset.essayIndex, field.value]),
            ),
          ),
        );
      const files = document.getElementById("assignmentSubmissionFile")?.files;
      if (files?.length) {
        init.body.delete("attachment");
        [...files].forEach((file) => init.body.append("attachments[]", file));
      }
    }
  }
  return nativeFetch(input, init);
};
const originalAssignmentEditor = openAssignmentEditor;
openAssignmentEditor = function (id = "") {
  originalAssignmentEditor(id);
  document.querySelector('#assignmentType option[value="exam"]')?.remove();
  const quizBox = document.getElementById("quizEditor");
  const mode = quizBox?.querySelector(".exam-mode-option");
  if (quizBox && mode) {
    quizBox.parentElement.insertBefore(mode, quizBox);
    quizBox.hidden =
      document.getElementById("assignmentType")?.value === "essay";
    document
      .getElementById("assignmentType")
      ?.addEventListener("change", (event) => {
        quizBox.hidden = event.target.value === "essay";
      });
  }
  const fields = [...document.querySelectorAll(".quiz-question-weight")];
  if (fields.length > 1 && fields.every((field) => Number(field.value) === 100))
    fields.forEach(
      (field, index) =>
        (field.value = String(
          Math.floor(100 / fields.length) +
            (index === fields.length - 1 ? 100 % fields.length : 0),
        )),
    );
  fields.forEach((field) => {
    field.step = "1";
    field.value = String(Math.round(Number(field.value) || 0));
  });
  document
    .querySelectorAll(".quiz-question-kind")
    .forEach((select) =>
      select.dispatchEvent(new Event("change", { bubbles: true })),
    );
  renumberQuizQuestions();
};
function renumberQuizQuestions() {
  document.querySelectorAll(".quiz-editor-question").forEach((row, index) => {
    const title = row.querySelector(".quiz-editor-question-head b");
    if (title) title.textContent = `Soal ${index + 1}`;
  });
}
document.addEventListener("click", (e) => {
  if (
    e.target.closest("#addQuizQuestion") ||
    e.target.closest("[data-remove-quiz-question]")
  )
    setTimeout(() => {
      const fields = [...document.querySelectorAll(".quiz-question-weight")];
      if (
        fields.length &&
        fields.every(
          (field) => Number(field.value) === 100 || Number(field.value) === 0,
        )
      )
        fields.forEach((field, index) => {
          field.step = "1";
          field.value = String(
            Math.floor(100 / fields.length) +
              (index === fields.length - 1 ? 100 % fields.length : 0),
          );
        });
      renumberQuizQuestions();
    }, 0);
});
const originalClassScopedLeaderboardPage = classScopedLeaderboardPage;
classScopedLeaderboardPage = function () {
  return originalClassScopedLeaderboardPage().replace(
    /<small>[^<]* benar<\/small>/g,
    "<small>Skala nilai 100</small>",
  );
};
renderers.leaderboard = classScopedLeaderboardPage;

// Satu kegiatan "Soal" dapat mencampur pilihan ganda dan esai.
const combinedQuestionEditor = quizQuestionEditor;
quizQuestionEditor = function (question = {}, index = null) {
  const html = combinedQuestionEditor(question, index);
  const kind = question.kind === "essay" ? "essay" : "choice";
  const hidden = kind === "essay" ? " hidden" : "";
  return html
    .replace(
      '<div class="quiz-option-fields">',
      `<div class="quiz-option-fields"${hidden}>`,
    )
    .replace(
      '<label class="editor-label">Kunci jawaban</label>',
      `<label class="editor-label"${hidden}>Kunci jawaban</label>`,
    )
    .replace(
      '<label class="editor-label">Pertanyaan</label>',
      `<label class="editor-label">Jenis soal</label><select class="editor-input quiz-question-kind"><option value="choice" ${kind === "choice" ? "selected" : ""}>Pilihan ganda</option><option value="essay" ${kind === "essay" ? "selected" : ""}>Esai</option></select><label class="editor-label">Pertanyaan</label>`,
    );
};
document.addEventListener("change", (event) => {
  if (!event.target.matches(".quiz-question-kind")) return;
  const row = event.target.closest(".quiz-editor-question");
  const fields = row?.querySelector(".quiz-option-fields");
  const key = row?.querySelector(".quiz-answer-key");
  if (!fields || !key) return;
  const essay = event.target.value === "essay";
  fields.hidden = essay;
  key.hidden = essay;
  if (essay)
    fields.querySelectorAll("input").forEach((input) => {
      input.value = "";
    });
  else
    fields.querySelectorAll("input").forEach((input) => {
      if (input.value === "-") input.value = "";
    });
});
// Final soal workflow: PG, PG + esai, dan fullscreen tetap tersedia bersamaan.
const baseAssignmentEditor = originalAssignmentEditor;
openAssignmentEditor = function (id = "") {
  const task = tasks.find((item) => item.id === id);
  const originalType = task?.type;
  if (task && originalType === "essay_quiz") task.type = "quiz";
  baseAssignmentEditor(id);
  if (task) task.type = originalType;
  const typeSelect = document.getElementById("assignmentType");
  if (!typeSelect) return;
  let mixed = typeSelect.querySelector('option[value="essayQuiz"]');
  if (!mixed) {
    mixed = document.createElement("option");
    mixed.value = "essayQuiz";
    mixed.textContent = "Soal PG + esai";
    typeSelect.appendChild(mixed);
  }
  typeSelect.querySelector('option[value="exam"]')?.remove();
  typeSelect
    .querySelector('option[value="quiz"]')
    ?.replaceChildren(document.createTextNode("Soal pilihan ganda"));
  if (originalType === "essay_quiz") typeSelect.value = "essayQuiz";
  document
    .querySelectorAll(".quiz-question-kind")
    .forEach((select) =>
      select.dispatchEvent(new Event("change", { bubbles: true })),
    );
  const fileLabel = document.querySelector(
    'label[for="assignmentSubmissionFile"]',
  );
  if (fileLabel)
    fileLabel.textContent =
      "Pilih foto atau video (bisa lebih dari satu, maksimal 8 file)";
};
document.addEventListener("change", (event) => {
  if (event.target.id !== "assignmentType") return;
  const quizBox = document.getElementById("quizEditor");
  const isQuestionSet = ["quiz", "exam", "essayQuiz"].includes(
    event.target.value,
  );
  if (quizBox) quizBox.hidden = !isQuestionSet;
  const mode = document.getElementById("assignmentExamMode");
  if (mode) {
    mode.disabled = !isQuestionSet;
    if (event.target.value === "exam") mode.checked = true;
    if (event.target.value === "essay") mode.checked = false;
  }
  if (isQuestionSet && !quizBox?.querySelector(".quiz-editor-question"))
    quizBox
      ?.querySelector(".quiz-editor-list")
      ?.insertAdjacentHTML("beforeend", quizQuestionEditor());
  if (event.target.value === "essayQuiz") {
    const editId =
      document.getElementById("saveAssignment")?.dataset.assignmentId;
    const source = tasks.find((item) => item.id === editId);
    (source?.questions || []).forEach((question, index) => {
      const select = document.querySelectorAll(".quiz-question-kind")[index];
      if (select) {
        select.value = question.kind === "essay" ? "essay" : "choice";
        select.dispatchEvent(new Event("change", { bubbles: true }));
      }
    });
    if (!source)
      document.querySelectorAll(".quiz-question-kind").forEach((select) => {
        select.value = "choice";
        select.dispatchEvent(new Event("change", { bubbles: true }));
      });
  }
});
// Paksa tampilan editor esai tetap tersembunyi meskipun ada style lain yang mengatur grid pilihan.
document.addEventListener(
  "change",
  (event) => {
    if (!event.target.matches(".quiz-question-kind")) return;
    const row = event.target.closest(".quiz-editor-question");
    const hidden = event.target.value === "essay";
    const fields = row?.querySelector(".quiz-option-fields");
    const key = row?.querySelector(".quiz-answer-key");
    const keyLabel = key?.previousElementSibling;
    if (fields) fields.hidden = hidden;
    if (fields)
      fields.querySelectorAll("input").forEach((input) => {
        input.disabled = hidden;
        if (hidden) input.value = "";
      });
    if (keyLabel) keyLabel.hidden = hidden;
    if (key) key.hidden = hidden;
    if (key) key.disabled = hidden;
  },
  true,
);
const baseStudentAssignment = openAssignment;
// Validasi lama membutuhkan nilai internal untuk pilihan soal esai; tampilannya tetap kosong.
document.addEventListener(
  "click",
  (event) => {
    const save = event.target.closest("#saveAssignment");
    if (!save) return;
    const temporary = [];
    document.querySelectorAll(".quiz-editor-question").forEach((row) => {
      if (row.querySelector(".quiz-question-kind")?.value !== "essay") return;
      row.querySelectorAll(".quiz-option-input").forEach((input) => {
        temporary.push(input);
        input.value = "-";
      });
    });
    if (temporary.length)
      setTimeout(
        () =>
          temporary.forEach((input) => {
            input.value = "";
          }),
        0,
      );
  },
  true,
);
openAssignment = function (id) {
  const task = tasks.find((item) => item.id === id);
  const originalType = task?.type;
  if (task && originalType === "essay_quiz") task.type = "quiz";
  baseStudentAssignment(id);
  if (task) task.type = originalType;
  if (!task || !["quiz", "essay_quiz"].includes(originalType)) return;
  task.questions.forEach((question, index) => {
    if (question.kind !== "essay") return;
    const fieldset = document.querySelectorAll(".quiz-question")[index];
    if (!fieldset) return;
    fieldset.innerHTML = `<legend>${index + 1}. ${escapeHtml(question.question)}</legend><textarea class="editor-input editor-textarea mixed-essay-answer" data-essay-index="${index}" maxlength="5000" placeholder="Tuliskan jawaban esai...">${escapeHtml(task.submission?.essayResponses?.[index] || "")}</textarea><input type="radio" name="answer-${index}" value="0" checked hidden>`;
  });
};
const baseSubmitAssignment = submitAssignment;
submitAssignment = async function (button) {
  const task = tasks.find((item) => item.id === button.dataset.taskid);
  const originalType = task?.type;
  if (task && originalType === "essay_quiz") task.type = "quiz";
  try {
    return await baseSubmitAssignment(button);
  } finally {
    if (task) task.type = originalType;
  }
};
