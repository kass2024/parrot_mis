"use strict";

/**
 * =====================================================
 * LIVE STUDY SEARCH – FINAL PRODUCTION VERSION
 * =====================================================
 * Backend endpoints:
 *  - save_application.php?action=program_levels_all
 *  - save_application.php?action=study_search
 *
 * Responsibilities:
 *  - Search by university OR program name
 *  - Require region
 *  - Prevent duplicates
 *  - Add only NEW universities using existing UI
 * =====================================================
 */

(function () {

  /* =====================================================
     DOM REFERENCES
  ===================================================== */
  const searchInput   = document.getElementById("studySearch");
  const levelSelect   = document.getElementById("searchLevel");
  const resultsWrap   = document.getElementById("searchResults");
  const clearBtn      = document.getElementById("clearSearch");
  const regionsSelect = document.getElementById("regions");
  const cartWrap = document.getElementById("studyCart");
const cartList = cartWrap?.querySelector(".list-group");


  if (!searchInput || !levelSelect || !resultsWrap || !regionsSelect) {
    console.warn("[study-search] Required DOM elements missing");
    return;
  }

  /* =====================================================
     STATE
  ===================================================== */
  let debounceTimer = null;
  let requestSeq = 0;

  /* =====================================================
     LOAD ALL PROGRAM LEVELS (ONCE)
  ===================================================== */
  fetch("save_application.php?action=program_levels_all", {
    headers: { Accept: "application/json" }
  })
    .then(r => r.ok ? r.json() : [])
    .then(levels => {
      levelSelect.innerHTML = `<option value="">All Levels</option>`;
      if (Array.isArray(levels)) {
        levels.forEach(l => {
          levelSelect.add(new Option(l.name, l.id));
        });
      }
    })
    .catch(() => {
      console.warn("[study-search] Failed to load program levels");
    });

  /* =====================================================
   RUN SEARCH (PRODUCTION – NON-DESTRUCTIVE)
   - Filters visibility ONLY
   - Never removes or resets choices
===================================================== */
function runSearch() {

  const regions = $(regionsSelect).val(); // Select2 array
  const query   = searchInput.value.trim().toLowerCase();
  const level   = levelSelect.value;

  /* =====================================================
     BASE STATE
  ===================================================== */

  // No regions selected → show all choices
  if (!regions || !regions.length) {
    resultsWrap.innerHTML =
      `<div class="text-muted small py-2">
        Select a region to search
      </div>`;
    showAllChoices();
    return;
  }

  // No search criteria → show all selected universities
  if (!query && !level) {
    resultsWrap.innerHTML =
      `<div class="text-muted small py-2">
        Type a university or program name, or select a level
      </div>`;
    showAllChoices();
    return;
  }

  /* =====================================================
     BACKEND SEARCH (PROGRAM MATCHES)
  ===================================================== */

  const params = new URLSearchParams();
  params.set("action", "study_search");
  params.set("regions", regions.join(","));
  if (level) params.set("level", level);
  if (query) params.set("q", query);

  const seq = ++requestSeq;

  fetch("save_application.php?" + params.toString(), {
    headers: { Accept: "application/json" }
  })
    .then(r => r.ok ? r.json() : [])
    .then(rows => {
      if (seq !== requestSeq) return;

      const matchedUniversityIds = new Set();

      /* =====================================================
         1️⃣ PROGRAM / LEVEL MATCHES (BACKEND)
      ===================================================== */
      if (Array.isArray(rows)) {
        rows.forEach(r => {
          if (r.university_id) {
            matchedUniversityIds.add(Number(r.university_id));
          }
        });
      }

      /* =====================================================
         2️⃣ UNIVERSITY NAME MATCH (FRONTEND FALLBACK)
         - Works even if university has NO programs
      ===================================================== */
      document
        .querySelectorAll("#studyChoices .study-choice")
        .forEach(card => {

          const uniSelect = card.querySelector(".university");
          if (!uniSelect) return;

          const uniId   = Number(uniSelect.value);
          const uniName =
            uniSelect.selectedOptions?.[0]?.textContent
              ?.toLowerCase() || "";

          if (query && uniName.includes(query)) {
            matchedUniversityIds.add(uniId);
          }
        });

      /* =====================================================
         APPLY VISUAL FILTER (NON-DESTRUCTIVE)
      ===================================================== */

      if (!matchedUniversityIds.size) {
        hideAllChoices();
        resultsWrap.innerHTML =
          `<div class="text-muted small py-2">
            No results found
          </div>`;
        return;
      }

      filterStudyChoices(matchedUniversityIds);

      resultsWrap.innerHTML =
        `<div class="text-muted small py-2">
          Showing ${matchedUniversityIds.size} matching university
        </div>`;
    })
    .catch(() => {
      if (seq !== requestSeq) return;
      resultsWrap.innerHTML =
        `<div class="text-danger small py-2">
          Search failed
        </div>`;
    });
}
function isStudyChoiceComplete(card) {
  const uniSel   = card.querySelector(".university");
  const levelSel = card.querySelector(".level");
  const progSel  = card.querySelector(".program");

  const programs = $(progSel).val(); // Select2 → array

  return (
    uniSel?.value &&
    levelSel?.value &&
    Array.isArray(programs) &&
    programs.length > 0
  );
}

function buildStudyCart() {
  if (!cartWrap || !cartList) return;

  cartList.innerHTML = "";

  const cards = document.querySelectorAll("#studyChoices .study-choice");

  if (!cards.length) {
    cartWrap.style.display = "none";
    return;
  }

  let hasValid = false;

  cards.forEach(card => {
    const uniSel   = card.querySelector(".university");
    const levelSel = card.querySelector(".level");
    const progSel  = card.querySelector(".program");
    const regionEl = card.querySelector(".region-badge");

   if (!isStudyChoiceComplete(card)) return;


    hasValid = true;

    const item = document.createElement("div");
    item.className =
      "list-group-item d-flex justify-content-between align-items-start";

   const universityText =
  uniSel?.selectedOptions?.[0]?.text || "Unknown University";

const levelText =
  levelSel?.selectedOptions?.[0]?.text || "Level not selected";

/* ✅ Program text (Select2 or native select fallback) */
let programText = "Program not selected";

if (progSel) {
  if ($(progSel).data("select2")) {
    const programs = $(progSel).select2("data") || [];
    if (programs.length) {
      programText = programs.map(p => p.text).join(", ");
    }
  } else if (progSel.selectedOptions?.length) {
    programText = [...progSel.selectedOptions]
      .map(o => o.text)
      .join(", ");
  }
}

const regionText = regionEl?.textContent || "";

item.innerHTML = `
  <div class="me-2 flex-grow-1">
    <div class="fw-semibold text-dark">
      ${universityText}
    </div>

    <div class="small text-muted">
      ${levelText}
      <span class="mx-1">—</span>
      ${programText}
    </div>

    ${regionText ? `
      <div class="small text-primary fw-semibold mt-1">
        ${regionText}
      </div>
    ` : ""}
  </div>

  <button
    type="button"
    class="btn btn-sm btn-link text-danger p-0 align-self-start">
    Remove
  </button>
`;

    // Remove handler (REAL removal)
    item.querySelector("button").addEventListener("click", () => {
      card.remove();
      buildStudyCart();
    });

    cartList.appendChild(item);
  });

  cartWrap.style.display = hasValid ? "" : "none";
}

  /* =====================================================
     HELPERS
  ===================================================== */
 function filterStudyChoices(allowedIds) {
  document
    .querySelectorAll("#studyChoices .study-choice")
    .forEach(card => {
      const sel = card.querySelector(".university");
      const id  = Number(sel?.value);
      card.style.display = allowedIds.has(id) ? "" : "none";
    });
}


function hideAllChoices() {
  document
    .querySelectorAll("#studyChoices .study-choice")
    .forEach(card => {
      card.style.display = "none";
    });
}

function showAllChoices() {
  const selectedRegions = $(regionsSelect).val() || [];

  document
    .querySelectorAll("#studyChoices .study-choice")
    .forEach(card => {

      const regionInput = card.querySelector(".region-id");
      const regionId    = Number(regionInput?.value);

      // If no region filter → show all
      if (!selectedRegions.length) {
        card.style.display = "";
        return;
      }

      // Show only cards belonging to selected regions
      card.style.display = selectedRegions.includes(String(regionId))
        ? ""
        : "none";
    });
}


function highlightExistingUniversity(universityId) {
  const cards = document.querySelectorAll("#studyChoices .study-choice");

  cards.forEach(card => {
    const sel = card.querySelector(".university");
    if (Number(sel?.value) === universityId) {

      card.scrollIntoView({
        behavior: "smooth",
        block: "center"
      });

      card.classList.add("border-primary");

      setTimeout(() => {
        card.classList.remove("border-primary");
      }, 2000);
    }
  });
}
  function getExistingUniversityIds() {
    const ids = new Set();

    document
      .querySelectorAll("#studyChoices .study-choice .university")
      .forEach(sel => {
        const id = Number(sel.value);
        if (id) ids.add(id);
      });

    return ids;
  }

  function extractUniqueUniversities(rows, existingIds) {
    const seen = new Set();
    const output = [];

    rows.forEach(r => {
      if (existingIds.has(r.university_id)) return;
      if (seen.has(r.university_id)) return;

      seen.add(r.university_id);
      output.push({
        region_id: r.region_id,
        region_name: r.region_name,
        university_id: r.university_id,
        university_name: r.university_name
      });
    });

    return output;
  }

  /* =====================================================
     EVENTS
  ===================================================== */
/* =====================================================
   SELECT2 CART SYNC (FINAL – CORRECT)
===================================================== */
$(document).on(
  "select2:select select2:unselect select2:clear",
  ".program",
  function () {
    buildStudyCart();
  }
);
function bindProgramSelect2CartSync() {
  $(".program").each(function () {
    const $el = $(this);

    if ($el.data("cart-bound")) return;
    $el.data("cart-bound", true);

    $el.on("select2:select select2:unselect select2:clear", () => {
      buildStudyCart();
    });
  });
}

/* =====================================================
   CART AUTO-SYNC (CRITICAL)
===================================================== */

// When user selects level or program

document.addEventListener("change", e => {
  if (e.target.classList.contains("level")) {
    setTimeout(() => {
      bindProgramSelect2CartSync();
      buildStudyCart();
    }, 0);
  }
});


// When a study-choice is removed (red Remove button)
document.addEventListener("click", e => {
  if (e.target.classList.contains("btn-remove")) {
    // allow DOM removal first
    setTimeout(buildStudyCart, 0);
  }
});

  searchInput.addEventListener("input", () => {
    clearTimeout(debounceTimer);
    debounceTimer = setTimeout(runSearch, 300);
  });

  levelSelect.addEventListener("change", runSearch);
  $(regionsSelect).on("change", runSearch);
if (clearBtn) {
  clearBtn.addEventListener("click", () => {
    searchInput.value = "";
    levelSelect.value = "";
    resultsWrap.innerHTML = "";
    showAllChoices();
    buildStudyCart(); // ✅ keep cart in sync
  });
}

// =====================================================
// INITIAL CART + SELECT2 SYNC (CRITICAL)
// =====================================================
$(document).ready(function () {
  bindProgramSelect2CartSync();
  buildStudyCart();
});


})();

