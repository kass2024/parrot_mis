<?php
/* =====================================================
   SESSION & DB
===================================================== */
session_start();
require_once __DIR__ . '/db.php';
/* =====================================================
   AUTH CHECK
===================================================== */
if (!isset($_SESSION['id'], $_SESSION['role'])) {
    header("Location: admin-login.php");
    exit;
}

$admin_id = (int) $_SESSION['id'];

/* =====================================================
   GET JOB ID
===================================================== */
$jobId = isset($_GET['job_id']) ? (int)$_GET['job_id'] : 0;
$jobTitle = '';
$jobType  = '';
$jobStatus = '';

if ($jobId <= 0) {
    header("Location: job_todo_list.php");
    exit;
}

/* =====================================================
   LOAD JOB (ONLY OTHER JOB ALLOWED)
===================================================== */
$stmt = $conn->prepare("
    SELECT title, job_type, status
    FROM job_list
    WHERE id = ?
");
$stmt->bind_param("i", $jobId);
$stmt->execute();
$stmt->bind_result($jobTitle, $jobType, $jobStatus);
$stmt->fetch();
$stmt->close();

/* =====================================================
   VALIDATION
===================================================== */
if ($jobType !== 'Other Job') {
    // Prevent opening student jobs here
    header("Location: job_todo_list.php");
    exit;
}

if ($jobStatus === 'completed') {
    // Prevent re-editing completed jobs
    header("Location: job_todo_list.php");
    exit;
}

/* =====================================================
   SANITIZE OUTPUT
===================================================== */
$jobTitleSafe = htmlspecialchars($jobTitle, ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>🛠 Job Entry</title>

<style>
body{
  font-family: "Segoe UI", sans-serif;
  background:#f4f7fc;
  padding:20px;
  margin:0;
}
.container{
  max-width:650px;
  margin:auto;
  background:#fff;
  padding:25px;
  border-radius:10px;
  box-shadow:0 6px 18px rgba(0,0,0,.1);
}
h2{
  text-align:center;
  color:#2563eb;
}
label{
  display:block;
  margin-top:15px;
  font-weight:600;
}
input,textarea{
  width:100%;
  padding:10px;
  margin-top:6px;
  border-radius:6px;
  border:1px solid #ccc;
}
input[readonly]{
  background:#f2f2f2;
}
button{
  margin-top:22px;
  width:100%;
  padding:14px;
  border:none;
  border-radius:8px;
  background:#2563eb;
  color:#fff;
  font-weight:700;
  font-size:16px;
  cursor:pointer;
}
button:hover{
  background:#1e40af;
}
.back-btn{
  display:block;
  text-align:center;
  margin-top:20px;
  color:#2563eb;
  font-weight:600;
  text-decoration:none;
}
#ai_suggestions_display.loading{
  background:#fff8dc;
}
</style>
</head>

<body>
<div class="container">

<h2>🛠 Other Job Entry</h2>

<form action="submit-job.php" method="POST" id="jobForm">

  <!-- REQUIRED JOB ID -->
  <input type="hidden" name="job_id" value="<?= $jobId ?>">

  <!-- JOB TITLE (LOCKED) -->
  <label for="job_title">Job Title</label>
  <input type="text"
         name="job_title"
         id="job_title"
         value="<?= $jobTitleSafe ?>"
         readonly>

  <!-- DESCRIPTION -->
  <label for="job_description">Job Description</label>
  <textarea name="job_description"
            id="job_description"
            rows="4"
            required
            placeholder="Describe what was done..."></textarea>

  <!-- AI SUGGESTION -->
  <label for="ai_suggestions_display">💬 AI Suggestion</label>
  <textarea id="ai_suggestions_display"
            rows="2"
            readonly
            placeholder="AI will suggest improvements here..."></textarea>

  <input type="hidden" name="ai_suggestions" id="ai_suggestions">

  <!-- OPTIONAL TRACKING -->
  <input type="hidden" name="hours_spent" value="0">
  <input type="hidden" name="productivity_score" value="0">
  <input type="hidden" name="remarks" value="">

  <button type="submit">✅ Save & Complete Job</button>
</form>

<a href="job_todo_list.php" class="back-btn">⬅ Back to Job List</a>

</div>

<script>
document.addEventListener("DOMContentLoaded", function () {

  const titleInput = document.getElementById("job_title");
  const descInput  = document.getElementById("job_description");
  const box        = document.getElementById("ai_suggestions_display");
  const hidden     = document.getElementById("ai_suggestions");

  function fetchAISuggestion() {
    const title = titleInput.value.trim();
    const desc  = descInput.value.trim();

    if (!title || !desc) return;

    box.classList.add("loading");
    box.value = "⏳ Thinking...";

    fetch("get-suggestion.php", {
      method: "POST",
      headers: {"Content-Type":"application/x-www-form-urlencoded"},
      body: new URLSearchParams({
        job_title: title,
        job_description: desc
      })
    })
    .then(res => res.json())
    .then(data => {
      box.classList.remove("loading");
      if (data.suggestion) {
        box.value = data.suggestion;
        hidden.value = data.suggestion;
      } else {
        box.value = "⚠️ No suggestion received.";
        hidden.value = "";
      }
    })
    .catch(() => {
      box.classList.remove("loading");
      box.value = "⚠️ Error fetching AI suggestion.";
      hidden.value = "";
    });
  }

  descInput.addEventListener("blur", fetchAISuggestion);
});
</script>

</body>
</html>
