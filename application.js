"use strict";
window.uploadStatus = window.uploadStatus || {};
/* =====================================================
   CONFIG
===================================================== */
const API = "save_application.php";
let currentApplicationId = null;
let isNavigating = false;


/* =====================================================
   REQUIRED UPLOAD CONFIG (DO NOT CHANGE)
===================================================== */
const REQUIRED_UPLOADS = [
  "degree_transcripts",
  "valid_passport",
  "cv_resume",
];

// upload validation state (field => response)


/* =====================================================
   STEP NAVIGATION (UNCHANGED)
===================================================== */
let step = 0;
const steps = [...document.querySelectorAll(".step")];
const bars  = [...document.querySelectorAll(".progress-step span")];
const form  = document.getElementById("applicationForm");

function getErrorMessage(err, fallback = "Something went wrong. Please try again.") {
  if (err == null) return fallback;
  if (typeof err === "string") {
    const s = err.trim();
    return s !== "" ? s : fallback;
  }
  const m = err.message;
  if (typeof m === "string") {
    const t = m.trim();
    if (t !== "") return t;
  }
  return fallback;
}

function showApplicationSaveError(message, options = {}) {
  const msg = typeof message === "string" && message.trim() !== "" ? message.trim() : getErrorMessage(message);
  const modalEl = document.getElementById("applicationSaveErrorModal");
  if (!modalEl || typeof bootstrap === "undefined" || !bootstrap.Modal) {
    window.alert(msg);
    return;
  }
  const titleEl = modalEl.querySelector(".modal-title");
  const bodyEl = modalEl.querySelector(".application-save-error-body");
  const defaultTitle = titleEl ? titleEl.textContent.trim() : "";
  if (titleEl && options.title) titleEl.textContent = options.title;
  if (bodyEl) bodyEl.textContent = msg;
  const inst = bootstrap.Modal.getOrCreateInstance(modalEl);
  modalEl.addEventListener(
    "hidden.bs.modal",
    function resetTitle() {
      if (titleEl && defaultTitle) titleEl.textContent = defaultTitle;
      modalEl.removeEventListener("hidden.bs.modal", resetTitle);
    },
    { once: true }
  );
  inst.show();
}

function syncApplicationIdToForm(id) {
  if (id == null || id === "") return;
  const n = Number(id);
  if (!Number.isFinite(n) || n <= 0) return;
  currentApplicationId = n;
  window.currentApplicationId = n;
  const hid = document.querySelector('input[name="application_id"]');
  if (hid) hid.value = String(n);
}

function showStep(index) {
  steps.forEach(s => s.classList.remove("active"));
  bars.forEach(b => b.classList.remove("active"));

  steps[index]?.classList.add("active");
  bars[index]?.classList.add("active");

  document.getElementById("prevBtn").disabled = index === 0;
}

document.getElementById("nextBtn").addEventListener("click", async () => {

  if (isNavigating) return;
  isNavigating = true;

  try {

    /* =====================================================
       STEP 0 — STUDY CHOICES + COUNTRY ROUTING
    ===================================================== */
    if (step === 0) {

  if (!validateStudyChoices()) return;

  let redirectToKorea = false;

  try {
    redirectToKorea = await shouldRedirectToKorea();
  } catch (err) {
    console.error("Korea routing check failed:", err);
  }

  if (redirectToKorea === true) {
    window.location.replace("korea.php");
    return;
  }
}


    /* =====================================================
       STEPS 1 → 5 — REQUIRED FIELD VALIDATION
    ===================================================== */
    if (!validateSteps2to6()) return;

    /* =====================================================
       FINAL STEP — UPLOADS + AGENT
    ===================================================== */
    if (step === steps.length - 1) {

      if (!validateRequiredUploads()) return;
      if (!(await validateRequiredAgent())) return;

      await submitForm();
      return;
    }

    /* =====================================================
       NORMAL STEP SAVE + MOVE FORWARD
    ===================================================== */
    await saveStep();     // ⛔ stop if save fails
    step++;
    showStep(step);

  } catch (err) {
    console.error("Next step failed:", err);
    showApplicationSaveError(getErrorMessage(err, "Failed to save your data. Please try again."));
  } finally {
    isNavigating = false;
  }
});


document.getElementById("prevBtn").addEventListener("click", () => {
  if (step > 0) {
    step--;
    showStep(step);
  }
});
/* =====================================================
   KOREA ROUTING CHECK (FIXED — STEP 0 SAFE)
===================================================== */
async function shouldRedirectToKorea() {

  // 1️⃣ ASIA must be selected
  const asiaSelected = $('#regions')
    .select2('data')
    .some(r => r.text.toUpperCase() === 'ASIA');

  if (!asiaSelected) return false;

  // 2️⃣ Read universities DIRECTLY from DOM
  const universitySelects = document.querySelectorAll('.university');
  if (!universitySelects.length) return false;

  // 3️⃣ Check each university country
  for (const uni of universitySelects) {
    const universityId = Number(uni.value);
    if (!universityId) continue;

    try {
      const res = await fetch(
        `${API}?action=university_country&university_id=${universityId}`
      );

      const data = await res.json();

      if (Number(data?.country_id) === 61) {
        return true; // 🇰🇷 SOUTH KOREA FOUND
      }
    } catch (err) {
      console.error("Korea routing failed:", err);
    }
  }

  return false;
}

/* =====================================================
   ELEMENT REFERENCES (SAFE)
===================================================== */
const regionsSelect     = document.getElementById("regions");
const studyChoicesWrap  = document.getElementById("studyChoices");
const studyEmpty        = document.getElementById("studyEmpty");
const studyEmptyText    = document.getElementById("studyEmptyText");
const studyTemplate     = document.getElementById("studyChoiceTemplate");
const studyLevelRowTemplate = document.getElementById("studyLevelRowTemplate");
const addUniversitySelect = document.getElementById("addUniversitySelect");
const studyAddUniversityPanel = document.getElementById("studyAddUniversityPanel");
const btnAddUniversity = document.getElementById("btnAddUniversity");

/* OLD ELEMENTS (KEEP – DO NOT REMOVE) */
const regionSelect       = document.getElementById("region");
const universitySelect   = document.getElementById("universities");
const programLevelSelect = document.getElementById("programLevel");
const programsSelect     = document.getElementById("programs");
const countrySelects     = document.querySelectorAll(".country-select");

/* =====================================================
   COUNTRY PLACEHOLDER FIX (JS ONLY)
===================================================== */
function initCountryPlaceholders() {
  document.querySelectorAll(".country-select").forEach(select => {

    const placeholder =
      select.getAttribute("data-placeholder") || "Select country";

    // Destroy Select2 safely if already initialized
    if ($(select).hasClass("select2-hidden-accessible")) {
      $(select).select2("destroy");
    }

    // Remove ALL existing options (kills "Select Region First")
    select.innerHTML = "";

    // Real placeholder anchor
    select.add(new Option("", "", false, false));

    // Keep disabled until countries load
    select.disabled = true;

    // Init Select2 with correct placeholder (search always on for long country lists)
    $(select).select2({
      theme: "bootstrap-5",
      width: "100%",
      placeholder: placeholder,
      allowClear: true,
      minimumResultsForSearch: 0,
      dropdownParent: $(document.body)
    });
  });
}
/* =====================================================
   LOAD COUNTRIES (SAFE)
===================================================== */
async function loadCountries() {
  
  try {
    const res = await fetch(`${API}?action=countries`);
    const countries = await res.json();

    if (!Array.isArray(countries)) {
      throw new Error("Invalid countries response");
    }

    document.querySelectorAll(".country-select").forEach(select => {

      countries.forEach(c => {
        select.add(
          new Option(c.name || c.text, c.id || c.code)
        );
      });

      // Enable AFTER data exists
      select.disabled = false;

      // Refresh Select2
      $(select).trigger("change.select2");
    });

  } catch (err) {
    console.error("Failed to load countries:", err);
  }
}


/* =====================================================
   SELECT2: SEARCHABLE DROPDOWNS (FORM + ADD UNIVERSITY)
===================================================== */
function initApplicationFormSmartSelects() {
  const form = document.getElementById("applicationForm");
  if (!form) return;

  $(form).find("select.form-select").each(function () {
    const $el = $(this);
    const id = $el.attr("id");
    if (id === "regions" || id === "addUniversitySelect" || id === "searchLevel") return;
    if ($el.hasClass("country-select")) return;
    if ($el.closest("#studyChoices").length) return;
    if ($el.closest("template").length) return;
    if ($el.hasClass("select2-hidden-accessible")) return;

    const ph = ($el.find('option[value=""]').first().text() || "").trim();
    const required = $el.prop("required");
    const multiple = $el.prop("multiple");

    $el.select2({
      theme: "bootstrap-5",
      width: "100%",
      placeholder: ph || "Select",
      allowClear: !required && !multiple,
      minimumResultsForSearch: 0,
      dropdownParent: $(document.body)
    });
  });
}

function initAddUniversitySelect2() {
  const $el = $("#addUniversitySelect");
  if (!$el.length) return;
  if ($el.hasClass("select2-hidden-accessible")) {
    $el.select2("destroy");
  }
  const ph = $el.attr("data-placeholder") || "Choose a university…";
  $el.select2({
    theme: "bootstrap-5",
    width: "100%",
    placeholder: ph,
    allowClear: true,
    minimumResultsForSearch: 0,
    dropdownParent: $(document.body)
  });
}

/* =====================================================
   SELECT2 INITIALIZATION (GLOBAL SAFE)
===================================================== */
$(function () {

 /* =====================================================
   STUDY REGIONS – SMART MULTI SELECT (INDEPENDENT CLOSE)
===================================================== */
if (regionsSelect) {

  const $regions = $('#regions');

  /* ---------- Select2 init ---------- */
$regions.select2({
  theme: 'bootstrap-5',
  width: '100%',
  placeholder: 'Select one or more regions',
  closeOnSelect: false,
  allowClear: false,
  /* No search field in dropdown or inline (avoids text cursor; UI uses custom picker) */
  minimumResultsForSearch: Infinity,
  dropdownParent: $(document.body),

  templateSelection: function (data) {
    if (!data.id) return data.text;

    return $(`
      <span class="region-chip" data-id="${data.id}">
        <span class="region-text">${data.text}</span>
        <span class="region-close" title="Remove region">×</span>
      </span>
    `);
  },

  escapeMarkup: function (m) {
    return m;
  }
});

  /* ---------- Independent region close ---------- */
  $(document)
    .off('click.regionClose')
    .on('click.regionClose', '.region-close', function (e) {
      e.stopPropagation();

      const regionId = String(
        $(this).closest('.region-chip').data('id')
      );

      // Remove only the clicked region
      const remaining = ($regions.val() || []).filter(
        id => id !== regionId
      );

      $regions.val(remaining).trigger('change');

      // Cleanup related study blocks
      removeRegionBlocks(regionId);
    });
}


  if (regionSelect) {
    $('#region').select2({
      theme: 'bootstrap-5',
      width: '100%',
      minimumResultsForSearch: 0,
      dropdownParent: $(document.body)
    });
  }
  if (universitySelect) {
    $('#universities').select2({
      theme: 'bootstrap-5',
      width: '100%',
      minimumResultsForSearch: 0,
      dropdownParent: $(document.body)
    });
  }
  if (programLevelSelect) {
    $('#programLevel').select2({
      theme: 'bootstrap-5',
      width: '100%',
      minimumResultsForSearch: 0,
      dropdownParent: $(document.body)
    });
  }
  if (programsSelect) {
    $('#programs').select2({
      theme: 'bootstrap-5',
      width: '100%',
      minimumResultsForSearch: 0,
      dropdownParent: $(document.body)
    });
  }
/* ✅ COUNTRY SELECTS – CORRECT PLACE */
if (countrySelects.length) {
  initCountryPlaceholders(); // placeholders FIRST
  loadCountries();           // data SECOND
}

  /* Optional study filter: expanded on tablet+, compact on phone */
  (function syncStudyFilterDetails() {
    const el = document.querySelector(".study-filter-details");
    if (!el || typeof window.matchMedia !== "function") return;
    const mq = window.matchMedia("(min-width: 768px)");
    const apply = () => {
      el.open = mq.matches;
    };
    if (mq.addEventListener) mq.addEventListener("change", apply);
    else if (mq.addListener) mq.addListener(apply);
    apply();
  })();

  /* All static form dropdowns (gender, yes/no, finance, referral, etc.) */
  initApplicationFormSmartSelects();
});

/* =====================================================
   INITIAL LOAD – REGIONS ONLY
===================================================== */
(async function init() {
  try {
    const res = await fetch(`${API}?action=load_meta`);
    const data = await res.json();

    if (!Array.isArray(data.regions)) {
      throw new Error("Invalid meta response");
    }

    // New Step 1
    if (regionsSelect) {
      resetSelect(regionsSelect, "Select Regions", false);
      data.regions.forEach(r =>
        regionsSelect.add(new Option(r.name, r.id))
      );
      $('#regions').trigger("change.select2");
    }

    updateStudyPanelVisibility();
    await refreshAddUniversitySelect();
    updateStudyEmptyMessage();

    // Old Step 1 (kept for compatibility)
    if (regionSelect) {
      resetSelect(regionSelect, "Select Region", false);
      data.regions.forEach(r =>
        regionSelect.add(new Option(r.name, r.id))
      );
      $('#region').trigger("change.select2");
    }

    showStep(0);

  } catch (err) {
    alert("Failed to load application data. Please refresh.");
    console.error(err);
  }
})();

/* =====================================================
   STEP 1 – REGION → ADD UNIVERSITIES → LEVEL ROWS
===================================================== */
function getExistingUniversityIds() {
  const ids = new Set();
  document.querySelectorAll("#studyChoices .study-choice .university").forEach(sel => {
    const id = Number(sel.value);
    if (id) ids.add(id);
  });
  return ids;
}

function destroySelect2IfAny($el) {
  if ($el && $el.length && $el.hasClass("select2-hidden-accessible")) {
    $el.select2("destroy");
  }
}

function teardownStudyCard(block) {
  $(block).find(".program").each(function () {
    destroySelect2IfAny($(this));
  });
  $(block).find(".level").each(function () {
    destroySelect2IfAny($(this));
  });
  destroySelect2IfAny($(block).find(".university"));
}

function updateStudyPanelVisibility() {
  const regionIds = $("#regions").val() || [];
  if (studyAddUniversityPanel) {
    studyAddUniversityPanel.style.display = regionIds.length ? "" : "none";
  }
}

function updateStudyEmptyMessage() {
  if (!studyEmpty || !studyEmptyText) return;

  const regionIds = $("#regions").val() || [];
  const hasCards = studyChoicesWrap && studyChoicesWrap.children.length > 0;
  const noRegion = studyEmpty.dataset.msgNoRegion || "";
  const addUni = studyEmpty.dataset.msgAddUni || "";

  if (!regionIds.length) {
    studyEmpty.style.display = "block";
    studyEmptyText.textContent = noRegion;
  } else if (!hasCards) {
    studyEmpty.style.display = "block";
    studyEmptyText.textContent = addUni;
  } else {
    studyEmpty.style.display = "none";
  }
}

async function refreshAddUniversitySelect() {
  if (!addUniversitySelect) return;

  if ($(addUniversitySelect).hasClass("select2-hidden-accessible")) {
    $(addUniversitySelect).select2("destroy");
  }

  const ph =
    addUniversitySelect.getAttribute("data-placeholder") ||
    "Choose a university…";

  addUniversitySelect.innerHTML = "";
  addUniversitySelect.add(new Option(ph, ""));

  const regionIds = $("#regions").val() || [];
  if (!regionIds.length) {
    initAddUniversitySelect2();
    return;
  }

  const existing = getExistingUniversityIds();
  const seenUni = new Set();

  for (const rid of regionIds) {
    let universities = [];
    try {
      const res = await fetch(`${API}?action=universities&region_id=${rid}`);
      universities = await res.json();
    } catch (e) {
      console.error(e);
      continue;
    }
    if (!Array.isArray(universities)) continue;

    universities.forEach(u => {
      const uid = Number(u.id);
      if (!uid || existing.has(uid) || seenUni.has(uid)) return;
      seenUni.add(uid);
      const opt = new Option(u.name, String(uid));
      opt.dataset.regionId = String(rid);
      addUniversitySelect.add(opt);
    });
  }

  initAddUniversitySelect2();
}

function appendLevelProgramRow(levelRowsWrap, university) {
  if (!studyLevelRowTemplate) return;

  const row = studyLevelRowTemplate.content.cloneNode(true).firstElementChild;
  const levelSelect = row.querySelector(".level");
  const programSelect = row.querySelector(".program");
  const btnRemoveRow = row.querySelector(".btn-remove-row");

  levelSelect.innerHTML = "";
  levelSelect.add(new Option("Select level", ""));
  programSelect.innerHTML = "";
  programSelect.multiple = true;
  programSelect.disabled = true;

  fetch(`${API}?action=program_levels&university_id=${university.id}`)
    .then(r => r.json())
    .then(levels => {
      levelSelect.innerHTML = "";
      levelSelect.add(new Option("Select level", ""));
      if (Array.isArray(levels)) {
        levels.forEach(l => {
          const opt = new Option(l.name, l.id);
          opt.dataset.name = l.name;
          levelSelect.add(opt);
        });
      }

      $(levelSelect).select2({
        theme: "bootstrap-5",
        width: "100%",
        placeholder: "Select level",
        allowClear: true,
        minimumResultsForSearch: 0,
        dropdownParent: $(document.body)
      });

      $(levelSelect).off("change.levelRow").on("change.levelRow", async function () {
        const levelId = this.value;

        destroySelect2IfAny($(programSelect));

        programSelect.innerHTML = "";
        programSelect.disabled = true;

        if (!levelId) {
          programSelect.multiple = true;
          if (window.buildStudyCart) window.buildStudyCart();
          return;
        }

        programSelect.add(new Option("Loading programs…", ""));
        programSelect.disabled = true;

        try {
          const res = await fetch(
            `${API}?action=programs&university_id=${university.id}&program_level_id=${levelId}`
          );
          const text = await res.text();
          if (!text) throw new Error("Empty response");

          const programs = JSON.parse(text);
          if (!Array.isArray(programs)) throw new Error("Invalid programs");

          programSelect.innerHTML = "";

          programs.forEach(p => {
            programSelect.add(new Option(p.program_name, p.id));
          });

          programSelect.disabled = false;

          $(programSelect).select2({
            theme: "bootstrap-5",
            width: "100%",
            placeholder: "Select one or more programs",
            closeOnSelect: false,
            allowClear: true,
            minimumResultsForSearch: 0,
            dropdownParent: $(document.body)
          });
        } catch (err) {
          console.error("Failed to load programs", err);
          programSelect.innerHTML = "";
          programSelect.add(new Option("Failed to load programs", ""));
          programSelect.disabled = true;
        }

        if (window.buildStudyCart) window.buildStudyCart();
      });
    })
    .catch(err => console.error("Failed to load program levels", err));

  btnRemoveRow.addEventListener("click", () => {
    destroySelect2IfAny($(levelSelect));
    destroySelect2IfAny($(programSelect));
    row.remove();
    if (window.buildStudyCart) window.buildStudyCart();
    updateStudyEmptyMessage();
  });

  levelRowsWrap.appendChild(row);
}

function createUniversityStudyCard(regionId, university) {
  if (!studyTemplate || !studyLevelRowTemplate) return;

  const block = studyTemplate.content.cloneNode(true).firstElementChild;

  const regionInput = block.querySelector(".region-id");
  const regionBadge = block.querySelector(".region-badge");
  const uniSelect = block.querySelector(".university");
  const levelRowsWrap = block.querySelector(".study-level-rows");
  const btnAddLevel = block.querySelector(".btn-add-level");
  const removeUniBtn = block.querySelector(".btn-remove-uni");

  regionInput.value = regionId;
  regionBadge.textContent =
    $('#regions option[value="' + regionId + '"]').text();

  uniSelect.innerHTML = "";
  uniSelect.add(new Option(university.name, university.id, true, true));
  uniSelect.disabled = true;

  $(uniSelect).select2({
    theme: "bootstrap-5",
    width: "100%",
    minimumResultsForSearch: 0,
    dropdownParent: $(document.body)
  });

  btnAddLevel.addEventListener("click", () => {
    appendLevelProgramRow(levelRowsWrap, university);
    if (window.buildStudyCart) window.buildStudyCart();
  });

  removeUniBtn.addEventListener("click", () => {
    teardownStudyCard(block);
    block.remove();
    refreshAddUniversitySelect();
    updateStudyEmptyMessage();
    if (window.buildStudyCart) window.buildStudyCart();
  });

  studyChoicesWrap.appendChild(block);

  appendLevelProgramRow(levelRowsWrap, university);

  refreshAddUniversitySelect();
  updateStudyEmptyMessage();
}

if (regionsSelect && studyChoicesWrap && studyTemplate) {
  $("#regions").on("change", async function () {
    [...studyChoicesWrap.children].forEach(child => {
      teardownStudyCard(child);
      child.remove();
    });

    updateStudyPanelVisibility();
    await refreshAddUniversitySelect();
    updateStudyEmptyMessage();
    if (window.buildStudyCart) window.buildStudyCart();
  });
}

if (btnAddUniversity && addUniversitySelect) {
  btnAddUniversity.addEventListener("click", () => {
    const $sel = $("#addUniversitySelect");
    const val = $sel.val();
    if (!val) return;

    const $opt = $sel.find("option:selected");
    const optEl = $opt.get(0);
    let regionId = null;
    if (optEl) {
      regionId =
        (optEl.dataset && optEl.dataset.regionId) ||
        optEl.getAttribute("data-region-id");
    }
    const university = {
      id: Number(val),
      name: $opt.text()
    };

    if (!regionId || !university.id) return;

    createUniversityStudyCard(String(regionId), university);
    $sel.val(null).trigger("change");
  });
}

/* =====================================================
   REMOVE ALL BLOCKS FOR A REGION
===================================================== */
function removeRegionBlocks(regionId) {
  [...studyChoicesWrap.children].forEach(block => {
    const input = block.querySelector(".region-id");
    if (input && input.value === regionId) {
      teardownStudyCard(block);
      block.remove();
    }
  });

  refreshAddUniversitySelect();
  updateStudyEmptyMessage();
  if (window.buildStudyCart) window.buildStudyCart();
}

window.refreshAddUniversitySelect = refreshAddUniversitySelect;
window.updateStudyEmptyMessage = updateStudyEmptyMessage;

/* =====================================================
   STEP 1 VALIDATION – AT LEAST ONE PROGRAM SELECTED
===================================================== */
function validateStudyChoices() {
  let hasAtLeastOneProgram = false;

  document.querySelectorAll(".study-choice").forEach(block => {
    block.querySelectorAll(".study-level-row .program").forEach(programEl => {
      const vals = $(programEl).val();
      const ids = Array.isArray(vals) ? vals : vals ? [vals] : [];
      if (ids.length && ids.some(id => id && String(id).trim())) {
        hasAtLeastOneProgram = true;
      }
    });
  });

  if (!hasAtLeastOneProgram) {
    alert("Please select at least one program to continue.");
    return false;
  }

  return true;
}
/* =====================================================
   COLLECT STUDY CHOICES (FOR SAVE)
===================================================== */
function collectStudyChoices() {
  const choices = [];
  const seen = new Set();

  document.querySelectorAll(".study-choice").forEach(block => {
    const regionId = block.querySelector(".region-id")?.value;
    const universityId = block.querySelector(".university")?.value;

    block.querySelectorAll(".study-level-row").forEach(row => {
      const levelId = row.querySelector(".level")?.value;
      const programSelect = row.querySelector(".program");
      if (!programSelect || !levelId) return;

      let programIds = $(programSelect).val();
      if (!Array.isArray(programIds)) {
        programIds = programIds ? [programIds] : [];
      }

      programIds.forEach(programId => {
        if (!regionId || !universityId || !levelId || !programId) return;

        const key = `${regionId}|${universityId}|${levelId}|${programId}`;
        if (seen.has(key)) return;
        seen.add(key);

        choices.push({
          region_id: Number(regionId),
          university_id: Number(universityId),
          program_level_id: Number(levelId),
          program_id: Number(programId)
        });
      });
    });
  });

  return choices;
}

/* =====================================================
   SERVER VALIDATION FEEDBACK (e.g. duplicate email)
===================================================== */
function applyServerFieldErrors(fields) {
  if (!fields || typeof fields !== "object") return;
  form.querySelectorAll(".is-invalid").forEach(el => el.classList.remove("is-invalid"));
  let first = null;
  Object.keys(fields).forEach(name => {
    const el = form.querySelector(`[name="${CSS.escape(name)}"]`);
    if (el) {
      el.classList.add("is-invalid");
      el.title = String(fields[name]);
      if (!first) first = el;
    }
  });
  if (first) first.scrollIntoView({ behavior: "smooth", block: "center" });
}

/* =====================================================
   AUTOSAVE (UNCHANGED)
===================================================== */
async function saveStep() {
  try {
    const choices = collectStudyChoices();

    /* =============================
       CLIENT DEBUG
    ============================== */
    console.group("SAVE STEP DEBUG");
    console.log("Current step:", step);
    console.log("Study choices array:", choices);

   const fd = new FormData(form);
fd.append("step", step);
fd.append("study_choices", JSON.stringify(choices));

/* ✅ ATTACH APPLICATION ID */
if (currentApplicationId) {
  fd.append("application_id", currentApplicationId);
}

    console.log("FormData entries:");
    for (const [k, v] of fd.entries()) {
      console.log(k, v);
    }

    /* =============================
       FETCH
    ============================== */
    const res = await fetch(API, {
      method: "POST",
      body: fd
    });

    console.log("HTTP status:", res.status);

    const rawText = await res.text();
    console.log("Raw server response:", rawText);

    /* =============================
       PARSE RESPONSE
    ============================== */
    let data;
    try {
      data = JSON.parse(rawText);
    } catch (e) {
      console.error("JSON parse error", e);
      throw new Error("Server returned invalid JSON");
    }

    console.log("Parsed response:", data);
    console.groupEnd();

    /* =============================
       VALIDATE RESPONSE
    ============================== */
    if (!res.ok) {
      applyServerFieldErrors(data.fields);
      throw new Error(data.message || data.debug || "Server error");
    }

   if (data.status !== "success") {
      applyServerFieldErrors(data.fields);
  throw new Error(data.message || data.debug || "Save failed");
}

/* ✅ STORE APPLICATION ID */
if (data.application_id) {
  syncApplicationIdToForm(data.application_id);
}

return true;


  } catch (err) {
    console.groupEnd();
    console.error("SAVE STEP FAILED:", err);
    throw err;
  }
}


/* =====================================================
   FINAL SUBMIT (UNCHANGED)
===================================================== */
async function submitForm() {
  try {
   const fd = new FormData(form);
fd.append("final", "1");
fd.append("study_choices", JSON.stringify(collectStudyChoices()));

/* ✅ ATTACH APPLICATION ID */
if (currentApplicationId) {
  fd.append("application_id", currentApplicationId);
}


    const res = await fetch(API, { method: "POST", body: fd });
    const rawText = await res.text();
    let data;
    try {
      data = JSON.parse(rawText);
    } catch {
      showApplicationSaveError("Submission error. Please try again.");
      return;
    }

    if (data.status === "success") {
  alert("Application submitted successfully");

  /* ✅ RESET STATE FOR NEXT PERSON */
  currentApplicationId = null;
  window.currentApplicationId = null;
  const hid = document.querySelector('input[name="application_id"]');
  if (hid) hid.value = "";

  location.reload();
}
 else {
      applyServerFieldErrors(data.fields);
      showApplicationSaveError(getErrorMessage({ message: data.message || data.debug }, "Submission failed"));
    }
  } catch (err) {
    showApplicationSaveError(getErrorMessage(err, "Submission error. Please try again."));
  }
}


/* =====================================================
   REQUIRED UPLOAD VALIDATION (UNCHANGED)
===================================================== */
function validateRequiredUploads() {
  const missing = REQUIRED_UPLOADS.filter(
    field =>
      !window.uploadStatus[field] ||
      window.uploadStatus[field].length === 0
  );

  if (missing.length) {
    alert(
      "Please upload the required documents:\n\n" +
      missing.join(", ")
    );
    return false;
  }

  return true;
}
/* =====================================================
   REQUIRED AGENT VALIDATION (FINAL STEP ONLY)
===================================================== */
async function validateRequiredAgent() {

  const first = document.getElementById("agent_first_name");
  const last  = document.getElementById("agent_last_name");
  const email = document.getElementById("agent_email");
  const referral = document.getElementById("referral_source");

  // Safety: elements must exist
  if (!first || !last || !email) {
    alert("Agent information section is missing.");
    return false;
  }

  /* Online / website: ensure default superadmin is applied before final check */
  if (referral && referral.value === "online") {
    if (
      !first.value.trim() ||
      !last.value.trim() ||
      !email.value.trim()
    ) {
      try {
        const res = await fetch("getDefaultOnlineAgent.php", {
          cache: "no-store"
        });
        const agent = await res.json();
        if (agent && agent.email) {
          first.value = agent.first_name || "";
          last.value = agent.last_name || "";
          email.value = agent.email || "";
        }
      } catch (e) {
        console.error("Default online agent fetch failed:", e);
      }
    }
  }

  if (
    !first.value.trim() ||
    !last.value.trim() ||
    !email.value.trim()
  ) {
    alert(
      "Please select an authorized agent before submitting your application."
    );

    first.scrollIntoView({
      behavior: "smooth",
      block: "center"
    });

    return false;
  }

  return true;
}
/* =====================================================
REQUIRED FIELD VALIDATION – STEPS 1 TO 5 (SMART FINAL)
===================================================== */
function validateSteps2to6() {

if (step < 1 || step > 5) return true;

const currentStep = steps[step];
if (!currentStep) return true;

let isValid = true;
let firstInvalid = null;

const fields = currentStep.querySelectorAll("input, select, textarea");

fields.forEach(field => {

if (field.disabled) return;

const value = field.value?.trim();
const name  = field.name;

/* ===============================
   REQUIRED CHECK (ALL STEPS)
=============================== */
if (field.hasAttribute("required") && !value) {
  invalidate(field);
  isValid = false;
  if (!firstInvalid) firstInvalid = field;
  return;
}

/* ===============================
   STEP 2 STRICT VALIDATION ONLY
=============================== */
if (step === 1 && value) {

  // NAME - Use our comprehensive validation
  if (name === "first_name" || name === "last_name") {
    // Check for meaningless patterns
    const meaninglessPatterns = [
      /^(test|demo|sample|asdf|qwer|123|abc|xyz|null|none|na|n\/a)$/i,
      /^.{1,2}$/, // Too short
      /^[^a-zA-Z]+$/, // No letters
      /^(.)\1+$/, // All same character
      /^[0-9\s\-_\.]+$/, // Only numbers/symbols
    ];
    
    let isMeaningless = false;
    for (const pattern of meaninglessPatterns) {
      if (pattern.test(value)) {
        isMeaningless = true;
        break;
      }
    }
    
    if (isMeaningless || !/^[A-Za-z\u00C0-\u024F\s'\-\.]{2,50}$/.test(value)) {
      invalidate(field);
      isValid = false;
      if (!firstInvalid) firstInvalid = field;
      return;
    }
  }

  // EMAIL
  if (name === "email") {
    const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
    if (!emailRegex.test(value)) {
      invalidate(field);
      isValid = false;
      if (!firstInvalid) firstInvalid = field;
      return;
    }
  }

  // PASSPORT
  if (name === "passport_number") {
    if (!/^[A-Z0-9]{6,20}$/.test(value.toUpperCase())) {
      invalidate(field);
      isValid = false;
      if (!firstInvalid) firstInvalid = field;
      return;
    }
  }

  // NATIONAL ID
  if (name === "student_national_id") {
    if (!/^[A-Za-z0-9-]{5,30}$/.test(value)) {
      invalidate(field);
      isValid = false;
      if (!firstInvalid) firstInvalid = field;
      return;
    }
  }

  // CITY
  if (name === "city_of_birth") {
    if (!/^[A-Za-z\s'-]{2,100}$/.test(value)) {
      invalidate(field);
      isValid = false;
      if (!firstInvalid) firstInvalid = field;
      return;
    }
  }

  // COUNTRY / NATIONALITY
  if (name === "country_of_birth" || name === "nationality") {
    if (value && isNaN(value)) {
      invalidate(field);
      isValid = false;
      if (!firstInvalid) firstInvalid = field;
      return;
    }
  }

  // SECOND NATIONALITY (OPTIONAL ✅)
  if (name === "second_nationality") {
    if (value && isNaN(value)) {
      invalidate(field);
      isValid = false;
      if (!firstInvalid) firstInvalid = field;
      return;
    }
  }

  // DOB
  if (name === "dob") {
    const date = new Date(value);
    if (!value || isNaN(date.getTime()) || date > new Date()) {
      invalidate(field);
      isValid = false;
      if (!firstInvalid) firstInvalid = field;
      return;
    }
  }
}

// VALID FIELD
field.classList.remove("is-invalid");

});

/* ===============================
PHONE VALIDATION (STEP 2 ONLY)
=============================== */
if (step === 1) {
const phoneInput = document.getElementById("intl_phone");

if (phoneInput && window.intlTelInputGlobals) {
  const iti = window.intlTelInputGlobals.getInstance(phoneInput);

  if (!iti || !iti.isValidNumber()) {
    invalidate(phoneInput);
    isValid = false;
    if (!firstInvalid) firstInvalid = phoneInput;
  }
}

}

/* ===============================
FINAL HANDLING
=============================== */
if (!isValid) {
scrollToField(firstInvalid);
return false;
}

return true;
}

/* =====================================================
HELPERS (CLEAN + REUSABLE)
===================================================== */

function invalidate(field) {
field.classList.add("is-invalid");
}

function scrollToField(field) {
if (!field) return;

field.scrollIntoView({
behavior: "smooth",
block: "center"
});

if ($(field).hasClass("select2-hidden-accessible")) {
$(field).select2("open");
} else {
field.focus();
}
}

/* =====================================================
SELECT RESET (UNCHANGED BUT CLEANED)
===================================================== */
function resetSelect(select, placeholder, disabled = true) {
if (!select) return;

select.innerHTML = "";
select.disabled = disabled;
select.add(new Option(placeholder, ""));

if ($(select).hasClass("select2-hidden-accessible")) {
$(select).trigger("change.select2");
}
}

/* =====================================================
AUTO-FILL DESTINATIONS (SAFE + CLEAN)
===================================================== */
(function () {

if (!regionsSelect) return;

const preferred = document.getElementById("preferredDestination");
const loan      = document.getElementById("loanDestination");

$('#regions').on('change', function () {

const names = $(this)
  .select2('data')
  .map(r => r.text)
  .join(", ");

if (preferred) preferred.value = names;
if (loan) loan.value = names;

});

})();
