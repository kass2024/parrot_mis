<?php
// admin/application-list.php
session_start();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers/role.php';
require_once __DIR__ . '/includes/company_branding.php';

$sessionRole = isset($_SESSION['role']) ? trim((string) $_SESSION['role']) : '';
$dbRole = '';
$adminPk = 0;
if (!empty($_SESSION['id'])) {
    $adminPk = (int) $_SESSION['id'];
} elseif (!empty($_SESSION['admin_id'])) {
    $adminPk = (int) $_SESSION['admin_id'];
}
if ($adminPk > 0) {
    $stRole = $conn->prepare('SELECT role FROM admins WHERE id = ? LIMIT 1');
    if ($stRole) {
        $stRole->bind_param('i', $adminPk);
        $stRole->execute();
        $rowRole = $stRole->get_result()->fetch_assoc();
        $stRole->close();
        if ($rowRole) {
            $dbRole = trim((string) ($rowRole['role'] ?? ''));
        }
    }
}
// Superadmin if either session or DB matches (delete API still enforces DB)
$canDeleteApplication = xander_is_superadmin_role($dbRole) || xander_is_superadmin_role($sessionRole);

$appRoot = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Student Applications | <?= htmlspecialchars(PCVC_COMPANY_DISPLAY_NAME, ENT_QUOTES, 'UTF-8') ?></title>

    <!-- Tailwind -->
    <script src="https://cdn.tailwindcss.com"></script>
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <!-- ================= CUSTOM STYLES ================= -->
   <style>

/* =====================================================
   GLOBAL FOUNDATION
   ===================================================== */
:root {
    --bg-dark: #0f172a;
    --bg-dark-hover: #1e293b;

    --text-light: #e5e7eb;
    --text-muted: #94a3b8;
    --text-dark: #0f172a;

    --primary: #4f46e5;
    --primary-soft: #eef2ff;

    --border-soft: #e5e7eb;
    --border-muted: #e2e8f0;

    --success: #22c55e;
}

body {
    font-family: ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont;
    color: var(--text-dark);
}

/* =====================================================
   SCROLLBAR (MINIMAL & MODERN)
   ===================================================== */
.scrollbar::-webkit-scrollbar {
    width: 6px;
}

.scrollbar::-webkit-scrollbar-thumb {
    background-color: #64748b;
    border-radius: 999px;
}

/* =====================================================
   SIDEBAR (NAVIGATION ZONE)
   ===================================================== */
aside {
    background-color: var(--bg-dark);
    color: var(--text-light);
}

aside h2 {
    color: #f8fafc;
}

aside p {
    color: var(--text-muted);
}

/* Student list rows – JS SAFE */
#studentList li {
    padding: 0.9rem 1.25rem;
    cursor: pointer;
    display: flex;
    flex-direction: column;
    gap: 0.2rem;
    transition: background-color .15s ease, transform .15s ease;
}

#studentList li:hover {
    background-color: var(--bg-dark-hover);
}

#studentList li.active {
    background: linear-gradient(
        90deg,
        var(--primary),
        #6366f1
    );
}

/* =====================================================
   CARDS (CONTENT ZONE)
   ===================================================== */
.card {
    background-color: #ffffff;
    border-radius: 1rem;
    border: 1px solid var(--border-soft);
    box-shadow:
        0 1px 2px rgba(0,0,0,.05),
        0 12px 28px rgba(0,0,0,.08);
    transition: transform .2s ease, box-shadow .2s ease;
}

.card:hover {
    transform: translateY(-2px);
    box-shadow:
        0 4px 8px rgba(0,0,0,.06),
        0 20px 40px rgba(0,0,0,.12);
}

/* Section headers */
.section-title {
    font-size: .75rem;
    letter-spacing: .08em;
    text-transform: uppercase;
    font-weight: 600;
    color: #475569;
    margin-bottom: 1rem;
}

/* =====================================================
   AI DECISION PANEL (HIGHLIGHT ZONE)
   ===================================================== */
#aiDecisionPanel {
    background: linear-gradient(
        135deg,
        #eef2ff,
        #f8fafc
    );
    border: 1px solid #c7d2fe;
    box-shadow: 0 16px 40px rgba(79,70,229,.2);
}

/* AI recommendation cards */
.ai-platform-card {
    background-color: #ffffff;
    border: 1px solid var(--border-soft);
    border-radius: .9rem;
    padding: 1rem;
    transition: transform .2s ease, box-shadow .2s ease;
}

.ai-platform-card:hover {
    transform: translateY(-4px);
    box-shadow: 0 20px 40px rgba(0,0,0,.15);
}

.ai-platform-badge {
    font-size: .65rem;
    font-weight: 600;
    padding: .25rem .65rem;
    border-radius: 999px;
    background-color: var(--primary-soft);
    color: #4338ca;
}

/* =====================================================
   APPLICATION JOURNEY (STATUS / TIMELINE ZONE)
   ===================================================== */
#applicationTracking {
    background: linear-gradient(
        180deg,
        #ffffff,
        #f8fafc
    );
    border-radius: 1.25rem;
    border: 1px solid var(--border-muted);
    box-shadow: 0 16px 40px rgba(0,0,0,.12);
}

/* Journey status badge */
#journeyStatusBadge {
    font-size: 11px;
    font-weight: 600;
    padding: .25rem .6rem;
    border-radius: 999px;
    background-color: #e0e7ff;
    color: #3730a3;
}

/* Timeline dots */
.timeline-dot {
    width: 10px;
    height: 10px;
    border-radius: 999px;
    background-color: #cbd5e1;
}

.timeline-dot.completed {
    background-color: var(--success);
    box-shadow: 0 0 0 4px rgba(34,197,94,.15);
}

.timeline-dot.current {
    background-color: #6366f1;
    box-shadow: 0 0 0 6px rgba(99,102,241,.25);
}

/* =====================================================
   TABLES (DATA PRESENTATION)
   ===================================================== */
table {
    width: 100%;
    border-collapse: collapse;
}

thead {
    background-color: #f1f5f9;
}

th {
    font-size: .7rem;
    letter-spacing: .08em;
    text-transform: uppercase;
    font-weight: 600;
    color: #475569;
}

td,
th {
    padding: .6rem;
    border: 1px solid var(--border-soft);
}

/* =====================================================
   EMPTY STATES
   ===================================================== */
#emptyState {
    background: linear-gradient(
        135deg,
        #f8fafc,
        #eef2ff
    );
    border-radius: 1.25rem;
    padding: 4rem 2rem;
}

#journeyEmpty {
    color: var(--text-muted);
}

</style>


</head>

<body class="bg-slate-100 text-slate-800">
<div class="flex h-screen overflow-hidden">

    <!-- ================= SIDEBAR ================= -->
    <aside class="w-96 flex flex-col border-r border-slate-800">
        <div class="px-6 py-5 border-b">
            <h2 class="text-lg font-bold">Applications</h2>
            <p class="text-xs text-gray-500 mt-1"><?= htmlspecialchars(PCVC_COMPANY_DISPLAY_NAME, ENT_QUOTES, 'UTF-8') ?> · All student submissions</p>
        </div>

        <div class="p-4 border-b">
          <input
    id="searchInput"
    type="text"
    placeholder="Search name,email,region or country"
    class="w-full px-4 py-2 border rounded-lg text-sm
           text-slate-900 placeholder-slate-400
           focus:ring focus:ring-blue-200 focus:outline-none"
>

        </div>

        <ul
            id="studentList"
            class="flex-1 overflow-y-auto scrollbar divide-y text-sm bg-white"
        ></ul>
    </aside>

    <!-- ================= MAIN CONTENT ================= -->
    <main class="flex-1 overflow-y-auto bg-slate-50 p-8">
        <div class="max-w-7xl mx-auto grid grid-cols-1 lg:grid-cols-3 gap-6">

            <!-- ================= LEFT COLUMN ================= -->
            <div class="lg:col-span-2 space-y-6">

                <!-- EMPTY STATE -->
                <div
                    id="emptyState"
                    class="flex flex-col items-center justify-center h-full
                           text-gray-400 text-center"
                >
                    <div class="text-lg font-medium">No application selected</div>
                    <div class="text-sm mt-1">
                        Select a student from the left to view details
                    </div>
                </div>

                <!-- ================= AI DECISION PANEL ================= -->
                <div
                    id="aiDecisionPanel"
                    class="hidden card bg-gradient-to-br from-blue-50 to-indigo-50
                           border-blue-200 p-6 space-y-5"
                >
                    <div class="flex items-center justify-between">
                        <h3 class="text-lg font-semibold text-blue-900">
                            🤖 Platform Recommendations
                        </h3>
                        <span
                            id="aiConfidence"
                            class="text-xs font-semibold px-3 py-1 rounded-full
                                   bg-blue-100 text-blue-800"
                        >
                            —
                        </span>
                    </div>

                    <p class="text-xs text-blue-700">
                        Platforms are selected based on the chosen university,
                        destination country, and admin workload.
                    </p>

                    <div
                        id="aiPlatforms"
                        class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4 text-sm"
                    ></div>
                </div>

                <!-- ================= APPLICATION DETAILS ================= -->
                <div id="applicationDetails" class="hidden space-y-6">

                    <!-- HEADER -->
                    <section class="card p-6">
                        <div class="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-3">
                            <div class="min-w-0 flex-1">
                                <h2 id="studentName" class="text-xl font-bold"></h2>
                                <p id="studentEmail" class="text-sm text-gray-500"></p>
                                <p id="studentPhone" class="text-sm text-gray-500"></p>
                                <p id="applicationMeta" class="text-xs text-gray-400 mt-1"></p>
                            </div>
                            <div
                                id="applicationActions"
                                class="flex-shrink-0 w-full sm:w-auto flex flex-wrap gap-2 justify-end sm:justify-start items-start"
                            >
                                <div id="applicationActionsDynamic" class="flex flex-wrap gap-2 justify-end"></div>
                                <?php if ($canDeleteApplication): ?>
                                <button
                                    type="button"
                                    id="btnDeleteApplicationHeader"
                                    class="pcvc-btn-delete-app"
                                    style="display:none;align-items:center;gap:0.35rem;padding:0.55rem 1rem;font-size:0.875rem;font-weight:600;color:#fff;background:#dc2626;border:1px solid #b91c1c;border-radius:0.5rem;cursor:pointer;box-shadow:0 1px 2px rgba(0,0,0,0.08);"
                                    disabled
                                    title="Select an application, then click to delete permanently"
                                >Delete application</button>
                                <?php endif; ?>
                            </div>
                        </div>
                    </section>

                    <!-- PERSONAL INFO -->
                    <section class="card p-6">
                        <div class="section-title">Personal Information</div>
                        <div class="grid grid-cols-2 gap-4 text-sm">
                            <div>Gender: <span id="pGender"></span></div>
                            <div>DOB: <span id="pDob"></span></div>
                            <div>Nationality: <span id="pNationality"></span></div>
                            <div>Birth Country: <span id="pBirthCountry"></span></div>
                            <div>Passport: <span id="pPassport"></span></div>
                            <div>National ID: <span id="pNationalId"></span></div>
                        </div>
                    </section>

                    <!-- ADDRESS -->
                    <section class="card p-6">
                        <div class="section-title">Address</div>
                        <div class="text-sm space-y-1">
                            <div id="addrLine"></div>
                            <div id="addrCity"></div>
                            <div id="addrPostal"></div>
                        </div>
                    </section>

                    <!-- FAMILY & EMERGENCY -->
                    <section class="card p-6">
                        <div class="section-title">Family & Emergency</div>

                        <div class="grid grid-cols-2 gap-4 text-sm mb-4">
                            <div>Father: <span id="pFather"></span></div>
                            <div>Mother: <span id="pMother"></span></div>
                        </div>

                        <div class="grid grid-cols-2 gap-4 text-sm">
                            <div>Name: <span id="eName"></span></div>
                            <div>Email: <span id="eEmail"></span></div>
                            <div>Phone: <span id="ePhone"></span></div>
                            <div>Relationship: <span id="eRelation"></span></div>
                        </div>
                    </section>

                    <!-- EDUCATION -->
                    <section class="card p-6">
                        <div class="section-title">Education Background</div>
                        <div class="grid grid-cols-2 gap-4 text-sm">
                            <div>Institution: <span id="eduInstitution"></span></div>
                            <div>Country: <span id="eduCountry"></span></div>
                            <div>Start Date: <span id="eduStart"></span></div>
                            <div>Graduation: <span id="eduGrad"></span></div>
                            <div class="col-span-2">Study Gap: <span id="eduGap"></span></div>
                        </div>
                    </section>

                    <!-- STUDY CHOICES -->
                    <section class="card p-6">
                        <div class="section-title">Study Choices</div>
                        <div class="overflow-x-auto">
                            <table class="w-full text-sm border">
                                <thead class="bg-gray-100">
                                    <tr>
                                        <th class="p-2 border">Region</th>
                                        <th class="p-2 border">University</th>
                                        <th class="p-2 border">Country</th>
                                        <th class="p-2 border">Level</th>
                                        <th class="p-2 border">Program</th>
                                    </tr>
                                </thead>
                                <tbody id="studyChoicesTable"></tbody>
                            </table>
                        </div>
                    </section>

                    <!-- DOCUMENTS -->
                    <section class="card p-6">
                        <div class="section-title">Documents</div>
                        <div id="documentsList" class="grid grid-cols-1 sm:grid-cols-2 gap-3"></div>
                    </section>

                    <!-- AGENT -->
                    <section class="card p-6">
                        <div class="section-title">Agent</div>
                        <div id="agentInfo" class="text-sm text-gray-700"></div>
                    </section>

                </div>
            </div>

           <!-- ================= RIGHT COLUMN: APPLICATION JOURNEY ================= -->
<aside
    id="applicationTracking"
    class="hidden lg:block sticky top-8 p-6 h-fit"
>

    <!-- Header -->
    <div class="flex items-center justify-between mb-6">
        <h3 class="text-sm font-semibold text-slate-900">
            Application Journey
        </h3>

        <span
            id="journeyStatusBadge"
            class="text-[11px] font-semibold px-2.5 py-1 rounded-full
                   bg-slate-100 text-slate-600"
        >
            In progress
        </span>
    </div>

    <!-- Superadmin only: delete in journey sidebar (PHP + wired by application-list.js) -->
    <div
        id="journeyDeleteActions"
        class="mb-4 pb-4 border-b border-slate-200<?php echo $canDeleteApplication ? '' : ' hidden'; ?>"
        aria-live="polite"
    >
        <?php if ($canDeleteApplication): ?>
        <button
            type="button"
            id="btnDeleteApplicationJourney"
            class="pcvc-btn-delete-app"
            style="display:none;width:100%;align-items:center;justify-content:center;gap:0.35rem;padding:0.6rem 1rem;font-size:0.875rem;font-weight:600;color:#fff;background:#dc2626;border:1px solid #b91c1c;border-radius:0.5rem;cursor:pointer;box-shadow:0 1px 2px rgba(0,0,0,0.08);"
            disabled
            title="Select an application, then click to delete permanently"
        >Delete application</button>
        <?php endif; ?>
    </div>

    <!-- Timeline -->
   <div
    id="trackingTimeline"
    class="relative flex flex-col gap-6 text-xs pl-6"
>

        <!-- Vertical line -->
        <div
            class="absolute left-[7px] top-0 bottom-0 w-px bg-slate-200"
        ></div>

        <!-- JS injects journey steps here -->
    </div>

    <!-- Empty state -->
    <div
        id="journeyEmpty"
        class="hidden text-center text-xs text-slate-400 py-6"
    >
        No journey activity yet
    </div>
</aside>


        </div>
    </main>
</div>

<script>
window.APP_ROOT = <?= json_encode($appRoot, JSON_UNESCAPED_SLASHES) ?>;
window.CAN_DELETE_APPLICATION = <?= json_encode($canDeleteApplication) ?>;
</script>
<script src="assets/js/application-list.js?v=<?= (int) @filemtime(__DIR__ . '/assets/js/application-list.js') ?>"></script>
</body>
</html>
