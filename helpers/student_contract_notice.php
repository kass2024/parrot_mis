<?php
declare(strict_types=1);

/**
 * Required documents notice shown before student contract signatures.
 */
function pcvc_student_contract_documents_notice(string $lang = 'en'): string
{
    $lang = strtolower(trim($lang));
    if ($lang === 'fr') {
        $title = 'Documents requis :';
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
    } else {
        $title = 'What You Need to Provide:';
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
    }

    $lis = '';
    foreach ($items as $item) {
        $lis .= '<li><span class="pcvc-doc-check">&#10004;&#10004;</span> ' . $item . '</li>';
    }

    return <<<HTML
<div class="contract-section pcvc-doc-notice" id="requiredDocuments">
  <h3><span class="pcvc-doc-icon" aria-hidden="true">&#128220;</span> {$title}</h3>
  <ul>
    {$lis}
  </ul>
  <p class="pcvc-doc-contact">&#9993; <em>{$contactLead}</em> <a href="mailto:infos@visaconsultantcanada.ca">infos@visaconsultantcanada.ca</a></p>
  <p class="pcvc-doc-journey">&#127760; <em>{$journey}</em> &#127891; &#9992;</p>
  <div class="pcvc-doc-banner">{$banner}</div>
</div>
<style>
  .pcvc-doc-notice {
    margin: 28px 0 8px;
    padding: 18px 18px 16px;
    border: 1px solid #dbe7df;
    border-radius: 14px;
    background: linear-gradient(180deg, #f7fbf8 0%, #ffffff 70%);
    color: #111;
    page-break-inside: avoid;
  }
  .pcvc-doc-notice h3 {
    margin: 0 0 12px;
    color: #1b7a3a;
    font-size: 1.15rem;
    font-weight: 750;
    display: flex;
    align-items: center;
    gap: .45rem;
  }
  .pcvc-doc-icon { font-size: 1.2rem; }
  .pcvc-doc-notice ul { list-style: none; margin: 0 0 14px; padding: 0; }
  .pcvc-doc-notice li {
    margin: .45rem 0;
    font-size: 1rem;
    line-height: 1.4;
    display: flex;
    gap: .45rem;
    align-items: flex-start;
  }
  .pcvc-doc-check {
    color: #1b7a3a;
    font-weight: 800;
    flex: 0 0 auto;
    letter-spacing: -.08em;
  }
  .pcvc-doc-contact { margin: 10px 0 6px; color: #1b7a3a; font-size: .98rem; }
  .pcvc-doc-contact a { color: #1a4db3; font-weight: 600; }
  .pcvc-doc-journey { margin: 4px 0 14px; font-size: .98rem; }
  .pcvc-doc-banner {
    background: #00a3e0;
    color: #ffffff;
    text-align: center;
    font-style: italic;
    font-size: .98rem;
    padding: 10px 14px;
    border-radius: 8px;
  }
  @media print {
    .pcvc-doc-notice { box-shadow: none; }
  }
</style>
HTML;
}
