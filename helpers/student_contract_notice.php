<?php
declare(strict_types=1);

function pcvc_student_contract_documents_notice(): string
{
    return <<<'HTML'
<div class="pcvc-doc-notice">
  <h3>What You Need to Provide:</h3>
  <ul>
    <li><span>&#10003;</span> Your high school Report or Bachelor Transcripts</li>
    <li><span>&#10003;</span> Your high school diploma or Bachelor's Degree</li>
    <li><span>&#10003;</span> A Valid Passport</li>
    <li><span>&#10003;</span> A Strong Personal Statement</li>
    <li><span>&#10003;</span> An Updated CV</li>
    <li><span>&#10003;</span> English Certificate <em>(One from University is accepted)</em></li>
  </ul>
  <p class="pcvc-doc-contact">&#9993; <em>Contact us today!</em> <a href="mailto:infos@visaconsultantcanada.ca">infos@visaconsultantcanada.ca</a></p>
  <p class="pcvc-doc-journey">&#9992; <em>Your Study Abroad Journey Starts NOW!</em> &#9992;</p>
  <div class="pcvc-doc-banner">Providing Best Study and Travel Abroad Services, Today a Reader, Tomorrow a Leader</div>
</div>
<style>
  .pcvc-doc-notice { margin-top: 28px; color: #111; page-break-inside: avoid; }
  .pcvc-doc-notice h3 { margin: 0 0 10px; color: #1b7a3a; font-size: 16pt; font-weight: 700; }
  .pcvc-doc-notice ul { list-style: none; margin: 0 0 12px; padding: 0; }
  .pcvc-doc-notice li { margin: 3px 0; font-size: 12pt; }
  .pcvc-doc-notice li span { color: #1b7a3a; font-weight: 700; margin-right: 6px; }
  .pcvc-doc-contact { margin: 8px 0 4px; color: #1b7a3a; font-size: 12pt; }
  .pcvc-doc-contact a { color: #1a4db3; }
  .pcvc-doc-journey { margin: 4px 0 14px; font-size: 12pt; }
  .pcvc-doc-banner {
    background: #00a3e0;
    color: #ffffff;
    text-align: center;
    font-style: italic;
    font-size: 13pt;
    padding: 10px 14px;
  }
</style>
HTML;
}
