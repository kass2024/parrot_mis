<?php
declare(strict_types=1);

/**
 * Required documents notice shown before student contract signatures.
 *
 * @param string $lang 'en' | 'fr'
 * @param bool $interactive When true, include acknowledge checkbox + stronger live UI.
 */
function pcvc_student_contract_documents_notice(string $lang = 'en', bool $interactive = false): string
{
    $lang = strtolower(trim($lang));
    if ($lang === 'fr') {
        $title = 'Documents requis';
        $subtitle = 'À fournir avant / pendant le traitement du dossier';
        $items = [
            'Votre bulletin du secondaire ou relevé de notes de bachelor',
            'Votre diplôme du secondaire ou diplôme de bachelor',
            'Un passeport valide',
            'Une lettre de motivation solide',
            'Un CV à jour',
            'Certificat d’anglais <em>(un certificat universitaire est accepté)</em>',
        ];
        $contactLead = 'Contactez-nous dès aujourd’hui !';
        $journey = 'Votre aventure d’études à l’étranger commence MAINTENANT !';
        $banner = 'Meilleurs services d’études et de voyage à l’étranger — Aujourd’hui lecteur, demain leader';
        $ackLabel = 'Je confirme que je fournirai ces documents requis pour mon dossier.';
        $chip = 'Documents requis — lisez et confirmez avant de signer';
    } else {
        $title = 'What You Need to Provide';
        $subtitle = 'Required documents for your study-abroad file';
        $items = [
            'Your high school Report or Bachelor Transcripts',
            'Your high school diploma or Bachelor\'s Degree',
            'A Valid Passport',
            'A Strong Personal Statement',
            'An Updated CV',
            'English Certificate <em>(One from University is accepted)</em>',
        ];
        $contactLead = 'Contact us today!';
        $journey = 'Your Study Abroad Journey Starts NOW!';
        $banner = 'Providing Best Study and Travel Abroad Services, Today a Reader, Tomorrow a Leader';
        $ackLabel = 'I confirm I will provide these required documents for my application.';
        $chip = 'Required documents — review and confirm before signing';
    }

    $lis = '';
    $n = 1;
    foreach ($items as $item) {
        $lis .= '<li class="pcvc-doc-item" style="--i:' . $n . '">'
            . '<span class="pcvc-doc-num" aria-hidden="true">' . $n . '</span>'
            . '<span class="pcvc-doc-check" aria-hidden="true">&#10004;&#10004;</span>'
            . '<span class="pcvc-doc-text">' . $item . '</span>'
            . '</li>';
        $n++;
    }

    $interactiveHtml = '';
    if ($interactive) {
        $interactiveHtml = <<<HTML
  <div class="pcvc-doc-ack">
    <label class="pcvc-doc-ack-label">
      <input type="checkbox" id="pcvcDocsAck" name="pcvc_docs_ack" value="1">
      <span>{$ackLabel}</span>
    </label>
  </div>
  <div class="pcvc-doc-chip" id="pcvcDocsChip" hidden>{$chip}</div>
HTML;
    }

    $interactiveClass = $interactive ? ' is-interactive' : '';

    return <<<HTML
<div class="contract-section pcvc-doc-notice{$interactiveClass}" id="requiredDocuments" data-docs-required="1">
  <div class="pcvc-doc-header">
    <span class="pcvc-doc-icon" aria-hidden="true">&#128220;</span>
    <div>
      <h3>{$title}</h3>
      <p class="pcvc-doc-subtitle">{$subtitle}</p>
    </div>
  </div>
  <ul class="pcvc-doc-list">
    {$lis}
  </ul>
  <p class="pcvc-doc-contact">&#9993; <em>{$contactLead}</em> <a href="mailto:infos@visaconsultantcanada.ca">infos@visaconsultantcanada.ca</a></p>
  <p class="pcvc-doc-journey">&#127760; <em>{$journey}</em> &#127891; &#9992;</p>
  <div class="pcvc-doc-banner">{$banner}</div>
  {$interactiveHtml}
</div>
<style>
  .pcvc-doc-notice {
    margin: 28px auto 8px;
    max-width: 900px;
    padding: 22px 22px 18px;
    border: 2px solid #86efac;
    border-radius: 16px;
    background:
      radial-gradient(1200px 180px at 10% -20%, rgba(34, 197, 94, 0.18), transparent 60%),
      linear-gradient(180deg, #f0fdf4 0%, #ffffff 55%);
    color: #111;
    page-break-inside: avoid;
    box-shadow: 0 10px 28px rgba(22, 163, 74, 0.12);
    position: relative;
    overflow: hidden;
  }
  .pcvc-doc-notice::before {
    content: "";
    position: absolute;
    inset: 0 auto 0 0;
    width: 6px;
    background: linear-gradient(180deg, #16a34a, #00a3e0);
  }
  .pcvc-doc-notice.is-interactive.is-pulse {
    animation: pcvcDocPulse 1.1s ease-in-out 2;
    border-color: #16a34a;
    box-shadow: 0 0 0 4px rgba(34, 197, 94, 0.22), 0 12px 30px rgba(22, 163, 74, 0.18);
  }
  @keyframes pcvcDocPulse {
    0%, 100% { transform: translateY(0); }
    50% { transform: translateY(-2px); }
  }
  .pcvc-doc-header {
    display: flex;
    align-items: flex-start;
    gap: .7rem;
    margin-bottom: 14px;
  }
  .pcvc-doc-notice h3 {
    margin: 0;
    color: #14532d;
    font-size: 1.22rem;
    font-weight: 800;
    letter-spacing: .01em;
  }
  .pcvc-doc-subtitle {
    margin: 4px 0 0;
    color: #166534;
    font-size: .95rem;
    font-weight: 600;
  }
  .pcvc-doc-icon { font-size: 1.55rem; line-height: 1; }
  .pcvc-doc-list { list-style: none; margin: 0 0 14px; padding: 0; display: grid; gap: .55rem; }
  .pcvc-doc-item {
    margin: 0;
    font-size: 1rem;
    line-height: 1.45;
    display: grid;
    grid-template-columns: auto auto 1fr;
    gap: .55rem;
    align-items: start;
    padding: 10px 12px;
    border-radius: 10px;
    background: rgba(255,255,255,.78);
    border: 1px solid #dcfce7;
    animation: pcvcDocIn .45s ease both;
    animation-delay: calc(var(--i, 1) * 45ms);
  }
  @keyframes pcvcDocIn {
    from { opacity: 0; transform: translateY(6px); }
    to { opacity: 1; transform: none; }
  }
  .pcvc-doc-num {
    width: 22px;
    height: 22px;
    border-radius: 999px;
    background: #14532d;
    color: #fff;
    font-size: .78rem;
    font-weight: 800;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    flex: 0 0 auto;
    margin-top: 1px;
  }
  .pcvc-doc-check {
    color: #16a34a;
    font-weight: 800;
    flex: 0 0 auto;
    letter-spacing: -.08em;
    margin-top: 1px;
  }
  .pcvc-doc-text { min-width: 0; }
  .pcvc-doc-contact { margin: 10px 0 6px; color: #166534; font-size: .98rem; }
  .pcvc-doc-contact a { color: #1a4db3; font-weight: 700; text-decoration: underline; }
  .pcvc-doc-journey { margin: 4px 0 14px; font-size: .98rem; }
  .pcvc-doc-banner {
    background: linear-gradient(90deg, #0284c7, #00a3e0 55%, #0ea5e9);
    color: #ffffff;
    text-align: center;
    font-style: italic;
    font-size: .98rem;
    padding: 11px 14px;
    border-radius: 10px;
    font-weight: 600;
  }
  .pcvc-doc-ack {
    margin-top: 14px;
    padding: 12px 14px;
    border-radius: 12px;
    background: #fff7ed;
    border: 1px solid #fdba74;
  }
  .pcvc-doc-ack-label {
    display: flex;
    gap: 10px;
    align-items: flex-start;
    cursor: pointer;
    font-weight: 650;
    color: #9a3412;
    line-height: 1.4;
  }
  .pcvc-doc-ack-label input {
    width: 18px;
    height: 18px;
    margin-top: 2px;
    accent-color: #ea580c;
    flex: 0 0 auto;
  }
  .pcvc-doc-ack.is-ok {
    background: #ecfdf5;
    border-color: #86efac;
  }
  .pcvc-doc-ack.is-ok .pcvc-doc-ack-label { color: #14532d; }
  .pcvc-doc-chip {
    position: sticky;
    bottom: 12px;
    margin-top: 12px;
    z-index: 5;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 10px 14px;
    border-radius: 999px;
    background: #14532d;
    color: #fff;
    font-size: .9rem;
    font-weight: 700;
    box-shadow: 0 8px 20px rgba(20, 83, 45, 0.28);
  }
  @media print {
    .pcvc-doc-notice { box-shadow: none; animation: none !important; }
    .pcvc-doc-ack, .pcvc-doc-chip { display: none !important; }
    .pcvc-doc-item { animation: none; }
  }
  @media (max-width: 640px) {
    .pcvc-doc-notice { padding: 16px 14px 14px; margin-left: 12px; margin-right: 12px; }
    .pcvc-doc-item { grid-template-columns: auto 1fr; }
    .pcvc-doc-check { display: none; }
  }
</style>
HTML;
}
