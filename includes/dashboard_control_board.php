<?php
declare(strict_types=1);

require_once __DIR__ . '/../helpers/dashboard_control.php';

$rosterMemberIds = null;
if (isset($dashboardRosterMemberIds) && is_array($dashboardRosterMemberIds)) {
    $rosterMemberIds = $dashboardRosterMemberIds;
}
// Company home: never merge Parrot HQ Cyprus applicants into the roster.
$rosterConn2 = null;
if ($rosterMemberIds === null && isset($conn2) && $conn2 instanceof mysqli) {
    $rosterConn2 = $conn2;
}
$roster = pcvc_dashboard_roster($conn, $rosterConn2, $rosterMemberIds);

$renderStudents = static function (array $people): string {
    if ($people === []) {
        return '<p class="fold-empty">No students yet.</p>';
    }
    $html = '<ul class="fold-students">';
    foreach ($people as $person) {
        $html .= '<li><span class="fold-student-name">' . htmlspecialchars((string) $person['name'], ENT_QUOTES, 'UTF-8') . '</span>';
        $email = trim((string) ($person['email'] ?? ''));
        if ($email !== '') {
            $html .= '<span class="fold-student-email">' . htmlspecialchars($email, ENT_QUOTES, 'UTF-8') . '</span>';
        }
        $status = trim((string) ($person['status'] ?? ''));
        if ($status !== '') {
            $html .= '<span class="fold-status">' . htmlspecialchars($status, ENT_QUOTES, 'UTF-8') . '</span>';
        }
        $html .= '</li>';
    }
    $html .= '</ul>';

    return $html;
};

$renderRows = static function (array $accounts, string $group) use ($renderStudents): string {
    if ($accounts === []) {
        return '<p class="fold-empty">Nothing in this group yet.</p>';
    }
    $html = '<div class="fold-rows">';
    foreach ($accounts as $account) {
        $students = $account['students'] ?? [];
        $count = count($students);
        $title = ($account['account_type'] ?? '') === 'company' && ($account['company_name'] ?? '') !== ''
            ? (string) $account['company_name']
            : (string) $account['name'];
        $kind = (string) ($account['kind'] ?? 'Account');
        $search = strtolower($title . ' ' . $account['email'] . ' ' . $account['contact'] . ' ' . $kind);
        foreach ($students as $person) {
            $search .= ' ' . strtolower((string) $person['name'] . ' ' . (string) $person['email']);
        }
        $html .= '<article class="fold-row" data-roster-row data-group="' . htmlspecialchars($group, ENT_QUOTES, 'UTF-8') . '" data-search="' . htmlspecialchars($search, ENT_QUOTES, 'UTF-8') . '">';
        $html .= '<details><summary>';
        $html .= '<span class="fold-who"><span class="fold-pill is-' . htmlspecialchars(strtolower($kind), ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars($kind, ENT_QUOTES, 'UTF-8') . '</span>';
        $html .= '<span class="fold-name">' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</span>';
        $html .= '<span class="fold-email">' . htmlspecialchars((string) $account['email'], ENT_QUOTES, 'UTF-8') . '</span></span>';
        $html .= '<span class="fold-meta"><span class="fold-num">' . $count . '</span><span>' . ($count === 1 ? 'student' : 'students') . '</span>';
        if (($account['status'] ?? 'active') !== 'active') {
            $html .= '<span class="fold-flag">' . htmlspecialchars(ucfirst((string) $account['status']), ENT_QUOTES, 'UTF-8') . '</span>';
        }
        $html .= '<i class="bi bi-chevron-down fold-chevron"></i></span></summary>';
        $html .= '<div class="fold-body">' . $renderStudents($students) . '</div></details></article>';
    }
    $html .= '</div>';

    return $html;
};
?>
<style>
  .roster-board { margin: 0 0 22px; }
  .roster-head h2 { margin: 0; font-size: 1.25rem; font-weight: 800; color: var(--navy); }
  .roster-head p { margin: 4px 0 14px; color: var(--muted); font-size: 0.9rem; max-width: 40rem; }
  .roster-kpis { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 10px; margin-bottom: 12px; }
  @media (max-width: 900px) { .roster-kpis { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
  .roster-kpi { text-align: left; background: #fff; border: 1px solid var(--border); border-radius: 14px; padding: 12px 14px; box-shadow: var(--shadow-sm); cursor: pointer; }
  .roster-kpi:hover, .roster-kpi.is-on { border-color: rgba(66, 116, 49, 0.45); box-shadow: var(--shadow-md); }
  .roster-kpi span { display: block; font-size: 0.7rem; text-transform: uppercase; letter-spacing: 0.05em; color: var(--muted); font-weight: 700; }
  .roster-kpi strong { display: block; margin-top: 4px; font-size: 1.7rem; line-height: 1; color: var(--navy); }
  .roster-kpi em { display: block; margin-top: 4px; font-style: normal; font-size: 0.75rem; color: var(--muted); }
  .roster-search { width: 100%; box-sizing: border-box; border: 1px solid var(--border); border-radius: 12px; padding: 10px 12px; margin-bottom: 12px; background: #fff; }
  .fold-section { background: #fff; border: 1px solid var(--border); border-radius: 14px; margin-bottom: 10px; box-shadow: var(--shadow-sm); }
  .fold-section > summary { list-style: none; cursor: pointer; display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 14px 16px; }
  .fold-section > summary::-webkit-details-marker { display: none; }
  .fold-section-title { font-weight: 800; color: var(--navy); }
  .fold-section-note { display: block; font-weight: 500; color: var(--muted); font-size: 0.8rem; margin-top: 2px; }
  .fold-section-count { font-weight: 800; color: var(--navy); background: #eef6ea; border-radius: 999px; padding: 4px 10px; font-size: 0.82rem; }
  .fold-panel { border-top: 1px solid var(--border); padding: 10px; }
  .fold-rows { display: flex; flex-direction: column; gap: 8px; }
  .fold-row { border: 1px solid var(--border); border-radius: 12px; background: #fafbfc; }
  .fold-row[hidden] { display: none !important; }
  .fold-row > details > summary { list-style: none; cursor: pointer; display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 10px 12px; }
  .fold-row > details > summary::-webkit-details-marker { display: none; }
  .fold-who { min-width: 0; display: flex; flex-direction: column; }
  .fold-name { font-weight: 750; font-weight: 700; color: var(--text); }
  .fold-email { color: var(--muted); font-size: 0.78rem; word-break: break-word; }
  .fold-pill { width: fit-content; border-radius: 999px; padding: 1px 7px; font-size: 0.65rem; font-weight: 800; letter-spacing: 0.04em; text-transform: uppercase; margin-bottom: 3px; }
  .fold-pill.is-staff, .fold-pill.is-superadmin { background: #e7f5ec; color: #166534; }
  .fold-pill.is-agent { background: #fff7ed; color: #9a3412; }
  .fold-pill.is-company { background: #e8f0fe; color: #1d4ed8; }
  .fold-meta { display: flex; align-items: center; gap: 6px; color: var(--muted); font-size: 0.78rem; font-weight: 700; flex: 0 0 auto; }
  .fold-num { font-size: 1.15rem; color: var(--navy); line-height: 1; }
  .fold-flag { color: #9a3412; }
  .fold-chevron { transition: transform 0.15s ease; }
  .fold-row details[open] .fold-chevron, .fold-section[open] > summary .fold-chevron { transform: rotate(180deg); }
  .fold-body { border-top: 1px solid var(--border); padding: 4px 12px 8px; background: #fff; border-radius: 0 0 12px 12px; }
  .fold-students { list-style: none; margin: 0; padding: 0; max-height: 240px; overflow: auto; }
  .fold-students li { display: grid; grid-template-columns: minmax(0, 1.4fr) minmax(0, 1.4fr) auto; gap: 8px; align-items: center; padding: 7px 0; border-bottom: 1px solid #f1f5f9; }
  .fold-student-name { font-weight: 700; color: var(--text); }
  .fold-student-email { color: var(--muted); font-size: 0.78rem; word-break: break-word; }
  .fold-status { justify-self: end; background: #f1f5f9; color: #334155; border-radius: 999px; padding: 2px 8px; font-size: 0.72rem; font-weight: 700; white-space: nowrap; }
  .fold-empty { margin: 8px; color: var(--muted); }
  .fold-sub { margin-top: 12px; background: #fff; border: 1px solid var(--border); border-radius: 12px; }
  .fold-sub > summary { list-style: none; cursor: pointer; display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 12px 14px; font-weight: 800; color: var(--navy); }
  .fold-sub > summary::-webkit-details-marker { display: none; }
  .fold-sub-body { border-top: 1px solid var(--border); padding: 12px; }
  .pipeline-summary { margin-top: 8px; background: #fff; border: 1px solid var(--border); border-radius: 14px; padding: 16px; box-shadow: var(--shadow-sm); }
  .pipeline-summary h3 { margin: 0 0 4px; font-size: 1.05rem; font-weight: 800; color: var(--navy); }
  .pipeline-summary p { margin: 0 0 12px; color: var(--muted); font-size: 0.84rem; }
  .pipeline-grid { display: grid; grid-template-columns: 1.1fr 1fr; gap: 16px; }
  @media (max-width: 900px) { .pipeline-grid { grid-template-columns: 1fr; } }
  .pipeline-box { background: #f8fafc; border: 1px solid var(--border); border-radius: 12px; padding: 12px; min-height: 260px; }
  .pipeline-box h4 { margin: 0 0 8px; font-size: 0.82rem; font-weight: 800; color: var(--muted); text-transform: uppercase; letter-spacing: 0.04em; }
  @media (max-width: 720px) {
    .fold-students li { grid-template-columns: 1fr; }
    .fold-status { justify-self: start; }
  }
</style>
<?php $rosterIsCompanyScope = is_array($rosterMemberIds); ?>
<section class="roster-board" aria-labelledby="rosterBoardTitle">
  <div class="roster-head">
    <h2 id="rosterBoardTitle"><?= $rosterIsCompanyScope ? 'Your company team' : 'Who is in charge' ?></h2>
    <p><?= $rosterIsCompanyScope
      ? 'Only this company’s staff, agents, and their students. Open a group, then a name, to see the students.'
      : 'Staff and agents appear when they have students in their charge. Companies are only accounts created as a company. Open a group, then a name, to see the students.' ?></p>
  </div>
  <div class="roster-kpis">
    <button type="button" class="roster-kpi" data-open="fold-staff"><span>Staff in charge</span><strong><?= (int) $roster['staff_count'] ?></strong><em><?= (int) $roster['staff_students'] ?> students</em></button>
    <button type="button" class="roster-kpi" data-open="fold-agents"><span>Agents</span><strong><?= (int) ($roster['agent_role_count'] ?? 0) ?></strong><em><?= (int) $roster['agent_students'] ?> students in charge</em></button>
    <?php if (!$rosterIsCompanyScope): ?>
    <button type="button" class="roster-kpi" data-open="fold-companies"><span>Companies</span><strong><?= (int) $roster['company_count'] ?></strong><em><?= (int) $roster['company_students'] ?> students</em></button>
    <?php endif; ?>
    <button type="button" class="roster-kpi" data-open="fold-staff"><span>Students in charge</span><strong><?= (int) $roster['students_in_charge'] ?></strong><em>Assigned to a staff member or agent</em></button>
  </div>
  <input id="rosterSearch" class="roster-search" type="search" placeholder="Search a name or a student" aria-label="Search staff, agents, companies, and students">

  <details class="fold-section" id="fold-staff">
    <summary>
      <span><span class="fold-section-title">Staff</span><span class="fold-section-note">Only staff who have students in charge</span></span>
      <span class="fold-section-count"><?= (int) $roster['staff_count'] ?></span>
    </summary>
    <div class="fold-panel"><?= $renderRows($roster['staff'], 'staff') ?></div>
  </details>

  <details class="fold-section" id="fold-agents">
    <summary>
      <span><span class="fold-section-title">Agents</span><span class="fold-section-note">Accounts whose role is agent</span></span>
      <span class="fold-section-count"><?= (int) ($roster['agent_role_count'] ?? 0) ?></span>
    </summary>
    <div class="fold-panel">
      <?= $renderRows($roster['agents'], 'agents') ?>
      <?php if (!empty($agentsCombined) && is_array($agentsCombined)): ?>
      <details class="fold-sub" id="fold-agent-counts">
        <summary>Recruiting counts <span class="fold-section-count"><?= count($agentsCombined) ?></span></summary>
        <div class="fold-sub-body">
          <?php if (!$rosterIsCompanyScope): ?>
          <div class="border rounded-3 p-3 mb-3 bg-white">
            <div class="fw-semibold text-dark mb-1"><i class="bi bi-arrow-left-right me-1"></i> Change recruiting agent</div>
            <p class="small text-muted mb-2">Search an applicant, pick a new agent, then save.</p>
            <label for="pcvcStudentAgentSearch" class="form-label small mb-1">Search student</label>
            <input type="search" id="pcvcStudentAgentSearch" class="form-control form-control-sm" maxlength="140" placeholder="Type at least 2 characters…" autocomplete="off">
            <div id="pcvcStudentAgentSearchResults" class="mt-2"></div>
          </div>
          <?php endif; ?>
          <div style="overflow-x: auto;">
            <table id="agentTable" class="display compact stripe" style="width: 100%;">
              <thead>
                <tr>
                  <th>#</th>
                  <th>Agent Name</th>
                  <th>Email</th>
                  <th>Total Students</th>
                  <th>Submitted</th>
                  <th>Admit</th>
                  <th>Visa Approved</th>
                  <th>Enrolled</th>
                </tr>
              </thead>
              <tbody>
                <?php
                $i = 1;
                foreach ($agentsCombined as $agent) {
                    $agentName = htmlspecialchars((string) ($agent['name'] ?? ''), ENT_QUOTES, 'UTF-8');
                    $agentEmailRaw = (string) ($agent['email'] ?? '');
                    $agentEmailEsc = htmlspecialchars($agentEmailRaw, ENT_QUOTES, 'UTF-8');
                    $agentNameAttr = htmlspecialchars((string) ($agent['name'] ?? ''), ENT_QUOTES, 'UTF-8');
                    $countBtn = static function (string $pipeline, string $label, int $count) use ($agentEmailEsc, $agentNameAttr): string {
                        return '<button type="button" class="agent-count-link js-agent-students" title="View ' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . ' students" data-pipeline="' . $pipeline . '" data-label="' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '" data-agent-email="' . $agentEmailEsc . '" data-agent-name="' . $agentNameAttr . '">' . $count . '</button>';
                    };
                    echo '<tr>
                    <td>' . (int) $i . '</td>
                    <td><button type="button" class="btn btn-link p-0 m-0 align-baseline text-start text-decoration-none fw-semibold js-agent-students" title="View students allocated to this agent" data-pipeline="allocated" data-label="Allocated" data-agent-email="' . $agentEmailEsc . '" data-agent-name="' . $agentNameAttr . '">' . $agentName . '</button></td>
                    <td>' . $agentEmailEsc . '</td>
                    <td>' . $countBtn('allocated', 'Allocated', (int) ($agent['total'] ?? 0)) . '</td>
                    <td>' . $countBtn('submitted', 'Submitted', (int) ($agent['submitted'] ?? 0)) . '</td>
                    <td>' . $countBtn('admit', 'Admitted', (int) ($agent['admit'] ?? 0)) . '</td>
                    <td>' . $countBtn('visa_approved', 'Visa approved', (int) ($agent['visa_approved'] ?? 0)) . '</td>
                    <td>' . $countBtn('enrolled', 'Enrolled', (int) ($agent['enrolled'] ?? 0)) . '</td>
                  </tr>';
                    $i++;
                }
                ?>
              </tbody>
            </table>
          </div>
          <script>
          window.pcvcAgentPickerOptions = <?= json_encode(array_values(array_map(static function ($row) {
              return [
                  'email' => (string) ($row['email'] ?? ''),
                  'name' => (string) ($row['name'] ?? ''),
              ];
          }, $agentsCombined)), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS) ?>;
          </script>
        </div>
      </details>
      <?php endif; ?>
    </div>
  </details>

  <?php if (!$rosterIsCompanyScope): ?>
  <details class="fold-section" id="fold-companies">
    <summary>
      <span><span class="fold-section-title">Companies</span><span class="fold-section-note">Accounts created as a company</span></span>
      <span class="fold-section-count"><?= (int) $roster['company_count'] ?></span>
    </summary>
    <div class="fold-panel"><?= $renderRows($roster['companies'], 'companies') ?></div>
  </details>
  <?php endif; ?>
  <?php
    $pipe = ['Allocated' => 0, 'Submitted' => 0, 'Admitted' => 0, 'Visa approved' => 0, 'Enrolled' => 0];
    $ranked = [];
    foreach ($agentsCombined ?? [] as $agent) {
        $total = (int) ($agent['total'] ?? 0);
        $pipe['Allocated'] += $total;
        $pipe['Submitted'] += (int) ($agent['submitted'] ?? 0);
        $pipe['Admitted'] += (int) ($agent['admit'] ?? 0);
        $pipe['Visa approved'] += (int) ($agent['visa_approved'] ?? 0);
        $pipe['Enrolled'] += (int) ($agent['enrolled'] ?? 0);
        if ($total > 0) {
            $ranked[] = ['name' => (string) ($agent['name'] ?? 'Account'), 'total' => $total];
        }
    }
    usort($ranked, static function (array $a, array $b): int {
        return $b['total'] <=> $a['total'];
    });
    $ranked = array_slice($ranked, 0, 8);
    $topLabels = array_column($ranked, 'name');
    $topData = array_column($ranked, 'total');
  ?>
  <section class="pipeline-summary" aria-label="Pipeline summary">
    <h3>Pipeline summary</h3>
    <p>These totals follow the same stages as the agent counts. Open Agents, then Recruiting counts, for the full list.</p>
    <div class="pipeline-grid">
      <div class="pipeline-box">
        <h4>Students by stage</h4>
        <canvas id="pipelineStageChart" height="220"></canvas>
      </div>
      <div class="pipeline-box">
        <h4>Largest accounts</h4>
        <canvas id="pipelineTopChart" height="220"></canvas>
      </div>
    </div>
  </section>
  <script>
  window.pcvcPipelineSummary = <?= json_encode([
      'stages' => array_keys($pipe),
      'stageValues' => array_values($pipe),
      'topLabels' => $topLabels,
      'topValues' => $topData,
  ], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS) ?>;
  </script>
</section>
<script>
(function () {
  const search = document.getElementById('rosterSearch');
  function openGroup(id) {
    const section = document.getElementById(id);
    if (!section) return;
    section.open = true;
    section.scrollIntoView({ behavior: 'smooth', block: 'start' });
  }
  document.querySelectorAll('.roster-kpi[data-open]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      document.querySelectorAll('.roster-kpi').forEach(function (el) { el.classList.remove('is-on'); });
      btn.classList.add('is-on');
      openGroup(btn.getAttribute('data-open'));
    });
  });
  document.addEventListener('DOMContentLoaded', function () {
    const summary = window.pcvcPipelineSummary || {};
    if (typeof Chart === 'undefined') return;
    const stage = document.getElementById('pipelineStageChart');
    if (stage) {
      new Chart(stage, {
        type: 'bar',
        data: {
          labels: summary.stages || [],
          datasets: [{
            data: summary.stageValues || [],
            backgroundColor: ['#427431', '#3661b9', '#0ea5e9', '#d97706', '#0d9f6e'],
            borderRadius: 8,
            borderSkipped: false
          }]
        },
        options: {
          responsive: true,
          plugins: { legend: { display: false } },
          scales: { y: { beginAtZero: true, ticks: { precision: 0 } }, x: { grid: { display: false } } }
        }
      });
    }
    const top = document.getElementById('pipelineTopChart');
    if (top) {
      new Chart(top, {
        type: 'bar',
        data: {
          labels: summary.topLabels || [],
          datasets: [{ data: summary.topValues || [], backgroundColor: '#3661b9', borderRadius: 6, borderSkipped: false }]
        },
        options: {
          indexAxis: 'y',
          responsive: true,
          plugins: { legend: { display: false } },
          scales: { x: { beginAtZero: true, ticks: { precision: 0 } }, y: { grid: { display: false } } }
        }
      });
    }
  });
  function adjustAgentTable() {
    if (!window.jQuery || !jQuery.fn.dataTable || !jQuery.fn.dataTable.isDataTable('#agentTable')) return;
    jQuery('#agentTable').DataTable().columns.adjust();
  }
  ['fold-agents', 'fold-agent-counts'].forEach(function (id) {
    const fold = document.getElementById(id);
    if (!fold) return;
    fold.addEventListener('toggle', function () {
      if (fold.open) setTimeout(adjustAgentTable, 60);
    });
  });
  if (!search) return;
  search.addEventListener('input', function () {
    const q = search.value.trim().toLowerCase();
    document.querySelectorAll('[data-roster-row]').forEach(function (row) {
      const hay = (row.getAttribute('data-search') || '').toLowerCase();
      row.hidden = q !== '' && hay.indexOf(q) === -1;
    });
    document.querySelectorAll('.fold-section').forEach(function (section) {
      const visible = section.querySelector('[data-roster-row]:not([hidden])');
      section.open = q !== '' && !!visible;
    });
  });
})();
</script>
