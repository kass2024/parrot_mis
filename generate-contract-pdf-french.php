<?php
declare(strict_types=1);

use Dompdf\Dompdf;
use Dompdf\Options;
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/helpers/contract_price_highlight.php';
require_once __DIR__ . '/helpers/contract_signature_image.php';
require_once __DIR__ . '/helpers/country_flag.php';
require_once __DIR__ . '/helpers/student_contract_notice.php';

/* =====================================================
   SAFE ESCAPE
===================================================== */
function esc(?string $v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function contractPdfPlainText(string $text): string
{
    $text = preg_replace('/[\x{1F300}-\x{1FAFF}\x{2600}-\x{26FF}\x{2700}-\x{27BF}]/u', '', $text) ?? $text;
    return trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
}

function contractPdfRenderFeeLine(string $line): string
{
    $parts = preg_split('/\s+—\s+/u', $line, 2) ?: [$line];
    $main = highlightContractPrices(trim($parts[0]));
    if (empty($parts[1])) {
        return $main;
    }

    return $main . '<span class="fee-desc">' . esc(trim($parts[1])) . '</span>';
}

function contractPdfRenderPackageLines(array $lines): string
{
    $html = '';
    $notes = [];

    $flushNotes = static function () use (&$html, &$notes): void {
        if ($notes === []) {
            return;
        }

        $html .= '<li class="package-note-block"><strong>NOTE:</strong><ol>';
        foreach ($notes as $note) {
            $html .= '<li>' . contractPdfRenderFeeLine($note) . '</li>';
        }
        $html .= '</ol></li>';
        $notes = [];
    };

    foreach ($lines as $line) {
        $line = (string) $line;
        if (preg_match('/^\s*NOTE:\s*(.+)$/u', $line, $match)) {
            $notes[] = trim($match[1]);
            continue;
        }

        $flushNotes();
        $flagCode = pcvc_flag_code_for_text($line);
        $flag = $flagCode !== '' ? pcvc_country_flag($flagCode, true) : '';
        $html .= '<li>' . $flag . contractPdfRenderFeeLine($line) . '</li>';
    }

    $flushNotes();

    return $html;
}

/* =====================================================
   ARTICLE 7 – PACKAGE MAP (SINGLE SOURCE OF TRUTH)
===================================================== */
function getFrenchPackageDetails(string $code): array
{
    $packages = [

        /* =========================
           7.1 Partner Universities – Targeted Countries
        ========================== */
        'p70' => [
            'title' => '7.1 FRAIS PAYÉS PAR LES ÉTUDIANTS POUR NOS UNIVERSITÉS ET ENTREPRISES PARTENAIRES, ET POUR CERTAINS PAYS CIBLÉS',
            'lines' => [
                'États-Unis – Frais d’inscription et de dossier : 150 USD (payés lorsque l’offre d’admission est émise)',
                'Canada – Frais d’inscription et de dossier : 225 CAD (payés lorsque l’offre d’admission est émise)',
                'Europe – Frais d’inscription et de dossier : 150 USD (payés avant le début du dossier)',
                'Corée du Sud – Frais d’inscription et de dossier : 250 USD (payés avant le début du dossier)',
                'Note : l’étudiant assume tous les frais supplémentaires liés à la demande de visa et à l’immigration, y compris les frais des ambassades, des centres de demande de visa, des organismes publics, des établissements médicaux ou de toute autre autorité compétente.',
            ],
            'total' => null,
        ],

        /* =========================
           7.2 USA – Loan Based
        ========================== */
        'p71' => [
            'title' => '7.2 Études aux États-Unis (avec prêt)',
            'lines' => [
                'Frais d’inscription et de dossier : 150 USD (remboursables si l’admission n’est pas obtenue dans les 4 mois)',
                'Après l’approbation du prêt : 1 200 USD',
                'Frais de préparation à l’entrevue simulée : 150 USD',
                'Après l’approbation du visa : 1 500 USD',
            ],
            'total' => '3 000 USD',
        ],

        /* =========================
           7.2 USA – Without Loan
        ========================== */
        'p72' => [
            'title' => '7.2 Études aux États-Unis (sans prêt)',
            'lines' => [
                'Frais d’inscription et de dossier : 150 USD (remboursables si l’admission n’est pas obtenue dans les 4 mois)',
                'Frais de préparation à l’entrevue simulée : 150 USD',
                'Après l’approbation du visa : 2 000 USD',
            ],
            'total' => '2 300 USD',
        ],

        /* =========================
           7.3 Europe – Without Loan
        ========================== */
        'p73' => [
            'title' => '7.3 Études en Europe (sans prêt)',
            'lines' => [
                'Frais d’inscription et de dossier : EUR 250 (remboursables si l’admission n’est pas obtenue dans les 4 mois)',
                'Avant la demande de visa : EUR 250',
                'Après l’approbation du visa : EUR 1,500',
            ],
            'total' => 'EUR 2,000',
        ],

        /* =========================
           7.4 Canada – Loan Based
        ========================== */
        'p74' => [
            'title' => '7.4 Études au Canada (avec prêt)',
            'lines' => [
                'Frais d’inscription et de dossier : 450 CAD (remboursables si l’admission n’est pas obtenue dans les 4 mois)',
                'Après l’approbation du visa : 3 050 CAD',
                'NOTE: Acompte de scolarité : 500 à 5 000 CAD, payable directement par l’étudiant.',
                'NOTE: Chaque personne à charge paie 1 000 CAD après l’approbation du visa.',
            ],
            'total' => '3 500 CAD',
        ],

        /* =========================
           7.5 Canada – Without Loan
        ========================== */
        'p75' => [
            'title' => '7.5 Études au Canada (sans prêt)',
            'lines' => [
                'Frais d’inscription et de dossier : 450 CAD (remboursables si l’admission n’est pas obtenue dans les 4 mois)',
                'Après l’approbation du visa : 2 050 CAD',
                'NOTE: Acompte de scolarité : 500 à 5 000 CAD, payable directement par l’étudiant.',
                'NOTE: Chaque personne à charge paie 1 000 CAD après l’approbation du visa.',
            ],
            'total' => '2 500 CAD',
        ],

        /* =========================
           7.6 Canada – High School (Loan)
        ========================== */
        'p76' => [
            'title' => '7.6 Canada – Diplômé du secondaire (avec prêt)',
            'lines' => [
                'Frais d’inscription et de dossier : CAD 450',
                'Frais de permis d’études (ambassade) : 150 CAD',
                'Frais de biométrie (ambassade) : 85 CAD',
                'Frais de CAQ (Québec seulement) : 132 CAD',
                'Frais de laissez-passer frontalier (avocat) : 250 CAD',
                'Frais de traitement du prêt : 1 000 CAD',
                'Frais de service après l’approbation du visa : 1 933 CAD',
                'NOTE: Acompte de scolarité : 500 à 5 000 CAD, payable directement par l’étudiant.',
                'NOTE: Chaque personne à charge paie 1 000 CAD après l’approbation du visa.',
            ],
            'total' => '4 000 CAD',
        ],

        /* =========================
           7.7 Canada – LOA Without Lawyer
        ========================== */
        'p77loa' => [
            'title' => '7.7 Études au Canada avec lettre d’admission (sans consultation d’avocat)',
            'lines' => [
                'Frais d’inscription au système : 225 CAD',
                'Frais de préparation des documents : 375 CAD',
                'Frais initiaux : 400 CAD',
                'Après l’approbation du visa : 1 000 CAD',
            ],
            'total' => '2 000 CAD',
        ],

        /* =========================
           7.8 Canada – LOA With Lawyer
        ========================== */
        'p77loalawyer' => [
            'title' => '7.8 Études au Canada avec lettre d’admission (avec consultation d’avocat)',
            'lines' => [
                'Frais d’inscription au système : 225 CAD',
                'Frais de préparation des documents : 375 CAD',
                'Frais de consultation d’avocat : 500 CAD',
                'Frais initiaux : 400 CAD',
                'Après l’approbation du visa : 1 000 CAD',
            ],
            'total' => '2 500 CAD',
        ],

        /* =========================
           7.9 South Korea – Study
        ========================== */
        'p77' => [
            'title' => '7.9 Études en Corée du Sud (autofinancées)',
            'lines' => [
                'Frais d’inscription et de suivi du dossier : 500 USD (à payer avant le début de l’admission ; remboursables si la lettre d’admission n’est pas obtenue)',
                'Frais de service autofinancés – baccalauréat : 2 000 USD (formation gratuite de coréen pendant 3 mois et orientation avant le départ)',
                'Frais de service autofinancés – maîtrise : 2 400 USD (formation gratuite de coréen pendant 3 mois et orientation avant le départ)',
                'Frais de service autofinancés – doctorat : 2 800 USD (formation gratuite de coréen pendant 3 mois et orientation avant le départ)',
                'Lorsque la lettre d’acceptation finale est approuvée, la moitié (1/2) des frais de service applicables doit être payée avant la demande de visa',
                'Une formation gratuite de coréen (3 mois) et une orientation avant le départ sont offertes au besoin',
            ],
            'total' => null,
        ],

        /* =========================
           7.10 South Korea – Visit
        ========================== */
        'p78' => [
            'title' => '7.10 🇰🇷 Visa de visiteur pour la Corée du Sud',
            'lines' => [
                'Frais d’inscription et de dossier : USD 500',
                'Frais de service (payés après réception de la lettre d’invitation et de la lettre de garantie) : 1 500 USD',
                'Les frais de participation varient selon l’organisateur de l’événement.',
            ],
            'total' => null,
        ],

        /* =========================
           7.11 Credit Transfer
        ========================== */
        'p79' => [
            'title' => '7.11 Transfert de crédits (baccalauréat, maîtrise, doctorat)',
            'lines' => [
                'Programme de baccalauréat : 920 USD',
                'Programme de maîtrise : 1 220 USD',
                'Programme de doctorat : 1 620 USD',
            ],
            'total' => null,
        ],

        /* =========================
           7.12 Visa de visiteur pour le Canada
        ========================== */
        'p710' => [
            'title' => '7.12 Visa de visiteur pour le Canada',
            'lines' => [
                'Documents et lettre d’invitation : 1 000 USD',
                'Frais de demande de visa : 100 CAD',
                'Frais de biométrie : 85 CAD',
                'Frais de service (après l’approbation du visa) : 2 000 CAD',
            ],
            'total' => null,
        ],

        /* =========================
           7.13 Visa de visiteur pour le Canada – avec lettre d’invitation
        ========================== */
        'p710b' => [
            'title' => '7.13 Visa de visiteur pour le Canada – avec lettre d’invitation',
            'lines' => [
                'Lettre d’invitation : déjà fournie par le demandeur',
                'Préparation des documents et vérification de la demande de visa : 815 CAD',
                'Frais de demande de visa : 100 CAD',
                'Frais de biométrie : 85 CAD',
                'Frais de service (après l’approbation du visa) : 2 000 CAD',
            ],
            'total' => '3 000 CAD',
            'total_label' => 'Coût total',
        ],

        /* =========================
           7.14 Visiter le Canada avec invitation, tarif promotionnel
        ========================== */
        'p710promo' => [
            'title' => '7.14 Visiter le Canada avec invitation, tarif promotionnel',
            'lines' => [
                'Frais de lettre d’invitation : 360 CAD',
                'Frais de préparation des documents : 240 CAD',
                'Frais de consultation d’avocat : 400 CAD',
                'Service Fees Après l’approbation du visa : 1 000 CAD',
            ],
            'total' => '2 000 CAD',
        ],

        /* =========================
           7.15 Visa de visiteur pour les États-Unis
        ========================== */
        'p711' => [
            'title' => '7.15 Visa de visiteur pour les États-Unis',
            'lines' => [
                'Documents et lettre d’invitation : 1 000 USD',
                'Frais de demande de visa : 185 USD',
                'Frais de service (après l’approbation du visa) : 1 500 USD',
            ],
            'total' => null,
        ],

        /* =========================
           7.16 Visa de visiteur pour l’Europe
        ========================== */
        'p712' => [
            'title' => '7.16 Visa de visiteur pour l’Europe',
            'lines' => [
                'Documents et lettre d’invitation : 600 €',
                'Frais de demande de visa : 85 à 500 € (selon le pays)',
                'Frais de service (après l’approbation du visa) : 1 000 €',
            ],
            'total' => null,
        ],

        /* =========================
           7.17 Visa de visiteur pour l’Asie
        ========================== */
        'p713' => [
            'title' => '7.17 Visa de visiteur pour l’Asie',
            'lines' => [
                'Documents et lettre d’invitation : 800 USD',
                'Frais de demande de visa : 85 à 500 USD',
                'Frais de service (après l’approbation du visa) : 1 500 USD',
            ],
            'total' => null,
        ],

        /* =========================
           7.18 Short Courses - Canada
        ========================== */
        'p714' => [
            'title' => '7.18 COURS DE COURTE DURÉE – CANADA',
            'lines' => [
                'Frais d’inscription et de dossier : 450 CAD (remboursables si l’admission n’est pas obtenue dans les 2 semaines)',
                'Frais d’inscription et de dossier pour un membre de la famille : 200 CAD (s’il y a lieu)',
                'Acompte de scolarité après la lettre d’offre : 535 CAD (payé directement à l’école)',
                'Avant la demande de visa : 100 CAD pour la demande de visa de visiteur (payés à l’ambassade)',
                'Biométrie : 85 CAD (payée à l’ambassade)',
                'Après l’approbation du visa : 2 500 CAD',
                'NOTE: Acompte de scolarité : 500 à 5 000 CAD, payable directement par l’étudiant.',
                'NOTE: Chaque personne à charge paie 1 000 CAD après l’approbation du visa.',
            ],
            'total' => '3 670 CAD',
        ],

        /* =========================
           7.19 Study PhD in Multiple Destinations
        ========================== */
        'p715' => [
            'title' => '7.19 DOCTORAT AU CANADA, AUX ÉTATS-UNIS, EN EUROPE ET EN ASIE',
            'lines' => [
                'Frais d’inscription et de dossier pour le Canada : 500 CAD (remboursables si l’admission n’est pas obtenue dans les 9 mois)',
                'Frais d’inscription et de dossier pour les États-Unis, l’Europe et l’Asie : 350 USD (remboursables si l’admission n’est pas obtenue dans les 9 mois)',
                'Frais de publication d’article (non remboursables) : 280 USD (s’il y a lieu)',
                'Aide à la rédaction de la proposition de recherche de doctorat (non remboursable) : 300 USD (s’il y a lieu)',
                'Frais de demande de visa pour un membre de la famille (non remboursables) : Canada 400 CAD (s’il y a lieu)',
                'Frais de demande de visa pour un membre de la famille (non remboursables) : États-Unis, Europe et Asie 250 USD (s’il y a lieu)',
                'Acompte de scolarité après la lettre d’offre pour le Canada : 1 000 à 5 000 CAD (payé directement à l’école après l’admission)',
                'Acompte de scolarité après la lettre d’offre pour les États-Unis, l’Europe et l’Asie : 500 à 5 000 USD (payé directement à l’école après l’admission)',
                'Après l’approbation du visa pour le Canada : 5 000 CAD',
                'Après l’approbation du visa pour les États-Unis, l’Europe et l’Asie : 4 500 USD',
                'NOTE: Acompte de scolarité : 500 à 5 000 CAD, payable directement par l’étudiant.',
                'NOTE: Chaque personne à charge paie 1 000 CAD après l’approbation du visa.',
            ],
            'total' => null,
        ],

        /* =========================
           7.20 WES Evaluation – International Equivalence
        ========================== */
        'p716' => [
            'title' => '7.20 ÉVALUATION WES – ÉQUIVALENCE INTERNATIONALE',
            'lines' => [
                '1. Honoraires professionnels : 200 CAD — Ces honoraires comprennent la consultation, l’orientation, l’aide à la préparation des documents et un suivi personnalisé pendant toute l’évaluation WES.',
                '2. Frais de dossier et de traitement : 300 CAD — Ce montant couvre les dépenses liées au dossier, les échanges avec les établissements, le traitement des documents et le suivi pendant l’évaluation.',
                '3. Coordination avec l’université et vérification : 100 CAD — Ce service consiste à contacter les universités, les registraires et les bureaux autorisés afin que les relevés de notes soient vérifiés et transmis à WES.',
                '4. Frais d’expédition des documents : 100 CAD — Ce coût comprend le courrier, l’expédition, l’aide à la transmission électronique et le suivi, afin que les documents parviennent à WES à temps et en sécurité.',
                '5. Temps, travail administratif et suivi : 200 CAD — Un temps et un travail administratif importants sont nécessaires pour suivre les envois, corriger les problèmes, répondre aux mises à jour et accompagner le demandeur jusqu’à la fin de l’évaluation.',
            ],
            'total' => '900 CAD',
        ],

        /* =========================
           7.21 Guaranteed Evaluation Support
        ========================== */
        'p717' => [
            'title' => '7.21 ACCOMPAGNEMENT GARANTI POUR L’ÉVALUATION',
            'lines' => [
                '1. Honoraires professionnels : 200 CAD — Ces honoraires comprennent la consultation, l’orientation, l’aide à la préparation des documents et un suivi personnalisé pendant toute l’évaluation.',
                '2. Frais de dossier et de traitement : 300 CAD — Ce montant couvre les dépenses liées au dossier, les échanges avec les établissements, le traitement des documents et le suivi pendant l’évaluation.',
                '3. Coordination avec l’université et vérification : 100 CAD — Ce service consiste à contacter les universités, les registraires et les bureaux autorisés afin que les relevés de notes soient vérifiés et transmis.',
                '4. Frais d’expédition des documents : 100 CAD — Ce coût comprend le courrier, l’expédition, l’aide à la transmission électronique et le suivi, afin que les documents parviennent à destination à temps et en sécurité.',
                '5. Temps, travail administratif et suivi : 200 CAD — Un temps et un travail administratif importants sont nécessaires pour suivre les envois, corriger les problèmes, répondre aux mises à jour et accompagner le demandeur jusqu’à la fin de l’évaluation.',
            ],
            'total' => '900 CAD',
            'total_label' => 'Total des formules',
        ],

        'p718' => [
            'title' => '7.22 Cours professionnels à Malte – pays européen de l’espace Schengen',
            'lines' => [
                'Frais d’inscription et de dossier : 250 EUR',
                'Après l’approbation du visa : 1 750 EUR',
                'Formule complète d’accompagnement visa PARROT : 2 000 EUR',
                'FORMULES DE FORMATION DISPONIBLES (14 SEMAINES CHACUNE)',
                'Programme souple : frais de scolarité 4 500 EUR et acompte initial 1 350 EUR',
                'Programme semi-intensif : frais de scolarité 6 900 EUR et acompte initial 2 070 EUR',
                'Programme intensif : frais de scolarité 10 900 EUR et acompte initial 3 270 EUR',
                'NOTE : Les frais de scolarité ci-dessus sont distincts de la formule de service PARROT de 2 000 EUR.',
                'NOTE : L’acompte initial de scolarité (30 %) est payable à la signature du contrat d’inscription de l’académie, avant la demande de visa.',
                'NOTE : Le solde de scolarité (70 %) est payable après la délivrance du visa et avant le début de la formation.',
                'NOTE : L’hébergement, le voyage, l’assurance, les frais de subsistance et les autres frais de tiers ne sont pas inclus.',
                'NOTE : L’approbation du visa n’est pas garantie.',
            ],
            'total' => '2 000 EUR',
            'total_label' => 'Formule complète d’accompagnement visa PARROT',
        ],
    ];

    return $packages[$code] ?? [];
}

/* =====================================================
   GENERATE FINAL SIGNED CONTRACT PDF
===================================================== */
function generateContractPDFFrench(int $contractId): string
{
    global $conn;

    /* =====================================================
       1. LOAD FULL CONTRACT + STUDENT + SIGNATURE
    ===================================================== */
$stmt = $conn->prepare("
    SELECT
        c.contract_token,
        c.selected_package_code,
        c.selected_package_label,

        COALESCE(
            NULLIF(TRIM(CONCAT_WS(' ',
                NULLIF(TRIM(s.first_name),  ''),
                NULLIF(TRIM(s.middle_name),  ''),
                NULLIF(TRIM(s.last_name),   '')
            )), ''),
            NULLIF(TRIM(sig.student_name), ''),
            'Student'
        ) AS full_name,

        COALESCE(NULLIF(TRIM(s.email), ''), NULLIF(TRIM(sig.student_email), '')) AS email,
        COALESCE(NULLIF(TRIM(s.dob), ''), '') AS dob,
        COALESCE(NULLIF(TRIM(nat.name), ''), NULLIF(TRIM(s.nationality), '')) AS nationality,
        COALESCE(NULLIF(TRIM(s.passport_number), ''), '') AS passport_number,
        COALESCE(NULLIF(TRIM(s.phone_number), ''), '') AS phone_number,

        sig.signed_date,
        sig.signature_image
    FROM student_contracts c
    INNER JOIN student_signatures sig ON sig.contract_id = c.id
    LEFT JOIN student_applications s ON s.id = c.student_id
    LEFT JOIN countries nat
           ON nat.id   = s.nationality
           OR nat.name = s.nationality
    WHERE c.id = ?
    LIMIT 1
");

    $stmt->bind_param('i', $contractId);
    if (!$stmt->execute()) {
        throw new RuntimeException('Failed to load contract data: ' . $stmt->error);
    }
    $data = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$data) {
        throw new RuntimeException('Contract not found.');
    }

    /* =====================================================
       2. SIGNATURE SOURCES
    ===================================================== */
    if (
        empty($data['signature_image']) ||
        !str_starts_with($data['signature_image'], 'data:image')
    ) {
        throw new RuntimeException('Invalid student signature.');
    }

    $studentSignature = $data['signature_image'];
    $studentSignatureFile = contract_signature_save_standard_png($contractId, $studentSignature);
    if ($studentSignatureFile !== null && is_file($studentSignatureFile)) {
        $studentSignatureSrc = $studentSignatureFile;
    } else {
        $normalizedPng = contract_signature_to_display_png($studentSignature);
        $studentSignatureSrc = $normalizedPng !== null
            ? 'data:image/png;base64,' . base64_encode($normalizedPng)
            : $studentSignature;
    }

    $managerSigPath = __DIR__ . '/admin/signature-manager.png';
    $companyStampPath = __DIR__ . '/admin/employer-signature.png';
    if (!file_exists($managerSigPath)) {
        throw new RuntimeException('Managing director signature missing.');
    }
    if (!file_exists($companyStampPath)) {
        throw new RuntimeException('Company stamp missing.');
    }

    $managerSignature =
        'data:image/png;base64,' . base64_encode(file_get_contents($managerSigPath));
    $companyStamp =
        'data:image/png;base64,' . base64_encode(file_get_contents($companyStampPath));

    /* =====================================================
       3. ARTICLE 7 – SELECTED PACKAGE
    ===================================================== */
    $package = getFrenchPackageDetails($data['selected_package_code']);
    if (!$package) {
        throw new RuntimeException('Selected package not defined.');
    }

    /* =====================================================
       4. BUILD HTML (ALL ARTICLES INCLUDED)
    ===================================================== */
    ob_start();
    ?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<style>
/* =========================
   PAGE SETUP (WORD A4)
========================= */
@page {
    size: A4;
    margin: 2.54cm 2.54cm 2.54cm 2.54cm; /* Word default margins */
}

/* =========================
   BASE BODY (WORD DEFAULT)
========================= */
body {
    font-family: "Times New Roman", Times, serif;
    font-size: 12pt;
    line-height: 1.6;
    color: #000;
}

/* =========================
   MAIN TITLE (WORD STYLE)
========================= */
h1 {
    text-align: center;
    font-size: 20pt;
    font-weight: bold;
    text-transform: uppercase;
    margin: 0 0 18pt 0;
}

/* =========================
   ARTICLE HEADINGS (1., 2., 3.)
========================= */
h2 {
    font-size: 14pt;
    font-weight: bold;
    text-transform: uppercase;
    margin: 22pt 0 10pt 0;
}

/* =========================
   SUB-HEADINGS (1.1, 1.2)
========================= */
h3 {
    font-size: 12pt;
    font-weight: bold;
    margin: 16pt 0 6pt 0;
}

/* =========================
   PARAGRAPHS (JUSTIFIED)
========================= */
p {
    text-align: justify;
    margin: 0 0 10pt 0;
}

/* =========================
   LISTS (WORD INDENTATION)
========================= */
ul, ol {
    margin: 0 0 12pt 32pt;
    padding: 0;
}

li {
    margin-bottom: 6pt;
}

/* =========================
   TABLES (SIGNATURE SECTION)
========================= */
table {
    width: 100%;
    border-collapse: collapse;
    margin-top: 18pt;
}

td {
    vertical-align: top;
    padding: 10pt;
    font-size: 11.5pt;
}

/* =========================
   SIGNATURE BOX (WORD STYLE)
========================= */
.signature-box {
    width: 7cm;
    height: 4cm;
    margin-top: 8pt;
    margin-bottom: 6pt;
    border-bottom: 1px solid #000;
}

.signature-box img {
    max-width: 100%;
    max-height: 100%;
    object-fit: contain;
}

.student-signature-line {
    border-bottom: 1px solid #000;
    height: 56px;
    width: 280px;
    position: relative;
    margin-top: 6pt;
    margin-bottom: 8pt;
}

.student-signature-line img {
    max-height: 52px;
    max-width: 270px;
    position: absolute;
    bottom: 2px;
    left: 0;
}

/* =========================
   FOOTER
========================= */
.footer {
    margin-top: 24pt;
    text-align: center;
    font-size: 10pt;
    color: #444;
}
/* =========================
   LINKS (EMAIL & WEBSITE)
========================= */
a {
    color: #0000EE;          /* Word default blue */
    text-decoration: underline;
}

/* =========================
   FEE AMOUNTS (PDF – subtle, inline only)
========================= */
.fee-price {
    font-weight: 700;
}

/* =========================
   SIGNATURES – keep on one page
========================= */
.signatures-section {
    page-break-inside: avoid;
    page-break-before: auto;
}

.signatures-section table,
.signatures-section tr,
.signatures-section td {
    page-break-inside: avoid;
}

.managing-director-block {
    margin-bottom: 18pt;
    page-break-inside: avoid;
}

.managing-director-block .manager-signature img {
    max-height: 52pt;
    max-width: 240pt;
}

.managing-director-block .company-stamp img {
    max-height: 110pt;
    max-width: 280pt;
}

.pricing-block {
    page-break-inside: avoid;
}

.selected-package-box {
    border: 1px solid #333;
    padding: 12pt 14pt;
    margin: 10pt 0 14pt 0;
    background: #fafafa;
    page-break-inside: avoid;
}

.selected-package-box .package-title {
    font-size: 12pt;
    font-weight: bold;
    margin: 0 0 10pt 0;
    line-height: 1.45;
    word-wrap: break-word;
}

.package-fees-list {
    margin: 0 0 10pt 22pt;
    padding: 0;
}

.package-fees-list li {
    margin-bottom: 8pt;
    text-align: justify;
    line-height: 1.55;
}

.package-fees-list .fee-desc {
    display: block;
    margin-top: 3pt;
    font-size: 11pt;
    font-weight: normal;
    color: #222;
}

.package-fees-list .package-note-block {
    list-style: none;
    margin: 10pt 0 0 -22pt;
    padding: 8pt 10pt;
    border-left: 4pt solid #2e7d32;
    background: #eef8f0;
    page-break-inside: avoid;
}

.package-note-block > strong {
    color: #1b5e20;
    letter-spacing: .03em;
}

.package-note-block ol {
    margin: 4pt 0 0 18pt;
    padding: 0;
}

.package-note-block li {
    margin-bottom: 2pt;
    line-height: 1.45;
}

.package-total {
    margin: 10pt 0 0 0;
    font-weight: bold;
}

</style>

</head>
<body>

<!-- =========================
     MAIN TITLE
========================= -->
<h1>
    ADMISSION D'ÉTUDIANTS INTERNATIONAUX<br>
    ET CONVENTION DE CONSEIL EN VISA
</h1>

<!-- INTRO TEXT -->
<p>
    La présente <strong>convention d’admission et de conseil en visa pour étudiants internationaux</strong>
    (la « <strong>Convention</strong> ») est conclue à la date de signature
    entre les parties suivantes :
</p>

<!-- DATE -->
<p>
    <strong>Date :</strong> <?= esc($data['signed_date']) ?>
</p>

<!-- =========================
     ARTICLE 1 – PARTIES
========================= -->
<h2>1. LES PARTIES</h2>

<!-- =========================
     1.1 CONSULTANT
========================= -->
<h3>1.1 Le consultant</h3>

<p>
    <strong>Parrot Canada Visa Consultant Company Ltd.</strong><br>
    Adresse du siège : Cellule Gasanze, Secteur Nduba, District de Gasabo, Kigali – Rwanda<br>
    Email:
    <a href="mailto:admission@visaconsultantcanada.com">admission@visaconsultantcanada.com</a>
    &amp;
    <a href="mailto:admission@visaconsultantcanada.com">admission@visaconsultantcanada.com</a><br>
    Website:
    <a href="https://www.visaconsultantcanada.com">www.visaconsultantcanada.com</a>
    &amp;
    <a href="https://www.visaconsultantcanada.com">www.visaconsultantcanada.com</a>
</p>

<!-- =========================
     1.2 STUDENT
========================= -->
<h3>1.2 L’étudiant</h3>

<p>
    <strong>Nom légal complet :</strong> <?= esc($data['full_name']) ?><br>
    <strong>Date de naissance :</strong> <?= esc($data['dob']) ?><br>
    <strong>Nationalité :</strong> <?= esc($data['nationality']) ?><br>
    <strong>Numéro de passeport :</strong> <?= esc($data['passport_number']) ?><br>
    <strong>Téléphone :</strong> <?= esc($data['phone_number']) ?><br>
    <strong>Courriel :</strong> <?= esc($data['email']) ?>
</p>

<p><em>(Ci-après dénommé <strong>« l’étudiant »</strong>)</em></p>

<!-- =========================
     ARTICLE 2 – PURPOSE OF AGREEMENT
========================= -->
<h2>2. OBJET DE LA CONVENTION</h2>

<p>
La présente convention régit la fourniture de
<strong>services d’admission aux études internationales, de conseil en visa et de services connexes</strong>
à l’étudiant qui souhaite étudier ou séjourner à l’étranger, notamment au
<strong>Canada, aux États-Unis d’Amérique, en Europe et en Corée du Sud</strong>.
L’étudiant reconnaît que
<strong>les décisions finales appartiennent uniquement aux établissements d’enseignement, aux ambassades,
aux autorités d’immigration et aux organismes tiers</strong>.
</p>

<!-- =========================
     ARTICLE 3 – SCOPE OF SERVICES
========================= -->
<h2>3. ÉTENDUE DES SERVICES</h2>

<p>
Le consultant fournit
<strong>l’orientation à l’admission, l’aide à la demande de visa, l’appui à la préparation des documents,
la préparation à l’entrevue (le cas échéant), l’orientation pour les prêts (formules avec prêt),
l’aide à la recherche d’emploi, l’aide à la recherche de logement et l’orientation avant le départ</strong>,
selon la formule choisie par l’étudiant dans la présente convention.
</p>

<!-- =========================
     ARTICLE 4 – CONSULTANT’S OBLIGATIONS
========================= -->
<h2>4. OBLIGATIONS DU CONSULTANT</h2>

<p>Le consultant s’engage à :</p>

<ol>
    <li>Fournir les services avec professionnalisme et bonne foi</li>
    <li>Suivre les directives officielles d’immigration et des établissements</li>
    <li>Garder confidentiels les renseignements de l’étudiant</li>
    <li>Communiquer l’avancement du dossier avec transparence</li>
    <li>Éviter toute falsification ou déclaration trompeuse</li>
</ol>

<!-- =========================
     ARTICLE 5 – STUDENT’S OBLIGATIONS
========================= -->
<h2>5. OBLIGATIONS DE L’ÉTUDIANT</h2>

<p>L’étudiant s’engage à :</p>

<ol>
    <li>Fournir des renseignements exacts, complets et véridiques</li>
    <li>Remettre des documents authentiques et vérifiables</li>
    <li>Payer tous les frais exigés à temps</li>
    <li>Collaborer pleinement pendant toute la procédure</li>
    <li>Assumer la responsabilité des conséquences de renseignements faux ou incomplets</li>
</ol>

<!-- =========================
     ARTICLE 6 – NO GUARANTEE DISCLAIMER
========================= -->
<h2>6. ABSENCE DE GARANTIE</h2>

<p>L’étudiant comprend et accepte que :</p>

<ul>
    <li>L’admission, le visa, le prêt et les délais de traitement ne sont <strong>pas garantis</strong></li>
    <li>Les décisions sont prises uniquement par les établissements d’enseignement, les ambassades, les autorités d’immigration et les autres organismes tiers</li>
    <li>Un refus ou un retard ne constitue pas un manquement du consultant à la présente convention</li>
</ul>
<!-- =========================
     ARTICLE 7 – FEES & PAYMENT TERMS
========================= -->
<h2>7. FRAIS ET MODALITÉS DE PAIEMENT (FORMULE CHOISIE)</h2>

<div class="selected-package-box">
<p class="package-title"><?php $flagCode = pcvc_flag_code_for_text((string) ($package['title'] ?? '')); echo $flagCode !== '' ? pcvc_country_flag($flagCode, true) : ''; ?><?= esc(contractPdfPlainText($package['title'])) ?></p>

<ul class="package-fees-list">
<?= contractPdfRenderPackageLines($package['lines']) ?>
</ul>

<?php if (!empty($package['total'])): ?>
<p class="package-total"><?= esc($package['total_label'] ?? 'Total de la formule') ?>: <?= highlightContractPrices($package['total']) ?></p>
<?php endif; ?>
</div>
<h3>Tarifs supplémentaires (sans prêt et services particuliers)</h3>

<div class="pricing-block">
<p><strong>1. Cours de courte durée du printemps, de l’hiver, de l’été ou de l’automne (monde entier)</strong></p>
<ul>
    <li><?= highlightContractPrices('Frais de dossier et d’inscription : 250 EUR, remboursables si l’approbation n’est pas obtenue dans un délai de quatre (4) mois') ?></li>
    <li><?= highlightContractPrices('Frais de service : 2 000 EUR, payables seulement lorsque le visa est approuvé') ?></li>
</ul>
</div>

<div class="pricing-block">
<p><strong>2. Avocat en immigration canadienne – demande de visa (Canada seulement)</strong></p>
<p>
Si l’étudiant demande qu’un avocat autorisé en immigration canadienne traite la demande de visa,
des frais supplémentaires de
<?= highlightContractPrices('300 CAD par demandeur') ?> s’appliquent.
</p>
</div>

<div class="pricing-block">
<p><strong>3. Avocat en immigration canadienne – conseil ou consultation (Canada seulement)</strong></p>
<p>
Si l’étudiant a besoin d’un conseil ou d’une consultation d’un avocat autorisé en immigration canadienne,
il paie des frais de consultation de
<?= highlightContractPrices('300 CAD') ?>.
</p>
</div>

<p>
<strong>Important:</strong>
Tous les frais gouvernementaux, frais d’ambassade, frais de biométrie, frais SEVIS, acomptes de scolarité,
honoraires d’avocat, frais de laissez-passer frontalier et frais de tiers sont payés séparément par l’étudiant
et sont <strong>non remboursables une fois versés</strong>.
</p>
<!-- =========================
     ARTICLE 8 – PAYMENT OF SERVICE FEES
========================= -->
<h2>8. PAIEMENT DES FRAIS DE SERVICE</h2>

<p>
Lorsque cela s’applique, les frais de service finaux deviennent exigibles dès l’approbation du visa.
Une fois le visa approuvé, l’étudiant paie tous les frais de service encore dus
<strong>au plus tard cinq (5) jours</strong> après la date d’approbation.
Le défaut de paiement constitue un <strong>manquement important</strong> à la présente convention.
</p>

<!-- =========================
     ARTICLE 9 – REFUND POLICY
========================= -->
<h2>9. POLITIQUE DE REMBOURSEMENT</h2>

<p>
Seuls les frais d’inscription sont remboursables, et uniquement dans les conditions expressément prévues
par la présente convention. Tous les autres frais, y compris les frais de service, les frais de traitement de prêt,
les frais juridiques et les frais de tiers, sont <strong>non remboursables</strong>.
</p>

<!-- =========================
     ARTICLE 10 – TERMINATION
========================= -->
<h2>10. RÉSILIATION</h2>

<p>
Le consultant peut résilier immédiatement la présente convention en cas de non-paiement,
de remise de documents frauduleux ou de manquement de l’étudiant à l’une de ses obligations.
La résiliation ne libère pas l’étudiant des sommes encore dues.
</p>

<!-- =========================
     ARTICLE 11 – LIMITATION OF LIABILITY
========================= -->
<h2>11. LIMITATION DE RESPONSABILITÉ</h2>

<p>
Le consultant n’est pas responsable des décisions, retards, refus ou résultats émanant
des ambassades, des établissements d’enseignement ou des autorités publiques indépendantes de sa volonté.
</p>

<!-- =========================
     ARTICLE 12 – CONFIDENTIALITY
========================= -->
<h2>12. CONFIDENTIALITÉ</h2>

<p>
Tous les renseignements échangés entre les parties restent confidentiels, sauf si
la loi ou une autorité compétente en exige la communication.
</p>

<!-- =========================
     ARTICLE 13 – GOVERNING LAW &amp; JURISDICTION
========================= -->
<h2>13. DROIT APPLICABLE ET TRIBUNAUX COMPÉTENTS</h2>

<p>
La présente convention est régie par le droit de la <strong>République du Rwanda</strong>.
Les tribunaux compétents du Rwanda ont compétence exclusive.
</p>

<!-- =========================
     ARTICLE 14 – ENTIRE AGREEMENT
========================= -->
<h2>14. INTÉGRALITÉ DE LA CONVENTION</h2>

<p>
La présente convention constitue l’intégralité de l’accord entre les parties et remplace
toute discussion antérieure. Toute modification doit être faite par écrit et signée par les deux parties.
</p>
<?= pcvc_student_contract_documents_notice('fr') ?>
<div class="signatures-section">
<h2>15. SIGNATURES</h2>

<table style="width:100%; border-collapse:collapse;">

<tr>
<!-- LEFT: Parrot / Dr. Twajamahoro -->
<td style="padding:10px 12px; vertical-align:top; width:50%;">

<strong>Pour Parrot Canada Visa Consultant Co. Ltd</strong><br><br>

Nom : Dr Jean Pierre Twajamahoro<br><br>

Title: Propriétaire et directeur général<br><br>

Signature:<br>
<div class="manager-signature" style="border-bottom:1px solid #000; min-height:54pt; width:90%; padding-top:2pt;">
<img src="<?= $managerSignature ?>" alt="Signature du directeur général">
</div><br>

Cachet de la société :<br>
<div class="company-stamp" style="min-height:112pt; width:90%; padding-top:4pt;">
<img src="<?= $companyStamp ?>" alt="Cachet de la société">
</div><br>

Date : <?= esc($data['signed_date']) ?>

</td>

<!-- RIGHT: Student e-sign -->
<td style="padding:10px 12px; vertical-align:top; width:50%;">

<strong>Pour l’étudiant</strong><br><br>

Nom : <?= esc($data['full_name']) ?><br><br>

Signature:<br>

<div class="student-signature-line">

<?php if (!empty($studentSignatureSrc)): ?>
<img src="<?= str_replace('\\', '/', $studentSignatureSrc) ?>" alt="Signature de l’étudiant">
<?php endif; ?>

</div>

Date : <?= esc($data['signed_date']) ?>

</td>
</tr>

</table>
<div class="footer">
Référence de la convention : <?= esc($data['contract_token']) ?>
</div>
</div>

</body>
</html>
<?php
    $html = ob_get_clean();

    /* =====================================================
       5. RENDER PDF
    ===================================================== */
    $dompdf = new Dompdf(new Options([
        'isRemoteEnabled' => true,
        'chroot' => __DIR__,
    ]));
    $dompdf->loadHtml($html);
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->render();

    $dir = __DIR__ . '/uploads/contracts';
    if (!is_dir($dir)) {
        mkdir($dir, 0777, true);
    }

    $path = $dir . "/contract_{$contractId}.pdf";
    file_put_contents($path, $dompdf->output());

    return $path;
}
