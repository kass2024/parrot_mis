<?php
session_start();
if (!empty($_GET['id']) && is_string($_GET['id'])) {
    $rid = preg_replace('/[^a-zA-Z0-9_\-]/', '', $_GET['id']);
    if ($rid !== '') {
        $_SESSION['user_id'] = $rid;
    }
}
$_SESSION['user_id'] ??= 'user_' . bin2hex(random_bytes(6));

// Language detection and setting
$lang = $_GET['lang'] ?? $_COOKIE['app_lang'] ?? 'en';
if (in_array($lang, ['en', 'fr'])) {
    setcookie('app_lang', $lang, time() + (86400 * 30), "/");
}

// Bilingual text arrays - ONLY for UI elements, not form field names
$text = [
    'en' => [
        'title' => 'Student Application Form',
        'next' => 'Next',
        'prev' => 'Back',
        'step1_title' => 'Choice of Studies',
        'step1_desc' => 'Follow the steps: pick regions, add universities, then choose one or more program levels and programs for each university. You may select several universities and multiple programs.',
        'step1_flow_1' => 'Region',
        'step1_flow_2' => 'University',
        'step1_flow_3' => 'Level',
        'step1_flow_4' => 'Program',
        'step1_regions_label' => 'Study areas',
        'step1_regions_placeholder' => 'Select one or more regions',
        'step1_regions_help' => 'Start by choosing where you want to study. You can select several regions.',
        'step1_add_panel_title' => 'Add a university',
        'step1_add_panel_help' => 'Pick a university from your selected regions, then click Add. Repeat to apply to more than one university.',
        'step1_add_university_btn' => 'Add university',
        'step1_pick_university' => 'Choose a university…',
        'step1_add_level' => 'Add another program level',
        'step1_remove_row' => 'Remove this row',
        'step1_remove_uni' => 'Remove university',
        'step1_choices_heading' => 'Your universities & programs',
        'step1_filter_summary' => 'Filter list (optional)',
        'step1_search_label' => 'Narrow your list (optional)',
        'step1_search_placeholder' => 'Search university or program name…',
        'step1_clear' => 'Clear',
        'step1_empty_no_region' => 'Select at least one region above to continue.',
        'step1_empty_add_uni' => 'Use “Add a university” below to choose your institutions. You can add multiple universities and several programs per university.',
        'step1_cart_title' => 'Your selections',
        'step1_cart_help' => 'Summarizes universities where you have chosen at least one program.',
        'step1_university' => 'University',
        'step1_level' => 'Program level',
        'step1_program' => 'Programs',
        'step1_remove' => 'Remove',
        'doc_prepare_title' => 'Documents to Prepare Before Starting',
        'doc_prepare_desc' => 'To ensure a smooth application process, please have the following documents available. You will be asked to upload them during the application steps.',
        'doc_formats' => 'Supported formats: PDF, JPG, PNG',
        'doc_list' => ['Valid Passport', 'Degree / Academic Transcripts', 'High School Certificate', 'CV / Resume', 'Recommendation Letter(s)', 'Personal Statement / Motivation Letter', 'English Proficiency Certificate', 'Birth Certificate', 'Application / Payment Proof'],
        
        // Step 2
        'step2_title' => 'Personal Information',
        'step2_desc' => 'Enter details exactly as shown on your passport.',
        'first_name' => 'First Name',
        'last_name' => 'Last Name',
        'email' => 'Email',
        'phone_label' => 'Phone Number',
        'phone_placeholder' => 'Enter phone number',
        'phone_help' => 'Select country to auto-fill international code.',
        'gender' => 'Gender',
        'gender_options' => ['Male', 'Female'],
        'dob' => 'Date of Birth',
        'passport' => 'Passport Number',
        'national_id' => 'National ID',
        'birth_country' => 'Country of birth',
        'city_birth' => 'City of Birth',
        'nationality' => 'Nationality',
        'second_nationality' => 'Second nationality',
        
        // Step 3
        'step3_title' => 'Address & Family',
        'step3_desc' => 'Provide your current address and parent information.',
        'address1' => 'Address Line 1',
        'address2' => 'Address Line 2 (optional)',
        'city' => 'City',
        'state' => 'State / Province',
        'postal' => 'Postal Code',
        'parents_title' => 'Parents Information',
        'father_first' => 'Father First Name',
        'father_last' => 'Father Last Name',
        'mother_first' => 'Mother First Name',
        'mother_last' => 'Mother Last Name',
        
        // Step 4
        'step4_title' => 'Emergency Contact',
        'step4_desc' => 'Provide details of a person we can contact in case of emergency.',
        'emergency_first' => 'First Name',
        'emergency_last' => 'Last Name',
        'emergency_email' => 'Email',
        'emergency_phone_label' => 'Emergency Phone',
        'emergency_phone_help' => 'Select country to auto-fill code and validate number length.',
        'relationship' => 'Relationship',
        'same_address' => 'Same Address?',
        'same_address_options' => ['Yes', 'No'],
        
        // Step 5
        'step5_title' => 'Education & Background',
        'step5_desc' => 'Provide details about your previous education and academic background.',
        'institution_name' => 'Institution Name',
        'institution_name_placeholder' => 'e.g. Kigali Secondary School',
        'institution_street' => 'Institution Street',
        'institution_street_placeholder' => 'e.g. KN 123 St',
        'institution_city' => 'Institution City',
        'institution_city_placeholder' => 'e.g. Kigali',
        'institution_province' => 'Institution Province / State',
        'institution_province_placeholder' => 'e.g. Kigali City',
        'institution_country' => 'Institution Country',
        'institution_postal' => 'Postal Code',
        'institution_postal_placeholder' => 'e.g. 00000',
        'language' => 'Language of Instruction',
        'language_options' => ['English', 'French', 'Other'],
        'study_start' => 'Study Start Date',
        'graduation' => 'Graduation / Completion Date',
        'study_gap' => 'Study Gap?',
        'study_gap_placeholder' => 'Explain reason and duration of study gap',
        'secondary_school' => 'Additional Secondary School?',
        'secondary_school_placeholder' => 'Provide school name, location, and years attended',
        'post_secondary' => 'Post Secondary Education?',
        'post_secondary_placeholder' => 'Describe institution, program, and duration',
        'criminal_history' => 'Criminal History?',
        'criminal_history_placeholder' => 'Offense, date, and outcome',
        'disability' => 'Disability?',
        'disability_placeholder' => 'Describe disability and required accommodations',
        'visa_rejection' => 'Visa Rejection History?',
        'visa_rejection_placeholder' => 'Country, year, and reason for refusal',
        'yes_no_options' => ['Yes', 'No'],
        
        // Step 6
        'step6_title' => 'Destination & Finance',
        'step6_desc' => 'Review your study destination and indicate how your education expenses will be covered.',
        'destination_title' => 'Study Destination',
        'preferred_destination' => 'Preferred Destination',
        'preferred_help' => 'Automatically filled based on the region selected in Step 1.',
        'loan_destination' => 'Loan Destination',
        'loan_destination_help' => 'Defaults to your preferred study destination.',
        'other_loan_destination' => 'Other Loan Destination (Optional)',
        'other_loan_placeholder' => 'Different destination covered by loan',
        'finance_title' => 'Financial Responsibility',
        'tuition' => 'Who Pays Tuition?',
        'living_cost' => 'Who Pays Living Cost?',
        'travel' => 'Who Pays Travel?',
        'finance_options' => ['Self', 'Parents', 'Sponsor', 'Loan'],
        
        // Step 7
        'step7_title' => 'Documents Upload',
        'step7_desc' => 'Upload clear, readable documents. Supported formats: PDF, JPG, PNG. Files are validated automatically.',
        'degree_transcripts' => 'Degree / Academic Transcripts',
        'high_school' => 'High School Certificate',
        'passport_doc' => 'Valid Passport',
        'cv_resume' => 'CV / Resume',
        'recommendation' => 'Recommendation Letter(s)',
        'personal_statement' => 'Personal Statement / Motivation Letter',
        'english_certificate' => 'English Proficiency Certificate',
        'birth_certificate' => 'Birth Certificate',
        'payment_proof' => 'Application / Payment Proof',
        'drop_transcripts' => 'Drop transcripts here',
        'drop_certificate' => 'Drop certificate here',
        'drop_passport' => 'Drop passport here',
        'drop_cv' => 'Drop CV here',
        'drop_recommendation' => 'Drop recommendation letters',
        'drop_statement' => 'Drop document here',
        'multiple_files' => 'Multiple files allowed',
        'single_file' => 'Single file',
        'referral_title' => 'How did you know us?',
        'referral_help' => 'This helps us assign the correct consultant to your application.',
        'referral_options' => [
            ['text' => 'Online / Website / Social Media', 'value' => 'online'],
            ['text' => 'Through an Agent', 'value' => 'agent']
        ],
        'agent_search_placeholder' => 'Search agent by name or email',
        'agent_first' => 'First Name',
        'agent_last' => 'Last Name',
        'agent_email' => 'Email',
        'agent_help' => 'Start typing to search and select a registered agent.',
        'comments_placeholder' => 'Additional comments, explanations, or missing document notes',
        'required' => 'Required',
        'save_error_title' => 'Unable to save this step',
        'save_error_ok' => 'OK',
    ],
    'fr' => [
        'title' => 'Formulaire de Demande d\'Étudiant',
        'next' => 'Suivant',
        'prev' => 'Retour',
        'step1_title' => 'Choix d\'études',
        'step1_desc' => 'Suivez les étapes : régions, universités, puis un ou plusieurs niveaux et programmes par université. Vous pouvez sélectionner plusieurs universités et plusieurs programmes.',
        'step1_flow_1' => 'Région',
        'step1_flow_2' => 'Université',
        'step1_flow_3' => 'Niveau',
        'step1_flow_4' => 'Programme',
        'step1_regions_label' => 'Zones d\'études',
        'step1_regions_placeholder' => 'Sélectionnez une ou plusieurs régions',
        'step1_regions_help' => 'Commencez par indiquer où vous souhaitez étudier. Plusieurs régions sont possibles.',
        'step1_add_panel_title' => 'Ajouter une université',
        'step1_add_panel_help' => 'Choisissez une université parmi les régions sélectionnées, puis cliquez sur Ajouter. Répétez pour plusieurs universités.',
        'step1_add_university_btn' => 'Ajouter',
        'step1_pick_university' => 'Choisir une université…',
        'step1_add_level' => 'Ajouter un autre niveau',
        'step1_remove_row' => 'Retirer cette ligne',
        'step1_remove_uni' => 'Retirer l\'université',
        'step1_choices_heading' => 'Vos universités et programmes',
        'step1_filter_summary' => 'Filtrer la liste (facultatif)',
        'step1_search_label' => 'Filtrer la liste (facultatif)',
        'step1_search_placeholder' => 'Rechercher une université ou un programme…',
        'step1_clear' => 'Effacer',
        'step1_empty_no_region' => 'Sélectionnez au moins une région ci-dessus pour continuer.',
        'step1_empty_add_uni' => 'Utilisez « Ajouter une université » ci-dessous pour choisir vos établissements. Vous pouvez en ajouter plusieurs et plusieurs programmes par université.',
        'step1_cart_title' => 'Vos choix',
        'step1_cart_help' => 'Récapitule les universités pour lesquelles au moins un programme est sélectionné.',
        'step1_university' => 'Université',
        'step1_level' => 'Niveau du programme',
        'step1_program' => 'Programmes',
        'step1_remove' => 'Supprimer',
        'doc_prepare_title' => 'Documents à Préparer Avant de Commencer',
        'doc_prepare_desc' => 'Pour assurer un processus de demande fluide, veuillez avoir les documents suivants disponibles. Ils vous seront demandés lors des étapes de la demande.',
        'doc_formats' => 'Formats supportés : PDF, JPG, PNG',
        'doc_list' => ['Passeport Valide', 'Diplômes / Relevés de Notes Académiques', 'Certificat de Lycée', 'CV / Curriculum Vitae', 'Lettre(s) de Recommandation', 'Lettre de Motivation / Déclaration Personnelle', 'Certificat de Compétence en Anglais', 'Certificat de Naissance', 'Preuve de Demande / Paiement'],
        
        // Step 2
        'step2_title' => 'Informations Personnelles',
        'step2_desc' => 'Entrez les détails exactement comme indiqué sur votre passeport.',
        'first_name' => 'Prénom',
        'last_name' => 'Nom',
        'email' => 'Email',
        'phone_label' => 'Numéro de Téléphone',
        'phone_placeholder' => 'Entrez le numéro de téléphone',
        'phone_help' => 'Sélectionnez le pays pour remplir automatiquement le code international.',
        'gender' => 'Genre',
        'gender_options' => ['Homme', 'Femme'],
        'dob' => 'Date de Naissance',
        'passport' => 'Numéro de Passeport',
        'national_id' => 'Carte d\'Identité Nationale',
        'birth_country' => 'Pays de naissance',
        'city_birth' => 'Ville de Naissance',
        'nationality' => 'Nationalité',
        'second_nationality' => 'Deuxième nationalité',
        
        // Step 3
        'step3_title' => 'Adresse & Famille',
        'step3_desc' => 'Fournissez votre adresse actuelle et les informations parentales.',
        'address1' => 'Adresse Ligne 1',
        'address2' => 'Adresse ligne 2 (facultatif)',
        'city' => 'Ville',
        'state' => 'État / Province',
        'postal' => 'Code Postal',
        'parents_title' => 'Informations des Parents',
        'father_first' => 'Prénom du Père',
        'father_last' => 'Nom du Père',
        'mother_first' => 'Prénom de la Mère',
        'mother_last' => 'Nom de la Mère',
        
        // Step 4
        'step4_title' => 'Contact d\'Urgence',
        'step4_desc' => 'Fournissez les détails d\'une personne que nous pouvons contacter en cas d\'urgence.',
        'emergency_first' => 'Prénom',
        'emergency_last' => 'Nom',
        'emergency_email' => 'Email',
        'emergency_phone_label' => 'Téléphone d\'Urgence',
        'emergency_phone_help' => 'Sélectionnez le pays pour remplir automatiquement le code et valider la longueur du numéro.',
        'relationship' => 'Relation',
        'same_address' => 'Même Adresse?',
        'same_address_options' => ['Oui', 'Non'],
        
        // Step 5
        'step5_title' => 'Éducation & Antécédents',
        'step5_desc' => 'Fournissez des détails sur votre éducation précédente et vos antécédents académiques.',
        'institution_name' => 'Nom de l\'Établissement',
        'institution_name_placeholder' => 'ex. Lycée de Kigali',
        'institution_street' => 'Rue de l\'Établissement',
        'institution_street_placeholder' => 'ex. KN 123 Rue',
        'institution_city' => 'Ville de l\'Établissement',
        'institution_city_placeholder' => 'ex. Kigali',
        'institution_province' => 'Province / État de l\'Établissement',
        'institution_province_placeholder' => 'ex. Ville de Kigali',
        'institution_country' => 'Pays de l\'Établissement',
        'institution_postal' => 'Code Postal',
        'institution_postal_placeholder' => 'ex. 00000',
        'language' => 'Langue d\'Enseignement',
        'language_options' => ['Anglais', 'Français', 'Autre'],
        'study_start' => 'Date de Début des Études',
        'graduation' => 'Date d\'Obtention du Diplôme',
        'study_gap' => 'Interruption dans les Études?',
        'study_gap_placeholder' => 'Expliquez la raison et la durée de l\'interruption',
        'secondary_school' => 'École Secondaire Supplémentaire?',
        'secondary_school_placeholder' => 'Fournissez le nom de l\'école, l\'emplacement et les années fréquentées',
        'post_secondary' => 'Éducation Post-Secondaire?',
        'post_secondary_placeholder' => 'Décrivez l\'établissement, le programme et la durée',
        'criminal_history' => 'Antécédents Judiciaires?',
        'criminal_history_placeholder' => 'Infraction, date et résultat',
        'disability' => 'Handicap?',
        'disability_placeholder' => 'Décrivez le handicap et les aménagements requis',
        'visa_rejection' => 'Refus de Visa Antérieur?',
        'visa_rejection_placeholder' => 'Pays, année et raison du refus',
        'yes_no_options' => ['Oui', 'Non'],
        
        // Step 6
        'step6_title' => 'Destination & Finance',
        'step6_desc' => 'Examinez votre destination d\'études et indiquez comment vos frais d\'éducation seront couverts.',
        'destination_title' => 'Destination d\'Études',
        'preferred_destination' => 'Destination Préférée',
        'preferred_help' => 'Rempli automatiquement en fonction de la région sélectionnée à l\'Étape 1.',
        'loan_destination' => 'Destination du Prêt',
        'loan_destination_help' => 'Par défaut, correspond à votre destination d\'études préférée.',
        'other_loan_destination' => 'Autre Destination de Prêt (Optionnelle)',
        'other_loan_placeholder' => 'Destination différente couverte par le prêt',
        'finance_title' => 'Responsabilité Financière',
        'tuition' => 'Qui Paie les Frais de Scolarité?',
        'living_cost' => 'Qui Paie le Coût de la Vie?',
        'travel' => 'Qui Paie les Frais de Voyage?',
        'finance_options' => ['Soi-même', 'Parents', 'Sponsor', 'Prêt'],
        
        // Step 7
        'step7_title' => 'Téléchargement des Documents',
        'step7_desc' => 'Téléchargez des documents clairs et lisibles. Formats supportés : PDF, JPG, PNG. Les fichiers sont validés automatiquement.',
        'degree_transcripts' => 'Diplômes / Relevés de Notes',
        'high_school' => 'Certificat de Lycée',
        'passport_doc' => 'Passeport Valide',
        'cv_resume' => 'CV / Curriculum Vitae',
        'recommendation' => 'Lettre(s) de Recommandation',
        'personal_statement' => 'Lettre de Motivation / Déclaration Personnelle',
        'english_certificate' => 'Certificat de Compétence en Anglais',
        'birth_certificate' => 'Certificat de Naissance',
        'payment_proof' => 'Preuve de Demande / Paiement',
        'drop_transcripts' => 'Déposez les relevés ici',
        'drop_certificate' => 'Déposez le certificat ici',
        'drop_passport' => 'Déposez le passeport ici',
        'drop_cv' => 'Déposez le CV ici',
        'drop_recommendation' => 'Déposez les lettres de recommandation',
        'drop_statement' => 'Déposez le document ici',
        'multiple_files' => 'Plusieurs fichiers autorisés',
        'single_file' => 'Fichier unique',
        'referral_title' => 'Comment nous avez-vous connus?',
        'referral_help' => 'Cela nous aide à attribuer le bon consultant à votre demande.',
        'referral_options' => [
            ['text' => 'En ligne / Site Web / Réseaux Sociaux', 'value' => 'online'],
            ['text' => 'Par l\'intermédiaire d\'un Agent', 'value' => 'agent']
        ],
        'agent_search_placeholder' => 'Rechercher un agent par nom ou email',
        'agent_first' => 'Prénom',
        'agent_last' => 'Nom',
        'agent_email' => 'Email',
        'agent_help' => 'Commencez à taper pour rechercher et sélectionner un agent enregistré.',
        'comments_placeholder' => 'Commentaires supplémentaires, explications ou notes sur les documents manquants',
        'required' => 'Obligatoire',
        'save_error_title' => 'Enregistrement impossible',
        'save_error_ok' => 'OK',
    ]
];

$t = $text[$lang];
?>
<!doctype html>
<html lang="<?php echo $lang; ?>">
<head>
<meta charset="utf-8">
<title><?php echo $t['title']; ?></title>
<meta name="viewport" content="width=device-width, initial-scale=1">

<!-- Language Switcher CSS -->
<style>
.language-switcher {
    position: fixed;
    top: 20px;
    right: 20px;
    z-index: 1000;
}
.language-btn {
    background: white;
    border: 1px solid #ddd;
    border-radius: 20px;
    padding: 6px 12px;
    font-size: 14px;
    cursor: pointer;
    display: flex;
    align-items: center;
    gap: 5px;
    box-shadow: 0 2px 5px rgba(0,0,0,0.1);
}
.language-btn.active {
    background: #0d6efd;
    color: white;
    border-color: #0d6efd;
}
.language-btn:hover {
    background: #f8f9fa;
}
.language-btn.active:hover {
    background: #0b5ed7;
}
</style>

<!-- Bootstrap -->
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">

<!-- Select2 -->
<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet">

<!-- Your existing styles (keep all CSS from your original form) -->
<style>
/* =====================================================
   GLOBAL RESET & BASE
===================================================== */
* {
  box-sizing: border-box;
}

body {
  background-color: #f5f7fb;
  font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Arial, sans-serif;
  color: #212529;
}

/* =====================================================
   CARD & LAYOUT
===================================================== */
.card {
  border-radius: 14px;
  border: none;
}

.card-body {
  padding: 2rem;
}

/* =====================================================
   STEP VISIBILITY
===================================================== */
.step {
  display: none;
}

.step.active {
  display: block;
}

/* =====================================================
   PROGRESS BAR (CLEAN & MODERN)
===================================================== */
.progress-step {
  display: flex;
  gap: 8px;
  margin-bottom: 1.75rem;
}

.progress-step span {
  flex: 1;
  height: 6px;
  background: #dee2e6;
  border-radius: 999px;
  transition: background-color .3s ease;
}

.progress-step span.active {
  background: linear-gradient(90deg, #0d6efd, #4f8cff);
}

/* =====================================================
   LABELS
===================================================== */
.form-label {
  font-weight: 600;
  margin-bottom: 6px;
  font-size: 14px;
  color: #343a40;
}

/* =====================================================
   INPUTS & SELECTS (BASE)
===================================================== */
.form-control,
.form-select {
  min-height: 48px;
  padding: 10px 14px;
  border-radius: 10px;
  border: 1px solid #dfe3eb;
  background-color: #fff;
  font-size: 14px;
  transition: border-color .2s ease, box-shadow .2s ease;
}

.form-control::placeholder {
  color: #adb5bd;
}

.form-control:focus,
.form-select:focus {
  border-color: #0d6efd;
  box-shadow: 0 0 0 3px rgba(13,110,253,.15);
  outline: none;
}

/* Disabled */
.form-control:disabled,
.form-select:disabled {
  background-color: #f1f3f6;
  color: #6c757d;
  cursor: not-allowed;
}

/* =====================================================
   SELECT2 – CORE FIX (NO MORE CUT EDGES)
===================================================== */
.select2-container {
  width: 100% !important;
}

/* Main selection */
.select2-container--bootstrap-5 .select2-selection {
  min-height: 48px;
  padding: 6px 10px;
  border-radius: 10px;
  border: 1px solid #dfe3eb;
  display: flex;
  align-items: center;
  background-color: #fff;
}

/* Placeholder text */
.select2-container--bootstrap-5 .select2-selection__placeholder {
  color: #adb5bd;
  font-size: 14px;
}

/* Focus state */
.select2-container--bootstrap-5.select2-container--focus .select2-selection {
  border-color: #0d6efd;
  box-shadow: 0 0 0 3px rgba(13,110,253,.15);
}

/* Disabled Select2 */
.select2-container--bootstrap-5.select2-container--disabled .select2-selection {
  background-color: #f1f3f6;
  color: #6c757d;
}

/* =====================================================
   SELECT2 – MULTI SELECT (PROGRAMS FIX)
===================================================== */
.select2-container--bootstrap-5 .select2-selection--multiple {
  padding: 6px 8px;
  gap: 6px;
  align-items: center;
}

/* Selected chips */
.select2-container--bootstrap-5 .select2-selection__choice {
  background: linear-gradient(135deg, #0d6efd, #4f8cff);
  color: #fff;
  border: none;
  border-radius: 999px;
  padding: 4px 10px;
  font-size: 12px;
  display: flex;
  align-items: center;
}

/* Remove "x" spacing issue */
.select2-selection__choice__remove {
  margin-right: 6px;
  color: #fff;
  opacity: .8;
}

.select2-selection__choice__remove:hover {
  opacity: 1;
}

/* =====================================================
   SELECT2 DROPDOWN (CLEAN & ELEGANT)
===================================================== */
.select2-container--bootstrap-5 .select2-dropdown {
  border-radius: 12px;
  border: 1px solid #dfe3eb;
  box-shadow: 0 10px 30px rgba(0,0,0,.08);
  overflow: hidden;
}

/* Options list */
.select2-container--bootstrap-5 .select2-results__options {
  max-height: 240px;
  overflow-y: auto;
}

/* Option */
.select2-container--bootstrap-5 .select2-results__option {
  padding: 12px 16px;
  font-size: 14px;
  cursor: pointer;
}

/* Hover */
.select2-container--bootstrap-5 .select2-results__option--highlighted {
  background-color: #0d6efd;
  color: #fff;
}

/* =====================================================
   BUTTONS
===================================================== */
.btn {
  border-radius: 10px;
  padding: 10px 18px;
  font-weight: 600;
}

.btn-primary {
  background: linear-gradient(135deg, #0d6efd, #4f8cff);
  border: none;
}

.btn-primary:hover {
  background: linear-gradient(135deg, #0b5ed7, #3f7be0);
}

.btn-secondary {
  background-color: #e9ecef;
  border: none;
  color: #343a40;
}

/* =====================================================
   FILE INPUTS
===================================================== */
.upload {
  border-radius: 10px;
}

/* =====================================================
   SMALL SCREENS
===================================================== */
@media (max-width: 768px) {
  .card-body {
    padding: 1.25rem;
  }
}
/* =====================================================
   FINAL FIX – SELECT2 PROGRAMS MULTI-SELECT
   (Fixes broken height, chips, cursor, overflow)
===================================================== */

/* Stop flex breaking the layout */
.select2-container--bootstrap-5 .select2-selection--multiple {
  display: block !important;
  min-height: 48px;
  padding: 8px 12px;
  line-height: 1.4;
  overflow: hidden;
}

/* Proper wrapping for selected items */
.select2-container--bootstrap-5
.select2-selection--multiple
.select2-selection__rendered {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 6px;
  padding: 0;
  margin: 0;
}

/* Selected program chips */
.select2-container--bootstrap-5
.select2-selection__choice {
  display: inline-flex;
  align-items: center;
  padding: 4px 10px;
  font-size: 12px;
  border-radius: 999px;
  white-space: nowrap;
}

/* Remove button alignment */
.select2-container--bootstrap-5
.select2-selection__choice__remove {
  margin-right: 6px;
  font-weight: 600;
}

/* Inline search input FIX (this was the big problem) */
.select2-container--bootstrap-5
.select2-search--inline
.select2-search__field {
  min-width: 120px;
  height: 32px;
  margin: 0;
  padding: 0;
  line-height: 32px;
  border: none !important;
  outline: none;
  box-shadow: none !important;
}

/* Prevent giant height when many programs */
.select2-container--bootstrap-5
.select2-selection--multiple {
  max-height: 120px;
  overflow-y: auto;
}
/* =====================================================
   SMART ROUNDED FILE UPLOAD PROGRESS
===================================================== */

.upload-progress {
  width: 100%;
  height: 12px;
  background: #e9ecef;
  border-radius: 999px;
  overflow: hidden;
  display: none;
}

.upload-bar {
  height: 100%;
  width: 0%;
  background: linear-gradient(90deg, #0d6efd, #4f8cff);
  border-radius: 999px;
  transition: width .35s ease;
  position: relative;
}

.upload-bar span {
  position: absolute;
  right: 8px;
  top: 50%;
  transform: translateY(-50%);
  font-size: 10px;
  font-weight: 600;
  color: #fff;
  opacity: 0;
  transition: opacity .3s ease;
}

/* Show percentage when progress starts */
.upload-progress.active .upload-bar span {
  opacity: 1;
}
/* =====================================================
   UI DEPTH, CONTAINERS & VISUAL HIERARCHY (PRODUCTION)
   Paste at the END of your <style>
===================================================== */

/* Page background – soft, non-flat */
body {
  background: linear-gradient(180deg, #f3f6fb 0%, #eef2f7 100%);
}

/* Main application container (card) */
.card {
  background: #ffffff;
  border-radius: 18px;
  border: 1px solid #e6ebf2;
  box-shadow:
    0 10px 28px rgba(0, 0, 0, 0.04),
    0 4px 10px rgba(0, 0, 0, 0.025);
}

/* Inner spacing consistency */
.card-body {
  padding: 2rem;
}
/* =====================================================
   STEP CONTAINER – STRONG VISUAL SEPARATION
===================================================== */

.step-section {
  position: relative;
  background: #ffffff;
  border-radius: 18px;
  padding: 2.25rem;
  margin-bottom: 2rem;

  border: 1px solid #e2e8f0;

  box-shadow:
    0 12px 28px rgba(15, 23, 42, 0.08),
    0 4px 10px rgba(15, 23, 42, 0.04);
}

/* Accent bar on the left (PRO LOOK) */
.step-section::before {
  content: "";
  position: absolute;
  left: 0;
  top: 0;
  bottom: 0;
  width: 6px;
  background: linear-gradient(180deg, #0d6efd, #4f8cff);
  border-radius: 18px 0 0 18px;
}

/* Step titles */
.step-section h5 {
  font-size: 17px;
  font-weight: 700;
  color: #0f172a;
}

/* Step description */
.step-section p {
  font-size: 13px;
  color: #64748b;
}

/* Form fields – subtle contrast improvement */
.form-control,
.form-select {
  background-color: #ffffff;
  border: 1px solid #dbe2ea;
}

/* Hover feedback */
.form-control:hover,
.form-select:hover {
  border-color: #c7d2e2;
}

/* Navigation separator (Back / Next area) */
.form-nav {
  border-top: 1px solid #edf1f7;
  padding-top: 1.25rem;
  margin-top: 2rem;
}

/* Mobile polish */
@media (max-width: 768px) {
  .container {
    padding-left: 12px;
    padding-right: 12px;
  }

  .card {
    border-radius: 14px;
  }

  .card-body {
    padding: 1.25rem;
  }
.card {
  background: #f8fafc;
}

  
}
/* =====================================================
   STUDY SELECTION – MULTI UNIVERSITY UI
===================================================== */

.study-choices {
  display: flex;
  flex-direction: column;
  gap: 1.25rem;
}

/* Study choice card */
.study-choice {
  border-radius: 16px;
  padding: 1.25rem 1.5rem;
  background: #ffffff;
  border: 1px solid #e2e8f0;

  box-shadow:
    0 10px 20px rgba(15, 23, 42, 0.06),
    0 3px 8px rgba(15, 23, 42, 0.04);

  position: relative;
}

/* Header row */
.study-choice-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
}

/* Region badge */
.region-badge {
  font-size: 12px;
  padding: 6px 10px;
  border-radius: 999px;
  background: linear-gradient(135deg, #0d6efd, #4f8cff);
}

/* Remove button */
.btn-remove {
  background: transparent;
  border: none;
  color: #dc3545;
  font-weight: 600;
  font-size: 13px;
  cursor: pointer;
}

.btn-remove:hover {
  text-decoration: underline;
}

/* Select spacing consistency */
.study-choice .form-select {
  min-height: 46px;
}

/* Mobile polish */
@media (max-width: 768px) {
  .study-choice {
    padding: 1.1rem;
  }
}
/* ================================
   STUDY SELECTION – PRO UI
================================ */

.study-choices {
  display: flex;
  flex-direction: column;
  gap: 1.25rem;
}

.study-choice {
  background: #ffffff;
  border: 1px solid #e2e8f0;
  border-radius: 16px;
  padding: 1.5rem;
  box-shadow:
    0 6px 16px rgba(15, 23, 42, 0.05),
    0 2px 6px rgba(15, 23, 42, 0.03);
}

.study-choice-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 1rem;
}

.region-badge {
  background: linear-gradient(135deg, #2563eb, #4f46e5);
  font-size: 12px;
  font-weight: 600;
  padding: 6px 12px;
  border-radius: 999px;
}

.btn-remove {
  background: none;
  border: none;
  color: #ef4444;
  font-size: 13px;
  font-weight: 600;
  cursor: pointer;
}

.btn-remove:hover {
  text-decoration: underline;
}

/* —— Choice of studies: layout + responsive —— */
.study-selection {
  max-width: 100%;
  overflow-x: clip;
}
.study-selection-header {
  text-align: left;
}
.study-step-title {
  color: #0f172a;
  letter-spacing: -0.02em;
  font-size: clamp(1.15rem, 4vw, 1.35rem);
}
.study-step-lead {
  line-height: 1.55;
  max-width: 40rem;
}
/* Flow: vertical on phone, row on tablet+ */
.study-flow-timeline {
  list-style: none;
  margin: 0;
  padding: 0;
  display: flex;
  flex-direction: column;
  gap: 0;
  position: relative;
}
.study-flow-timeline-item {
  display: flex;
  align-items: flex-start;
  gap: 0.65rem;
  padding: 0.5rem 0 0.5rem 0.25rem;
  margin: 0;
  font-size: 0.8125rem;
  font-weight: 600;
  color: #475569;
  border-left: 3px solid #e2e8f0;
  padding-left: 0.85rem;
  margin-left: 0.6rem;
}
.study-flow-timeline-item:last-child {
  border-left-color: transparent;
}
.study-flow-timeline-item.is-active {
  color: #4338ca;
}
.study-flow-timeline-item.is-active .study-flow-timeline-num {
  background: linear-gradient(135deg, #6366f1, #4f46e5);
  color: #fff;
  box-shadow: 0 2px 8px rgba(99, 102, 241, 0.35);
}
.study-flow-timeline-num {
  flex-shrink: 0;
  width: 1.5rem;
  height: 1.5rem;
  margin-left: -1.6rem;
  margin-top: 0.05rem;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  font-size: 0.7rem;
  font-weight: 800;
  border-radius: 50%;
  background: #f1f5f9;
  color: #64748b;
  border: 2px solid #fff;
  box-shadow: 0 0 0 1px #e2e8f0;
}
.study-flow-timeline-text {
  padding-top: 0.1rem;
  line-height: 1.35;
}
@media (min-width: 576px) {
  .study-flow-timeline {
    flex-direction: row;
    flex-wrap: wrap;
    align-items: stretch;
    gap: 0.5rem;
    border-left: none;
    margin-left: 0;
  }
  .study-flow-timeline-item {
    flex: 1 1 calc(50% - 0.5rem);
    min-width: 140px;
    border-left: none;
    margin-left: 0;
    padding: 0.5rem 0.65rem;
    background: #f8fafc;
    border-radius: 12px;
    border: 1px solid #e2e8f0;
    align-items: center;
    gap: 0.5rem;
  }
  .study-flow-timeline-num {
    margin-left: 0;
  }
}
@media (min-width: 992px) {
  .study-flow-timeline {
    flex-wrap: nowrap;
    gap: 0.65rem;
  }
  .study-flow-timeline-item {
    flex: 1 1 0;
  }
}

.study-selection-stack {
  display: flex;
  flex-direction: column;
  gap: 1rem;
}
@media (min-width: 768px) {
  .study-selection-stack {
    gap: 1.25rem;
  }
}

.study-panel {
  position: relative;
  padding: 1rem 1rem;
  border-radius: 14px;
  background: #fff;
  border: 1px solid #e2e8f0;
  box-shadow: 0 2px 12px rgba(15, 23, 42, 0.05);
}
@media (min-width: 768px) {
  .study-panel {
    padding: 1.25rem 1.35rem;
    border-radius: 16px;
    box-shadow: 0 4px 14px rgba(15, 23, 42, 0.04);
  }
}
.study-panel--accent {
  border-color: #c7d2fe;
  box-shadow: 0 4px 20px rgba(99, 102, 241, 0.1);
  background: linear-gradient(180deg, #ffffff 0%, #fafbff 100%);
}
.study-panel--soft {
  border-color: #bfdbfe;
  background: linear-gradient(135deg, #f8fafc 0%, #eff6ff 100%);
}
.study-panel--muted {
  border-style: dashed;
  border-color: #cbd5e1;
  background: #fafafa;
}

/* Optional filter: <details> — compact on small screens */
.study-filter-details.study-panel {
  padding-top: 0.35rem;
}
.study-filter-summary {
  list-style: none;
  cursor: pointer;
  font-weight: 600;
  font-size: 0.9rem;
  color: #334155;
  padding: 0.65rem 0.25rem;
  display: flex;
  align-items: center;
  gap: 0.5rem;
  user-select: none;
}
.study-filter-summary::-webkit-details-marker {
  display: none;
}
.study-filter-summary::after {
  content: "";
  width: 0.5rem;
  height: 0.5rem;
  margin-left: auto;
  border-right: 2px solid #94a3b8;
  border-bottom: 2px solid #94a3b8;
  transform: rotate(45deg);
  transition: transform 0.2s;
}
.study-filter-details[open] .study-filter-summary::after {
  transform: rotate(225deg);
  margin-top: 0.2rem;
}
.study-filter-summary-icon {
  font-size: 1.1rem;
  opacity: 0.85;
}
@media (min-width: 768px) {
  .study-filter-details.study-panel {
    padding-top: 1.25rem;
  }
  .study-filter-summary {
    display: none;
  }
  .study-filter-details .study-filter-body {
    display: block !important;
  }
}

.study-panel-badge {
  position: absolute;
  top: -10px;
  left: 14px;
  background: linear-gradient(135deg, #4f46e5, #6366f1);
  color: #fff;
  font-size: 11px;
  font-weight: 700;
  padding: 5px 14px;
  border-radius: 999px;
  box-shadow: 0 6px 16px rgba(79, 70, 229, 0.35);
}
@media (max-width: 575px) {
  .study-panel-badge {
    left: 10px;
    font-size: 10px;
    padding: 4px 10px;
  }
}

.study-touch-btn {
  min-height: 2.75rem;
  padding-top: 0.55rem;
  padding-bottom: 0.55rem;
}
.study-touch-control {
  min-height: 2.75rem;
}

/* Select2 full width inside panels */
.study-selection .select2-container {
  width: 100% !important;
  max-width: 100%;
}
.study-select-fullwidth {
  width: 100%;
}

/* Regions: keep native + Select2 in sync but hide the Select2 widget (custom picker only; no I-beam cursor) */
#regionStep #regions.select-smart + .select2-container {
  display: none !important;
}
#regionStep #regions.select-smart + .select2-container .select2-search--inline,
#regionStep #regions.select-smart + .select2-container .select2-search--dropdown {
  display: none !important;
}

.regions-picker-face:focus {
  border-color: #86b7fe;
  box-shadow: 0 0 0 0.25rem rgba(13, 110, 253, 0.25);
  outline: 0;
}

.study-choices-section {
  min-width: 0;
}

.study-summary-card {
  padding: 1rem 1rem;
  border-radius: 14px;
  border: 1px solid #e2e8f0;
  background: linear-gradient(180deg, #ffffff 0%, #f8fafc 100%);
  box-shadow: 0 8px 24px rgba(15, 23, 42, 0.06);
}
@media (min-width: 768px) {
  .study-summary-card {
    padding: 1.15rem 1.35rem;
  }
}
.study-summary-aside {
  order: 10;
}
.study-summary-pill {
  background: #eef2ff !important;
  color: #4338ca !important;
  font-weight: 600;
}

.study-cart-list .list-group-item {
  padding-left: 0;
  padding-right: 0;
  border-color: #f1f5f9;
  word-break: break-word;
}

.study-university-card {
  border-radius: 14px;
  padding: 1rem 1rem;
  border: 1px solid #e2e8f0;
  background: #fff;
  box-shadow:
    0 8px 20px rgba(15, 23, 42, 0.06),
    0 2px 6px rgba(15, 23, 42, 0.03);
}
@media (min-width: 768px) {
  .study-university-card {
    border-radius: 18px;
    padding: 1.25rem 1.5rem;
  }
}
.study-uni-header {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  flex-wrap: wrap;
  gap: 0.5rem 0.75rem;
  border-bottom: 1px solid #f1f5f9;
  padding-bottom: 0.75rem;
}
.study-uni-header .btn-remove-uni {
  min-height: 2.25rem;
  margin-left: auto;
  text-align: right;
  white-space: normal;
  max-width: 100%;
}
.study-level-rows {
  display: flex;
  flex-direction: column;
  gap: 0.85rem;
}
@media (min-width: 768px) {
  .study-level-rows {
    gap: 1rem;
  }
}
.study-level-row-card {
  padding: 0.85rem 0.75rem;
  border-radius: 12px;
  background: #f8fafc;
  border: 1px solid #e2e8f0;
}
@media (min-width: 768px) {
  .study-level-row-card {
    padding: 1rem 1rem 0.75rem;
  }
}
.study-level-row-actions {
  display: flex;
  justify-content: flex-end;
  margin-top: 0.25rem;
}
.btn-add-level {
  font-weight: 600;
  width: 100%;
  min-height: 2.5rem;
}
@media (min-width: 576px) {
  .btn-add-level {
    width: auto;
  }
}

.study-empty {
  border: 1px dashed #cbd5e1;
  border-radius: 14px;
  color: #64748b;
  background: linear-gradient(180deg, #fafbfc 0%, #f1f5f9 100%);
  max-width: 100%;
  margin-left: 0;
  margin-right: 0;
  padding: 1.25rem 1rem;
  text-align: left;
}
@media (min-width: 768px) {
  .study-empty {
    text-align: center;
    max-width: 640px;
    margin-left: auto;
    margin-right: auto;
    padding: 1.75rem;
  }
}

@media (max-width: 575px) {
  .study-selection .form-text {
    font-size: 0.8rem;
  }
  .study-remove-row-btn {
    width: 100%;
  }
  .study-level-row-actions {
    justify-content: stretch;
  }
}

/* ===============================
   REGION CHIPS – SMART CLOSE
================================ */

.region-chip {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  background: linear-gradient(135deg, #0d6efd, #4f8cff);
  color: #fff;
  padding: 4px 10px;
  border-radius: 999px;
  font-size: 12px;
  font-weight: 600;
}

.region-close {
  cursor: pointer;
  font-size: 14px;
  line-height: 1;
  opacity: 0.85;
}

.region-close:hover {
  opacity: 1;
}

#agent_first_name[readonly],
#agent_last_name[readonly],
#agent_email[readonly] {
    background-color: #f1f3f6;
    cursor: not-allowed;
}

/* =====================================================
   DOCUMENT DROPZONE (STEP 7)
===================================================== */

.doc-dropzone {
  position: relative;
  border: 2px dashed #d1d5db;
  border-radius: 16px;
  padding: 1.4rem;
  background: #f8fafc;
  text-align: center;
  cursor: pointer;
  transition: all .25s ease;
}

.doc-dropzone.multi {
  border-color: #6366f1;
  background: #eef2ff;
}

.doc-dropzone:hover {
  background: #eef2ff;
}

.doc-dropzone.dragover {
  background: #e0e7ff;
  border-color: #4f46e5;
  box-shadow: 0 0 0 4px rgba(99,102,241,.18);
}

.doc-dropzone input[type="file"] {
  position: absolute;
  inset: 0;
  opacity: 0;
  cursor: pointer;
}

.dz-content strong {
  display: block;
  font-size: 14px;
  font-weight: 700;
  color: #0f172a;
}

.dz-content span {
  font-size: 12px;
  color: #64748b;
}

/* File preview chips */
.dz-files {
  list-style: none;
  padding: 0;
  margin-top: 10px;
}

.dz-files li {
  display: inline-block;
  margin: 4px 6px 0 0;
  padding: 4px 10px;
  font-size: 12px;
  background: #ffffff;
  border: 1px solid #e5e7eb;
  border-radius: 999px;
  color: #334155;
}

</style>
<!-- ✅ Mobile-only overrides MUST be last -->
<link rel="stylesheet" href="mobile-study-selection.css">
</head>

<body>
<!-- Language Switcher -->
<div class="language-switcher">
    <button class="language-btn <?php echo $lang === 'en' ? 'active' : ''; ?>" data-lang="en">
        <span>🇺🇸</span> English
    </button>
    <button class="language-btn <?php echo $lang === 'fr' ? 'active' : ''; ?>" data-lang="fr">
        <span>🇫🇷</span> Français
    </button>
</div>

<div class="container my-5">
<div class="card shadow-sm">
<div class="card-body">
<!-- SMART APPLICATION RETRIEVAL -->
<div class="card mb-3 border-primary">
<div class="card-body">

<strong>Resume Incomplete Application</strong>

<input
type="text"
id="resume_email_search"
class="form-control mt-2"
placeholder="Type first 3 letters of your email"
autocomplete="off"
>

<div id="resumeResults"
class="list-group mt-2 d-none"></div>

</div>
</div>
<h4 class="fw-semibold mb-3"><?php echo $t['title']; ?></h4>

<!-- ===============================
     STEP PROGRESS
=============================== -->
<div class="progress-step mb-4">
  <span class="active"></span>
  <span></span>
  <span></span>
  <span></span>
  <span></span>
  <span></span>
  <span></span>
</div>

<form id="applicationForm" enctype="multipart/form-data">
<input type="hidden" name="user_id" value="<?=htmlspecialchars($_SESSION['user_id'])?>">
<input type="hidden" name="application_id" id="application_id">

<!-- =====================================================
 STEP 1 : STUDY SELECTION (WITH DOCUMENT CHECKLIST)
===================================================== -->
<div class="step active">

  <!-- ===============================
       DOCUMENT CHECKLIST (STEP 1 ONLY)
  =============================== -->
  <div
    style="
      background: #f6f8ff;
      border: 1px solid #c7d2fe;
      border-left: 6px solid #4f46e5;
      border-radius: 18px;
      padding: 1.6rem 1.8rem;
      margin-bottom: 2.2rem;
      box-shadow: 0 14px 30px rgba(79,70,229,.12);
    "
  >
    <div style="display:flex; gap:16px; align-items:flex-start;">

      <!-- Icon -->
      <div
        style="
          width:44px;
          height:44px;
          border-radius:50%;
          background:linear-gradient(135deg,#4f46e5,#6366f1);
          color:#fff;
          display:flex;
          align-items:center;
          justify-content:center;
          font-size:20px;
          flex-shrink:0;
          box-shadow:0 8px 18px rgba(79,70,229,.45);
        "
      >
        📄
      </div>

      <!-- Content -->
      <div>
        <h6 style="margin:0 0 6px; font-weight:700; color:#0f172a;">
          <?php echo $t['doc_prepare_title']; ?>
        </h6>

        <p style="margin:0 0 14px; font-size:13px; color:#475569; line-height:1.6;">
          <?php echo $t['doc_prepare_desc']; ?>
        </p>

        <ul
          style="
            margin:0;
            padding-left:18px;
            font-size:13px;
            color:#1e293b;
            line-height:1.75;
          "
        >
          <?php foreach ($t['doc_list'] as $item): ?>
          <li><?php echo $item; ?></li>
          <?php endforeach; ?>
        </ul>

        <div style="margin-top:12px; font-size:12px; color:#64748b;">
          <?php echo $t['doc_formats']; ?>
        </div>
      </div>
    </div>
  </div>

  <!-- ===============================
       STEP 1 CONTENT
  =============================== -->
  <div class="step-section study-selection">

    <header class="study-selection-header mb-3 mb-lg-4">
      <h5 class="fw-semibold mb-2 study-step-title"><?php echo $t['step1_title']; ?></h5>
      <p class="text-muted small mb-3 mb-lg-4 study-step-lead">
        <?php echo $t['step1_desc']; ?>
      </p>

      <ol class="study-flow-timeline" aria-label="Steps">
        <li class="study-flow-timeline-item is-active">
          <span class="study-flow-timeline-num">1</span>
          <span class="study-flow-timeline-text"><?php echo $t['step1_flow_1']; ?></span>
        </li>
        <li class="study-flow-timeline-item">
          <span class="study-flow-timeline-num">2</span>
          <span class="study-flow-timeline-text"><?php echo $t['step1_flow_2']; ?></span>
        </li>
        <li class="study-flow-timeline-item">
          <span class="study-flow-timeline-num">3</span>
          <span class="study-flow-timeline-text"><?php echo $t['step1_flow_3']; ?></span>
        </li>
        <li class="study-flow-timeline-item">
          <span class="study-flow-timeline-num">4</span>
          <span class="study-flow-timeline-text"><?php echo $t['step1_flow_4']; ?></span>
        </li>
      </ol>
    </header>

    <div class="study-selection-stack">

      <!-- REGIONS -->
      <section class="study-panel study-panel--accent" id="regionStep" aria-labelledby="study-regions-label">
        <div class="study-panel-badge" id="regionHint">
          <?php echo $lang === 'en' ? 'Start here' : 'Commencez ici'; ?>
        </div>

        <h2 id="study-regions-label" class="h6 fw-semibold mb-2 mb-md-3"><?php echo $t['step1_regions_label']; ?></h2>

        <select
          id="regions"
          class="form-select select-smart study-select-fullwidth"
          multiple
          data-placeholder="<?php echo $t['step1_regions_placeholder']; ?>"
        ></select>

        <p class="form-text mt-2 mb-0 small"><?php echo $t['step1_regions_help']; ?></p>
      </section>

      <!-- ADD UNIVERSITY -->
      <section id="studyAddUniversityPanel" class="study-panel study-panel--soft" style="display:none;" aria-labelledby="study-add-uni-label">
        <h2 id="study-add-uni-label" class="h6 fw-semibold mb-2 mb-md-3"><?php echo $t['step1_add_panel_title']; ?></h2>
        <div class="row g-3 align-items-stretch align-items-md-end">
          <div class="col-12 col-lg-8">
            <label for="addUniversitySelect" class="form-label small text-secondary d-lg-none mb-1"><?php echo $t['step1_pick_university']; ?></label>
            <select id="addUniversitySelect" class="form-select rounded-3 study-touch-control study-select-fullwidth" data-placeholder="<?php echo htmlspecialchars($t['step1_pick_university'], ENT_QUOTES, 'UTF-8'); ?>">
              <option value=""><?php echo $t['step1_pick_university']; ?></option>
            </select>
          </div>
          <div class="col-12 col-lg-4">
            <button type="button" id="btnAddUniversity" class="btn btn-primary w-100 rounded-3 fw-semibold study-touch-btn">
              <?php echo $t['step1_add_university_btn']; ?>
            </button>
          </div>
        </div>
        <p class="form-text mb-0 mt-2 small"><?php echo $t['step1_add_panel_help']; ?></p>
      </section>

      <!-- OPTIONAL FILTER: collapsed on small screens -->
      <details class="study-filter-details study-panel study-panel--muted">
        <summary class="study-filter-summary">
          <span class="study-filter-summary-icon" aria-hidden="true">⌕</span>
          <?php echo htmlspecialchars($t['step1_filter_summary'], ENT_QUOTES, 'UTF-8'); ?>
        </summary>
        <div class="study-filter-body pt-2 pt-md-0">
          <label class="form-label fw-semibold mb-2 d-none d-md-block"><?php echo $t['step1_search_label']; ?></label>

          <div class="row g-3">
            <div class="col-12 col-md-6">
              <label for="studySearch" class="form-label small text-secondary d-md-none mb-1"><?php echo $t['step1_search_placeholder']; ?></label>
              <input
                type="text"
                id="studySearch"
                class="form-control rounded-3 study-touch-control"
                placeholder="<?php echo $t['step1_search_placeholder']; ?>"
                autocomplete="off">
            </div>

            <div class="col-12 col-sm-6 col-md-3">
              <label for="searchLevel" class="form-label small text-secondary d-md-none mb-1"><?php echo $lang === 'en' ? 'Level' : 'Niveau'; ?></label>
              <select id="searchLevel" class="form-select rounded-3 study-touch-control study-select-fullwidth">
                <option value=""><?php echo $lang === 'en' ? 'All levels' : 'Tous les niveaux'; ?></option>
              </select>
            </div>

            <div class="col-12 col-sm-6 col-md-3 d-grid">
              <label class="form-label small text-secondary d-md-none mb-1">&nbsp;</label>
              <button
                type="button"
                id="clearSearch"
                class="btn btn-outline-secondary rounded-3 study-touch-btn">
                <?php echo $t['step1_clear']; ?>
              </button>
            </div>
          </div>

          <div id="searchResults" class="mt-2 small text-muted"></div>
        </div>
      </details>

      <!-- USER CARDS + EMPTY -->
      <section class="study-choices-section" aria-labelledby="study-choices-heading">
        <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-2 mb-md-3">
          <h2 id="study-choices-heading" class="h6 fw-semibold mb-0"><?php echo $t['step1_choices_heading']; ?></h2>
        </div>
        <div id="studyChoices" class="study-choices"></div>

        <div
          id="studyEmpty"
          class="study-empty"
          data-msg-no-region="<?php echo htmlspecialchars($t['step1_empty_no_region'], ENT_QUOTES, 'UTF-8'); ?>"
          data-msg-add-uni="<?php echo htmlspecialchars($t['step1_empty_add_uni'], ENT_QUOTES, 'UTF-8'); ?>"
        >
          <p class="mb-0 small" id="studyEmptyText"><?php echo htmlspecialchars($t['step1_empty_no_region'], ENT_QUOTES, 'UTF-8'); ?></p>
        </div>
      </section>

      <!-- SUMMARY LAST -->
      <aside id="studyCart" class="study-summary-card study-summary-aside" style="display:none;" aria-labelledby="study-cart-title">
        <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-2">
          <h2 id="study-cart-title" class="h6 fw-semibold mb-0"><?php echo $t['step1_cart_title']; ?></h2>
          <span class="badge rounded-pill study-summary-pill">
            <?php echo $lang === 'en' ? 'Summary' : 'Résumé'; ?>
          </span>
        </div>
        <div class="list-group list-group-flush small study-cart-list"></div>
        <p class="form-text mt-2 mb-0 small"><?php echo $t['step1_cart_help']; ?></p>
      </aside>

    </div>

  </div>
</div>

<!-- ================= TEMPLATES ================= -->
<template id="studyChoiceTemplate">
  <article class="study-choice study-university-card">

    <input type="hidden" class="region-id">

    <div class="study-uni-header">
      <span class="badge region-badge rounded-pill"></span>
      <button type="button" class="btn-remove-uni btn-remove btn btn-link text-danger p-0 small fw-semibold">
        <?php echo $t['step1_remove_uni']; ?>
      </button>
    </div>

    <div class="study-uni-body mt-3">
      <label class="form-label small text-secondary mb-1"><?php echo $t['step1_university']; ?></label>
      <select class="form-select university rounded-3 study-touch-control study-select-fullwidth" disabled></select>

      <div class="study-level-rows mt-3"></div>

      <button type="button" class="btn btn-outline-primary btn-sm rounded-pill btn-add-level mt-2">
        + <?php echo $t['step1_add_level']; ?>
      </button>
    </div>

  </article>
</template>

<template id="studyLevelRowTemplate">
  <div class="study-level-row study-level-row-card">
    <div class="row g-3 align-items-start">
      <div class="col-12 col-lg-5">
        <label class="form-label small mb-1"><?php echo $t['step1_level']; ?></label>
        <select class="form-select level rounded-3 study-touch-control study-select-fullwidth"></select>
      </div>
      <div class="col-12 col-lg-7">
        <label class="form-label small mb-1"><?php echo $t['step1_program']; ?></label>
        <select class="form-select program rounded-3 study-touch-control study-select-fullwidth" multiple></select>
      </div>
      <div class="col-12 study-level-row-actions">
        <button type="button" class="btn btn-outline-secondary btn-sm rounded-3 btn-remove-row study-remove-row-btn">
          <?php echo $t['step1_remove_row']; ?>
        </button>
      </div>
    </div>
  </div>
</template>
<!-- =====================================================
 STEP 2 : PERSONAL INFORMATION (STRICT VALIDATION – FINAL)
===================================================== -->

<div class="step">

  <div class="step-section">

<!-- ================= HEADER ================= -->
<div class="mb-4">
  <h5 class="fw-semibold mb-1"><?php echo $t['step2_title']; ?></h5>
  <p class="text-muted small mb-0">
    <?php echo $t['step2_desc']; ?>
  </p>
</div>

<!-- ================= PERSONAL DETAILS ================= -->
<div class="row">

  <!-- FIRST NAME -->
  <div class="col-md-6 mb-3">
    <label class="form-label"><?php echo $t['first_name']; ?> *</label>
    <input
      type="text"
      class="form-control"
      name="first_name"
      required
      minlength="2"
      maxlength="50"
      pattern="^[-A-Za-z\s']+$"
      placeholder="<?php echo $t['first_name']; ?>"
    >
  </div>

  <!-- LAST NAME -->
  <div class="col-md-6 mb-3">
    <label class="form-label"><?php echo $t['last_name']; ?> *</label>
    <input
      type="text"
      class="form-control"
      name="last_name"
      required
      minlength="2"
      maxlength="50"
      pattern="^[-A-Za-z\s']+$"
      placeholder="<?php echo $t['last_name']; ?>"
    >
  </div>

  <!-- EMAIL -->
  <div class="col-md-6 mb-3">
    <label class="form-label"><?php echo $t['email']; ?> *</label>
    <input
      type="email"
      class="form-control"
      name="email"
      required
      maxlength="100"
      placeholder="<?php echo $t['email']; ?>"
    >
  </div>

  <!-- PHONE -->
  <div class="col-md-6 mb-3">
    <label class="form-label fw-semibold"><?php echo $t['phone_label']; ?> *</label>

    <input
      type="tel"
      id="intl_phone"
      class="form-control"
      required
    >

    <!-- Hidden fields -->
    <input type="hidden" name="area_code" id="area_code" required>
    <input type="hidden" name="phone_number" id="phone_number" required>

    <div class="form-text">
      <?php echo $t['phone_help']; ?>
    </div>
  </div>

  <!-- GENDER -->
  <div class="col-md-6 mb-3">
    <label class="form-label"><?php echo $t['gender']; ?> *</label>
    <select class="form-select" name="gender" required>
      <option value=""><?php echo $t['gender']; ?></option>
      <?php foreach ($t['gender_options'] as $option): ?>
      <option value="<?php echo $option; ?>"><?php echo $option; ?></option>
      <?php endforeach; ?>
    </select>
  </div>

  <!-- DOB -->
  <div class="col-md-6 mb-3">
    <label class="form-label"><?php echo $t['dob']; ?> *</label>
    <input
      type="date"
      class="form-control"
      name="dob"
      required
      max="<?php echo date('Y-m-d'); ?>"
    >
  </div>

</div>

<!-- ================= IDENTITY ================= -->
<div class="row">

  <!-- PASSPORT -->
  <div class="col-md-6 mb-3">
    <label class="form-label"><?php echo $t['passport']; ?> *</label>
    <input
      type="text"
      class="form-control"
      name="passport_number"
      required
      minlength="6"
      maxlength="20"
      pattern="^[A-Z0-9]+$"
      placeholder="<?php echo $t['passport']; ?>"
      style="text-transform:uppercase"
    >
    <div class="form-text">Letters & numbers only (no spaces).</div>
  </div>

  <!-- NATIONAL ID -->
  <div class="col-md-6 mb-3">
    <label class="form-label"><?php echo $t['national_id']; ?> *</label>
    <input
      type="text"
      class="form-control"
      name="student_national_id"
      required
      minlength="5"
      maxlength="30"
      pattern="^[-A-Za-z0-9]+$"
      placeholder="<?php echo $t['national_id']; ?>"
    >
  </div>

  <!-- COUNTRY OF BIRTH -->
  <div class="col-md-4 mb-3">
    <label class="form-label"><?php echo $t['birth_country']; ?> *</label>
    <select
      class="form-select country-select"
      name="country_of_birth"
      required
    >
      <option value=""><?php echo $lang === 'en' ? 'Select Country' : 'Sélectionnez un pays'; ?></option>
    </select>
  </div>

  <!-- CITY -->
  <div class="col-md-4 mb-3">
    <label class="form-label"><?php echo $t['city_birth']; ?> *</label>
    <input
      type="text"
      class="form-control"
      name="city_of_birth"
      required
      minlength="2"
      pattern="^[-A-Za-z\s']+$"
      placeholder="<?php echo $t['city_birth']; ?>"
    >
  </div>

  <!-- NATIONALITY -->
  <div class="col-md-4 mb-3">
    <label class="form-label"><?php echo $t['nationality']; ?> *</label>
    <select
      class="form-select country-select"
      name="nationality"
      required
    >
      <option value=""><?php echo $lang === 'en' ? 'Select Nationality' : 'Sélectionnez une nationalité'; ?></option>
    </select>
  </div>

  <!-- SECOND NATIONALITY (OPTIONAL) -->
  <div class="col-md-6 mb-3">
    <label class="form-label"><?php echo $t['second_nationality']; ?></label>
    <select
      class="form-select country-select"
      name="second_nationality"
    >
      <option value=""><?php echo $lang === 'en' ? 'Optional' : 'Optionnel'; ?></option>
    </select>
  </div>

</div>

  </div>
</div>

<!-- =====================================================
 STEP 3 : ADDRESS & FAMILY (FULLY VALIDATED – NO SKIPS)
===================================================== -->
<div class="step">

  <div class="step-section">

    <!-- ================= HEADER ================= -->
    <div class="mb-4">
      <h5 class="fw-semibold mb-1"><?php echo $t['step3_title']; ?></h5>
      <p class="text-muted small mb-0">
        <?php echo $t['step3_desc']; ?>
      </p>
    </div>

    <!-- ================= ADDRESS ================= -->

    <input
      type="text"
      class="form-control mb-3"
      name="address_line1"
      placeholder="<?php echo $t['address1']; ?>"
      required
    >

    <input
      type="text"
      class="form-control mb-3"
      name="address_line2"
      placeholder="<?php echo $t['address2']; ?>"
      autocomplete="address-line2"
    >

    <div class="row">

      <div class="col-md-4 mb-3">
        <input
          type="text"
          class="form-control"
          name="city"
          placeholder="<?php echo $t['city']; ?>"
          required
        >
      </div>

      <div class="col-md-4 mb-3">
        <input
          type="text"
          class="form-control"
          name="state_province"
          placeholder="<?php echo $t['state']; ?>"
          required
        >
      </div>

      <div class="col-md-4 mb-3">
        <input
          type="text"
          class="form-control"
          name="postal_code"
          placeholder="<?php echo $t['postal']; ?>"
          required
        >
      </div>

    </div>

    <!-- ================= PARENTS ================= -->

    <h6 class="fw-semibold mt-4 mb-3"><?php echo $t['parents_title']; ?></h6>

    <div class="row">

      <div class="col-md-6 mb-3">
        <input
          type="text"
          class="form-control"
          name="father_first_name"
          placeholder="<?php echo $t['father_first']; ?>"
          required
        >
      </div>

      <div class="col-md-6 mb-3">
        <input
          type="text"
          class="form-control"
          name="father_last_name"
          placeholder="<?php echo $t['father_last']; ?>"
          required
        >
      </div>

      <div class="col-md-6 mb-3">
        <input
          type="text"
          class="form-control"
          name="mother_first_name"
          placeholder="<?php echo $t['mother_first']; ?>"
          required
        >
      </div>

      <div class="col-md-6 mb-3">
        <input
          type="text"
          class="form-control"
          name="mother_last_name"
          placeholder="<?php echo $t['mother_last']; ?>"
          required
        >
      </div>

    </div>

  </div>
</div>

<!-- =====================================================
 STEP 4 : EMERGENCY CONTACT (FULLY VALIDATED – NO SKIPS)
===================================================== -->
<div class="step">

  <div class="step-section">

    <!-- ================= HEADER ================= -->
    <div class="mb-4">
      <h5 class="fw-semibold mb-1"><?php echo $t['step4_title']; ?></h5>
      <p class="text-muted small mb-0">
        <?php echo $t['step4_desc']; ?>
      </p>
    </div>

    <div class="row">

      <!-- First Name -->
      <div class="col-md-6 mb-3">
        <input
          type="text"
          class="form-control"
          name="emergency_first_name"
          placeholder="<?php echo $t['emergency_first']; ?>"
          required
        >
      </div>

      <!-- Last Name -->
      <div class="col-md-6 mb-3">
        <input
          type="text"
          class="form-control"
          name="emergency_last_name"
          placeholder="<?php echo $t['emergency_last']; ?>"
          required
        >
      </div>

      <!-- Email -->
      <div class="col-md-6 mb-3">
        <input
          type="email"
          class="form-control"
          name="emergency_email"
          placeholder="<?php echo $t['emergency_email']; ?>"
          required
        >
      </div>

      <!-- Emergency Phone -->
      <div class="col-md-6 mb-3">
        <label class="form-label"><?php echo $t['emergency_phone_label']; ?></label>

        <!-- Visible phone input -->
        <input
          type="tel"
          id="emergency_phone"
          class="form-control"
          placeholder="<?php echo $t['phone_placeholder']; ?>"
          required
        >

        <!-- Hidden fields (KEEP DB STRUCTURE SAME) -->
        <input
          type="hidden"
          name="emergency_area_code"
          id="emergency_area_code"
          required
        >
        <input
          type="hidden"
          name="emergency_phone_number"
          id="emergency_phone_number"
          required
        >

        <div class="form-text">
          <?php echo $t['emergency_phone_help']; ?>
        </div>
      </div>

      <!-- Relationship -->
      <div class="col-md-6 mb-3">
        <input
          type="text"
          class="form-control"
          name="emergency_relationship"
          placeholder="<?php echo $t['relationship']; ?>"
          required
        >
      </div>

      <!-- Same Address -->
      <div class="col-md-6 mb-3">
        <select
          class="form-select"
          name="emergency_same_address"
          required
        >
          <option value=""><?php echo $t['same_address']; ?></option>
          <?php foreach ($t['same_address_options'] as $option): ?>
          <option value="<?php echo $option; ?>"><?php echo $option; ?></option>
          <?php endforeach; ?>
        </select>
      </div>

    </div>

  </div>
</div>
<!-- =====================================================
 STEP 5 : EDUCATION & BACKGROUND (CLEAN & SOLID)
===================================================== -->
<div class="step">
  <div class="step-section">

    <!-- ================= HEADER ================= -->
    <div class="mb-4">
      <h5 class="fw-semibold mb-1"><?php echo $t['step5_title']; ?></h5>
      <p class="text-muted small mb-0">
        <?php echo $t['step5_desc']; ?>
      </p>
    </div>

    <!-- ================= INSTITUTION DETAILS ================= -->

    <div class="mb-3">
      <label class="form-label"><?php echo $t['institution_name']; ?></label>
      <input type="text" class="form-control"
             name="previous_institution_name"
             placeholder="<?php echo $t['institution_name_placeholder']; ?>"
             required>
    </div>

    <div class="mb-3">
      <label class="form-label"><?php echo $t['institution_street']; ?></label>
      <input type="text" class="form-control"
             name="previous_institution_street"
             placeholder="<?php echo $t['institution_street_placeholder']; ?>"
             required>
    </div>

    <div class="mb-3">
      <label class="form-label"><?php echo $t['institution_city']; ?></label>
      <input type="text" class="form-control"
             name="previous_institution_city"
             placeholder="<?php echo $t['institution_city_placeholder']; ?>"
             required>
    </div>

    <div class="mb-3">
      <label class="form-label"><?php echo $t['institution_province']; ?></label>
      <input type="text" class="form-control"
             name="previous_institution_province"
             placeholder="<?php echo $t['institution_province_placeholder']; ?>"
             required>
    </div>

    <div class="mb-3">
      <label class="form-label"><?php echo $t['institution_country']; ?></label>
      <select class="form-select country-select"
              name="previous_institution_country"
              data-placeholder="<?php echo $lang === 'en' ? 'Select Country' : 'Sélectionnez un pays'; ?>"
              required>
        <option value=""><?php echo $lang === 'en' ? 'Select Country' : 'Sélectionnez un pays'; ?></option>
      </select>
    </div>

    <div class="mb-4">
      <label class="form-label"><?php echo $t['institution_postal']; ?></label>
      <input type="text" class="form-control"
             name="previous_institution_post_code"
             placeholder="<?php echo $t['institution_postal_placeholder']; ?>"
             required>
    </div>

    <!-- ================= STUDY INFORMATION ================= -->

    <div class="mb-3">
      <label class="form-label"><?php echo $t['language']; ?></label>
      <select class="form-select"
              name="language_of_instruction"
              required>
        <option value=""><?php echo $lang === 'en' ? 'Select Language' : 'Sélectionnez une langue'; ?></option>
        <?php foreach ($t['language_options'] as $option): ?>
        <option value="<?php echo $option; ?>"><?php echo $option; ?></option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="row">
      <div class="col-md-6 mb-3">
        <label class="form-label"><?php echo $t['study_start']; ?></label>
        <input type="date" class="form-control"
               name="previous_study_start"
               required>
      </div>

      <div class="col-md-6 mb-4">
        <label class="form-label"><?php echo $t['graduation']; ?></label>
        <input type="date" class="form-control"
               name="previous_study_graduation"
               required>
      </div>
    </div>

    <!-- ================= BACKGROUND QUESTIONS ================= -->

    <!-- STUDY GAP -->
    <div class="mb-3">
      <label class="form-label"><?php echo $t['study_gap']; ?></label>
      <select class="form-select conditional-select"
              name="study_gap"
              data-followup="study_gap_details"
              required>
        <option value=""><?php echo $lang === 'en' ? 'Select' : 'Sélectionnez'; ?></option>
        <?php foreach ($t['yes_no_options'] as $option): ?>
        <option value="<?php echo $option; ?>"><?php echo $option; ?></option>
        <?php endforeach; ?>
      </select>

      <textarea class="form-control mt-2 conditional-field"
                name="study_gap_details"
                placeholder="<?php echo $t['study_gap_placeholder']; ?>"
                style="display:none;"></textarea>
    </div>

    <!-- ADDITIONAL SECONDARY -->
    <div class="mb-3">
      <label class="form-label"><?php echo $t['secondary_school']; ?></label>
      <select class="form-select conditional-select"
              name="additional_secondary_school"
              data-followup="additional_secondary_details"
              required>
        <option value=""><?php echo $lang === 'en' ? 'Select' : 'Sélectionnez'; ?></option>
        <?php foreach ($t['yes_no_options'] as $option): ?>
        <option value="<?php echo $option; ?>"><?php echo $option; ?></option>
        <?php endforeach; ?>
      </select>

      <textarea class="form-control mt-2 conditional-field"
                name="additional_secondary_details"
                placeholder="<?php echo $t['secondary_school_placeholder']; ?>"
                style="display:none;"></textarea>
    </div>

    <!-- POST SECONDARY -->
    <div class="mb-3">
      <label class="form-label"><?php echo $t['post_secondary']; ?></label>
      <select class="form-select conditional-select"
              name="post_secondary"
              data-followup="post_secondary_details"
              required>
        <option value=""><?php echo $lang === 'en' ? 'Select' : 'Sélectionnez'; ?></option>
        <?php foreach ($t['yes_no_options'] as $option): ?>
        <option value="<?php echo $option; ?>"><?php echo $option; ?></option>
        <?php endforeach; ?>
      </select>

      <textarea class="form-control mt-2 conditional-field"
                name="post_secondary_details"
                placeholder="<?php echo $t['post_secondary_placeholder']; ?>"
                style="display:none;"></textarea>
    </div>

    <!-- CRIMINAL HISTORY -->
    <div class="mb-3">
      <label class="form-label"><?php echo $t['criminal_history']; ?></label>
      <select class="form-select conditional-select"
              name="criminal_history"
              data-followup="criminal_history_details"
              required>
        <option value=""><?php echo $lang === 'en' ? 'Select' : 'Sélectionnez'; ?></option>
        <?php foreach ($t['yes_no_options'] as $option): ?>
        <option value="<?php echo $option; ?>"><?php echo $option; ?></option>
        <?php endforeach; ?>
      </select>

      <textarea class="form-control mt-2 conditional-field"
                name="criminal_history_details"
                placeholder="<?php echo $t['criminal_history_placeholder']; ?>"
                style="display:none;"></textarea>
    </div>

    <!-- DISABILITY -->
    <div class="mb-3">
      <label class="form-label"><?php echo $t['disability']; ?></label>
      <select class="form-select conditional-select"
              name="disability"
              data-followup="disability_details"
              required>
        <option value=""><?php echo $lang === 'en' ? 'Select' : 'Sélectionnez'; ?></option>
        <?php foreach ($t['yes_no_options'] as $option): ?>
        <option value="<?php echo $option; ?>"><?php echo $option; ?></option>
        <?php endforeach; ?>
      </select>

      <textarea class="form-control mt-2 conditional-field"
                name="disability_details"
                placeholder="<?php echo $t['disability_placeholder']; ?>"
                style="display:none;"></textarea>
    </div>

    <!-- VISA REJECTION -->
    <div class="mb-3">
      <label class="form-label"><?php echo $t['visa_rejection']; ?></label>
      <select class="form-select conditional-select"
              name="visa_rejection"
              data-followup="visa_rejection_details"
              required>
        <option value=""><?php echo $lang === 'en' ? 'Select' : 'Sélectionnez'; ?></option>
        <?php foreach ($t['yes_no_options'] as $option): ?>
        <option value="<?php echo $option; ?>"><?php echo $option; ?></option>
        <?php endforeach; ?>
      </select>

      <textarea class="form-control mt-2 conditional-field"
                name="visa_rejection_details"
                placeholder="<?php echo $t['visa_rejection_placeholder']; ?>"
                style="display:none;"></textarea>
    </div>

  </div>
</div>

<!-- =====================================================
 STEP 6 : DESTINATION & FINANCE (PRODUCTION READY)
===================================================== -->

<div class="step">

  <div class="step-section">

    <!-- Step Header -->
    <div class="mb-4">
      <h5 class="fw-semibold mb-1"><?php echo $t['step6_title']; ?></h5>
      <p class="text-muted small mb-0">
        <?php echo $t['step6_desc']; ?>
      </p>
    </div>

    <!-- ================= DESTINATION ================= -->
    <div class="mb-4">

      <h6 class="fw-semibold mb-3"><?php echo $t['destination_title']; ?></h6>

      <div class="row g-3">

        <!-- Preferred Destination -->
        <div class="col-12 col-md-8 col-lg-6">
          <label class="form-label fw-semibold"><?php echo $t['preferred_destination']; ?></label>
          <input
            type="text"
            class="form-control"
            name="destination"
            id="preferredDestination"
            readonly
          >
          <div class="form-text">
            <?php echo $t['preferred_help']; ?>
          </div>
        </div>

      </div>

      <!-- ========== LOAN DESTINATION (MASTER ONLY) ========== -->
      <div class="row g-3 mt-1 loan-section">

        <div class="col-md-6">
          <label class="form-label fw-semibold"><?php echo $t['loan_destination']; ?></label>
          <input
            type="text"
            class="form-control"
            name="destination_loan"
            id="loanDestination"
            readonly
          >
          <div class="form-text">
            <?php echo $t['loan_destination_help']; ?>
          </div>
        </div>

        <div class="col-md-6">
          <label class="form-label fw-semibold"><?php echo $t['other_loan_destination']; ?></label>
          <input
            type="text"
            class="form-control"
            name="other_destination_loan"
            placeholder="<?php echo $t['other_loan_placeholder']; ?>"
          >
        </div>

      </div>

    </div>

    <!-- ================= FINANCE ================= -->
    <div>

      <h6 class="fw-semibold mb-3"><?php echo $t['finance_title']; ?></h6>

      <div class="row g-3">

        <!-- Tuition -->
        <div class="col-md-4">
          <label class="form-label fw-semibold"><?php echo $t['tuition']; ?></label>
          <select class="form-select finance-select" name="paying_tuition_fees">
            <option value=""><?php echo $lang === 'en' ? 'Select' : 'Sélectionnez'; ?></option>
            <?php foreach ($t['finance_options'] as $option): ?>
            <option value="<?php echo $option; ?>" class="<?php echo $option === 'Loan' ? 'loan-option' : ''; ?>"><?php echo $option; ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <!-- Living Cost -->
        <div class="col-md-4">
          <label class="form-label fw-semibold"><?php echo $t['living_cost']; ?></label>
          <select class="form-select finance-select" name="paying_cost_living">
            <option value=""><?php echo $lang === 'en' ? 'Select' : 'Sélectionnez'; ?></option>
            <?php foreach ($t['finance_options'] as $option): ?>
            <option value="<?php echo $option; ?>" class="<?php echo $option === 'Loan' ? 'loan-option' : ''; ?>"><?php echo $option; ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <!-- Travel -->
        <div class="col-md-4">
          <label class="form-label fw-semibold"><?php echo $t['travel']; ?></label>
          <select class="form-select finance-select" name="paying_travel_expenses">
            <option value=""><?php echo $lang === 'en' ? 'Select' : 'Sélectionnez'; ?></option>
            <?php foreach ($t['finance_options'] as $option): ?>
            <option value="<?php echo $option; ?>" class="<?php echo $option === 'Loan' ? 'loan-option' : ''; ?>"><?php echo $option; ?></option>
            <?php endforeach; ?>
          </select>
        </div>

      </div>
    </div>

  </div>
</div>
<!-- =====================================================
 STEP 7 : DOCUMENTS, AGENT & COMMENTS (FINAL REBUILD)
===================================================== -->
<div class="step">

  <div class="step-section">

    <!-- ================= HEADER ================= -->
    <div class="mb-4">
      <h5 class="fw-semibold mb-1"><?php echo $t['step7_title']; ?></h5>
      <p class="text-muted small mb-0">
        <?php echo $t['step7_desc']; ?>
      </p>
    </div>

    <!-- ================= DOCUMENT GRID ================= -->
    <div class="row g-4">

      <!-- DEGREE / TRANSCRIPTS (MULTI) -->
      <div class="col-md-6">
        <label class="form-label fw-semibold">
          <?php echo $t['degree_transcripts']; ?> <span class="text-danger">*</span>
        </label>
        <div class="doc-dropzone multi" data-field="degree_transcripts">
          <input type="file" multiple accept=".pdf,.jpg,.png">
          <div class="dz-content">
            <strong><?php echo $t['drop_transcripts']; ?></strong>
            <span><?php echo $t['multiple_files']; ?></span>
          </div>
          <ul class="dz-files"></ul>
        </div>
      </div>

      <!-- HIGH SCHOOL -->
      <div class="col-md-6">
        <label class="form-label fw-semibold"><?php echo $t['high_school']; ?></label>
        <div class="doc-dropzone" data-field="high_school_degree">
          <input type="file" accept=".pdf,.jpg,.png">
          <div class="dz-content">
            <strong><?php echo $t['drop_certificate']; ?></strong>
            <span><?php echo $t['single_file']; ?></span>
          </div>
          <ul class="dz-files"></ul>
        </div>
      </div>

      <!-- PASSPORT -->
      <div class="col-md-6">
        <label class="form-label fw-semibold">
          <?php echo $t['passport_doc']; ?> <span class="text-danger">*</span>
        </label>
        <div class="doc-dropzone" data-field="valid_passport">
          <input type="file" accept=".pdf,.jpg,.png">
          <div class="dz-content">
            <strong><?php echo $t['drop_passport']; ?></strong>
            <span><?php echo $t['single_file']; ?></span>
          </div>
          <ul class="dz-files"></ul>
        </div>
      </div>

      <!-- CV -->
      <div class="col-md-6">
        <label class="form-label fw-semibold">
          <?php echo $t['cv_resume']; ?> <span class="text-danger">*</span>
        </label>
        <div class="doc-dropzone" data-field="cv_resume">
          <input type="file" accept=".pdf,.jpg,.png">
          <div class="dz-content">
            <strong><?php echo $t['drop_cv']; ?></strong>
            <span><?php echo $t['single_file']; ?></span>
          </div>
          <ul class="dz-files"></ul>
        </div>
      </div>

      <!-- RECOMMENDATION (MULTI) -->
      <div class="col-md-6">
        <label class="form-label fw-semibold"><?php echo $t['recommendation']; ?></label>
        <div class="doc-dropzone multi" data-field="recommendation_letters">
          <input type="file" multiple accept=".pdf,.jpg,.png">
          <div class="dz-content">
            <strong><?php echo $t['drop_recommendation']; ?></strong>
            <span><?php echo $t['multiple_files']; ?></span>
          </div>
          <ul class="dz-files"></ul>
        </div>
      </div>

      <!-- PERSONAL STATEMENT -->
      <div class="col-md-6">
        <label class="form-label fw-semibold">
          <?php echo $t['personal_statement']; ?>
        </label>
        <div class="doc-dropzone" data-field="personal_statement">
          <input type="file" accept=".pdf,.jpg,.png">
          <div class="dz-content">
            <strong><?php echo $t['drop_statement']; ?></strong>
            <span><?php echo $t['single_file']; ?></span>
          </div>
          <ul class="dz-files"></ul>
        </div>
      </div>

      <!-- ENGLISH -->
      <div class="col-md-6">
        <label class="form-label fw-semibold"><?php echo $t['english_certificate']; ?></label>
        <div class="doc-dropzone" data-field="english_certificate">
          <input type="file" accept=".pdf,.jpg,.png">
          <div class="dz-content">
            <strong><?php echo $t['drop_certificate']; ?></strong>
            <span><?php echo $t['single_file']; ?></span>
          </div>
          <ul class="dz-files"></ul>
        </div>
      </div>

      <!-- BIRTH -->
      <div class="col-md-6">
        <label class="form-label fw-semibold"><?php echo $t['birth_certificate']; ?></label>
        <div class="doc-dropzone" data-field="birth_certificate">
          <input type="file" accept=".pdf,.jpg,.png">
          <div class="dz-content">
            <strong><?php echo $t['drop_certificate']; ?></strong>
            <span><?php echo $t['single_file']; ?></span>
          </div>
          <ul class="dz-files"></ul>
        </div>
      </div>

      <!-- PAYMENT -->
      <div class="col-md-6">
        <label class="form-label fw-semibold"><?php echo $t['payment_proof']; ?></label>
        <div class="doc-dropzone" data-field="payment_proof">
          <input type="file" accept=".pdf,.jpg,.png">
          <div class="dz-content">
            <strong><?php echo $t['drop_certificate']; ?></strong>
            <span><?php echo $t['single_file']; ?></span>
          </div>
          <ul class="dz-files"></ul>
        </div>
      </div>

    </div>

    <!-- ================= AI VALIDATION ================= -->
    <div id="docValidationStatus" class="mt-4 small text-muted"></div>

    <div id="docProgressWrap" class="upload-progress mt-3">
      <div class="upload-bar">
        <span id="docProgressText">0%</span>
      </div>
    </div>

  <!-- =====================================================
     AGENT INFORMATION (STEP 7 – REQUIRED)
===================================================== -->
<div class="mt-5 position-relative">

  <!-- ===============================
       REFERRAL SOURCE (FIXED)
  =============================== -->
  <div class="mb-4">
    <label class="form-label fw-semibold">
      <?php echo $t['referral_title']; ?> <span class="text-danger">*</span>
    </label>

    <select
      id="referral_source"
      class="form-select"
      required
    >
      <option value=""><?php echo $lang === 'en' ? 'Select an option' : 'Sélectionnez une option'; ?></option>
      <?php foreach ($t['referral_options'] as $option): ?>
      <option value="<?php echo $option['value']; ?>"><?php echo $option['text']; ?></option>
      <?php endforeach; ?>
    </select>

    <div class="form-text">
      <?php echo $t['referral_help']; ?>
    </div>
  </div>

  <!-- ===============================
       AGENT SECTION (HIDDEN BY DEFAULT)
  =============================== -->
  <div id="agentSection" style="display:none;">

    <!-- Agent Search -->
    <input
      type="text"
      id="agent_search"
      class="form-control mb-2"
      placeholder="<?php echo $t['agent_search_placeholder']; ?>"
      autocomplete="off"
    >

    <!-- Search Results -->
    <div
      id="agentResults"
      class="list-group position-absolute w-100 d-none"
      style="z-index: 1000;"
    ></div>

    <!-- Selected Agent Fields (LOCKED) -->
    <div class="row mt-3">

      <div class="col-md-4 mb-2">
        <label class="form-label small fw-semibold"><?php echo $t['agent_first']; ?></label>
        <input
          type="text"
          class="form-control"
          name="agent_first_name"
          id="agent_first_name"
          placeholder="<?php echo $lang === 'en' ? 'Auto-filled' : 'Rempli automatiquement'; ?>"
          readonly
          required
        >
      </div>

      <div class="col-md-4 mb-2">
        <label class="form-label small fw-semibold"><?php echo $t['agent_last']; ?></label>
        <input
          type="text"
          class="form-control"
          name="agent_last_name"
          id="agent_last_name"
          placeholder="<?php echo $lang === 'en' ? 'Auto-filled' : 'Rempli automatiquement'; ?>"
          readonly
          required
        >
      </div>

      <div class="col-md-4 mb-2">
        <label class="form-label small fw-semibold"><?php echo $t['agent_email']; ?></label>
        <input
          type="email"
          class="form-control"
          name="agent_email"
          id="agent_email"
          placeholder="<?php echo $lang === 'en' ? 'Auto-filled' : 'Rempli automatiquement'; ?>"
          readonly
          required
        >
      </div>

    </div>

    <div class="form-text mt-2">
      <?php echo $t['agent_help']; ?>
    </div>

  </div>

</div>


    <!-- ================= COMMENTS ================= -->
    <div class="mt-4">
      <textarea
        class="form-control"
        name="comments"
        placeholder="<?php echo $t['comments_placeholder']; ?>"
      ></textarea>
    </div>

  </div>
</div>

<!-- ================= NAVIGATION ================= -->
<div class="d-flex justify-content-between mt-4">
  <button type="button" class="btn btn-secondary" id="prevBtn"><?php echo $t['prev']; ?></button>
  <button type="button" class="btn btn-primary" id="nextBtn"><?php echo $t['next']; ?></button>
</div>

</form>

<!-- Save / server validation feedback (Bootstrap modal; requires bundle JS below) -->
<div class="modal fade" id="applicationSaveErrorModal" tabindex="-1" aria-labelledby="applicationSaveErrorModalLabel" aria-hidden="true" data-bs-backdrop="static">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content border-0 shadow">
      <div class="modal-header border-0 pb-0">
        <h5 class="modal-title fw-semibold" id="applicationSaveErrorModalLabel"><?php echo htmlspecialchars($t['save_error_title'], ENT_QUOTES, 'UTF-8'); ?></h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body pt-2 application-save-error-body text-body small"></div>
      <div class="modal-footer border-0 pt-0">
        <button type="button" class="btn btn-primary px-4" data-bs-dismiss="modal"><?php echo htmlspecialchars($t['save_error_ok'], ENT_QUOTES, 'UTF-8'); ?></button>
      </div>
    </div>
  </div>
</div>
</div>
</div>
</div>

<link
  rel="stylesheet"
  href="https://cdn.jsdelivr.net/npm/intl-tel-input@18.2.1/build/css/intlTelInput.css"
/>

<script src="https://cdn.jsdelivr.net/npm/intl-tel-input@18.2.1/build/js/intlTelInput.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/intl-tel-input@18.2.1/build/js/utils.js"></script>

<script src="https://cdn.jsdelivr.net/npm/jquery@3.7.1/dist/jquery.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2-bootstrap-5-theme@1.3.0/dist/select2-bootstrap-5.min.js"></script>

<!-- Language Switcher Script -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    // Language switcher functionality
    document.querySelectorAll('.language-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            const lang = this.dataset.lang;
            // Reload page with new language
            const url = new URL(window.location);
            url.searchParams.set('lang', lang);
            window.location.href = url.toString();
        });
    });

    // Update form placeholders when language changes (if using AJAX)
    window.updateFormLanguage = function(lang) {
        // This function can be called if you implement AJAX language switching
        console.log('Language updated to:', lang);
    };
});
</script>

<!-- Bootstrap JS (modal for save errors) -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<!-- Your existing JavaScript files -->
<script src="application.js"></script>
<script src="study-search.js"></script>

<!-- Your existing inline scripts (keep them as they are - they don't need translation) -->
<script>
"use strict";

/* =====================================================
   GLOBAL SAFETY (shared with application.js)
   Keeps track of uploaded files per field
===================================================== */
window.uploadStatus = window.uploadStatus || {};

/* =====================================================
   PROGRESS CONTROLLER (UI ONLY)
   Single global progress bar controller
===================================================== */
function createProgressController() {
  const wrap   = document.getElementById("docProgressWrap");
  const bar    = wrap.querySelector(".upload-bar");
  const text   = document.getElementById("docProgressText");
  const status = document.getElementById("docValidationStatus");

  /* ---------- Reset UI ---------- */
  bar.style.background = "";
  bar.style.width = "0%";
  text.textContent = "0%";
  status.textContent = "";

  wrap.style.display = "block";
  wrap.classList.add("active");

  return {
    set(percent, label) {
      percent = Math.max(0, Math.min(100, percent));
      bar.style.width = percent + "%";
      text.textContent = percent + "%";
      if (label) status.textContent = label;
    },

    success(message) {
      bar.style.width = "100%";
      text.textContent = "100%";
      status.textContent = message || "Document validated successfully";
    },

    error(message) {
      bar.style.width = "100%";
      bar.style.background = "#dc3545";
      text.textContent = "!";
      status.textContent = message || "Upload failed";
    },

    hide(delay = 1200) {
      setTimeout(() => {
        wrap.classList.remove("active");
        wrap.style.display = "none";
      }, delay);
    }
  };
}

/* =====================================================
   DROPZONE INITIALIZATION
   Works for single & multi-file zones
===================================================== */
document.querySelectorAll(".doc-dropzone").forEach(zone => {

  const input    = zone.querySelector('input[type="file"]');
  const list     = zone.querySelector(".dz-files");
  const field    = zone.dataset.field;
  const multiple = input.hasAttribute("multiple");

  /* ---------- Render selected files ---------- */
  function renderFiles(files) {
    list.innerHTML = "";
    [...files].forEach(file => {
      const li = document.createElement("li");
      li.textContent = file.name;
      list.appendChild(li);
    });
  }

  /* ---------- Drag & Drop UI ---------- */
  ["dragenter", "dragover"].forEach(evt =>
    zone.addEventListener(evt, e => {
      e.preventDefault();
      zone.classList.add("dragover");
    })
  );

  ["dragleave", "drop"].forEach(evt =>
    zone.addEventListener(evt, e => {
      e.preventDefault();
      zone.classList.remove("dragover");
    })
  );

  zone.addEventListener("drop", e => {
    if (!multiple && e.dataTransfer.files.length > 1) {
      alert("Only one file is allowed for this document.");
      return;
    }
    input.files = e.dataTransfer.files;
    input.dispatchEvent(new Event("change"));
  });

  /* ---------- File selection ---------- */
  input.addEventListener("change", async () => {
    if (!input.files || !input.files.length) return;

    if (!multiple && input.files.length > 1) {
      alert("Only one file allowed.");
      input.value = "";
      return;
    }

    renderFiles(input.files);

  /* ---------- Upload files sequentially ---------- */
for (const file of input.files) {
  await uploadSingleFile(field, file);
}

/* ---------- Lock ONLY single-file inputs ---------- */
if (!multiple) {
  input.disabled = true;
  input.classList.add("is-valid");
} else {
  // Allow multi uploads to continue
  input.value = ""; // reset so user can add more files
}

  });
});

/* =====================================================
   SINGLE FILE UPLOAD (CORE LOGIC)
   One file → one request → safe progress
===================================================== */
function uploadSingleFile(field, file) {

  return new Promise((resolve, reject) => {

    /* ===============================
       SAFETY: TRACK PER FIELD
    =============================== */
    window.uploadStatus[field] = window.uploadStatus[field] || [];

    // Prevent duplicate upload
    if (window.uploadStatus[field].includes(file.name)) {
      resolve();
      return;
    }

    /* ===============================
       UI REFERENCES
    =============================== */
    const zone  = document.querySelector(
      `.doc-dropzone[data-field="${field}"]`
    );
    const input = zone?.querySelector('input[type="file"]');
    const list  = zone?.querySelector('.dz-files');
    const isMulti = input?.hasAttribute("multiple");

    const progress = createProgressController();
    progress.set(5, "Preparing document…");

    /* ===============================
       BUILD FORM DATA
    =============================== */
    const formData = new FormData();
    formData.append("file", file);
    formData.append("field", field);
    formData.append(
      "first_name",
      document.querySelector('[name="first_name"]')?.value || ""
    );
    formData.append(
      "last_name",
      document.querySelector('[name="last_name"]')?.value || ""
    );

    /* ===============================
       INIT REQUEST
    =============================== */
    const xhr = new XMLHttpRequest();
    xhr.open("POST", "upload_file.php", true);

    let validationTimer = null;

    /* ===============================
       UPLOAD PROGRESS (0 → 60%)
    =============================== */
    xhr.upload.onprogress = e => {
      if (!e.lengthComputable) return;
      const percent = Math.round((e.loaded / e.total) * 60);
      progress.set(percent, `Uploading ${file.name}…`);
    };

    /* ===============================
       UPLOAD COMPLETE → START AI UI
    =============================== */
    xhr.upload.onload = () => {
      progress.set(62, "Upload complete. Validating document…");
      validationTimer = startValidationSimulation(progress);
    };

    /* ===============================
       NETWORK ERROR
    =============================== */
    xhr.onerror = () => {
      clearInterval(validationTimer);
      progress.error("Network error during upload");
      cleanupFailedFile();
      reject();
    };

    /* ===============================
       SERVER RESPONSE
    =============================== */
    xhr.onload = () => {
      clearInterval(validationTimer);

      if (xhr.status !== 200) {
        progress.error("Server error during validation");
        cleanupFailedFile();
        reject();
        return;
      }

      let response;
      try {
        response = JSON.parse(xhr.responseText);
      } catch {
        progress.error("Invalid server response");
        cleanupFailedFile();
        reject();
        return;
      }

      /* ===============================
         SUCCESS
      =============================== */
      if (response.status === "success") {

        progress.success(response.message || "Document validated");
        window.uploadStatus[field].push(file.name);
        progress.hide();
        resolve();
        return;
      }

      /* ===============================
         ❌ VALIDATION FAILED
      =============================== */
      progress.error(response.message || "Document validation failed");
      cleanupFailedFile();
      reject();
    };

    xhr.send(formData);

    /* =====================================================
       CLEANUP FUNCTION (CRITICAL)
       Removes bad documents safely
    ===================================================== */
    function cleanupFailedFile() {

      // Remove from uploadStatus
      window.uploadStatus[field] =
        (window.uploadStatus[field] || []).filter(
          name => name !== file.name
        );

      // Remove preview chip
      if (list) {
        [...list.children].forEach(li => {
          if (li.textContent === file.name) {
            li.remove();
          }
        });
      }

      // Re-enable input
      if (input) {
        input.disabled = false;
        input.classList.remove("is-valid");
        input.value = "";
      }

      // Hide progress after short delay
      progress.hide(1500);
    }

  });
}

/* =====================================================
   VALIDATION SIMULATION (60 → 98%)
   Runs independently of backend timing
===================================================== */
function startValidationSimulation(progress) {
  let percent = 62;

  const labels = [
    "Extracting document text…",
    "Analyzing format & structure…",
    "Verifying identity consistency…",
    "Checking authenticity markers…",
    "Final validation…"
  ];

  let i = 0;

  return setInterval(() => {
    if (percent >= 98) return;
    percent += Math.random() * 4;
    progress.set(
      Math.min(98, Math.round(percent)),
      labels[i % labels.length]
    );
    i++;
  }, 700);
}
</script>

<script>
const searchInput = document.getElementById('agent_search');
const resultsBox  = document.getElementById('agentResults');

searchInput.addEventListener('input', function () {
    const query = this.value.trim();

    if (query.length < 2) {
        resultsBox.classList.add('d-none');
        resultsBox.innerHTML = '';
        return;
    }

    fetch('searchAgents.php?q=' + encodeURIComponent(query))
        .then(res => (res.ok ? res.json() : Promise.reject(new Error('Agent search failed'))))
        .then(data => {
            resultsBox.innerHTML = '';

            if (!Array.isArray(data) || data.length === 0) {
                resultsBox.classList.add('d-none');
                return;
            }

            data.forEach(agent => {
                const item = document.createElement('button');
                item.type = 'button';
                item.className = 'list-group-item list-group-item-action';
                item.innerHTML = `
                    <strong>${agent.full_name}</strong><br>
                    <small>${agent.email}</small>
                `;

                item.onclick = () => {
                    document.getElementById('agent_first_name').value = agent.first_name;
                    document.getElementById('agent_last_name').value  = agent.last_name;
                    document.getElementById('agent_email').value      = agent.email;
                    searchInput.value = agent.full_name;
                    resultsBox.classList.add('d-none');
                };

                resultsBox.appendChild(item);
            });

            resultsBox.classList.remove('d-none');
        })
        .catch(() => {
            resultsBox.classList.add('d-none');
        });
});

// Close dropdown when clicking outside
document.addEventListener('click', e => {
    if (!e.target.closest('#agent_search')) {
        resultsBox.classList.add('d-none');
    }
});
</script>
<script>
(function () {
    const firstName = document.getElementById('agent_first_name');
    const lastName  = document.getElementById('agent_last_name');
    const email     = document.getElementById('agent_email');

    if (!firstName || !lastName || !email) return;

    function lockFields() {
        firstName.readOnly = true;
        lastName.readOnly  = true;
        email.readOnly     = true;
    }

    /* 🔒 Hard lock as soon as any value appears */
    function enforceLock() {
        if (
            firstName.value.trim() !== '' ||
            lastName.value.trim() !== '' ||
            email.value.trim() !== ''
        ) {
            lockFields();
        }
    }

    /* Catch ALL ways values can be set */
    ['input', 'change', 'keyup', 'paste'].forEach(evt => {
        firstName.addEventListener(evt, enforceLock);
        lastName.addEventListener(evt, enforceLock);
        email.addEventListener(evt, enforceLock);
    });

    /* Also enforce lock on page load (safety) */
    document.addEventListener('DOMContentLoaded', enforceLock);

})();
</script>
<script>
(function () {

  const loanSections   = document.querySelectorAll(".loan-section");
  const loanOptions    = document.querySelectorAll(".loan-option");
  const financeSelects = document.querySelectorAll(".finance-select");
  const studyChoices   = document.getElementById("studyChoices");

  function normalize(text) {
    return text.toLowerCase().replace(/[^a-z]/g, "");
  }

  function isMasterLevel(name) {
    const v = normalize(name);
    return [
      "master",
      "masters",
      "msc",
      "mba",
      "mphil",
      "mster"
    ].some(k => v.includes(k));
  }

  function clearLoanData() {
    document
      .querySelectorAll('input[name="destination_loan"], input[name="other_destination_loan"]')
      .forEach(i => i.value = "");

    financeSelects.forEach(select => {
      if (select.value === "Loan") {
        select.value = "";
      }
    });
  }

  function applyLoanPolicy() {
    let allowLoan = false;

    document.querySelectorAll(".study-choice .level").forEach(select => {
      const opt = select.selectedOptions[0];
      if (!opt) return;

      const levelName =
        opt.dataset?.name ||
        opt.textContent ||
        "";

      if (isMasterLevel(levelName)) {
        allowLoan = true;
      }
    });

    // Toggle loan destination fields
    loanSections.forEach(section => {
      section.style.display = allowLoan ? "" : "none";
    });

    // Toggle Loan option in finance dropdowns
    loanOptions.forEach(option => {
      option.style.display = allowLoan ? "" : "none";
      option.disabled = !allowLoan;
    });

    if (!allowLoan) {
      clearLoanData();
    }
  }

  // Observe dynamic program changes
  const observer = new MutationObserver(applyLoanPolicy);
  observer.observe(studyChoices, { childList: true, subtree: true });

  // Catch direct changes to level selects
  document.addEventListener("change", e => {
    if (e.target.classList.contains("level")) {
      applyLoanPolicy();
    }
  });

  document.addEventListener("DOMContentLoaded", applyLoanPolicy);

})();
</script>
<script>
(function () {

  const preferredDestination = document.getElementById("preferredDestination");
  const loanDestination      = document.getElementById("loanDestination");
  const loanSections         = document.querySelectorAll(".loan-section");
  const financeSelects       = document.querySelectorAll(".finance-select");
  const studyChoices         = document.getElementById("studyChoices");

  function normalize(text) {
    return text.toLowerCase().replace(/[^a-z]/g, "");
  }

  function isMasterLevel(name) {
    const v = normalize(name);
    return [
      "master",
      "masters",
      "msc",
      "mba",
      "mphil",
      "mster"
    ].some(k => v.includes(k));
  }

  function clearLoanData() {
    if (loanDestination) loanDestination.value = "";

    document
      .querySelectorAll('input[name="other_destination_loan"]')
      .forEach(i => i.value = "");

    financeSelects.forEach(select => {
      if (select.value === "Loan") {
        select.value = "";
      }
    });
  }

  function syncLoanDestination() {
    if (!loanDestination || !preferredDestination) return;

    loanDestination.value = preferredDestination.value || "";
  }

  function applyLoanPolicy() {
    let allowLoan = false;

    document.querySelectorAll(".study-choice .level").forEach(select => {
      const opt = select.selectedOptions[0];
      if (!opt) return;

      const levelName =
        opt.dataset?.name ||
        opt.textContent ||
        "";

      if (isMasterLevel(levelName)) {
        allowLoan = true;
      }
    });

    // Toggle loan destination section
    loanSections.forEach(section => {
      section.style.display = allowLoan ? "" : "none";
    });

    // Toggle Loan option in finance selects
    document.querySelectorAll(".loan-option").forEach(opt => {
      opt.disabled = !allowLoan;
      opt.style.display = allowLoan ? "" : "none";
    });

    if (allowLoan) {
      syncLoanDestination();
    } else {
      clearLoanData();
    }
  }

  /* ===============================
     WATCHERS
  =============================== */

  // When study programs change
  const observer = new MutationObserver(applyLoanPolicy);
  observer.observe(studyChoices, { childList: true, subtree: true });

  // When program level changes
  document.addEventListener("change", e => {
    if (e.target.classList.contains("level")) {
      applyLoanPolicy();
    }
  });

  // 🔁 When preferred destination changes → sync loan destination
  preferredDestination?.addEventListener("input", syncLoanDestination);
  preferredDestination?.addEventListener("change", syncLoanDestination);

  document.addEventListener("DOMContentLoaded", applyLoanPolicy);

})();
</script>
<script>
(function () {

  document.querySelectorAll('.conditional-select').forEach(select => {

    const targetName = select.dataset.followup;
    const field = document.querySelector(
      '.conditional-field[name="' + targetName + '"]'
    );

    if (!field) return;

    function toggle() {
      if (select.value === 'Yes') {
        field.style.display = 'block';
      } else {
        field.style.display = 'none';
        field.value = '';
      }
    }

    // Initial state
    toggle();

    // On change
    select.addEventListener('change', toggle);
  });

})();
</script>
<script>
document.addEventListener("DOMContentLoaded", function () {

  const phoneInput = document.querySelector("#emergency_phone");
  const areaCode   = document.querySelector("#emergency_area_code");
  const phoneNum   = document.querySelector("#emergency_phone_number");

  if (!phoneInput) return;

  const iti = window.intlTelInput(phoneInput, {
    initialCountry: "auto",
    separateDialCode: true,
    nationalMode: true,
    utilsScript:
      "https://cdn.jsdelivr.net/npm/intl-tel-input@18.2.1/build/js/utils.js",
    geoIpLookup: function (callback) {
      fetch("https://ipapi.co/json/")
        .then(res => (res.ok ? res.json() : Promise.reject(new Error("geo fail"))))
        .then(data => callback(data && data.country_code ? data.country_code : "US"))
        .catch(() => callback("US"));
    }
  });

  /* ===============================
     LIVE VALIDATION
  =============================== */
  phoneInput.addEventListener("blur", () => {
    if (phoneInput.value.trim() === "") return;

    if (!iti.isValidNumber()) {
      phoneInput.classList.add("is-invalid");
      phoneInput.classList.remove("is-valid");
    } else {
      phoneInput.classList.remove("is-invalid");
      phoneInput.classList.add("is-valid");
    }
  });

  /* ===============================
     SAVE VALUES FOR BACKEND
  =============================== */
  phoneInput.addEventListener("change", syncPhone);
  phoneInput.addEventListener("keyup", syncPhone);

  function syncPhone() {
    if (!iti.isValidNumber()) return;

    areaCode.value = "+" + iti.getSelectedCountryData().dialCode;
    phoneNum.value = iti.getNumber(
      window.intlTelInputUtils.numberFormat.NATIONAL
    );
  }

});
</script>
<script>
document.addEventListener("DOMContentLoaded", () => {

  const phoneInput = document.querySelector("#intl_phone");
  if (!phoneInput) return;

  const iti = window.intlTelInput(phoneInput, {
    initialCountry: "auto",
    nationalMode: true,
    separateDialCode: true,
    autoPlaceholder: "polite",
    preferredCountries: ["us", "gb", "fr", "ca", "de", "rw"],
    geoIpLookup: callback => {
      fetch("https://ipapi.co/json/")
        .then(res => (res.ok ? res.json() : Promise.reject(new Error("geo fail"))))
        .then(data => callback(data && data.country_code ? data.country_code : "us"))
        .catch(() => callback("us"));
    },
    utilsScript:
      "https://cdn.jsdelivr.net/npm/intl-tel-input@19.5.7/build/js/utils.js"
  });

  const areaCodeInput  = document.getElementById("area_code");
  const phoneNumInput  = document.getElementById("phone_number");

  function syncPhoneFields() {
    if (!iti.isValidNumber()) {
      areaCodeInput.value = "";
      phoneNumInput.value = "";
      phoneInput.classList.add("is-invalid");
      return false;
    }

    const data = iti.getSelectedCountryData();

    areaCodeInput.value = `+${data.dialCode}`;
    phoneNumInput.value = phoneInput.value.replace(/\D/g, "");

    phoneInput.classList.remove("is-invalid");
    phoneInput.classList.add("is-valid");
    return true;
  }

  phoneInput.addEventListener("blur", syncPhoneFields);
  phoneInput.addEventListener("change", syncPhoneFields);
  phoneInput.addEventListener("keyup", syncPhoneFields);

  /* Prevent form submit if invalid */
  const form = phoneInput.closest("form");
  if (form) {
    form.addEventListener("submit", e => {
      if (!syncPhoneFields()) {
        e.preventDefault();
        alert("Please enter a valid phone number.");
      }
    });
  }

});
</script>
<script>
document.addEventListener("DOMContentLoaded", () => {
  // =====================================================
  // REGION SELECTION AS CLOSEABLE TABS - DEBUG VERSION
  // =====================================================
  
  console.log("=== REGION TABS DEBUG START ===");
  
  const regionsSelect = document.getElementById("regions");
  const regionStep = document.getElementById("regionStep");
  
  console.log("regionsSelect:", regionsSelect);
  console.log("regionStep:", regionStep);
  
  if (!regionsSelect || !regionStep) {
    console.error("Missing required elements");
    return;
  }
  
  // Debug: Check if regions are loaded
  console.log("Initial regions options count:", regionsSelect.options.length);
  for (let i = 0; i < regionsSelect.options.length; i++) {
    console.log(`Option ${i}:`, regionsSelect.options[i].value, regionsSelect.options[i].text);
  }
  
  // Create container for selected region tabs
  const selectedRegionsContainer = document.createElement("div");
  selectedRegionsContainer.id = "selectedRegionsContainer";
  selectedRegionsContainer.className = "selected-regions-container mt-3";
  selectedRegionsContainer.style.cssText = `
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    min-height: 40px;
    padding: 8px;
    background: #f8fafc;
    border-radius: 8px;
    border: 1px solid #e2e8f0;
  `;
  
  // Insert the container after the regions select
  regionsSelect.parentNode.insertBefore(selectedRegionsContainer, regionsSelect.nextSibling);
  
  // Function to create a region tab
  function createRegionTab(regionId, regionName) {
    const tab = document.createElement("div");
    tab.className = "region-tab";
    tab.dataset.regionId = regionId;
    tab.style.cssText = `
      display: inline-flex;
      align-items: center;
      gap: 6px;
      padding: 6px 12px;
      background: linear-gradient(135deg, #0d6efd, #4f8cff);
      color: white;
      border-radius: 20px;
      font-size: 13px;
      font-weight: 600;
      box-shadow: 0 2px 4px rgba(13, 110, 253, 0.2);
      transition: all 0.2s ease;
      cursor: default;
    `;
    
    tab.innerHTML = `
      <span class="region-name">${regionName}</span>
      <button type="button" class="region-close-btn" style="
        background: none;
        border: none;
        color: white;
        font-size: 16px;
        font-weight: bold;
        cursor: pointer;
        padding: 0;
        width: 18px;
        height: 18px;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        transition: background-color 0.2s ease;
      " title="Remove region">×</button>
    `;
    
    // Add hover effects
    tab.addEventListener('mouseenter', () => {
      tab.style.transform = 'translateY(-1px)';
      tab.style.boxShadow = '0 4px 8px rgba(13, 110, 253, 0.3)';
    });
    
    tab.addEventListener('mouseleave', () => {
      tab.style.transform = 'translateY(0)';
      tab.style.boxShadow = '0 2px 4px rgba(13, 110, 253, 0.2)';
    });
    
    // Add close functionality
    const closeBtn = tab.querySelector('.region-close-btn');
    closeBtn.addEventListener('click', (e) => {
      e.stopPropagation();
      removeRegionTab(regionId);
    });
    
    closeBtn.addEventListener('mouseenter', () => {
      closeBtn.style.backgroundColor = 'rgba(255, 255, 255, 0.2)';
    });
    
    closeBtn.addEventListener('mouseleave', () => {
      closeBtn.style.backgroundColor = 'none';
    });
    
    return tab;
  }
  
  // Function to remove a region tab
  function removeRegionTab(regionId) {
    const tab = selectedRegionsContainer.querySelector(`[data-region-id="${regionId}"]`);
    if (tab) {
      tab.style.transform = 'scale(0.8)';
      tab.style.opacity = '0';
      setTimeout(() => {
        tab.remove();
        updateSelectedRegionsDisplay();
      }, 200);
    }
    
    // Update the select2 value
    const currentValues = $(regionsSelect).val() || [];
    const newValues = currentValues.filter(id => String(id) !== String(regionId));
    $(regionsSelect).val(newValues).trigger('change');
  }
  
  // Function to update the display based on selected regions
  function updateSelectedRegionsDisplay() {
    const selectedRegions = $(regionsSelect).val() || [];
    
    // Clear existing tabs
    selectedRegionsContainer.innerHTML = '';
    
    if (selectedRegions.length === 0) {
      selectedRegionsContainer.innerHTML = `
        <div style="color: #64748b; font-size: 13px; font-style: italic;">
          No regions selected. Select regions above to see them here.
        </div>
      `;
      return;
    }
    
    // Add tabs for each selected region
    selectedRegions.forEach(regionId => {
      const option = regionsSelect.querySelector(`option[value="${regionId}"]`);
      if (option) {
        const tab = createRegionTab(regionId, option.textContent);
        selectedRegionsContainer.appendChild(tab);
      }
    });
  }
  
  /* Picker label sync (set after custom face is created) */
  let syncRegionPickerLabel = function () {};

  // Listen for changes in the regions select
  $(regionsSelect).on('change', function() {
    console.log("=== REGIONS SELECT CHANGE ===");
    console.log("Current values:", $(this).val());
    console.log("Options count:", this.options.length);
    updateSelectedRegionsDisplay();
    syncRegionPickerLabel();
  });
  
  // Debug: Monitor jQuery and Select2
  console.log("jQuery available:", typeof $ !== 'undefined');
  console.log("Select2 available:", typeof $.fn.select2 !== 'undefined');
  
  // Check if Select2 is initialized on regions
  setTimeout(() => {
    const $regions = $('#regions');
    console.log("$regions object:", $regions);
    console.log("Select2 initialized:", $regions.hasClass('select2-hidden-accessible'));
    console.log("Select2 container:", $regions.next('.select2-container'));
    
    updateSelectedRegionsDisplay();
  }, 500);
  
  // Wait for regions to be loaded, then initialize display
  setTimeout(() => {
    updateSelectedRegionsDisplay();
  }, 500);
  
  /* Select2 widget hidden via CSS (#regionStep …); keep programmatic sync */

  const regionPlaceholder =
    regionsSelect.getAttribute("data-placeholder") ||
    "Select one or more regions";

  // Full-width button surface — no text input / no I-beam cursor
  const dropdownTrigger = document.createElement("div");
  dropdownTrigger.className = "regions-dropdown-trigger w-100 position-relative";

  const pickerFace = document.createElement("button");
  pickerFace.type = "button";
  pickerFace.className =
    "form-control text-start d-flex align-items-center justify-content-between study-touch-control rounded-3 regions-picker-face";
  pickerFace.setAttribute("aria-haspopup", "listbox");
  pickerFace.setAttribute("aria-expanded", "false");
  pickerFace.style.cursor = "pointer";
  pickerFace.style.userSelect = "none";

  const pickerLabel = document.createElement("span");
  pickerLabel.className = "regions-picker-label text-body-secondary text-truncate me-2";
  pickerLabel.textContent = regionPlaceholder;

  const pickerChevron = document.createElement("span");
  pickerChevron.className = "small text-primary fw-semibold flex-shrink-0";
  pickerChevron.setAttribute("aria-hidden", "true");
  pickerChevron.textContent = "▾";

  pickerFace.appendChild(pickerLabel);
  pickerFace.appendChild(pickerChevron);
  dropdownTrigger.appendChild(pickerFace);

  const dropdownMenu = document.createElement("div");
  dropdownMenu.className = "regions-dropdown-menu";
  dropdownMenu.style.cssText = `
    position: absolute;
    top: 100%;
    left: 0;
    right: 0;
    background: white;
    border: 1px solid #d1d5db;
    border-radius: 8px;
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
    z-index: 1000;
    max-height: 220px;
    overflow-y: auto;
    display: none;
    margin-top: 4px;
  `;
  dropdownTrigger.appendChild(dropdownMenu);

  function setRegionMenuOpen(open) {
    dropdownMenu.style.display = open ? "block" : "none";
    pickerFace.setAttribute("aria-expanded", open ? "true" : "false");
  }

  syncRegionPickerLabel = function () {
    const selected = $(regionsSelect).val() || [];
    if (!selected.length) {
      pickerLabel.textContent = regionPlaceholder;
      pickerLabel.classList.add("text-body-secondary");
      return;
    }
    const names = selected.map((id) => {
      const opt = regionsSelect.querySelector(
        'option[value="' + String(id).replace(/"/g, '\\"') + '"]'
      );
      return opt ? opt.textContent : id;
    });
    pickerLabel.textContent = names.join(", ");
    pickerLabel.classList.remove("text-body-secondary");
  };
  
  // Function to populate dropdown menu
  function populateDropdownMenu() {
    // Clear existing items
    dropdownMenu.innerHTML = '';
    
    // Add all region options to the dropdown
    const regionOptions = regionsSelect.querySelectorAll('option');
    let hasOptions = false;
    
    regionOptions.forEach(option => {
      if (option.value) {
        hasOptions = true;
        const item = document.createElement("div");
        item.className = "region-dropdown-item";
        item.style.cssText = `
          padding: 8px 12px;
          cursor: pointer;
          border-bottom: 1px solid #f3f4f6;
          transition: background-color 0.2s ease;
          font-size: 13px;
        `;
        item.textContent = option.textContent;
        item.dataset.value = option.value;
        
        item.addEventListener('mouseenter', () => {
          item.style.backgroundColor = '#f8fafc';
        });
        
        item.addEventListener('mouseleave', () => {
          item.style.backgroundColor = 'white';
        });
        
        item.addEventListener('click', () => {
          const currentValues = ($(regionsSelect).val() || []).map(String);
          const v = String(option.value);
          if (!currentValues.includes(v)) {
            currentValues.push(v);
            $(regionsSelect).val(currentValues).trigger('change');
          }
          setRegionMenuOpen(false);
        });
        
        dropdownMenu.appendChild(item);
      }
    });
    
    if (!hasOptions) {
      dropdownMenu.innerHTML = `
        <div style="padding: 12px; color: #64748b; font-size: 13px; text-align: center;">
          Loading regions...
        </div>
      `;
    }
  }
  
  $(regionsSelect).on("change.regionOptions", function () {
    populateDropdownMenu();
  });

  // Initial population
  populateDropdownMenu();
  
  // Re-populate after regions are loaded (meta fetch timing backup)
  setTimeout(() => {
    populateDropdownMenu();
  }, 1000);
  
  // Insert dropdown trigger before the regions select
  regionsSelect.parentNode.insertBefore(dropdownTrigger, regionsSelect);
  
  pickerFace.addEventListener("click", (e) => {
    e.stopPropagation();
    const open = dropdownMenu.style.display === "none";
    setRegionMenuOpen(open);
  });

  pickerFace.addEventListener("keydown", (e) => {
    if (e.key === "Enter" || e.key === " ") {
      e.preventDefault();
      const open = dropdownMenu.style.display === "none";
      setRegionMenuOpen(open);
    } else if (e.key === "Escape") {
      setRegionMenuOpen(false);
    }
  });
  
  document.addEventListener("click", (e) => {
    if (!dropdownTrigger.contains(e.target)) {
      setRegionMenuOpen(false);
    }
  });

  syncRegionPickerLabel();
  
  // Hide the original regions select
  regionsSelect.style.display = 'none';
  
  // Add some styling for the empty state
  if (selectedRegionsContainer.querySelector('div')) {
    selectedRegionsContainer.style.alignItems = 'center';
    selectedRegionsContainer.style.justifyContent = 'center';
  }
});
</script>
<script>
document.addEventListener("DOMContentLoaded", () => {

  // =====================================================
  // COMPREHENSIVE FORM VALIDATION SYSTEM
  // =====================================================
  
  // Enhanced name validation patterns
  const NAME_PATTERNS = {
    MEANINGLESS: [
      // Test/fake patterns
      /^(test|demo|sample|asdf|qwer|123|abc|xyz|null|none|na|n\/a|foo|bar|baz|qux|lorem|ipsum|temp|user|guest|admin|student|sample|example|placeholder)$/i,
      /^.{1,2}$/, // Too short
      /^[^a-zA-Z]+$/, // No letters at all
      /^(.)\1+$/, // All same character (aaa, bbb, etc.)
      /^[0-9\s\-_\.]+$/, // Only numbers/symbols
      // Repeating patterns
      /^(.)\1{2,}$/, // Three or more same character
      /^(.)\1(.)\1$/, // ABA pattern
      /^([a-z])\1\1\1\1$/i, // Five same letters
      // Sequential patterns
      /^(abc|def|ghi|jkl|mno|pqr|stu|vwx|yz)$/i, // Sequential letters
      /^(123|234|345|456|567|678|789|890|012)$/i, // Sequential numbers
      // Keyboard patterns
      /^(qwerty|asdf|zxcv|asdfgh|qweasdzxc|123456|123abc)$/i,
      // Common fake words
      /^(fake|dummy|mock|test|sample|demo|placeholder|example|temp|tmp|anon|unknown|n\/a)$/i,
      // Gibberish detection
      /^[a-z]{1,}(.)\1{2,}[a-z]*$/i, // Repeated middle characters
      /^[a-z]{3,}([a-z])\1{2,}$/i, // Repeated ending characters
      // Vowel/consonant imbalance
      /^[aeiou]{3,}$/i, // All vowels
      /^[bcdfghjklmnpqrstvwxyz]{5,}$/i, // All consonants
      // Alternating patterns that look fake
      /^([a-z]{2})\1{1,}$/i, // Repeated 2-letter chunk (abab, cdcdcd)
    ],
    VALID: /^[a-zA-Z\u00C0-\u024F\s'\-\.]{2,50}$/, // Allow international letters, spaces, hyphens, apostrophes, dots
  };

  // Passport validation patterns by country
  const PASSPORT_PATTERNS = {
    default: /^[A-Z0-9]{6,9}$/,
    usa: /^[A-Z0-9]{9}$/,
    uk: /^[A-Z]{9}[0-9]$/,
    canada: /^[A-Z]{2}[0-9]{6}$/,
    australia: /^[A-Z]{1,2}[0-9]{7}$/,
    germany: /^[A-Z]{1}[0-9]{7}$/,
    france: /^[0-9]{2}[A-Z]{2}[0-9]{5}$/,
    india: /^[A-Z]{1}[0-9]{7}$/,
    china: /^[GDE][0-9]{8}$/,
    japan: /^[A-Z]{2}[0-9]{7}$/,
  };

  // Phone validation by country code
  const PHONE_VALIDATION = {
    '+1': { pattern: /^[2-9]\d{2}[2-9]\d{2}\d{4}$/, maxLength: 10, name: 'North America' },
    '+44': { pattern: /^[1-9]\d{9,10}$/, maxLength: 11, name: 'UK' },
    '+33': { pattern: /^[1-9]\d{8}$/, maxLength: 9, name: 'France' },
    '+49': { pattern: /^[1-9]\d{6,11}$/, maxLength: 12, name: 'Germany' },
    '+91': { pattern: /^[6-9]\d{9}$/, maxLength: 10, name: 'India' },
    '+86': { pattern: /^[1-9]\d{10,11}$/, maxLength: 12, name: 'China' },
    '+81': { pattern: /^[1-9]\d{8,9}$/, maxLength: 10, name: 'Japan' },
    '+61': { pattern: /^[2-9]\d{8}$/, maxLength: 9, name: 'Australia' },
    '+92': { pattern: /^[3-9]\d{9}$/, maxLength: 10, name: 'Pakistan' },
    '+234': { pattern: /^[7-9]\d{9}$/, maxLength: 10, name: 'Nigeria' },
    '+254': { pattern: /^[7]\d{8}$/, maxLength: 9, name: 'Kenya' },
    '+256': { pattern: /^[7]\d{8}$/, maxLength: 9, name: 'Uganda' },
    '+250': { pattern: /^[7]\d{8}$/, maxLength: 9, name: 'Rwanda' },
  };

  // Enhanced validation functions
  function validateName(name, fieldName) {
    const trimmed = name.trim();
    
    if (!trimmed) {
      return { valid: false, message: `${fieldName} is required` };
    }
    
    // Check for meaningless patterns
    for (const pattern of NAME_PATTERNS.MEANINGLESS) {
      if (pattern.test(trimmed)) {
        return { valid: false, message: `Please enter a real ${fieldName.toLowerCase()}` };
      }
    }
    
    // Check valid format
    if (!NAME_PATTERNS.VALID.test(trimmed)) {
      return { valid: false, message: `${fieldName} can only contain letters, spaces, hyphens, and apostrophes (2-50 characters)` };
    }
    
    // Additional real name validation
    const nameParts = trimmed.split(/\s+/);
    
    // Check if name has reasonable structure
    if (nameParts.length === 1 && nameParts[0].length < 3) {
      return { valid: false, message: `${fieldName} appears too short for a real name` };
    }
    
    // Check for vowel presence in longer names (most real names have vowels)
    if (trimmed.length > 5 && !/[aeiouAEIOU]/.test(trimmed)) {
      return { valid: false, message: `${fieldName} should contain vowels` };
    }
    
    // Check for reasonable consonant-to-vowel ratio
    const vowelCount = (trimmed.match(/[aeiouAEIOU]/g) || []).length;
    const consonantCount = (trimmed.match(/[bcdfghjklmnpqrstvwxyzBCDFGHJKLMNPQRSTVWXYZ]/g) || []).length;
    
    if (trimmed.length > 8 && consonantCount > vowelCount * 4) {
      return { valid: false, message: `${fieldName} doesn't look like a real name` };
    }
    
    // Check for alternating patterns that look fake
    const isAlternating = /^([a-zA-Z])\1([a-zA-Z])+$/.test(trimmed);
    if (isAlternating && nameParts[0].length >= 4) {
      return { valid: false, message: `${fieldName} appears to be a fake pattern` };
    }
    
    // Check for excessive repetition
    const charCounts = {};
    for (const char of trimmed.toLowerCase()) {
      charCounts[char] = (charCounts[char] || 0) + 1;
    }
    const maxCount = Math.max(...Object.values(charCounts));
    if (maxCount > trimmed.length * 0.6) {
      return { valid: false, message: `${fieldName} has too much repetition` };
    }
    
    return { valid: true, message: '' };
  }

  function validatePassport(passport, countryCode = '') {
    const trimmed = passport.trim().toUpperCase();
    
    if (!trimmed) {
      return { valid: false, message: 'Passport number is required' };
    }
    
    // Remove spaces and special characters
    const cleanPassport = trimmed.replace(/[^A-Z0-9]/g, '');
    
    if (cleanPassport.length < 6 || cleanPassport.length > 12) {
      return { valid: false, message: 'Passport number must be 6-12 characters (letters and numbers only)' };
    }
    
    // Country-specific validation
    let pattern = PASSPORT_PATTERNS.default;
    if (countryCode) {
      const countryLower = countryCode.toLowerCase();
      const countryMap = {
        'us': 'usa', 'usa': 'usa', 'united states': 'usa',
        'gb': 'uk', 'uk': 'uk', 'united kingdom': 'uk',
        'ca': 'canada', 'canada': 'canada',
        'au': 'australia', 'australia': 'australia',
        'de': 'germany', 'germany': 'germany',
        'fr': 'france', 'france': 'france',
        'in': 'india', 'india': 'india',
        'cn': 'china', 'china': 'china',
        'jp': 'japan', 'japan': 'japan',
      };
      
      const countryKey = countryMap[countryLower];
      if (countryKey && PASSPORT_PATTERNS[countryKey]) {
        pattern = PASSPORT_PATTERNS[countryKey];
      }
    }
    
    if (!pattern.test(cleanPassport)) {
      return { valid: false, message: 'Invalid passport number format for selected country' };
    }
    
    return { valid: true, message: '' };
  }

  function validatePhoneByCountry(phone, countryCode) {
    const trimmed = phone.trim();
    
    if (!trimmed) {
      return { valid: false, message: 'Phone number is required' };
    }
    
    // Remove all non-digit characters
    const cleanPhone = trimmed.replace(/\D/g, '');
    
    const countryInfo = PHONE_VALIDATION[countryCode];
    if (!countryInfo) {
      return { valid: false, message: 'Invalid country code for phone validation' };
    }
    
    if (cleanPhone.length !== countryInfo.maxLength) {
      return { valid: false, message: `${countryInfo.name} phone numbers must be exactly ${countryInfo.maxLength} digits` };
    }
    
    if (!countryInfo.pattern.test(cleanPhone)) {
      return { valid: false, message: `Invalid ${countryInfo.name} phone number format` };
    }
    
    return { valid: true, message: '' };
  }

  // Apply validation to form fields
  function applyFieldValidation() {
    // Name fields validation
    const nameFields = ['first_name', 'last_name', 'father_first_name', 'father_last_name', 'mother_first_name', 'mother_last_name', 'emergency_first_name', 'emergency_last_name'];
    
    nameFields.forEach(fieldName => {
      const field = document.querySelector(`[name="${fieldName}"]`);
      if (field) {
        field.addEventListener('blur', function() {
          const validation = validateName(this.value, fieldName.replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase()));
          showFieldValidation(this, validation);
        });
        
        field.addEventListener('input', function() {
          if (this.classList.contains('is-invalid')) {
            const validation = validateName(this.value, fieldName.replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase()));
            if (validation.valid) {
              showFieldValidation(this, validation);
            }
          }
        });
      }
    });

    // Passport validation
    const passportField = document.querySelector('[name="passport_number"]');
    if (passportField) {
      passportField.addEventListener('blur', function() {
        const nationalityField = document.querySelector('[name="nationality"]');
        const countryCode = nationalityField ? nationalityField.value : '';
        const validation = validatePassport(this.value, countryCode);
        showFieldValidation(this, validation);
      });
    }

    // Enhanced phone validation
    const phoneInputs = document.querySelectorAll('input[type="tel"]');
    phoneInputs.forEach(input => {
      input.addEventListener('blur', function() {
        // Get country code from intl-tel-input instance
        let countryCode = '+1'; // default fallback
        
        // Try to get from intl-tel-input first
        if (window.intlTelInputGlobals && this.id) {
          const iti = window.intlTelInputGlobals.getInstance(this);
          if (iti && iti.getSelectedCountryData()) {
            countryCode = '+' + iti.getSelectedCountryData().dialCode;
          }
        }
        
        // Fallback to hidden field
        if (countryCode === '+1') {
          const hiddenCode = document.getElementById(this.id.replace('phone', 'area_code'));
          if (hiddenCode && hiddenCode.value) {
            countryCode = hiddenCode.value;
          }
        }
        
        // Additional fallback: try to get from nationality field
        if (countryCode === '+1') {
          const nationalityField = document.querySelector('[name="nationality"]');
          if (nationalityField && nationalityField.value) {
            // Map country names to codes
            const countryToCode = {
              'kenya': '+254', 'rwanda': '+250', 'uganda': '+256',
              'nigeria': '+234', 'south africa': '+27', 'tanzania': '+255',
              'ghana': '+233', 'ethiopia': '+251', 'egypt': '+20',
              'morocco': '+212', 'algeria': '+213', 'libya': '+218',
              'sudan': '+249', 'tunisia': '+216', 'zimbabwe': '+263',
              'zambia': '+260', 'mozambique': '+258', 'botswana': '+267',
              'namibia': '+264', 'malawi': '+265', 'lesotho': '+266',
              'swaziland': '+268', 'angola': '+244', 'cameroon': '+237',
              'chad': '+235', 'congo': '+242', 'drc': '+243',
              'gabon': '+241', 'equatorial guinea': '+240', 'sao tome': '+239',
              'cape verde': '+238', 'guinea': '+224', 'guinea-bissau': '+245',
              'senegal': '+221', 'gambia': '+220', 'mali': '+223',
              'burkina faso': '+226', 'niger': '+227', 'benin': '+229',
              'togo': '+228', 'sierra leone': '+232', 'liberia': '+231',
              'ivory coast': '+225', 'burundi': '+257', 'djibouti': '+253',
              'eritrea': '+291', 'somalia': '+252', 'madagascar': '+261',
              'mauritius': '+230', 'seychelles': '+248', 'comoros': '+269',
              'reunion': '+262', 'mayotte': '+262'
            };
            
            const countryLower = nationalityField.value.toLowerCase();
            if (countryToCode[countryLower]) {
              countryCode = countryToCode[countryLower];
            }
          }
        }
        
        const validation = validatePhoneByCountry(this.value, countryCode);
        showFieldValidation(this, validation);
      });
    });
  }

  function showFieldValidation(field, validation) {
    // Remove existing validation states
    field.classList.remove('is-valid', 'is-invalid');
    
    // Remove existing feedback
    const existingFeedback = field.parentNode.querySelector('.invalid-feedback, .valid-feedback');
    if (existingFeedback) {
      existingFeedback.remove();
    }
    
    if (validation.valid) {
      field.classList.add('is-valid');
      const feedback = document.createElement('div');
      feedback.className = 'valid-feedback';
      feedback.textContent = 'Looks good!';
      field.parentNode.appendChild(feedback);
    } else {
      field.classList.add('is-invalid');
      const feedback = document.createElement('div');
      feedback.className = 'invalid-feedback';
      feedback.textContent = validation.message;
      field.parentNode.appendChild(feedback);
    }
  }

  // Make second nationality truly optional
  function makeSecondNationalityOptional() {
    const secondNationalityField = document.querySelector('[name="second_nationality"]');
    if (secondNationalityField) {
      secondNationalityField.removeAttribute('required');
      const label = secondNationalityField.closest('.mb-3').querySelector('.form-label');
      if (label) {
        label.innerHTML = label.innerHTML.replace(' *', '');
      }
    }
  }

  // Enhanced UI visibility improvements
  function enhanceUIVisibility() {
    // Add better contrast and visibility styles
    const style = document.createElement('style');
    style.textContent = `
      /* Enhanced form field styles */
      .form-control, .form-select {
        border-width: 2px;
        font-weight: 500;
        transition: all 0.3s ease;
      }
      
      .form-control:focus, .form-select:focus {
        border-color: #0d6efd;
        box-shadow: 0 0 0 0.25rem rgba(13, 110, 253, 0.25);
        transform: translateY(-1px);
      }
      
      .form-control.is-valid {
        border-color: #198754;
        background-color: #f0fff4;
      }
      
      .form-control.is-invalid {
        border-color: #dc3545;
        background-color: #fff5f5;
      }
      
      .valid-feedback {
        color: #198754;
        font-weight: 600;
        font-size: 0.875rem;
        margin-top: 0.25rem;
      }
      
      .invalid-feedback {
        color: #dc3545;
        font-weight: 600;
        font-size: 0.875rem;
        margin-top: 0.25rem;
      }
      
      /* Enhanced step sections */
      .step-section {
        border: 2px solid #e2e8f0;
        background: linear-gradient(135deg, #ffffff 0%, #f8fafc 100%);
        position: relative;
        overflow: hidden;
      }
      
      .step-section::before {
        content: "";
        position: absolute;
        left: 0;
        top: 0;
        bottom: 0;
        width: 8px;
        background: linear-gradient(180deg, #0d6efd, #4f8cff);
        border-radius: 0;
      }
      
      .step-section:hover {
        border-color: #0d6efd;
        box-shadow: 0 8px 25px rgba(13, 110, 253, 0.15);
      }
      
      /* Enhanced labels */
      .form-label {
        font-weight: 700;
        color: #1a202c;
        margin-bottom: 0.5rem;
        font-size: 0.95rem;
      }
      
      /* Required field indicator */
      .form-label .text-danger {
        font-weight: 800;
        font-size: 1.1rem;
      }
      
      /* Enhanced buttons */
      .btn {
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        border-radius: 12px;
        padding: 12px 24px;
        transition: all 0.3s ease;
      }
      
      .btn-primary {
        background: linear-gradient(135deg, #0d6efd, #4f8cff);
        border: none;
        box-shadow: 0 4px 15px rgba(13, 110, 253, 0.3);
      }
      
      .btn-primary:hover {
        transform: translateY(-2px);
        box-shadow: 0 6px 20px rgba(13, 110, 253, 0.4);
      }
      
      .btn-secondary {
        background: linear-gradient(135deg, #6c757d, #495057);
        border: none;
        box-shadow: 0 4px 15px rgba(108, 117, 125, 0.3);
      }
      
      .btn-secondary:hover {
        transform: translateY(-2px);
        box-shadow: 0 6px 20px rgba(108, 117, 125, 0.4);
      }
      
      /* Enhanced progress bar */
      .progress-step span.active {
        background: linear-gradient(90deg, #0d6efd, #4f8cff);
        box-shadow: 0 2px 8px rgba(13, 110, 253, 0.4);
      }
      
      /* Better focus indicators */
      .form-control:focus, .form-select:focus {
        outline: 3px solid rgba(13, 110, 253, 0.5);
        outline-offset: 2px;
      }
      
      /* Enhanced error states */
      .alert {
        border-radius: 12px;
        border-width: 2px;
        font-weight: 600;
      }
      
      /* Better card shadows */
      .card {
        box-shadow: 0 20px 40px rgba(0, 0, 0, 0.08), 0 8px 16px rgba(0, 0, 0, 0.04);
        border: 2px solid #e2e8f0;
      }
      
      /* Enhanced dropdowns */
      .select2-container--bootstrap-5 .select2-selection {
        border-width: 2px;
        border-radius: 12px;
      }
      
      .select2-container--bootstrap-5.select2-container--focus .select2-selection {
        border-color: #0d6efd;
        box-shadow: 0 0 0 0.25rem rgba(13, 110, 253, 0.25);
      }
    `;
    document.head.appendChild(style);
  }

  // Initialize all enhancements
  applyFieldValidation();
  makeSecondNationalityOptional();
  enhanceUIVisibility();
  
  // Ensure second nationality is truly optional and remove required attribute
  setTimeout(() => {
    const secondNationalityField = document.querySelector('[name="second_nationality"]');
    if (secondNationalityField) {
      secondNationalityField.removeAttribute('required');
      const label = secondNationalityField.closest('.mb-3').querySelector('.form-label');
      if (label && label.innerHTML.includes(' *')) {
        label.innerHTML = label.innerHTML.replace(' *', '');
      }
    }
  }, 100);

  const referralSelect = document.getElementById("referral_source");
  const agentSection   = document.getElementById("agentSection");

  const agentSearch    = document.getElementById("agent_search");
  const agentResults   = document.getElementById("agentResults");

  const firstNameInput = document.getElementById("agent_first_name");
  const lastNameInput  = document.getElementById("agent_last_name");
  const emailInput     = document.getElementById("agent_email");

  if (
    !referralSelect ||
    !agentSection ||
    !firstNameInput ||
    !lastNameInput ||
    !emailInput
  ) {
    return; // safety guard
  }

  /* ===============================
     HELPERS
  =============================== */

  function clearAgentFields() {
    firstNameInput.value = "";
    lastNameInput.value  = "";
    emailInput.value     = "";
    if (agentSearch) agentSearch.value = "";
    if (agentResults) {
      agentResults.innerHTML = "";
      agentResults.classList.add("d-none");
    }
  }

  function lockAgentFields() {
    firstNameInput.readOnly = true;
    lastNameInput.readOnly  = true;
    emailInput.readOnly     = true;
  }

  /* ===============================
     REFERRAL CHANGE HANDLER (FIXED)
  =============================== */

  referralSelect.addEventListener("change", async () => {

    clearAgentFields();

    /* ---------- ONLINE ---------- */
    if (referralSelect.value === "online") {

      agentSection.style.display = "none";

      try {
        const res = await fetch("getDefaultOnlineAgent.php", {
          cache: "no-store"
        });

        const agent = await res.json();

        if (!agent || !agent.email) {
          alert("No default agent available. Please select an agent manually.");
          referralSelect.value = "agent";
          agentSection.style.display = "block";
          return;
        }

        firstNameInput.value = agent.first_name || "";
        lastNameInput.value  = agent.last_name  || "";
        emailInput.value     = agent.email      || "";

        lockAgentFields();

      } catch (err) {
        alert("Failed to auto-assign agent. Please try again.");
        referralSelect.value = "";
      }

    }

    /* ---------- THROUGH AGENT ---------- */
    else if (referralSelect.value === "agent") {

      agentSection.style.display = "block";

      if (agentSearch) {
        agentSearch.focus();
      }

    }

    /* ---------- RESET ---------- */
    else {
      agentSection.style.display = "none";
    }

  });

  /* ===============================
     HARD LOCK (SAFETY)
  =============================== */

  ["input", "change", "paste"].forEach(evt => {
    firstNameInput.addEventListener(evt, lockAgentFields);
    lastNameInput.addEventListener(evt, lockAgentFields);
    emailInput.addEventListener(evt, lockAgentFields);
  });

});
</script>
<script>
(function(){

"use strict";

/* ======================================================
CONFIG
====================================================== */

const MIN_CHARS = 3;
const API_SEARCH = "searchApplication.php";
const API_LOAD   = "loadApplicationData.php";

/* ======================================================
ELEMENTS
====================================================== */

const searchBox  = document.getElementById("resume_email_search");
const resultsBox = document.getElementById("resumeResults");

if(!searchBox || !resultsBox) return;

/* ======================================================
STATE
====================================================== */

let debounceTimer = null;
let controller = null;
let selectedIndex = -1;

/* ======================================================
UTILITIES
====================================================== */

function showResults(){
resultsBox.classList.remove("d-none");
}

function hideResults(){
resultsBox.classList.add("d-none");
selectedIndex = -1;
}

function clearResults(){
resultsBox.innerHTML = "";
}

function escapeHtml(text){
const div = document.createElement("div");
div.textContent = text;
return div.innerHTML;
}

/* ======================================================
SEARCH INPUT
====================================================== */

searchBox.addEventListener("input",function(){

const query = this.value.trim();

if(debounceTimer) clearTimeout(debounceTimer);

debounceTimer = setTimeout(()=>{
performSearch(query);
},300);

});

/* ======================================================
SEARCH
====================================================== */

async function performSearch(query){

if(query.length < MIN_CHARS){
hideResults();
clearResults();
return;
}

try{

if(controller) controller.abort();

controller = new AbortController();

resultsBox.innerHTML =
'<div class="list-group-item text-muted">Searching...</div>';

showResults();

const response = await fetch(
`${API_SEARCH}?q=${encodeURIComponent(query)}`,
{signal:controller.signal}
);

if(!response.ok) throw new Error("Search failed");

const data = await response.json();

renderResults(data);

}
catch(error){

if(error.name === "AbortError") return;

console.error(error);

resultsBox.innerHTML =
'<div class="list-group-item text-danger">Search failed</div>';

showResults();

}

}

/* ======================================================
RENDER RESULTS
====================================================== */

function renderResults(data){

clearResults();

if(!Array.isArray(data) || data.length === 0){

resultsBox.innerHTML =
'<div class="list-group-item">No application found</div>';

showResults();
return;
}

data.forEach((app)=>{

const item = document.createElement("button");

item.type = "button";
item.className = "list-group-item list-group-item-action";

item.dataset.id = app.id;

item.innerHTML = `
<strong>${escapeHtml(app.email)}</strong>
<br>
<small class="text-muted">Continue application</small>
`;

item.addEventListener("click",()=>loadApplication(app.id));

resultsBox.appendChild(item);

});

showResults();

}

/* ======================================================
LOAD APPLICATION
====================================================== */

async function loadApplication(id){

try{

resultsBox.innerHTML =
'<div class="list-group-item text-muted">Loading application...</div>';

const response = await fetch(`${API_LOAD}?id=${encodeURIComponent(id)}`);

if(!response.ok) throw new Error("Load failed");

const data = await response.json();

if(data.status !== "success"){
throw new Error("Invalid response");
}

/* restore autosave ID */

window.currentApplicationId = data.id;

const hiddenIdField = document.querySelector('input[name="application_id"]');
if(hiddenIdField){
    hiddenIdField.value = data.id;
}

/* populate form fields */

populateForm(data.application);

/* restore study selections */

restoreStudySelections(data.study_choices);

hideResults();

if(data.application.email){
searchBox.value = data.application.email;
}

window.scrollTo({top:0,behavior:"smooth"});

alert("Application loaded successfully.");

}
catch(error){

console.error(error);

alert("Failed to load application.");

}

}

/* ======================================================
POPULATE FORM
====================================================== */

function populateForm(data){

Object.entries(data).forEach(([field,value])=>{

const elements = document.querySelectorAll(`[name="${field}"]`);

if(!elements.length) return;

elements.forEach(input=>{

if(input.type === "file") return;

if(input.type === "radio"){

if(input.value == value) input.checked = true;

}

else if(input.type === "checkbox"){

input.checked = Boolean(value);

}

else if(input.tagName === "SELECT"){

input.value = value ?? "";

if(window.jQuery && $(input).hasClass("select2-hidden-accessible")){
$(input).trigger("change");
}

}

else if(input.tagName === "TEXTAREA"){

input.value = value ?? "";

}

else{

input.value = value ?? "";

}

});

});

}

/* ======================================================
RESTORE STUDY SELECTIONS
====================================================== */

function restoreStudySelections(choices){

if(!Array.isArray(choices) || !choices.length) return;

const regionSelect = $("#regions");

/* clear previous */

regionSelect.val(null).trigger("change");

choices.forEach(choice=>{

/* restore region */

const regionOption = new Option(
choice.region_name,
choice.region_id,
true,
true
);

regionSelect.append(regionOption).trigger("change");

/* restore university */

setTimeout(()=>{

const universitySelect = document.querySelector(".university:last-child");

if(universitySelect){

const opt = new Option(
choice.university_name,
choice.university_id,
true,
true
);

$(universitySelect).append(opt).trigger("change");

}

/* restore level */

setTimeout(()=>{

const levelSelect = document.querySelector(".level:last-child");

if(levelSelect){

const opt = new Option(
choice.level_name,
choice.program_level_id,
true,
true
);

$(levelSelect).append(opt).trigger("change");

}

/* restore program */

setTimeout(()=>{

const programSelect = document.querySelector(".program:last-child");

if(programSelect){

const opt = new Option(
choice.program_name,
choice.program_id,
true,
true
);

$(programSelect).append(opt).trigger("change");

}

},400);

},300);

},300);

});

}

/* ======================================================
KEYBOARD NAVIGATION
====================================================== */

searchBox.addEventListener("keydown",function(e){

const items = resultsBox.querySelectorAll(".list-group-item-action");

if(!items.length) return;

if(e.key === "ArrowDown"){
e.preventDefault();
selectedIndex = (selectedIndex+1)%items.length;
}

else if(e.key === "ArrowUp"){
e.preventDefault();
selectedIndex = (selectedIndex-1+items.length)%items.length;
}

else if(e.key === "Enter"){
if(selectedIndex >= 0){
e.preventDefault();
items[selectedIndex].click();
}
}

items.forEach(el=>el.classList.remove("active"));

if(selectedIndex >= 0) items[selectedIndex].classList.add("active");

});

/* ======================================================
CLICK OUTSIDE
====================================================== */

document.addEventListener("click",function(e){

if(!e.target.closest("#resume_email_search") &&
!e.target.closest("#resumeResults")){
hideResults();
}

});

})();
</script>
</body>
</html>