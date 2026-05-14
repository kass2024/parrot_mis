/**
 * =====================================================
 * API URL (works inside admin-dashboard iframe + subfolders)
 * =====================================================
 */
function projectApiPath(relativePath) {
    const rel = String(relativePath || "").replace(/^\//, "");
    const base =
        typeof window.APP_ROOT === "string" && window.APP_ROOT.length
            ? String(window.APP_ROOT).replace(/\/$/, "")
            : "";
    return base ? `${base}/${rel}` : rel;
}

/**
 * =====================================================
 * GLOBAL ELEMENTS
 * =====================================================
 */
const studentListEl = document.getElementById("studentList");
const detailsEl = document.getElementById("applicationDetails");
const emptyStateEl = document.getElementById("emptyState");
const aiPanelEl = document.getElementById("aiDecisionPanel");


const searchInput = document.getElementById("searchInput");
const filterAssignedStaff = document.getElementById("filterAssignedStaff");
const filterAppStatus = document.getElementById("filterAppStatus");
const filterClearBtn = document.getElementById("filterClearBtn");
const filterRegion = document.getElementById("filterRegion");
const filterUniversity = document.getElementById("filterUniversity");
const filterLevel = document.getElementById("filterLevel");

/** Currently selected application numeric id (student_applications.id) for inline edits */
let currentViewApplicationId = null;
let studyChoiceRegionsLoaded = false;
let studyChoiceAddFormWired = false;

/**
 * =====================================================
 * INITIAL LOAD
 * =====================================================
 */
document.addEventListener("DOMContentLoaded", () => {
    startTimeAgoTicker();
    loadFilterOptions().finally(() => loadStudents());
    wireStudyChoiceAddFormOnce();
    document.getElementById("btnSaveAssignment")?.addEventListener("click", saveApplicationAssignment);
});

/**
 * =====================================================
 * EVENT LISTENERS
 * =====================================================
 */
searchInput?.addEventListener("input", debounce(loadStudents, 300));
filterRegion?.addEventListener("change", loadStudents);
filterUniversity?.addEventListener("change", loadStudents);
filterLevel?.addEventListener("change", loadStudents);
filterAssignedStaff?.addEventListener("change", () => loadStudents());
filterAppStatus?.addEventListener("change", () => loadStudents());
filterClearBtn?.addEventListener("click", () => {
    if (filterAssignedStaff) filterAssignedStaff.value = "";
    if (filterAppStatus) filterAppStatus.value = "";
    loadStudents();
});

/**
 * =====================================================
 * FILTER OPTIONS (staff + status labels)
 * =====================================================
 */
async function loadFilterOptions() {
    window.pcvcStatusLabels = {};
    try {
        const res = await fetch(projectApiPath("api/applications.php?action=filter_options"), {
            cache: "no-store"
        });
        const json = await res.json();
        if (!json?.success || !json?.data) return;

        const staff = Array.isArray(json.data.staff) ? json.data.staff : [];
        window.pcvcStaffAssignOptions = staff;
        const statuses = Array.isArray(json.data.statuses) ? json.data.statuses : [];

        statuses.forEach((s) => {
            if (s?.value) window.pcvcStatusLabels[s.value] = s.label || s.value;
        });

        if (filterAppStatus) {
            const keep = filterAppStatus.value;
            filterAppStatus.innerHTML = '<option value="">All statuses</option>';
            statuses.forEach((s) => {
                if (!s?.value) return;
                const opt = document.createElement("option");
                opt.value = s.value;
                opt.textContent = s.label || s.value;
                filterAppStatus.appendChild(opt);
            });
            if (keep && [...filterAppStatus.options].some((o) => o.value === keep)) {
                filterAppStatus.value = keep;
            }
        }

        if (filterAssignedStaff) {
            const keepStaff = filterAssignedStaff.value;
            filterAssignedStaff.innerHTML =
                '<option value="">All staff</option><option value="-1">Unassigned</option>';
            staff.forEach((row) => {
                const id = row?.id;
                if (!id) return;
                const opt = document.createElement("option");
                opt.value = String(id);
                opt.textContent = row.label || `Staff #${id}`;
                filterAssignedStaff.appendChild(opt);
            });
            if (keepStaff && [...filterAssignedStaff.options].some((o) => o.value === keepStaff)) {
                filterAssignedStaff.value = keepStaff;
            }
        }
    } catch (e) {
        console.warn("loadFilterOptions:", e);
    }
}

/**
 * =====================================================
 * LOAD STUDENT LIST (SIDEBAR)
 * =====================================================
 */
function loadStudents() {
    const params = new URLSearchParams({
        action: "list",
        q: searchInput?.value?.trim() || "",
        region_id: filterRegion?.value || "",
        university_id: filterUniversity?.value || "",
        program_level_id: filterLevel?.value || ""
    });
    if (filterAssignedStaff?.value) {
        params.set("assigned_to", filterAssignedStaff.value);
    }
    if (filterAppStatus?.value) {
        params.set("application_status", filterAppStatus.value);
    }

    studentListEl.innerHTML =
        `<li class="p-4 text-sm text-gray-400">Loading...</li>`;

    fetch(projectApiPath(`api/applications.php?${params}`))
        .then(r => r.json())
        .then(res => {
            console.log("LIST RESPONSE:", res);

            studentListEl.innerHTML = "";

            if (!res?.success || !Array.isArray(res.data) || !res.data.length) {
                studentListEl.innerHTML =
                    `<li class="p-4 text-sm text-gray-400">No applications found</li>`;
                return;
            }

            res.data.forEach(app => {
                studentListEl.appendChild(renderStudentItem(app));
            });
            refreshTimeAgoLabels();
        })
        .catch(err => {
            console.error("loadStudents error:", err);
            studentListEl.innerHTML =
                `<li class="p-4 text-sm text-red-500">Failed to load applications</li>`;
        });
}

/**
 * =====================================================
 * RENDER STUDENT ITEM (SIDEBAR)
 * =====================================================
 */
function renderStudentItem(app) {
    const bio   = app.bio || {};
    const meta  = app.meta || {};
    const study = app.study || {};

    const li = document.createElement("li");
    li.className =
        "p-3 cursor-pointer hover:bg-slate-100 flex justify-between items-start gap-2";

   
 // Build study line safely (AGGREGATED FIELDS)
const studyLine = [
    study.universities,
    study.regions,
    study.countries
].filter(Boolean).join(" • ");


    const timeData = formatFullTime(meta.created_at);
    const timeDisplay = timeData ? `
        <div class="mt-1 text-[11px] font-medium application-time"
             style="display:inline-flex;align-items:center;gap:6px;flex-wrap:wrap;color:${timeData.color}">
            <span>${timeData.icon}</span>
            <span data-time-ago="${escapeAttr(String(meta.created_at || ""))}">${timeAgo(meta.created_at)}</span>
            <span class="text-gray-400">•</span>
            <span>${timeData.date}</span>
            <span class="text-gray-400">•</span>
            <span>${timeData.time}</span>
        </div>
    ` : "";

    const assignedDisplay = escapeHTML(String(meta.assigned_display || "Parrot Canada"));

    const assignedLine = `
        <div class="mt-1 text-[11px] leading-snug text-slate-600">
            <span class="text-slate-500 font-medium">Assigned:</span>
            <span>${assignedDisplay}</span>
        </div>
    `;

    const effKey = meta.effective_status || "";
    const effLabel =
        effKey && window.pcvcStatusLabels && window.pcvcStatusLabels[effKey]
            ? window.pcvcStatusLabels[effKey]
            : effKey;
    const statusLine = effLabel
        ? `<div class="mt-0.5 text-[11px] text-slate-700"><span class="text-slate-500 font-medium">Status:</span> ${escapeHTML(String(effLabel))}</div>`
        : "";

    li.innerHTML = `
        <div class="min-w-0">
            <div class="font-semibold text-sm whitespace-normal break-words">
                ${escapeHTML(bio.first_name || "")} ${escapeHTML(bio.last_name || "")}
            </div>

            <div class="text-xs text-gray-500 whitespace-normal break-words">
                ${escapeHTML(bio.email || "")}
            </div>

           ${
    studyLine
        ? `<div class="text-xs text-slate-600 mt-0.5 whitespace-normal break-words">
            ${escapeHTML(studyLine)}
          </div>`
        : ""
}


            ${timeDisplay}
            ${assignedLine}
            ${statusLine}
        </div>

        ${Number(meta.is_read) === 0 ? unreadDot() : ""}
    `;

    li.addEventListener("click", () => {
    showAiDecision(app.id);          // 👈 AI FIRST
    loadApplication(app.id, li);     // 👈 DETAILS SECOND
});

    return li;
}
/**
 * =====================================================
 * AI FETCH UTILITIES (REQUIRED)
 * =====================================================
 */
let aiAbortController = null;

async function safeFetchJSON(url, options = {}) {
    const res = await fetch(url, options);
    const text = await res.text();

    let data;
    try {
        data = JSON.parse(text);
    } catch (e) {
        console.error("❌ Invalid JSON from:", url);
        console.error("RAW RESPONSE:", text);
        return null; // 👈 DO NOT THROW
    }

    // ⛔ DO NOT THROW FOR AI SERVICE
    if (!res.ok) {
        console.warn("⚠️ Request failed:", res.status, url);
        return null; // 👈 THIS IS THE KEY CHANGE
    }

    if (data?.success === false) {
        console.warn("⚠️ API error:", data);
        return null;
    }

    return data;
}
/**
 * =====================================================
 * LOAD AI DECISION (FAST – BEFORE FULL VIEW)
 * =====================================================
 */
async function showAiDecision(appId) {
    if (!appId) return;

    /* =========================================
       CANCEL PREVIOUS REQUEST (RACE SAFE)
    ========================================= */
    if (aiAbortController) {
        aiAbortController.abort();
    }
    aiAbortController = new AbortController();

    /* =========================================
       UI: INITIAL STATE
    ========================================= */
    emptyStateEl?.classList.add("hidden");
    aiPanelEl?.classList.remove("hidden");

    const platformsEl  = document.getElementById("aiPlatforms");
    const confidenceEl = document.getElementById("aiConfidence");

    if (platformsEl) {
        platformsEl.innerHTML = `
            <div class="col-span-full text-sm text-gray-500">
                Analyzing suitable platforms…
            </div>
        `;
    }

    if (confidenceEl) {
        confidenceEl.textContent = "—";
    }

    try {
        /* =========================================
           FETCH (SAFE, NON-THROWING)
        ========================================= */
        const res = await safeFetchJSON(
            projectApiPath(
                `api/ai-decision.php?application_id=${encodeURIComponent(appId)}`
            ),
            { signal: aiAbortController.signal }
        );

        // Hard failure only (network / invalid response)
        if (!res || !res.data) {
            throw new Error("AI service unavailable");
        }

        console.log("AI RESPONSE:", res);

        const platforms  = Array.isArray(res.data.platforms)
            ? res.data.platforms
            : [];

        const confidence = Number.isFinite(Number(res.data.confidence))
            ? Math.round(Number(res.data.confidence))
            : 0;

        /* =========================================
           EMPTY / NO MATCH RESULT
        ========================================= */
        if (platforms.length === 0) {
            if (platformsEl) {
                platformsEl.innerHTML = `
                    <div class="col-span-full text-sm text-gray-500">
                        No suitable platforms could be identified for this application.
                    </div>
                `;
            }
            if (confidenceEl) {
                confidenceEl.textContent = "Confidence 0%";
            }
            return;
        }

        /* =========================================
           RENDER ALL PLATFORMS (SAFE)
        ========================================= */
        if (typeof renderAIDecision === "function") {
            renderAIDecision({
                platforms,
                confidence
            });
        } else {
            console.error("renderAIDecision() is not defined");

            // Minimal fallback if renderer missing
            if (platformsEl) {
                platformsEl.innerHTML = `
                    <div class="col-span-full text-sm text-gray-500">
                        Platform recommendations loaded, but renderer is unavailable.
                    </div>
                `;
            }
            if (confidenceEl) {
                confidenceEl.textContent = `Confidence ${confidence}%`;
            }
        }

    } catch (err) {
        /* =========================================
           ABORT IS NOT AN ERROR
        ========================================= */
        if (err?.name === "AbortError") {
            console.debug("AI request aborted");
            return;
        }

        console.warn("AI decision unavailable:", err.message);

        /* =========================================
           HARD FALLBACK UI (USER-FRIENDLY)
        ========================================= */
        if (platformsEl) {
            platformsEl.innerHTML = `
                <div class="col-span-full text-sm text-red-500">
                    AI recommendations are currently unavailable.
                    Please try again later.
                </div>
            `;
        }

        if (confidenceEl) {
            confidenceEl.textContent = "—";
        }
    }
}
/**
 * =====================================================
 * RENDER AI DECISION (ALL PLATFORMS)
 * =====================================================
 */
function renderAIDecision({ platforms, confidence }) {
    const panel = document.getElementById("aiDecisionPanel");
    const list  = document.getElementById("aiPlatforms");
    const confidenceEl = document.getElementById("aiConfidence");

    if (!panel || !list || !confidenceEl) {
        console.error("AI panel elements missing");
        return;
    }

    panel.classList.remove("hidden");
    list.innerHTML = "";
    confidenceEl.textContent = `Confidence ${confidence}%`;

    // Defensive guard
    if (!Array.isArray(platforms) || platforms.length === 0) {
        list.innerHTML = `
            <div class="col-span-full text-sm text-gray-500">
                No platform recommendations available.
            </div>
        `;
        return;
    }

    platforms.forEach((p, index) => {
        // ✅ ADMIN NAME COMES FROM admins TABLE (JOINED)
        const adminName =
            p.person_in_charge &&
            typeof p.person_in_charge.full_name === "string" &&
            p.person_in_charge.full_name.trim() !== ""
                ? p.person_in_charge.full_name
                : "—";

        const card = document.createElement("div");
        card.className = "ai-platform-card";

        card.innerHTML = `
            <span class="ai-platform-badge">
                Recommendation ${index + 1}
            </span>

            <div class="ai-platform-title">
                ${escapeHTML(p.platform_name || "Unknown Platform")}
            </div>

           <div class="ai-platform-admin">
    Person in charge: ${escapeHTML(adminName)}
</div>


            <div class="ai-platform-reason">
                ${escapeHTML(p.reason || "")}
            </div>
        `;

        list.appendChild(card);
    });
}

/**
 * =====================================================
 * LOAD FULL APPLICATION
 * =====================================================
 */
function loadApplication(id, listItem) {
    if (!id) return;

    fetch(projectApiPath(`api/applications.php?action=view&id=${id}`))
        .then(r => r.json())
        .then(res => {
            console.log("VIEW RESPONSE:", res);

            if (!res?.success || !res.data) {
                alert("Failed to load application details");
                currentViewApplicationId = null;
                document.getElementById("studyChoiceAddPanel")?.classList.add("hidden");
                return;
            }

            emptyStateEl.classList.add("hidden");
            detailsEl.classList.remove("hidden");

            renderApplication(res.data, id);
loadJourney(id); // 👈 USE STUDENT APPLICATION ID


/* =====================================================
   🔔 JOB CREATION TOAST (THIS WAS MISSING)
===================================================== */
const jobsCreated = Number(res.data?.meta?.jobs_created || 0);

console.log("JOBS CREATED:", jobsCreated); // 👈 DEBUG (keep for now)

if (jobsCreated > 0) {
    showToast(
        `${jobsCreated} job${jobsCreated > 1 ? "s" : ""} created`,
        () => {
            // optional: redirect to jobs page
            window.location.href = "admin-jobs.php";
        },
        "Jobs created"
    );
}

const dot = listItem?.querySelector(".unread-dot");
if (dot) dot.remove();

        })
        .catch(err => console.error("loadApplication error:", err));
}

/**
 * Superadmin delete: prefer API view meta (authoritative), fallback to page bootstrap flag.
 */
function resolveCanDeleteApplication(data) {
    const meta = data?.meta || {};
    if (meta.can_delete_application === true) {
        return true;
    }
    if (
        typeof window.CAN_DELETE_APPLICATION !== "undefined" &&
        window.CAN_DELETE_APPLICATION === true
    ) {
        return true;
    }
    return false;
}

/** Superadmin + DB column — authoritative from view API meta */
function resolveCanEditAssignment(data) {
    return data?.meta?.can_edit_assignment === true;
}

/** Who owns the file — shown to every role; editing is superadmin-only */
function renderAssignedReadOnly(meta) {
    const el = document.getElementById("applicationAssignedDisplay");
    if (!el) {
        return;
    }
    const def =
        typeof window.PCVC_DEFAULT_ASSIGNED_LABEL === "string" &&
        window.PCVC_DEFAULT_ASSIGNED_LABEL.length
            ? window.PCVC_DEFAULT_ASSIGNED_LABEL
            : "Parrot Canada";
    const raw = meta?.assigned_display;
    const name =
        raw != null && String(raw).trim() !== ""
            ? String(raw).trim()
            : def;
    el.textContent = `Assigned: ${name}`;
}

function fillAssignStaffSelect() {
    const sel = document.getElementById("assignStaffSelect");
    if (!sel) {
        return;
    }
    const defLabel =
        typeof window.PCVC_DEFAULT_ASSIGNED_LABEL === "string" &&
        window.PCVC_DEFAULT_ASSIGNED_LABEL.length
            ? window.PCVC_DEFAULT_ASSIGNED_LABEL
            : "Parrot Canada";
    sel.innerHTML = "";
    const o0 = document.createElement("option");
    o0.value = "0";
    o0.textContent = `Unassigned (${defLabel})`;
    sel.appendChild(o0);
    const staff = Array.isArray(window.pcvcStaffAssignOptions)
        ? window.pcvcStaffAssignOptions
        : [];
    staff.forEach((row) => {
        const id = row?.id;
        if (!id) {
            return;
        }
        const opt = document.createElement("option");
        opt.value = String(id);
        opt.textContent = row.label || `Staff #${id}`;
        sel.appendChild(opt);
    });
}

function renderAssignmentEditor(data) {
    const panel = document.getElementById("assignmentEditorPanel");
    if (!panel) {
        return;
    }
    if (!resolveCanEditAssignment(data)) {
        panel.classList.add("hidden");
        return;
    }
    panel.classList.remove("hidden");
    fillAssignStaffSelect();
    const sel = document.getElementById("assignStaffSelect");
    const meta = data?.meta || {};
    const tid = Number(meta.assigned_to_admin_id || 0);
    if (sel) {
        sel.value = String(tid);
        if (sel.value !== String(tid) && tid > 0) {
            const opt = document.createElement("option");
            opt.value = String(tid);
            opt.textContent = meta.assigned_display || `Staff #${tid}`;
            sel.appendChild(opt);
            sel.value = String(tid);
        }
    }
    const statusEl = document.getElementById("assignmentSaveStatus");
    if (statusEl) {
        statusEl.textContent = "";
    }
}

async function saveApplicationAssignment() {
    const panel = document.getElementById("assignmentEditorPanel");
    if (!panel || panel.classList.contains("hidden")) {
        return;
    }
    const appId = currentViewApplicationId;
    if (!appId) {
        alert("Select an application first.");
        return;
    }
    const sel = document.getElementById("assignStaffSelect");
    const statusEl = document.getElementById("assignmentSaveStatus");
    const btn = document.getElementById("btnSaveAssignment");
    const newVal = String(sel ? parseInt(sel.value, 10) || 0 : 0);

    if (statusEl) {
        statusEl.textContent = "";
    }
    const prevText = btn ? btn.textContent : "";
    if (btn) {
        btn.disabled = true;
        btn.textContent = "Saving…";
    }

    const fd = new FormData();
    fd.append("application_id", String(appId));
    fd.append("assigned_to_admin_id", newVal);

    try {
        const res = await fetch(
            projectApiPath("api/applications.php?action=update_assignment"),
            {
                method: "POST",
                body: fd,
                credentials: "same-origin"
            }
        );
        const raw = await res.text();
        let json;
        try {
            json = JSON.parse(raw);
        } catch (e) {
            console.error("assignment save (non-JSON):", raw);
            if (statusEl) {
                statusEl.textContent = "Invalid server response.";
            }
            return;
        }
        if (!json?.success) {
            if (statusEl) {
                statusEl.textContent = json?.message || "Save failed.";
            }
            return;
        }
        const d = json.data || {};
        const msg = d.notified
            ? "Assignment saved. The new assignee was notified (email / WhatsApp where configured)."
            : "Assignment saved.";
        if (statusEl) {
            statusEl.textContent = msg;
        }
        showToast(msg, null, "Assignment");
        loadStudents();
        loadApplication(appId, null);
    } catch (err) {
        console.error(err);
        if (statusEl) {
            statusEl.textContent = "Network error.";
        }
    } finally {
        if (btn) {
            btn.disabled = false;
            btn.textContent = prevText || "Save assignment";
        }
    }
}

/**
 * =====================================================
 * RENDER FULL APPLICATION
 * =====================================================
 */
function renderApplication(data, applicationNumericId) {
    const {
        bio = {},
        address = {},
        parents = {},
        emergency = {},
        education = {},
        study_choices = [],
        documents = {},
        agent = {},
        meta = {}
    } = data;

    const canDelete = resolveCanDeleteApplication(data);
    window.__lastCanDeleteApplication = canDelete;

    // HEADER
    setText("studentName", `${bio.first_name || ""} ${bio.last_name || ""}`.trim());
    setText("studentEmail", bio.email);
    setText("studentPhone", bio.phone);
    const appMetaEl = document.getElementById("applicationMeta");
    if (appMetaEl) {
        if (meta.created_at) {
            const ts = String(meta.created_at);
            appMetaEl.innerHTML = `Applied <span data-time-ago="${escapeAttr(ts)}" class="tabular-nums">${timeAgo(ts)}</span>`;
        } else {
            appMetaEl.textContent = "-";
        }
    }

    renderAssignedReadOnly(meta);

    // PERSONAL
    setText("pGender", bio.gender);
    setText("pDob", bio.dob);
    setText("pNationality", bio.nationality);
    setText("pBirthCountry", bio.country_of_birth);
    setText("pPassport", bio.passport_number);
    setText("pNationalId", bio.national_id);

    // ADDRESS
    setText("addrLine", `${address.line1 || ""} ${address.line2 || ""}`.trim());
    setText("addrCity", `${address.city || ""} ${address.state || ""}`.trim());
    setText("addrPostal", address.postal_code);

    // FAMILY & EMERGENCY
    setText("pFather", parents.father);
    setText("pMother", parents.mother);
    setText("eName", emergency.name);
    setText("eEmail", emergency.email);
    setText("ePhone", emergency.phone);
    setText("eRelation", emergency.relationship);

    // EDUCATION
    setText("eduInstitution", education.institution);
    setText("eduCountry", education.country);
    setText("eduStart", education.start_date);
    setText("eduGrad", education.graduation);
    setText(
        "eduGap",
        education.study_gap === "Yes"
            ? education.study_gap_details
            : "No"
    );

    renderStudyChoices(study_choices);
    renderDocuments(documents);
    renderAgent(agent);

    renderDeleteControls(applicationNumericId, canDelete);
    renderAssignmentEditor(data);

    currentViewApplicationId = applicationNumericId;
    const scPanel = document.getElementById("studyChoiceAddPanel");
    if (scPanel) {
        scPanel.classList.remove("hidden");
        void prepareStudyChoiceAddForm();
    }
}

/**
 * =====================================================
 * DELETE APPLICATION (superadmin only — enforced server-side)
 * =====================================================
 */
function buildDeleteApplicationButton(applicationNumericId) {
    const btn = document.createElement("button");
    btn.type = "button";
    btn.className =
        "inline-flex items-center justify-center gap-2 px-4 py-2.5 text-sm font-semibold text-white bg-red-600 border border-red-700 rounded-lg shadow-sm hover:bg-red-700 focus:outline-none focus:ring-2 focus:ring-red-400 focus:ring-offset-2 transition whitespace-nowrap w-full sm:w-auto";
    btn.setAttribute("aria-label", "Delete this application permanently");
    btn.title = "Permanently remove this application (Superadmin only)";
    btn.innerHTML = `
        <svg class="w-4 h-4 shrink-0" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" d="m14.74 9-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 0 1-2.244 2.077H8.084a2.25 2.25 0 0 1-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 0 0-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 0 1 3.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.651 51.651 0 0 0-1.819-.004m-7.5 0c-.834 0-1.64.105-2.448.298" />
        </svg>
        <span>Delete application</span>
    `;
    btn.addEventListener("click", () => deleteApplication(applicationNumericId));
    return btn;
}

function renderDeleteControls(applicationNumericId, canDelete) {
    const dyn = document.getElementById("applicationActionsDynamic");
    if (dyn) {
        dyn.innerHTML = "";
    }

    const headerBtn = document.getElementById("btnDeleteApplicationHeader");
    const journeyBtn = document.getElementById("btnDeleteApplicationJourney");

    if (headerBtn) {
        if (!applicationNumericId || !canDelete) {
            headerBtn.style.display = "none";
            headerBtn.disabled = true;
            headerBtn.onclick = null;
        } else {
            headerBtn.style.display = "inline-flex";
            headerBtn.disabled = false;
            headerBtn.onclick = () => deleteApplication(applicationNumericId);
        }
    }

    if (journeyBtn) {
        if (!applicationNumericId || !canDelete) {
            journeyBtn.style.display = "none";
            journeyBtn.disabled = true;
            journeyBtn.onclick = null;
        } else {
            journeyBtn.style.display = "flex";
            journeyBtn.disabled = false;
            journeyBtn.onclick = () => deleteApplication(applicationNumericId);
        }
    }

    const journeyWrap = document.getElementById("journeyDeleteActions");
    if (journeyWrap) {
        if (!canDelete || !applicationNumericId) {
            journeyWrap.classList.add("hidden");
        } else {
            journeyWrap.classList.remove("hidden");
        }
    }

    if (
        canDelete &&
        applicationNumericId &&
        !headerBtn &&
        !journeyBtn &&
        dyn
    ) {
        dyn.appendChild(buildDeleteApplicationButton(applicationNumericId));
    }

    const journeyWrapOnly = document.getElementById("journeyDeleteActions");
    if (
        canDelete &&
        applicationNumericId &&
        !journeyBtn &&
        journeyWrapOnly
    ) {
        journeyWrapOnly.innerHTML = "";
        journeyWrapOnly.appendChild(
            buildDeleteApplicationButton(applicationNumericId)
        );
        journeyWrapOnly.classList.remove("hidden");
    }
}

/**
 * Journey timeline is filled async; re-apply sidebar delete after DOM settles.
 */
function syncJourneyDeleteOnly(applicationNumericId, canDelete) {
    const journeyBtn = document.getElementById("btnDeleteApplicationJourney");
    if (journeyBtn) {
        if (!applicationNumericId || !canDelete) {
            journeyBtn.style.display = "none";
            journeyBtn.disabled = true;
            journeyBtn.onclick = null;
        } else {
            journeyBtn.style.display = "flex";
            journeyBtn.disabled = false;
            journeyBtn.onclick = () => deleteApplication(applicationNumericId);
        }
        const journeyWrap = document.getElementById("journeyDeleteActions");
        if (journeyWrap) {
            if (!canDelete || !applicationNumericId) {
                journeyWrap.classList.add("hidden");
            } else {
                journeyWrap.classList.remove("hidden");
            }
        }
        return;
    }

    const journeyWrap = document.getElementById("journeyDeleteActions");
    if (!journeyWrap) {
        return;
    }
    journeyWrap.innerHTML = "";
    if (!applicationNumericId || !canDelete) {
        journeyWrap.classList.add("hidden");
        return;
    }
    journeyWrap.appendChild(buildDeleteApplicationButton(applicationNumericId));
    journeyWrap.classList.remove("hidden");
}

async function deleteApplication(applicationNumericId) {
    if (!window.__lastCanDeleteApplication) {
        alert("Only Super Admin can delete applications.");
        return;
    }
    if (
        !confirm(
            "Permanently delete this application and related jobs? This cannot be undone."
        )
    ) {
        return;
    }

    const fd = new FormData();
    fd.append("id", String(applicationNumericId));

    try {
        const res = await fetch(
            projectApiPath("api/applications.php?action=delete"),
            {
                method: "POST",
                body: fd,
                credentials: "same-origin"
            }
        );
        const raw = await res.text();
        let json;
        try {
            json = JSON.parse(raw);
        } catch (parseErr) {
            console.error("Delete response (not JSON):", raw);
            alert(
                res.ok
                    ? "Delete failed: invalid server response."
                    : `Delete failed (HTTP ${res.status}).`
            );
            return;
        }

        if (!json?.success) {
            alert(json?.message || "Delete failed.");
            return;
        }

        const dyn = document.getElementById("applicationActionsDynamic");
        if (dyn) {
            dyn.innerHTML = "";
        }
        const headerBtn = document.getElementById("btnDeleteApplicationHeader");
        const journeyBtn = document.getElementById("btnDeleteApplicationJourney");
        if (headerBtn) {
            headerBtn.style.display = "none";
            headerBtn.disabled = true;
            headerBtn.onclick = null;
        }
        if (journeyBtn) {
            journeyBtn.style.display = "none";
            journeyBtn.disabled = true;
            journeyBtn.onclick = null;
        }
        const journeyDel = document.getElementById("journeyDeleteActions");
        if (journeyDel) {
            journeyDel.classList.add("hidden");
        }
        if (detailsEl) {
            detailsEl.classList.add("hidden");
        }
        if (emptyStateEl) {
            emptyStateEl.classList.remove("hidden");
        }
        const journeyPanel = document.getElementById("applicationTracking");
        if (journeyPanel) {
            journeyPanel.classList.add("hidden");
        }
        if (aiPanelEl) {
            aiPanelEl.classList.add("hidden");
        }

        loadStudents();
    } catch (e) {
        console.error("deleteApplication:", e);
        alert("Delete failed. Please try again.");
    }
}
/**
 * =====================================================
 * LOAD APPLICATION JOURNEY (TRACK EVERYTHING)
 * =====================================================
 */
/**
 * =====================================================
 * LOAD APPLICATION JOURNEY
 * =====================================================
 */
function loadJourney(applicationId) {
    if (!applicationId) return;

    const panel = document.getElementById("applicationTracking");
    const timeline = document.getElementById("trackingTimeline");
    const empty = document.getElementById("journeyEmpty");

    if (!panel || !timeline) return;

    const canDelete = !!window.__lastCanDeleteApplication;

    panel.classList.remove("hidden");
    timeline.innerHTML = `
        <div class="text-xs text-slate-400">Loading journey…</div>
    `;
    empty.classList.add("hidden");

    fetch(projectApiPath(`api/applications.php?action=journey&id=${applicationId}`))
        .then(r => r.json())
        .then(res => {
            console.log("JOURNEY RESPONSE:", res);

            if (!res?.success || !Array.isArray(res.data) || res.data.length === 0) {
                timeline.innerHTML = "";
                empty.classList.remove("hidden");
                syncJourneyDeleteOnly(applicationId, canDelete);
                return;
            }

            timeline.innerHTML = "";
            res.data.forEach(job => {
                timeline.appendChild(renderJourneyStep(job));
            });
            refreshTimeAgoLabels();
            syncJourneyDeleteOnly(applicationId, canDelete);
        })
        .catch(err => {
            console.error("Journey load error:", err);
            timeline.innerHTML = "";
            empty.classList.remove("hidden");
            syncJourneyDeleteOnly(applicationId, canDelete);
        });
}
/**
 * =====================================================
 * RENDER JOURNEY STEP
 * =====================================================
 */
function renderJourneyStep(job) {
    const completed = job.status === "completed";

    const el = document.createElement("div");
    el.className = "relative flex gap-4";

    el.innerHTML = `
        <!-- DOT -->
        <div class="relative z-10 pt-1">
            <span class="timeline-dot ${completed ? "completed" : ""}"></span>
        </div>

        <!-- CONTENT -->
        <div class="pb-6">
            <div class="font-semibold text-slate-800">
                ${escapeHTML(job.university_name || "Unknown University")}
            </div>

            <div class="text-slate-500 mt-0.5">
                Platform: ${escapeHTML(job.platform_name || "—")}
            </div>

            <div class="text-slate-500">
                Admin: ${escapeHTML(job.admin_name || "—")}
            </div>

            <div class="text-[11px] mt-1 ${completed ? "text-green-600" : "text-slate-400"}">
                ${completed ? "Completed" : "In progress"} • <span data-time-ago="${escapeAttr(String(job.created_at || ""))}" class="tabular-nums">${timeAgo(job.created_at)}</span>
            </div>
        </div>
    `;

    return el;
}

/**
 * =====================================================
 * STUDY CHOICES
 * =====================================================
 */
function renderStudyChoices(choices) {
    const tbody = document.getElementById("studyChoicesTable");
    tbody.innerHTML = "";

    if (!choices.length) {
        tbody.innerHTML =
            `<tr><td colspan="5" class="p-3 text-center text-gray-400">
                No study choices
            </td></tr>`;
        return;
    }

    choices.forEach(c => {
        tbody.insertAdjacentHTML("beforeend", `
            <tr>
                <td class="p-2 border">${escapeHTML(c.region)}</td>
                <td class="p-2 border">${escapeHTML(c.university)}</td>
                <td class="p-2 border">${escapeHTML(c.university_country || "-")}</td>
                <td class="p-2 border">${escapeHTML(c.program_level_abbr || c.program_level)}</td>
                <td class="p-2 border">${escapeHTML(c.program)}</td>
            </tr>
        `);
    });
}

/**
 * =====================================================
 * DOCUMENTS
 * =====================================================
 */
function renderDocuments(docs) {
    const list = document.getElementById("documentsList");
    list.innerHTML = "";

    let found = false;

    Object.entries(docs).forEach(([label, value]) => {
        if (!value) return;

        const files = Array.isArray(value) ? value : [value];
        files.forEach(path => {
            if (!path) return;
            found = true;
            list.appendChild(documentCard(path, label.replace(/_/g, " ")));
        });
    });

    if (!found) {
        list.innerHTML =
            `<div class="text-sm text-gray-400">No documents uploaded</div>`;
    }
}

/**
 * =====================================================
 * AGENT
 * =====================================================
 */
function renderAgent(agent) {
    const el = document.getElementById("agentInfo");

    if (!agent?.name && !agent?.email) {
        el.innerText = "No agent information";
        return;
    }

    el.innerHTML = `
        <div><strong>Name:</strong> ${escapeHTML(agent.name || "-")}</div>
        <div><strong>Email:</strong> ${escapeHTML(agent.email || "-")}</div>
    `;
}

/**
 * =====================================================
 * UTILITIES
 * =====================================================
 */
function setText(id, value) {
    const el = document.getElementById(id);
    if (el) el.innerText = value || "-";
}

function unreadDot() {
    return `<span class="unread-dot w-2 h-2 bg-blue-600 rounded-full mt-1"></span>`;
}

function formatFullTime(dateStr) {
    if (!dateStr) return null;

    const t = normalizeDateInput(dateStr);
    if (Number.isNaN(t)) return null;
    const d = new Date(t);
    if (isNaN(d.getTime())) return null;

    const now = new Date();
    const diffDays = Math.floor((now - d) / (1000 * 60 * 60 * 24));

    let color = "#94a3b8"; // slate-400
    let icon = "⏱";

    if (diffDays <= 1) {
        color = "#16a34a"; // green-600
        icon = "🆕";
    } else if (diffDays <= 5) {
        color = "#f59e0b"; // amber-500
        icon = "⏱";
    } else {
        color = "#dc2626"; // red-600
        icon = "📅";
    }

    const date = d.toLocaleDateString("en-US", {
        month: "short",
        day: "numeric",
        year: "numeric"
    });

    const time = d.toLocaleTimeString("en-US", {
        hour: "2-digit",
        minute: "2-digit"
    });

    return { color, icon, date, time, diffDays };
}

/**
 * MySQL / PHP often returns "YYYY-MM-DD HH:mm:ss" without timezone.
 * Normalize so elapsed time matches wall-clock expectations across browsers.
 */
function normalizeDateInput(dateStr) {
    const s = String(dateStr || "").trim();
    if (!s) return NaN;
    // Explicit offset / Z — use as-is (API returns ISO UTC with Z).
    if (/Z$/i.test(s) || /[+-]\d{2}:\d{2}$/.test(s)) {
        return Date.parse(s);
    }
    // MySQL naive UTC from DB session time_zone '+00:00' — must not parse as local.
    if (/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}/.test(s)) {
        return Date.parse(s.replace(" ", "T") + "Z");
    }
    return Date.parse(s);
}

/**
 * Human-readable relative time: seconds & minutes first, then hours+minutes
 * under 24h (e.g. "3h 15m ago"), then days / months / years.
 */
function timeAgo(dateStr) {
    if (!dateStr) return "-";
    const t = normalizeDateInput(dateStr);
    if (Number.isNaN(t)) return "-";

    let diffMs = Date.now() - t;
    if (diffMs < 0) diffMs = 0;

    const sec = Math.floor(diffMs / 1000);
    if (sec < 10) return "just now";
    if (sec < 60) return `${sec}s ago`;

    const min = Math.floor(sec / 60);
    if (min < 60) return `${min}m ago`;

    const hr = Math.floor(sec / 3600);
    if (hr < 24) {
        const remMin = Math.floor((sec % 3600) / 60);
        if (remMin > 0) return `${hr}h ${remMin}m ago`;
        return `${hr}h ago`;
    }

    const day = Math.floor(sec / 86400);
    if (day < 30) return `${day}d ago`;

    const mo = Math.floor(day / 30);
    if (mo < 12) return `${mo}mo ago`;

    const yr = Math.floor(day / 365);
    return `${yr > 0 ? yr : 1}y ago`;
}

let __timeAgoTimer = null;

function refreshTimeAgoLabels() {
    document.querySelectorAll("[data-time-ago]").forEach(el => {
        const ts = el.getAttribute("data-time-ago");
        if (ts) el.textContent = timeAgo(ts);
    });
}

function startTimeAgoTicker() {
    if (__timeAgoTimer) clearInterval(__timeAgoTimer);
    refreshTimeAgoLabels();
    __timeAgoTimer = setInterval(refreshTimeAgoLabels, 30000);
}

function debounce(fn, delay) {
    let t;
    return (...args) => {
        clearTimeout(t);
        t = setTimeout(() => fn.apply(this, args), delay);
    };
}

function escapeHTML(str) {
    if (typeof str !== "string") return "";
    return str.replace(/[&<>"']/g, m => ({
        "&": "&amp;",
        "<": "&lt;",
        ">": "&gt;",
        '"': "&quot;",
        "'": "&#039;"
    }[m]));
}

function escapeAttr(str) {
    if (typeof str !== "string") return "";
    return str
        .replace(/&/g, "&amp;")
        .replace(/"/g, "&quot;")
        .replace(/'/g, "&#039;");
}
/**
 * =====================================================
 * DOCUMENT CARD (REQUIRED)
 * =====================================================
 */
function documentCard(path, label) {
    const div = document.createElement("div");
    div.className =
        "flex items-center justify-between p-3 border rounded-md bg-slate-50 hover:bg-slate-100";

    const fileName = path.split("/").pop();

    div.innerHTML = `
        <div class="flex flex-col">
            <span class="text-sm font-medium capitalize">
                ${escapeHTML(label)}
            </span>
            <span class="text-xs text-gray-500 truncate max-w-[220px]">
                ${escapeHTML(fileName)}
            </span>
        </div>

        <a
            href="${escapeHTML(path)}"
            target="_blank"
            class="text-blue-600 text-xs font-semibold hover:underline"
        >
            View
        </a>
    `;

    return div;
}
/**
 * =====================================================
 * ADD STUDY CHOICE (Student Application Report)
 * Select2 searchable dropdowns + cascading loads
 * =====================================================
 */
function studyChoiceAddStudyDropdownParent() {
    if (typeof window.jQuery !== "function") return null;
    /* Body = dropdown stacks above sticky journey sidebar (avoids clipping / wrong z-index in grid) */
    return window.jQuery(document.body);
}

function destroyStudyChoiceSelect2IfAny(el) {
    if (!el || typeof window.jQuery !== "function" || !window.jQuery.fn.select2) return;
    const $el = window.jQuery(el);
    if ($el.length && $el.hasClass("select2-hidden-accessible")) {
        $el.select2("destroy");
    }
}

function mountStudyChoiceSelect2(el, opts) {
    const options = opts || {};
    if (!el || typeof window.jQuery !== "function" || !window.jQuery.fn.select2) return;
    const $el = window.jQuery(el);
    if ($el.hasClass("select2-hidden-accessible")) {
        $el.select2("destroy");
    }
    $el.prop("disabled", !!options.disabled);
    const $par = studyChoiceAddStudyDropdownParent();
    $el.select2({
        theme: "bootstrap-5",
        width: "100%",
        placeholder: options.placeholder || "Select…",
        allowClear: true,
        minimumResultsForSearch: 0,
        dropdownParent: $par && $par.length ? $par : window.jQuery(document.body)
    });
}

function addStudySelectValue(id) {
    const el = document.getElementById(id);
    if (!el) return "";
    if (
        typeof window.jQuery === "function" &&
        window.jQuery(el).hasClass("select2-hidden-accessible")
    ) {
        const v = window.jQuery(el).val();
        return v === null || v === undefined ? "" : String(v);
    }
    return el.value || "";
}

function bindStudyChoiceCascadeEvents() {
    if (typeof window.jQuery !== "function") return;
    const $ = window.jQuery;
    $("#addStudyRegion")
        .off("change.pcvcStudy select2:select.pcvcStudy select2:clear.pcvcStudy")
        .on("change.pcvcStudy select2:select.pcvcStudy select2:clear.pcvcStudy", function () {
            const el = this;
            clearTimeout(bindStudyChoiceCascadeEvents._tRegion);
            bindStudyChoiceCascadeEvents._tRegion = setTimeout(() => {
                onAddStudyRegionChange.call(el);
            }, 0);
        });
    $("#addStudyUniversity")
        .off("change.pcvcStudy select2:select.pcvcStudy select2:clear.pcvcStudy")
        .on("change.pcvcStudy select2:select.pcvcStudy select2:clear.pcvcStudy", function () {
            const el = this;
            clearTimeout(bindStudyChoiceCascadeEvents._tUni);
            bindStudyChoiceCascadeEvents._tUni = setTimeout(() => {
                onAddStudyUniversityChange.call(el);
            }, 0);
        });
    $("#addStudyLevel")
        .off("change.pcvcStudy select2:select.pcvcStudy select2:clear.pcvcStudy")
        .on("change.pcvcStudy select2:select.pcvcStudy select2:clear.pcvcStudy", function () {
            const el = this;
            clearTimeout(bindStudyChoiceCascadeEvents._tLev);
            bindStudyChoiceCascadeEvents._tLev = setTimeout(() => {
                onAddStudyLevelChange.call(el);
            }, 0);
        });
    $("#addStudyProgram")
        .off("change.pcvcStudy select2:select.pcvcStudy select2:clear.pcvcStudy")
        .on("change.pcvcStudy select2:select.pcvcStudy select2:clear.pcvcStudy", function () {
            updateAddStudyChoiceButtonState();
        });
}

function wireStudyChoiceAddFormOnce() {
    if (studyChoiceAddFormWired) return;
    studyChoiceAddFormWired = true;

    document.getElementById("btnAddStudyChoice")?.addEventListener("click", submitAddStudyChoice);
    bindStudyChoiceCascadeEvents();
}

function resetStudyChoiceAddSelects() {
    const region = document.getElementById("addStudyRegion");
    const uni = document.getElementById("addStudyUniversity");
    const lev = document.getElementById("addStudyLevel");
    const prog = document.getElementById("addStudyProgram");
    const status = document.getElementById("studyChoiceAddStatus");

    [region, uni, lev, prog].forEach((el) => destroyStudyChoiceSelect2IfAny(el));

    if (region) region.value = "";
    if (uni) {
        uni.innerHTML = '<option value="">Select region first</option>';
        uni.disabled = true;
    }
    if (lev) {
        lev.innerHTML = '<option value="">Select university</option>';
        lev.disabled = true;
    }
    if (prog) {
        prog.innerHTML = '<option value="">Select level</option>';
        prog.disabled = true;
    }
    if (status) status.textContent = "";

    if (region) {
        mountStudyChoiceSelect2(region, {
            placeholder: "Search or select region…",
            disabled: false
        });
    }
    if (uni) {
        mountStudyChoiceSelect2(uni, {
            placeholder: "Search university…",
            disabled: true
        });
    }
    if (lev) {
        mountStudyChoiceSelect2(lev, {
            placeholder: "Search level…",
            disabled: true
        });
    }
    if (prog) {
        mountStudyChoiceSelect2(prog, {
            placeholder: "Search program…",
            disabled: true
        });
    }

    bindStudyChoiceCascadeEvents();
    updateAddStudyChoiceButtonState();
}

function updateAddStudyChoiceButtonState() {
    const btn = document.getElementById("btnAddStudyChoice");
    if (!btn) return;
    const ok =
        currentViewApplicationId &&
        addStudySelectValue("addStudyRegion") &&
        addStudySelectValue("addStudyUniversity") &&
        addStudySelectValue("addStudyLevel") &&
        addStudySelectValue("addStudyProgram");
    btn.disabled = !ok;
}

async function loadStudyChoiceRegions() {
    const sel = document.getElementById("addStudyRegion");
    if (!sel) return;

    if (studyChoiceRegionsLoaded) return;

    destroyStudyChoiceSelect2IfAny(sel);
    sel.innerHTML = '<option value="">Loading…</option>';
    try {
        const r = await fetch(projectApiPath("save_application.php?action=load_meta"), {
            credentials: "same-origin"
        });
        const data = await r.json();
        const regions = Array.isArray(data.regions) ? data.regions : [];
        destroyStudyChoiceSelect2IfAny(sel);
        sel.innerHTML = '<option value="">Select region</option>';
        regions.forEach((row) => {
            const o = document.createElement("option");
            o.value = String(row.id);
            o.textContent = row.name || `Region #${row.id}`;
            sel.appendChild(o);
        });
        studyChoiceRegionsLoaded = true;
    } catch (e) {
        console.error("loadStudyChoiceRegions:", e);
        destroyStudyChoiceSelect2IfAny(sel);
        sel.innerHTML = '<option value="">Failed to load regions</option>';
    }
}

async function prepareStudyChoiceAddForm() {
    await loadStudyChoiceRegions();
    resetStudyChoiceAddSelects();
}

async function onAddStudyRegionChange() {
    const regionId = this.value;
    const uni = document.getElementById("addStudyUniversity");
    const lev = document.getElementById("addStudyLevel");
    const prog = document.getElementById("addStudyProgram");
    if (!uni || !lev || !prog) return;

    [uni, lev, prog].forEach((el) => destroyStudyChoiceSelect2IfAny(el));

    lev.innerHTML = '<option value="">Select university</option>';
    lev.disabled = true;
    prog.innerHTML = '<option value="">Select level</option>';
    prog.disabled = true;

    if (!regionId) {
        uni.innerHTML = '<option value="">Select region first</option>';
        uni.disabled = true;
        mountStudyChoiceSelect2(uni, { placeholder: "Search university…", disabled: true });
        mountStudyChoiceSelect2(lev, { placeholder: "Search level…", disabled: true });
        mountStudyChoiceSelect2(prog, { placeholder: "Search program…", disabled: true });
        updateAddStudyChoiceButtonState();
        return;
    }

    uni.innerHTML = '<option value="">Loading…</option>';
    uni.disabled = true;
    mountStudyChoiceSelect2(uni, { placeholder: "Loading universities…", disabled: true });
    mountStudyChoiceSelect2(lev, { placeholder: "Search level…", disabled: true });
    mountStudyChoiceSelect2(prog, { placeholder: "Search program…", disabled: true });

    try {
        const r = await fetch(
            projectApiPath(
                `save_application.php?action=universities&region_id=${encodeURIComponent(regionId)}`
            ),
            { credentials: "same-origin" }
        );
        const rows = await r.json();
        destroyStudyChoiceSelect2IfAny(uni);
        uni.innerHTML = '<option value="">Select university</option>';
        (Array.isArray(rows) ? rows : []).forEach((row) => {
            const o = document.createElement("option");
            o.value = String(row.id);
            o.textContent = row.name || `University #${row.id}`;
            uni.appendChild(o);
        });
        uni.disabled = false;
        mountStudyChoiceSelect2(uni, { placeholder: "Search university…", disabled: false });
        mountStudyChoiceSelect2(lev, { placeholder: "Search level…", disabled: true });
        mountStudyChoiceSelect2(prog, { placeholder: "Search program…", disabled: true });
    } catch (e) {
        console.error(e);
        destroyStudyChoiceSelect2IfAny(uni);
        uni.innerHTML = '<option value="">Load failed</option>';
        uni.disabled = true;
        mountStudyChoiceSelect2(uni, { placeholder: "Search university…", disabled: true });
        mountStudyChoiceSelect2(lev, { placeholder: "Search level…", disabled: true });
        mountStudyChoiceSelect2(prog, { placeholder: "Search program…", disabled: true });
    }
    updateAddStudyChoiceButtonState();
}

async function onAddStudyUniversityChange() {
    const uid = this.value;
    const lev = document.getElementById("addStudyLevel");
    const prog = document.getElementById("addStudyProgram");
    if (!lev || !prog) return;

    [lev, prog].forEach((el) => destroyStudyChoiceSelect2IfAny(el));

    prog.innerHTML = '<option value="">Select level</option>';
    prog.disabled = true;

    if (!uid) {
        lev.innerHTML = '<option value="">Select university</option>';
        lev.disabled = true;
        mountStudyChoiceSelect2(lev, { placeholder: "Search level…", disabled: true });
        mountStudyChoiceSelect2(prog, { placeholder: "Search program…", disabled: true });
        updateAddStudyChoiceButtonState();
        return;
    }

    lev.innerHTML = '<option value="">Loading…</option>';
    lev.disabled = true;
    mountStudyChoiceSelect2(lev, { placeholder: "Loading levels…", disabled: true });
    mountStudyChoiceSelect2(prog, { placeholder: "Search program…", disabled: true });

    try {
        const r = await fetch(
            projectApiPath(
                `save_application.php?action=program_levels&university_id=${encodeURIComponent(uid)}`
            ),
            { credentials: "same-origin" }
        );
        const rows = await r.json();
        destroyStudyChoiceSelect2IfAny(lev);
        lev.innerHTML = '<option value="">Select level</option>';
        (Array.isArray(rows) ? rows : []).forEach((row) => {
            const o = document.createElement("option");
            o.value = String(row.id);
            const abbr = row.abbreviation ? String(row.abbreviation) : "";
            const nm = row.name ? String(row.name) : "";
            o.textContent = abbr && nm ? `${abbr} — ${nm}` : nm || abbr || `Level #${row.id}`;
            lev.appendChild(o);
        });
        lev.disabled = false;
        mountStudyChoiceSelect2(lev, { placeholder: "Search level…", disabled: false });
        mountStudyChoiceSelect2(prog, { placeholder: "Search program…", disabled: true });
    } catch (e) {
        console.error(e);
        destroyStudyChoiceSelect2IfAny(lev);
        lev.innerHTML = '<option value="">Load failed</option>';
        lev.disabled = true;
        mountStudyChoiceSelect2(lev, { placeholder: "Search level…", disabled: true });
        mountStudyChoiceSelect2(prog, { placeholder: "Search program…", disabled: true });
    }
    updateAddStudyChoiceButtonState();
}

async function onAddStudyLevelChange() {
    const uid = addStudySelectValue("addStudyUniversity");
    const levelId = this.value;
    const prog = document.getElementById("addStudyProgram");
    if (!prog) return;

    destroyStudyChoiceSelect2IfAny(prog);

    if (!uid || !levelId) {
        prog.innerHTML = '<option value="">Select level first</option>';
        prog.disabled = true;
        mountStudyChoiceSelect2(prog, { placeholder: "Search program…", disabled: true });
        updateAddStudyChoiceButtonState();
        return;
    }

    prog.innerHTML = '<option value="">Loading…</option>';
    prog.disabled = true;
    mountStudyChoiceSelect2(prog, { placeholder: "Loading programs…", disabled: true });

    try {
        const r = await fetch(
            projectApiPath(
                `save_application.php?action=programs&university_id=${encodeURIComponent(
                    uid
                )}&program_level_id=${encodeURIComponent(levelId)}`
            ),
            { credentials: "same-origin" }
        );
        const rows = await r.json();
        destroyStudyChoiceSelect2IfAny(prog);
        prog.innerHTML = '<option value="">Select program</option>';
        (Array.isArray(rows) ? rows : []).forEach((row) => {
            const o = document.createElement("option");
            o.value = String(row.id);
            o.textContent = row.program_name || `Program #${row.id}`;
            prog.appendChild(o);
        });
        prog.disabled = false;
        mountStudyChoiceSelect2(prog, { placeholder: "Search program…", disabled: false });
    } catch (e) {
        console.error(e);
        destroyStudyChoiceSelect2IfAny(prog);
        prog.innerHTML = '<option value="">Load failed</option>';
        prog.disabled = true;
        mountStudyChoiceSelect2(prog, { placeholder: "Search program…", disabled: true });
    }
    updateAddStudyChoiceButtonState();
}

async function submitAddStudyChoice() {
    if (!currentViewApplicationId) return;

    const fd = new FormData();
    fd.append("application_id", String(currentViewApplicationId));
    fd.append("region_id", addStudySelectValue("addStudyRegion"));
    fd.append("university_id", addStudySelectValue("addStudyUniversity"));
    fd.append("program_level_id", addStudySelectValue("addStudyLevel"));
    fd.append("program_id", addStudySelectValue("addStudyProgram"));

    const btn = document.getElementById("btnAddStudyChoice");
    const status = document.getElementById("studyChoiceAddStatus");
    if (btn) btn.disabled = true;
    if (status) status.textContent = "Saving…";

    try {
        const res = await fetch(projectApiPath("api/applications.php?action=add_study_choice"), {
            method: "POST",
            body: fd,
            credentials: "same-origin"
        });
        const json = await res.json();
        if (!json.success) {
            if (status) status.textContent = json.message || "Could not save.";
            alert(json.message || "Could not add study choice.");
            updateAddStudyChoiceButtonState();
            return;
        }

        const d = json.data || {};
        renderStudyChoices(Array.isArray(d.study_choices) ? d.study_choices : []);
        loadStudents();

        if (d.duplicate) {
            if (status) status.textContent = d.message || "Already listed.";
            showToast(d.message || "This choice is already on the application.", undefined, "Study choices");
        } else {
            let msg = d.message || "Study choice added.";
            if (d.jobs_created > 0) {
                msg += ` ${d.jobs_created} job(s) created.`;
                showToast(msg, () => {
                    window.location.href = "admin-jobs.php";
                }, "Study choices");
            } else {
                showToast(
                    msg +
                        (d.student_notified
                            ? " Student notified by email."
                            : " Student email not sent (missing or invalid address)."),
                    undefined,
                    "Study choices"
                );
            }
            if (status) {
                status.textContent = d.student_notified
                    ? "Saved and student notified."
                    : "Saved. Email not sent (check student email).";
            }
        }

        resetStudyChoiceAddSelects();
    } catch (e) {
        console.error(e);
        if (status) status.textContent = "Network error.";
    } finally {
        updateAddStudyChoiceButtonState();
    }
}

/**
 * =====================================================
 * TOAST NOTIFICATION (CLICKABLE)
 * =====================================================
 */
function showToast(message, onClick, title) {
    let container = document.getElementById("toastContainer");

    if (!container) {
        container = document.createElement("div");
        container.id = "toastContainer";
        container.className =
            "fixed top-4 right-4 z-50 flex flex-col gap-2";
        document.body.appendChild(container);
    }

    const toast = document.createElement("div");
    toast.className =
        "bg-green-600 text-white px-4 py-3 rounded shadow cursor-pointer hover:bg-green-700 transition";

    const heading =
        typeof title === "string" && title.trim() !== ""
            ? title.trim()
            : "Jobs Created";

    toast.innerHTML = `
        <div class="text-sm font-semibold">${escapeHTML(String(heading ?? ""))}</div>
        <div class="text-xs">${escapeHTML(String(message ?? ""))}</div>
    `;

    toast.onclick = () => {
        if (typeof onClick === "function") onClick();
        toast.remove();
    };

    container.appendChild(toast);

    setTimeout(() => toast.remove(), 6000);
}
