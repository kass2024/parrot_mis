<?php
session_start();
require_once __DIR__ . '/includes/brand_logo.php';
$parrotBrandLogoHref = parrot_brand_logo_href(__DIR__);

// ============================================
// INCLUDE HEADER FOR LANGUAGE SWITCHING LOGIC
// ============================================
include 'header.php';

// ✅ Use the ID from the URL if available
$userId = $_GET['id'] ?? ($_SESSION['credit_user_id'] ?? ('credit-' . time() . '-' . rand(1000, 9999)));

// ✅ Keep tracking it in session
$_SESSION['credit_user_id'] = $userId;

// ============================================
// TRANSLATIONS FOR CREDIT TRANSFER PAGE
// ============================================

$credit_translations = [
    'en' => [
        'page_title' => 'Credit Transfer & Certification | Parrot Canada Visa Consultant',
        'page_description' => 'Apply for credit transfer and certification with our partner universities worldwide.',
        'form_title' => 'Credit Transfer & Certification Application',
        'form_subtitle' => 'Complete your application for credit transfer and certification with our partner universities.',
        
        // Personal Information Section
        'personal_info' => 'Personal Information',
        'student_name' => 'Student Name',
        'first_name' => 'First Name',
        'middle_name' => 'Middle Name',
        'last_name' => 'Last Name',
        'birth_date' => 'Birth Date',
        'gender' => 'Gender',
        'contact_address' => 'Contact Address',
        'street_address' => 'Street Address',
        'address_line_2' => 'Apartment, Suite, Building (Optional)',
        'city' => 'City',
        'state' => 'State/Province',
        'postal_code' => 'Postal/ZIP Code',
        'email_address' => 'Email Address',
        'contact_numbers' => 'Contact Numbers',
        'mobile_number' => 'Mobile Number',
        'phone_number' => 'Phone Number',
        'work_number' => 'Work Number',
        'current_company' => 'Current Company/Organization',
        
        // Academic Information Section
        'academic_info' => 'Academic Information',
        'current_education_level' => 'Current Level of Education',
        'desired_certification' => 'Desired Certification Level',
        'current_program' => 'Current Program',
        'select_university' => 'Select University',
        'proposed_program' => 'Proposed Program',
        
        // Required Documents Section
        'required_documents' => 'Required Documents',
        'degree_certificate' => 'Current Degree Certificate',
        'academic_transcripts' => 'Current Academic Transcripts',
        'passport_id' => 'Valid Passport or National ID',
        'academic_cv' => 'Academic CV/Resume',
        'payment_proof' => 'Payment Proof',
        'additional_comments' => 'Additional Comments',
        
        // Buttons and Labels
        'continue_to_academic' => 'Continue to Academic Info',
        'back_to_personal' => 'Back to Personal Info',
        'submit_application' => 'Submit Application',
        'step1_label' => 'Personal Details',
        'step2_label' => 'Academic Information',
        
        // Hints and Tips
        'legal_first_name' => 'Legal first name',
        'family_name' => 'Family name',
        'email_hint' => 'We\'ll send application updates to this email',
        'education_hint' => 'Select all that apply to your current education status',
        'program_hint' => 'Select university first, then type to filter available programs',
        'comments_hint' => 'Optional: Share your motivation, special circumstances, or questions',
        
        // Progress Messages
        'processing_application' => 'Processing Your Application',
        'please_wait' => 'Please wait while we submit your information...',
        'saving_info' => 'Saving Personal Information',
        'saving_details' => 'Please wait while we save your details...',
        'submitting_application' => 'Submitting Your Application',
        'may_take_moment' => 'This may take a moment...',
        
        // Progress Steps
        'validating_data' => 'Validating Data',
        'uploading_files' => 'Uploading Files',
        'saving_information' => 'Saving Information',
        'finalizing' => 'Finalizing',
        
        // File Upload
        'click_to_upload' => 'Click to upload',
        'or_drag_drop' => 'or drag and drop files here',
        'max_10mb' => 'PDF, JPG, PNG, DOC (Max 10MB)',
        'accepted_formats' => 'Accepted: .pdf, .jpg, .jpeg, .png, .doc, .docx',
        
        // Gender Options
        'gender_male' => 'Male',
        'gender_female' => 'Female',
        'gender_other' => 'Other',
        
        // Education Levels
        'edu_high_school' => 'High School Certificate',
        'edu_ordinary_diploma' => 'Ordinary Diploma (2 years)',
        'edu_advanced_diploma' => 'Advanced Diploma (3 years)',
        'edu_bachelor_no_degree' => 'Bachelor (No Degree)',
        'edu_bachelor_lower' => 'Bachelor (Lower Division)',
        'edu_bachelor_upper' => 'Bachelor (Upper Division)',
        'edu_masters_lower' => 'Masters (Lower Division)',
        'edu_masters_upper' => 'Masters (Upper Division)',
        
        // Certification Levels
        'cert_bachelor' => 'Bachelor',
        'cert_masters' => 'Masters',
        'cert_phd' => 'PhD',
    ],
    
    'fr' => [
        'page_title' => 'Transfert de Crédits & Certification | Parrot Canada Visa Consultant',
        'page_description' => 'Postulez pour le transfert de crédits et la certification avec nos universités partenaires.',
        'form_title' => 'Demande de Transfert de Crédits & Certification',
        'form_subtitle' => 'Complétez votre demande de transfert de crédits et certification avec nos universités partenaires.',
        
        // Personal Information Section
        'personal_info' => 'Informations Personnelles',
        'student_name' => 'Nom de l\'Étudiant',
        'first_name' => 'Prénom',
        'middle_name' => 'Deuxième Prénom',
        'last_name' => 'Nom de Famille',
        'birth_date' => 'Date de Naissance',
        'gender' => 'Genre',
        'contact_address' => 'Adresse de Contact',
        'street_address' => 'Adresse',
        'address_line_2' => 'Appartement, Suite, Bâtiment (Optionnel)',
        'city' => 'Ville',
        'state' => 'État/Province',
        'postal_code' => 'Code Postal',
        'email_address' => 'Adresse Email',
        'contact_numbers' => 'Numéros de Contact',
        'mobile_number' => 'Numéro Mobile',
        'phone_number' => 'Numéro de Téléphone',
        'work_number' => 'Numéro Professionnel',
        'current_company' => 'Entreprise/Organisation Actuelle',
        
        // Academic Information Section
        'academic_info' => 'Informations Académiques',
        'current_education_level' => 'Niveau d\'Éducation Actuel',
        'desired_certification' => 'Niveau de Certification Désiré',
        'current_program' => 'Programme Actuel',
        'select_university' => 'Sélectionner l\'Université',
        'proposed_program' => 'Programme Proposé',
        
        // Required Documents Section
        'required_documents' => 'Documents Requis',
        'degree_certificate' => 'Certificat de Diplôme Actuel',
        'academic_transcripts' => 'Relevés de Notes Actuels',
        'passport_id' => 'Passeport Valide ou Carte d\'Identité Nationale',
        'academic_cv' => 'CV Académique',
        'payment_proof' => 'Preuve de Paiement',
        'additional_comments' => 'Commentaires Additionnels',
        
        // Buttons and Labels
        'continue_to_academic' => 'Continuer vers les Informations Académiques',
        'back_to_personal' => 'Retour aux Informations Personnelles',
        'submit_application' => 'Soumettre la Demande',
        'step1_label' => 'Détails Personnels',
        'step2_label' => 'Informations Académiques',
        
        // Hints and Tips
        'legal_first_name' => 'Prénom légal',
        'family_name' => 'Nom de famille',
        'email_hint' => 'Nous enverrons les mises à jour de la demande à cet email',
        'education_hint' => 'Sélectionnez tout ce qui s\'applique à votre statut d\'éducation actuel',
        'program_hint' => 'Sélectionnez d\'abord l\'université, puis tapez pour filtrer les programmes disponibles',
        'comments_hint' => 'Optionnel : Partagez votre motivation, circonstances spéciales ou questions',
        
        // Progress Messages
        'processing_application' => 'Traitement de Votre Demande',
        'please_wait' => 'Veuillez patienter pendant que nous soumettons vos informations...',
        'saving_info' => 'Sauvegarde des Informations Personnelles',
        'saving_details' => 'Veuillez patienter pendant que nous sauvegardons vos détails...',
        'submitting_application' => 'Soumission de Votre Demande',
        'may_take_moment' => 'Cela peut prendre un moment...',
        
        // Progress Steps
        'validating_data' => 'Validation des Données',
        'uploading_files' => 'Téléchargement des Fichiers',
        'saving_information' => 'Sauvegarde des Informations',
        'finalizing' => 'Finalisation',
        
        // File Upload
        'click_to_upload' => 'Cliquez pour télécharger',
        'or_drag_drop' => 'ou glissez-déposez les fichiers ici',
        'max_10mb' => 'PDF, JPG, PNG, DOC (Max 10MB)',
        'accepted_formats' => 'Accepté : .pdf, .jpg, .jpeg, .png, .doc, .docx',
        
        // Gender Options
        'gender_male' => 'Homme',
        'gender_female' => 'Femme',
        'gender_other' => 'Autre',
        
        // Education Levels
        'edu_high_school' => 'Certificat d\'Études Secondaires',
        'edu_ordinary_diploma' => 'Diplôme Ordinaire (2 ans)',
        'edu_advanced_diploma' => 'Diplôme Avancé (3 ans)',
        'edu_bachelor_no_degree' => 'Bachelor (Sans Diplôme)',
        'edu_bachelor_lower' => 'Bachelor (Division Inférieure)',
        'edu_bachelor_upper' => 'Bachelor (Division Supérieure)',
        'edu_masters_lower' => 'Masters (Division Inférieure)',
        'edu_masters_upper' => 'Masters (Division Supérieure)',
        
        // Certification Levels
        'cert_bachelor' => 'Bachelor',
        'cert_masters' => 'Masters',
        'cert_phd' => 'Doctorat',
    ]
];

// Function to get credit transfer translation
function ct($key) {
    global $credit_translations, $current_lang;
    return isset($credit_translations[$current_lang][$key]) ? $credit_translations[$current_lang][$key] : $key;
}
?>
<!DOCTYPE html>
<html lang="<?php echo $current_lang; ?>">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <meta name="description" content="<?php echo ct('page_description'); ?>">
  <title><?php echo ct('page_title'); ?></title>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <style>
    /* ===== Brand color theme ===== */
    :root {
      --navy-blue: #427431;
      --secondary-blue: #3661B9;
      --dark-blue: #2f5a26;
      --gold: #E21D1E;
      --white: #FFFFFF;
      --light-gray: #f8f9fa;
      --medium-gray: #e9ecef;
      --dark-gray: #6c757d;
      --success: #28a745;
      --danger: #dc3545;
      --warning: #ffc107;
      --shadow: 0 10px 30px rgba(1, 47, 107, 0.1);
      --transition: all 0.3s ease;
    }

    * { 
      box-sizing: border-box; 
      margin: 0; 
      padding: 0; 
    }

    body { 
      font-family: 'Segoe UI', 'Roboto', 'Helvetica Neue', sans-serif; 
      background: linear-gradient(135deg, var(--light-gray) 0%, var(--medium-gray) 100%);
      color: var(--dark-blue);
      min-height: 100vh;
      padding: 20px;
      line-height: 1.6;
    }

    /* ===== HEADER & LOGO ===== */
    .header {
      text-align: center;
      margin-bottom: 30px;
      padding: 20px 0;
    }

    .logo-container {
      display: flex;
      align-items: center;
      justify-content: center;
      gap: 15px;
      margin-bottom: 15px;
    }

    .logo-container .brand-logo-img {
      height: 56px;
      width: auto;
      max-width: 200px;
      object-fit: contain;
      display: block;
    }

    .logo-text {
      font-size: 28px;
      font-weight: 700;
      background: linear-gradient(90deg, var(--navy-blue), var(--dark-blue));
      -webkit-background-clip: text;
      -webkit-text-fill-color: transparent;
      letter-spacing: 1px;
    }

    .logo-subtext {
      color: var(--dark-gray);
      font-size: 14px;
      font-weight: 500;
      letter-spacing: 2px;
      text-transform: uppercase;
      margin-top: 5px;
    }

    /* ===== FORM CONTAINER ===== */
    .form-container { 
      background: var(--white); 
      max-width: 1000px; 
      margin: 0 auto; 
      padding: 40px; 
      border-radius: 20px; 
      box-shadow: var(--shadow);
      position: relative;
      overflow: hidden;
    }

    .form-container::before {
      content: '';
      position: absolute;
      top: 0;
      left: 0;
      width: 100%;
      height: 5px;
      background: linear-gradient(90deg, var(--navy-blue), var(--gold));
    }

    /* ===== STEP INDICATOR ===== */
    .step-indicator {
      display: flex;
      justify-content: space-between;
      margin-bottom: 40px;
      position: relative;
      padding: 0 20px;
    }

    .step-indicator::before {
      content: '';
      position: absolute;
      top: 15px;
      left: 10%;
      right: 10%;
      height: 3px;
      background: var(--medium-gray);
      z-index: 1;
    }

    .step {
      position: relative;
      z-index: 2;
      text-align: center;
      flex: 1;
    }

    .step-circle {
      width: 35px;
      height: 35px;
      background: var(--white);
      border: 3px solid var(--medium-gray);
      border-radius: 50%;
      display: flex;
      align-items: center;
      justify-content: center;
      margin: 0 auto 10px;
      font-weight: 600;
      color: var(--dark-gray);
      transition: var(--transition);
    }

    .step.active .step-circle {
      background: var(--navy-blue);
      border-color: var(--navy-blue);
      color: var(--white);
      transform: scale(1.1);
    }

    .step.completed .step-circle {
      background: var(--gold);
      border-color: var(--gold);
      color: var(--white);
    }

    .step-label {
      font-size: 14px;
      font-weight: 600;
      color: var(--dark-gray);
    }

    .step.active .step-label {
      color: var(--navy-blue);
    }

    /* ===== FORM SECTIONS ===== */
    .form-step { 
      display: none; 
      animation: fadeIn 0.5s ease;
    }

    @keyframes fadeIn {
      from { opacity: 0; transform: translateY(10px); }
      to { opacity: 1; transform: translateY(0); }
    }

    .form-step.active { 
      display: block; 
    }

    .form-section {
      margin-bottom: 30px;
    }

    .section-title {
      color: var(--navy-blue);
      font-size: 20px;
      font-weight: 600;
      margin-bottom: 20px;
      padding-bottom: 10px;
      border-bottom: 2px solid var(--gold);
      display: flex;
      align-items: center;
      gap: 10px;
    }

    .section-title i {
      color: var(--gold);
    }

    /* ===== FORM ELEMENTS ===== */
    .form-group {
      margin-bottom: 25px;
    }

    label { 
      display: block; 
      font-weight: 600; 
      margin-bottom: 8px; 
      color: var(--dark-blue);
      font-size: 15px;
    }

    label.required::after {
      content: ' *';
      color: var(--danger);
    }

    input, select, textarea { 
      width: 100%; 
      padding: 14px 18px; 
      border: 2px solid var(--medium-gray); 
      border-radius: 10px; 
      font-size: 16px; 
      transition: var(--transition);
      background: var(--white);
      color: var(--dark-blue);
    }

    input:focus, select:focus, textarea:focus {
      outline: none;
      border-color: var(--navy-blue);
      box-shadow: 0 0 0 3px rgba(1, 47, 107, 0.1);
    }

    textarea { 
      resize: vertical; 
      min-height: 120px;
      font-family: inherit;
    }

    .inline-inputs { 
      display: flex; 
      flex-wrap: wrap; 
      gap: 15px; 
    }

    .inline-inputs > * { 
      flex: 1 1 calc(33.333% - 15px); 
      min-width: 150px;
    }

    /* ===== CHECKBOX GRID ===== */
    .checkbox-grid { 
      display: grid; 
      grid-template-columns: repeat(auto-fill, minmax(250px, 1fr)); 
      gap: 15px 25px; 
      margin-top: 10px; 
    }

    .checkbox-grid label { 
      display: flex; 
      align-items: center; 
      gap: 12px; 
      font-weight: normal; 
      line-height: 1.5;
      cursor: pointer;
      padding: 12px 15px;
      border-radius: 8px;
      background: var(--light-gray);
      transition: var(--transition);
    }

    .checkbox-grid label:hover {
      background: rgba(1, 47, 107, 0.05);
      transform: translateY(-2px);
    }

    .checkbox-grid input[type="checkbox"] {
      width: 20px;
      height: 20px;
      accent-color: var(--navy-blue);
    }

    /* ===== FIXED FILE UPLOAD - CRITICAL FIX ===== */
    .file-upload-container {
      margin-top: 10px;
    }

    .file-upload-wrapper {
      position: relative;
      border: 3px dashed var(--medium-gray);
      border-radius: 12px;
      padding: 25px 20px;
      text-align: center;
      color: var(--dark-gray);
      transition: var(--transition);
      background: var(--light-gray);
      cursor: pointer;
      min-height: 150px;
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
    }

    .file-upload-wrapper:hover {
      border-color: var(--navy-blue);
      background: rgba(1, 47, 107, 0.02);
    }

    .file-upload-wrapper.dragover {
      border-color: var(--gold);
      background: rgba(242, 166, 90, 0.05);
    }

    .file-upload-wrapper.has-file {
      border-color: var(--success);
      background: rgba(40, 167, 69, 0.05);
    }

    .file-upload-wrapper input[type="file"] {
      position: absolute;
      top: 0;
      left: 0;
      width: 100%;
      height: 100%;
      opacity: 0;
      cursor: pointer;
      z-index: 10;
    }

    .upload-icon {
      font-size: 40px;
      color: var(--navy-blue);
      margin-bottom: 10px;
      transition: var(--transition);
    }

    .file-upload-wrapper:hover .upload-icon {
      color: var(--gold);
      transform: scale(1.1);
    }

    .upload-text {
      font-weight: 600;
      margin-bottom: 5px;
      font-size: 16px;
      color: var(--dark-blue);
    }

    .upload-hint {
      font-size: 13px;
      color: var(--dark-gray);
      margin-bottom: 15px;
    }

    .file-preview {
      margin-top: 15px;
      font-size: 14px;
      font-weight: 500;
      padding: 10px 15px;
      border-radius: 8px;
      background: rgba(40, 167, 69, 0.1);
      color: var(--success);
      width: 100%;
      text-align: center;
      display: none;
    }

    .file-preview i {
      margin-right: 8px;
    }

    .file-error {
      background: rgba(220, 53, 69, 0.1);
      color: var(--danger);
    }

    .file-size {
      font-size: 12px;
      color: var(--dark-gray);
      margin-left: 5px;
    }

    .file-requirements {
      font-size: 12px;
      color: var(--dark-gray);
      margin-top: 5px;
      font-style: italic;
    }

    /* ===== BUTTONS ===== */
    .form-buttons { 
      margin-top: 40px; 
      display: flex; 
      gap: 15px; 
      justify-content: space-between;
      padding-top: 25px;
      border-top: 1px solid var(--medium-gray);
    }

    .btn {
      padding: 16px 30px;
      border: none;
      border-radius: 10px;
      font-size: 16px;
      font-weight: 600;
      cursor: pointer;
      transition: var(--transition);
      display: flex;
      align-items: center;
      justify-content: center;
      gap: 10px;
      min-width: 150px;
    }

    .btn-primary {
      background: linear-gradient(135deg, var(--navy-blue), var(--secondary-blue));
      color: var(--white);
    }

    .btn-primary:hover {
      background: linear-gradient(135deg, var(--dark-blue), var(--navy-blue));
      transform: translateY(-2px);
      box-shadow: 0 5px 15px rgba(1, 47, 107, 0.3);
    }

    .btn-secondary {
      background: var(--white);
      color: var(--navy-blue);
      border: 2px solid var(--navy-blue);
    }

    .btn-secondary:hover {
      background: rgba(1, 47, 107, 0.05);
      transform: translateY(-2px);
    }

    .btn-gold {
      background: linear-gradient(135deg, var(--gold), #e6953e);
      color: var(--white);
    }

    .btn-gold:hover {
      background: linear-gradient(135deg, #e6953e, var(--gold));
      transform: translateY(-2px);
      box-shadow: 0 5px 15px rgba(242, 166, 90, 0.3);
    }

    .btn:disabled {
      opacity: 0.6;
      cursor: not-allowed;
      transform: none !important;
      box-shadow: none !important;
    }

    /* ===== SMART PROGRESS OVERLAY ===== */
    .progress-overlay { 
      position: fixed; 
      top:0; 
      left:0; 
      width:100%; 
      height:100%; 
      background: rgba(255, 255, 255, 0.95); 
      z-index:9999; 
      display:none; 
      align-items:center; 
      justify-content:center; 
      flex-direction: column;
    }

    .progress-container {
      width: 90%;
      max-width: 500px;
      background: var(--white);
      border-radius: 20px;
      padding: 40px;
      box-shadow: var(--shadow);
      text-align: center;
    }

    .progress-icon {
      font-size: 60px;
      color: var(--navy-blue);
      margin-bottom: 20px;
      animation: pulse 1.5s infinite;
    }

    @keyframes pulse {
      0%, 100% { transform: scale(1); }
      50% { transform: scale(1.1); }
    }

    .progress-title {
      font-size: 24px;
      font-weight: 700;
      color: var(--navy-blue);
      margin-bottom: 10px;
    }

    .progress-subtitle {
      color: var(--dark-gray);
      margin-bottom: 30px;
    }

    .progress-bar {
      height: 10px;
      background: var(--medium-gray);
      border-radius: 5px;
      overflow: hidden;
      margin-bottom: 20px;
    }

    .progress-fill {
      height: 100%;
      background: linear-gradient(90deg, var(--navy-blue), var(--gold));
      width: 0%;
      transition: width 0.5s ease;
      border-radius: 5px;
    }

    .progress-text {
      font-size: 14px;
      color: var(--dark-gray);
      font-weight: 500;
    }

    .progress-steps {
      display: flex;
      justify-content: space-between;
      margin-top: 20px;
      font-size: 12px;
      color: var(--dark-gray);
    }

    .progress-step {
      position: relative;
      text-align: center;
      flex: 1;
    }

    .progress-step.active {
      color: var(--navy-blue);
      font-weight: 600;
    }

    /* ===== HINTS & VALIDATION ===== */
    .hint { 
      font-size: 13px; 
      color: var(--dark-gray); 
      margin-top: 8px; 
      font-style: italic;
    }

    .error-message {
      color: var(--danger);
      font-size: 14px;
      margin-top: 5px;
      display: none;
    }

    .success-message {
      color: var(--success);
      font-size: 14px;
      margin-top: 5px;
      display: none;
    }

    /* ===== DRAG & DROP STYLES ===== */
    .drag-drop-hint {
      font-size: 12px;
      color: var(--navy-blue);
      margin-top: 8px;
      font-weight: 500;
    }

    /* ===== RESPONSIVE ===== */
    @media (max-width: 768px) {
      .form-container { 
        padding: 25px; 
      }
      
      .inline-inputs { 
        flex-direction: column; 
      }
      
      .inline-inputs > * { 
        width: 100%; 
      }
      
      .checkbox-grid { 
        grid-template-columns: 1fr; 
      }
      
      .form-buttons { 
        flex-direction: column; 
      }
      
      .btn { 
        width: 100%; 
      }
      
      .step-indicator {
        padding: 0 10px;
      }
      
      .step-label {
        font-size: 12px;
      }
      
      .file-upload-wrapper {
        min-height: 120px;
        padding: 20px 15px;
      }
    }

    @media (max-width: 480px) {
      body { 
        padding: 10px; 
      }
      
      .form-container { 
        padding: 20px; 
      }
      
      .logo-text {
        font-size: 24px;
      }
      
      .upload-icon {
        font-size: 32px;
      }
      
      .upload-text {
        font-size: 14px;
      }
    }
  </style>
</head>
<body>

<!-- SMART PROGRESS OVERLAY -->
<div id="progressOverlay" class="progress-overlay">
  <div class="progress-container">
    <div class="progress-icon">
      <i class="fas fa-graduation-cap"></i>
    </div>
    <h3 class="progress-title" id="progressTitle"><?php echo ct('processing_application'); ?></h3>
    <p class="progress-subtitle" id="progressSubtitle"><?php echo ct('please_wait'); ?></p>
    
    <div class="progress-bar">
      <div class="progress-fill" id="progressFill"></div>
    </div>
    <div class="progress-text" id="progressText">0% Complete</div>
    
    <div class="progress-steps">
      <div class="progress-step" id="step1Progress"><?php echo ct('validating_data'); ?></div>
      <div class="progress-step" id="step2Progress"><?php echo ct('uploading_files'); ?></div>
      <div class="progress-step" id="step3Progress"><?php echo ct('saving_information'); ?></div>
      <div class="progress-step" id="step4Progress"><?php echo ct('finalizing'); ?></div>
    </div>
  </div>
</div>

<div class="header">
  <div class="logo-container">
    <img class="brand-logo-img" src="<?php echo htmlspecialchars($parrotBrandLogoHref); ?>" alt="Parrot Canada Visa Consultant" width="180" height="56" decoding="async" />
    <div>
      <div class="logo-text">Parrot Canada Visa Consultant</div>
      <div class="logo-subtext"><?php echo ct('form_title'); ?></div>
    </div>
  </div>
  <p style="color: var(--dark-gray); max-width: 600px; margin: 0 auto; font-size: 15px;">
    <?php echo ct('form_subtitle'); ?>
  </p>
</div>

<div class="form-container">
  <!-- STEP INDICATOR -->
  <div class="step-indicator">
    <div class="step active" id="stepIndicator1">
      <div class="step-circle">1</div>
      <div class="step-label"><?php echo ct('step1_label'); ?></div>
    </div>
    <div class="step" id="stepIndicator2">
      <div class="step-circle">2</div>
      <div class="step-label"><?php echo ct('step2_label'); ?></div>
    </div>
  </div>

  <form id="creditForm" enctype="multipart/form-data" data-save="save_credit_transfer.php">
    <input type="hidden" name="user_id" value="<?= htmlspecialchars($userId) ?>">

    <!-- STEP 1: Personal Info -->
    <div class="form-step active" id="step1">
      <div class="section-title">
        <i class="fas fa-user-circle"></i>
        <?php echo ct('personal_info'); ?>
      </div>

      <div class="form-section">
        <div class="form-group">
          <label class="required"><?php echo ct('student_name'); ?></label>
          <div class="inline-inputs">
            <div>
              <input type="text" name="first_name" placeholder="<?php echo ct('first_name'); ?>" required>
              <div class="hint"><?php echo ct('legal_first_name'); ?></div>
            </div>
            <div>
              <input type="text" name="middle_name" placeholder="<?php echo ct('middle_name'); ?>">
              <div class="hint"><?php echo $current_lang === 'fr' ? 'Optionnel' : 'Optional'; ?></div>
            </div>
            <div>
              <input type="text" name="last_name" placeholder="<?php echo ct('last_name'); ?>" required>
              <div class="hint"><?php echo ct('family_name'); ?></div>
            </div>
          </div>
        </div>

        <div class="form-group">
          <label class="required"><?php echo ct('birth_date'); ?></label>
          <div class="inline-inputs">
            <select name="birth_month" required>
              <option value=""><?php echo $current_lang === 'fr' ? 'Mois' : 'Month'; ?></option>
              <?php foreach (range(1, 12) as $m): ?>
                <option value="<?= sprintf('%02d', $m) ?>"><?= date('F', mktime(0, 0, 0, $m, 1)) ?></option>
              <?php endforeach; ?>
            </select>
            
            <select name="birth_day" required>
              <option value=""><?php echo $current_lang === 'fr' ? 'Jour' : 'Day'; ?></option>
              <?php foreach (range(1, 31) as $d): ?>
                <option value="<?= sprintf('%02d', $d) ?>"><?= $d ?></option>
              <?php endforeach; ?>
            </select>
            
            <select name="birth_year" required>
              <option value=""><?php echo $current_lang === 'fr' ? 'Année' : 'Year'; ?></option>
              <?php $currentYear = date('Y'); ?>
              <?php foreach (range($currentYear, $currentYear - 80) as $y): ?>
                <option value="<?= $y ?>"><?= $y ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>

        <div class="form-group">
          <label class="required"><?php echo ct('gender'); ?></label>
          <select name="gender" required>
            <option value="" disabled selected><?php echo $current_lang === 'fr' ? 'Sélectionner le Genre' : 'Select Gender'; ?></option>
            <option value="Male"><?php echo ct('gender_male'); ?></option>
            <option value="Female"><?php echo ct('gender_female'); ?></option>
            <option value="Other"><?php echo ct('gender_other'); ?></option>
          </select>
        </div>

        <div class="form-group">
          <label><?php echo ct('contact_address'); ?></label>
          <input type="text" name="street_address" placeholder="<?php echo ct('street_address'); ?>">
          <input type="text" name="address_line_2" placeholder="<?php echo ct('address_line_2'); ?>" class="mt-3">
          <div class="inline-inputs mt-3">
            <input type="text" name="city" placeholder="<?php echo ct('city'); ?>">
            <input type="text" name="state" placeholder="<?php echo ct('state'); ?>">
            <input type="text" name="postal_code" placeholder="<?php echo ct('postal_code'); ?>">
          </div>
        </div>

        <div class="form-group">
          <label class="required"><?php echo ct('email_address'); ?></label>
          <input type="email" name="email" placeholder="your.email@example.com" required>
          <div class="hint"><?php echo ct('email_hint'); ?></div>
        </div>

        <div class="form-group">
          <label><?php echo ct('contact_numbers'); ?></label>
          <div class="inline-inputs">
            <input type="text" name="mobile_number" placeholder="<?php echo ct('mobile_number'); ?>">
            <input type="text" name="phone_number" placeholder="<?php echo ct('phone_number'); ?>">
            <input type="text" name="work_number" placeholder="<?php echo ct('work_number'); ?>">
          </div>
        </div>

        <div class="form-group">
          <label><?php echo ct('current_company'); ?></label>
          <input type="text" name="company" placeholder="<?php echo $current_lang === 'fr' ? 'Nom de l\'Entreprise (Optionnel)' : 'Company Name (Optional)'; ?>">
        </div>
      </div>

      <div class="form-buttons">
        <button type="button" class="btn btn-gold" onclick="saveStep('step1')">
          <i class="fas fa-arrow-right"></i>
          <?php echo ct('continue_to_academic'); ?>
        </button>
      </div>
    </div>

    <!-- STEP 2: Academic Info -->
    <div class="form-step" id="step2">
      <div class="section-title">
        <i class="fas fa-graduation-cap"></i>
        <?php echo ct('academic_info'); ?>
      </div>

      <div class="form-section">
        <div class="form-group">
          <label class="required"><?php echo ct('current_education_level'); ?></label>
          <div class="checkbox-grid">
            <label><input type="checkbox" name="edu_level[]" value="High School Certificate"> <?php echo ct('edu_high_school'); ?></label>
            <label><input type="checkbox" name="edu_level[]" value="Ordinary Diploma of 2 years"> <?php echo ct('edu_ordinary_diploma'); ?></label>
            <label><input type="checkbox" name="edu_level[]" value="Advanced Diploma of 3 years"> <?php echo ct('edu_advanced_diploma'); ?></label>
            <label><input type="checkbox" name="edu_level[]" value="Bachelor without Degree"> <?php echo ct('edu_bachelor_no_degree'); ?></label>
            <label><input type="checkbox" name="edu_level[]" value="Bachelor with Lower Division"> <?php echo ct('edu_bachelor_lower'); ?></label>
            <label><input type="checkbox" name="edu_level[]" value="Bachelor with Upper Division"> <?php echo ct('edu_bachelor_upper'); ?></label>
            <label><input type="checkbox" name="edu_level[]" value="Masters with Lower Division"> <?php echo ct('edu_masters_lower'); ?></label>
            <label><input type="checkbox" name="edu_level[]" value="Masters with Upper Division"> <?php echo ct('edu_masters_upper'); ?></label>
          </div>
          <div class="hint"><?php echo ct('education_hint'); ?></div>
        </div>

        <div class="form-group">
          <label class="required"><?php echo ct('desired_certification'); ?></label>
          <div class="checkbox-grid">
            <label><input type="checkbox" name="cert_level[]" value="Bachelor"> <?php echo ct('cert_bachelor'); ?></label>
            <label><input type="checkbox" name="cert_level[]" value="Masters"> <?php echo ct('cert_masters'); ?></label>
            <label><input type="checkbox" name="cert_level[]" value="PhD"> <?php echo ct('cert_phd'); ?></label>
          </div>
        </div>

        <div class="form-group">
          <label class="required"><?php echo ct('current_program'); ?></label>
          <input type="text" name="current_program" placeholder="<?php echo $current_lang === 'fr' ? 'ex. Bachelor of Business Administration' : 'e.g., Bachelor of Business Administration'; ?>" required>
        </div>

        <div class="form-group">
          <label class="required"><?php echo ct('select_university'); ?></label>
          <select name="university" id="university" required>
            <option value="" disabled selected><?php echo $current_lang === 'fr' ? 'Choisissez votre université' : 'Choose your university'; ?></option>
            <option value="UPAFA">Université Africaine Franco-Arabe (UPAFA)</option>
            <option value="DPHU">Distant Production house University (DPHU)</option>
            <option value="IST">Institut Supérieur de Burkina Faso (IST)</option>
          </select>
        </div>

        <div class="form-group">
          <label class="required"><?php echo ct('proposed_program'); ?></label>
          <input type="text" name="proposed_program" id="proposed_program" list="programOptions" placeholder="<?php echo $current_lang === 'fr' ? 'Commencez à taper pour rechercher des programmes...' : 'Start typing to search programs...'; ?>" required autocomplete="off">
          <datalist id="programOptions"></datalist>
          <div class="hint">
            <i class="fas fa-lightbulb"></i> <?php echo ct('program_hint'); ?>
          </div>
        </div>

        <div class="section-title mt-5">
          <i class="fas fa-file-upload"></i>
          <?php echo ct('required_documents'); ?>
        </div>

        <!-- FIXED FILE UPLOAD COMPONENTS -->
        <div class="form-group">
          <label class="required"><?php echo ct('degree_certificate'); ?></label>
          <div class="file-upload-container">
            <div class="file-upload-wrapper" id="degreeUploadWrapper">
              <input type="file" name="current_degree" id="current_degree" accept=".pdf,.jpg,.jpeg,.png,.doc,.docx" required>
              <div class="upload-icon">
                <i class="fas fa-file-certificate"></i>
              </div>
              <div class="upload-text"><?php echo ct('click_to_upload'); ?> <?php echo ct('degree_certificate'); ?></div>
              <div class="upload-hint"><?php echo ct('max_10mb'); ?></div>
              <div class="drag-drop-hint"><?php echo ct('or_drag_drop'); ?></div>
              <div class="file-preview" id="degreePreview"></div>
            </div>
            <div class="file-requirements"><?php echo ct('accepted_formats'); ?></div>
          </div>
        </div>

        <div class="form-group">
          <label class="required"><?php echo ct('academic_transcripts'); ?></label>
          <div class="file-upload-container">
            <div class="file-upload-wrapper" id="transcriptUploadWrapper">
              <input type="file" name="current_transcripts" id="current_transcripts" accept=".pdf,.jpg,.jpeg,.png,.doc,.docx" required>
              <div class="upload-icon">
                <i class="fas fa-file-alt"></i>
              </div>
              <div class="upload-text"><?php echo ct('click_to_upload'); ?> <?php echo ct('academic_transcripts'); ?></div>
              <div class="upload-hint"><?php echo ct('max_10mb'); ?></div>
              <div class="drag-drop-hint"><?php echo ct('or_drag_drop'); ?></div>
              <div class="file-preview" id="transcriptPreview"></div>
            </div>
            <div class="file-requirements"><?php echo ct('accepted_formats'); ?></div>
          </div>
        </div>

        <div class="form-group">
          <label class="required"><?php echo ct('passport_id'); ?></label>
          <div class="file-upload-container">
            <div class="file-upload-wrapper" id="passportUploadWrapper">
              <input type="file" name="passport_or_id" id="passport_or_id" accept=".pdf,.jpg,.jpeg,.png" required>
              <div class="upload-icon">
                <i class="fas fa-id-card"></i>
              </div>
              <div class="upload-text"><?php echo ct('click_to_upload'); ?> <?php echo $current_lang === 'fr' ? 'Document d\'identité' : 'ID Document'; ?></div>
              <div class="upload-hint"><?php echo ct('max_10mb'); ?></div>
              <div class="drag-drop-hint"><?php echo ct('or_drag_drop'); ?></div>
              <div class="file-preview" id="passportPreview"></div>
            </div>
            <div class="file-requirements"><?php echo $current_lang === 'fr' ? 'Accepté : .pdf, .jpg, .jpeg, .png' : 'Accepted: .pdf, .jpg, .jpeg, .png'; ?></div>
          </div>
        </div>

        <div class="form-group">
          <label class="required"><?php echo ct('academic_cv'); ?></label>
          <div class="file-upload-container">
            <div class="file-upload-wrapper" id="cvUploadWrapper">
              <input type="file" name="academic_cv" id="academic_cv" accept=".pdf,.doc,.docx" required>
              <div class="upload-icon">
                <i class="fas fa-file-contract"></i>
              </div>
              <div class="upload-text"><?php echo ct('click_to_upload'); ?> <?php echo ct('academic_cv'); ?></div>
              <div class="upload-hint"><?php echo ct('max_10mb'); ?></div>
              <div class="drag-drop-hint"><?php echo ct('or_drag_drop'); ?></div>
              <div class="file-preview" id="cvPreview"></div>
            </div>
            <div class="file-requirements"><?php echo $current_lang === 'fr' ? 'Accepté : .pdf, .doc, .docx' : 'Accepted: .pdf, .doc, .docx'; ?></div>
          </div>
        </div>

        <div class="form-group">
          <label class="required"><?php echo ct('payment_proof'); ?></label>
          <div class="file-upload-container">
            <div class="file-upload-wrapper" id="paymentUploadWrapper">
              <input type="file" name="payment_proof" id="payment_proof" accept=".pdf,.jpg,.jpeg,.png" required>
              <div class="upload-icon">
                <i class="fas fa-receipt"></i>
              </div>
              <div class="upload-text"><?php echo ct('click_to_upload'); ?> <?php echo ct('payment_proof'); ?></div>
              <div class="upload-hint"><?php echo ct('max_10mb'); ?></div>
              <div class="drag-drop-hint"><?php echo ct('or_drag_drop'); ?></div>
              <div class="file-preview" id="paymentPreview"></div>
            </div>
            <div class="file-requirements"><?php echo $current_lang === 'fr' ? 'Accepté : .pdf, .jpg, .jpeg, .png' : 'Accepted: .pdf, .jpg, .jpeg, .png'; ?></div>
          </div>
        </div>

        <div class="form-group">
          <label><?php echo ct('additional_comments'); ?></label>
          <textarea name="comments" placeholder="<?php echo $current_lang === 'fr' ? 'Toute information supplémentaire que vous souhaitez partager avec le comité d\'admission...' : 'Any additional information you\'d like to share with the admissions committee...'; ?>"></textarea>
          <div class="hint"><?php echo ct('comments_hint'); ?></div>
        </div>
      </div>

      <div class="form-buttons">
        <button type="button" class="btn btn-secondary" onclick="prevStep()">
          <i class="fas fa-arrow-left"></i>
          <?php echo ct('back_to_personal'); ?>
        </button>
        <button type="submit" class="btn btn-primary" id="submitButton">
          <i class="fas fa-paper-plane"></i>
          <?php echo ct('submit_application'); ?>
        </button>
      </div>
    </div>
  </form>
</div>

<?php include 'footer.php'; ?>

<script>
/* ===== Brand colors ===== */
const COLORS = {
  navyBlue: '#427431',
  secondaryBlue: '#3661B9',
  darkBlue: '#2f5a26',
  gold: '#E21D1E',
  white: '#FFFFFF',
  success: '#28a745',
  danger: '#dc3545'
};

/* ===== PROGRAM DATA ===== */
const PROGRAMS = {
  UPAFA: [
    "Management Information Systems", "General Computing", "Economy", "Corporate and Market Finance",
    "Business Administration and Aviation", "Business Administration in International Marketing",
    "Maintenance – Networks and Telecommunications", "Marketing & Public Relations", "Hotel Management and Tourism",
    "Supply Chain Management and Logistics", "Business Management and Administration", "Accounting",
    "Economic and Financial Analysis", "Islamic Finance", "Home Economics", "Finance Bank", "Transport Logistics",
    "Customs Transit", "Project Planning and Management", "Finance", "Information and Communication Technology (ICT)",
    "Computer and Multimedia Networks", "Data Science", "Catastrophic Risk Management and Adaptation to Climate Change",
    "Risk Management and Insurance Digital and Customers", "Portfolio Management", "Cash Management",
    "Organization Management", "Economy of Inspiration", "Economics of Resilience", "Business Management",
    "Public Administration", "Audit", "Literature History", "Civilization and Heritage", "Legal Sciences",
    "Politics and Administration", "Jurisprudence", "Science of Education and Training", "Translation and Interpretation",
    "Journalism and Communication", "Sociology and Anthropology", "Social Work and Community Development",
    "Human Resources Management", "Philosophy", "International Development", "Private and Public Law",
    "International Law", "Criminology", "Management and Political Science", "Theology", "Islamic Sciences",
    "International Relations and Diplomacy", "Human and Social Sciences", "Comparison of Religions",
    "Islamic Philosophy", "Business Law and Taxation", "Geography", "Islamic Theology",
    "Literature and Language (English, Chinese, Russian, Spanish, African Languages)", "Surveying and Geomatics Sciences",
    "Geotechnical and Pavement Engineering", "Civil Engineering", "Civil Engineering (Construction Technology, Road and Highway Engineering)",
    "Electrical and Electronic Engineering", "Water and Sanitation Engineering", "Geology", "Forestry Sciences",
    "Agronomy and Animal Husbandry", "Energy", "Mining Survey", "Mining Engineering", "Oil and Gas Engineering",
    "Architecture", "Food Science", "GIS and Urban Planning", "Agri-business Management", "Construction Management",
    "Land Management and Administration", "Mechanical Engineering", "Mechanical Engineering (Automotive, Manufacturing)",
    "Industrial Engineering", "Biotechnology", "Art and Design Technology (Graphic Design, Fashion Design, Textile and Sewing Technology)",
    "Meter", "Biodiversity and Conservation", "Environmental Management", "Thermal Engineering",
    "Energy and Renewable Energy", "Real Estate Valuation and Property Management", "Biomedical Technology",
    "General Medicine", "Health Services Management", "Public Health", "Human Nutrition", "Epidemiology",
    "Forensic Medicine", "Community Health", "Clinical Psychology and Guidance", "Biomedical Laboratory Sciences",
    "Ultrasound", "Medical Laboratory Sciences", "Nursing", "Pharmacy", "Pathology", "Orthopedic Surgery",
    "Radiology", "Gynecology and Obstetrics", "Mental Health"
  ],

  DPHU: [
    "MBA", "Transport and Logistics Management", "Human Resource Management", "Project Management",
    "Economic Development", "Information and Communications Technology", "International Criminal & Justice",
    "Land Administration and Management", "Open Distance Learning", "Psychology",
    "Administration, Planning and Policy & Studies", "Curriculum Design and Development", "Quality Management",
    "Environmental Studies – Health", "Environmental Studies – Management", "Environmental Studies – Sciences",
    "Computer Science", "Information Technology Management", "Biology", "Botany", "Chemistry", "Physics",
    "Human Nutrition", "Mathematics", "Information Communication Technology", "Social Work", "Economics",
    "Community Economic Development", "Tourism Studies", "Natural Resource Assessment and Management",
    "International Development and Cooperation", "Humanitarian Action, Cooperation & Development",
    "Governance and Leadership", "Kiswahili", "Literature", "Linguistics", "Library and Information Management",
    "Monitoring and Evaluation", "Gender Studies", "Mass Communication", "Arts in Literature", "Geography",
    "History", "Accounting and Financial Sciences and Techniques", "Banking and Corporate Finance",
    "Human Resources Management", "Sales Management and International Marketing",
    "Administration and Management of Organizations", "Transport Logistics", "Management Information Systems",
    "Business Communication", "Private Law", "Business Law", "Public Law", "International Humanitarian Law",
    "International Relations and Diplomacy", "Banking and Financial Law", "Insurance Law", "Corporate Tax Law",
    "Peace Administration", "International Governance and Sustainable Development",
    "Computer Networks and Telecommunications", "Civil Engineering – Public Works", "Electrical Engineering",
    "Mechanical Engineering", "Rural and Environmental Engineering", "Livestock and Animal Production",
    "Agronomy – Plant Production", "Water and Environmental Management/Water and Forestry",
    "Socio-Economy & Rural Economy", "Sanitary and Environmental Engineering", "Human Nutrition and Nutrition Policy",
    "Epidemiology of Intervention", "Health Information Systems Engineering", "Nursing Sciences",
    "Obstetrical and Gynecological Sciences", "Mental Health (Psychiatric Care)", "Community Health Care",
    "Health psychpedagogy", "Emergency Care", "Health Care Administration", "Management of Health and Social Organizations",
    "Hospital Management", "Reproductive Health", "Management of Health Projects and Programs",
    "Monitoring & Evaluation of Health Projects and Programs"
  ],

  IST: {
    "Advanced Diploma": [
      "Electrical Engineering", "Mechanical Engineering", "Mechanical and Manufacturing Engineering",
      "Aerospace Engineering", "Civil Engineering and Management", "Automotive and Power Engineering",
      "Mining Engineering – Geology option", "Mining Engineering – Metallurgy option", "Thermal & Energy Engineering",
      "Industrial Engineering", "Networks & Computer Systems (IT)", "Agro-industry", "Agribusiness Engineering",
      "Business Administration and Finance", "Finance & Accounting", "Marketing & Business Communication",
      "Banking & Microfinance", "Medical Laboratory Sciences", "Nursing", "Pharmacy"
    ],
    "Bachelor's Programs": [
      "Electrical Engineering", "Mechanical Engineering", "Mechanical and Manufacturing Engineering",
      "Aerospace Engineering", "Civil Engineering and Management", "Automotive and Power Engineering",
      "Mining Engineering – Geology option", "Mining Engineering – Metallurgy option", "Thermal & Energy Engineering",
      "Industrial Engineering", "Networks & Computer Systems (IT)", "Agro-industry", "Agribusiness Engineering",
      "Business Administration and Finance", "Finance & Accounting", "Marketing & Business Communication",
      "Banking & Microfinance", "Medical Laboratory Sciences", "Nursing", "Pharmacy"
    ],
    "Master's Programs": [
      "Mining Engineering – Mineralurgy option", "Electrical Engineering", "Mechanical Engineering",
      "Mechanical and Manufacturing Engineering", "Aerospace Engineering", "Civil Engineering and Management",
      "Automotive and Power Engineering", "Mining Engineering – Geology option", "Mining Engineering – Metallurgy option",
      "Thermal & Energy Engineering", "Industrial Engineering", "Networks & Computer Systems (IT)", "Agro-industry",
      "Agribusiness Engineering", "Business Administration and Finance", "Finance & Accounting",
      "Marketing & Business Communication", "Banking & Microfinance", "Medical Laboratory Sciences", "Nursing", "Pharmacy"
    ]
  }
};

/* ===== FORM STATE ===== */
let currentStep = 1;
let uploadedFiles = {
  current_degree: null,
  current_transcripts: null,
  passport_or_id: null,
  academic_cv: null,
  payment_proof: null
};

/* ===== UTILITY FUNCTIONS ===== */
function updateStepIndicator(step) {
  document.querySelectorAll('.step').forEach((el, idx) => {
    if (idx + 1 < step) {
      el.classList.remove('active');
      el.classList.add('completed');
    } else if (idx + 1 === step) {
      el.classList.add('active');
      el.classList.remove('completed');
    } else {
      el.classList.remove('active', 'completed');
    }
  });
}

function showStep(n) {
  document.querySelectorAll('.form-step').forEach(step => {
    step.classList.remove('active');
  });
  
  document.getElementById(`step${n}`).classList.add('active');
  updateStepIndicator(n);
  currentStep = n;
  
  document.querySelector('.form-container').scrollIntoView({ behavior: 'smooth' });
}

function prevStep() {
  if (currentStep > 1) showStep(currentStep - 1);
}

/* ===== FILE UPLOAD HANDLING - FIXED ===== */
function setupFileUploadHandlers() {
  const fileFields = [
    { id: 'current_degree', wrapper: 'degreeUploadWrapper', preview: 'degreePreview' },
    { id: 'current_transcripts', wrapper: 'transcriptUploadWrapper', preview: 'transcriptPreview' },
    { id: 'passport_or_id', wrapper: 'passportUploadWrapper', preview: 'passportPreview' },
    { id: 'academic_cv', wrapper: 'cvUploadWrapper', preview: 'cvPreview' },
    { id: 'payment_proof', wrapper: 'paymentUploadWrapper', preview: 'paymentPreview' }
  ];

  fileFields.forEach(field => {
    const input = document.getElementById(field.id);
    const wrapper = document.getElementById(field.wrapper);
    const preview = document.getElementById(field.preview);
    
    if (!input || !wrapper || !preview) return;
    
    // Click handler for wrapper
    wrapper.addEventListener('click', (e) => {
      if (e.target !== input) {
        input.click();
      }
    });
    
    // File change handler
    input.addEventListener('change', function(e) {
      if (this.files.length > 0) {
        handleFileSelect(this.files[0], field.id, wrapper, preview);
      }
    });
    
    // Drag and drop handlers
    wrapper.addEventListener('dragover', (e) => {
      e.preventDefault();
      wrapper.classList.add('dragover');
    });
    
    wrapper.addEventListener('dragleave', () => {
      wrapper.classList.remove('dragover');
    });
    
    wrapper.addEventListener('drop', (e) => {
      e.preventDefault();
      wrapper.classList.remove('dragover');
      
      if (e.dataTransfer.files.length > 0) {
        input.files = e.dataTransfer.files;
        handleFileSelect(e.dataTransfer.files[0], field.id, wrapper, preview);
      }
    });
  });
}

function handleFileSelect(file, fieldName, wrapper, previewEl) {
  // Validate file size (10MB max)
  const maxSize = 10 * 1024 * 1024; // 10MB
  
  if (file.size > maxSize) {
    previewEl.innerHTML = `<i class="fas fa-exclamation-circle"></i> <?php echo $current_lang === 'fr' ? 'Fichier trop volumineux ! Max 10MB' : 'File too large! Max 10MB'; ?>`;
    previewEl.classList.add('file-error');
    previewEl.style.display = 'block';
    wrapper.classList.remove('has-file');
    wrapper.classList.add('dragover');
    
    // Reset input
    const input = document.getElementById(fieldName);
    input.value = '';
    
    uploadedFiles[fieldName] = null;
    return;
  }
  
  // Validate file type based on field
  const validExtensions = getValidExtensions(fieldName);
  const fileExt = file.name.split('.').pop().toLowerCase();
  
  if (!validExtensions.includes(fileExt)) {
    previewEl.innerHTML = `<i class="fas fa-exclamation-circle"></i> <?php echo $current_lang === 'fr' ? 'Type de fichier invalide ! Autorisé :' : 'Invalid file type! Allowed:'; ?> ${validExtensions.join(', ')}`;
    previewEl.classList.add('file-error');
    previewEl.style.display = 'block';
    wrapper.classList.remove('has-file');
    
    // Reset input
    const input = document.getElementById(fieldName);
    input.value = '';
    
    uploadedFiles[fieldName] = null;
    return;
  }
  
  // Success - update UI
  const fileSize = (file.size / 1024 / 1024).toFixed(2);
  previewEl.innerHTML = `
    <i class="fas fa-check-circle"></i> 
    <strong>${file.name}</strong>
    <span class="file-size">(${fileSize} MB)</span>
  `;
  previewEl.classList.remove('file-error');
  previewEl.style.display = 'block';
  wrapper.classList.add('has-file');
  wrapper.classList.remove('dragover');
  
  // Store file reference
  uploadedFiles[fieldName] = file;
  
  console.log(`File attached: ${fieldName} - ${file.name} (${fileSize} MB)`);
}

function getValidExtensions(fieldName) {
  switch(fieldName) {
    case 'current_degree':
    case 'current_transcripts':
      return ['pdf', 'jpg', 'jpeg', 'png', 'doc', 'docx'];
    case 'passport_or_id':
    case 'payment_proof':
      return ['pdf', 'jpg', 'jpeg', 'png'];
    case 'academic_cv':
      return ['pdf', 'doc', 'docx'];
    default:
      return ['pdf', 'jpg', 'jpeg', 'png', 'doc', 'docx'];
  }
}

/* ===== SMART PROGRAM POPULATION ===== */
function populatePrograms(university) {
  const datalist = document.getElementById('programOptions');
  const programInput = document.getElementById('proposed_program');
  
  datalist.innerHTML = '';
  programInput.value = '';
  
  const data = PROGRAMS[university];
  if (!data) return;
  
  if (Array.isArray(data)) {
    data.forEach(name => {
      const opt = document.createElement('option');
      opt.value = name;
      datalist.appendChild(opt);
    });
  } else {
    for (const category in data) {
      if (Array.isArray(data[category])) {
        data[category].forEach(name => {
          const opt = document.createElement('option');
          opt.value = `${name} (${category})`;
          opt.dataset.category = category;
          datalist.appendChild(opt);
        });
      }
    }
  }
}

/* ===== SMART PROGRESS BAR ===== */
function showProgress(title, subtitle) {
  const overlay = document.getElementById('progressOverlay');
  const progressTitle = document.getElementById('progressTitle');
  const progressSubtitle = document.getElementById('progressSubtitle');
  
  progressTitle.textContent = title;
  progressSubtitle.textContent = subtitle;
  overlay.style.display = 'flex';
  
  document.getElementById('progressFill').style.width = '0%';
  document.getElementById('progressText').textContent = '0% Complete';
  
  document.querySelectorAll('.progress-step').forEach(step => {
    step.classList.remove('active');
  });
  document.getElementById('step1Progress').classList.add('active');
}

function updateProgress(percent, step, message) {
  const progressFill = document.getElementById('progressFill');
  const progressText = document.getElementById('progressText');
  
  progressFill.style.width = `${percent}%`;
  progressText.textContent = message || `${percent}% Complete`;
  
  document.querySelectorAll('.progress-step').forEach(el => {
    el.classList.remove('active');
  });
  
  if (step >= 1 && step <= 4) {
    document.getElementById(`step${step}Progress`).classList.add('active');
  }
}

function hideProgress() {
  document.getElementById('progressOverlay').style.display = 'none';
}

/* ===== FORM VALIDATION ===== */
function validateStep2() {
  const requiredFiles = ['current_degree', 'current_transcripts', 'passport_or_id', 'academic_cv', 'payment_proof'];
  const missingFiles = [];
  
  requiredFiles.forEach(fieldName => {
    const input = document.getElementById(fieldName);
    if (!input || !input.files || input.files.length === 0) {
      missingFiles.push(fieldName.replace(/_/g, ' '));
    }
  });
  
  if (missingFiles.length > 0) {
    alert(`<?php echo $current_lang === 'fr' ? 'Veuillez télécharger tous les fichiers requis :' : 'Please upload all required files:' ?>\n\n• ${missingFiles.join('\n• ')}`);
    return false;
  }
  
  // Validate file sizes
  for (const fieldName of requiredFiles) {
    const input = document.getElementById(fieldName);
    if (input.files[0].size > 10 * 1024 * 1024) {
      alert(`<?php echo $current_lang === 'fr' ? 'Fichier trop volumineux :' : 'File too large:' ?> ${fieldName.replace(/_/g, ' ')}\n<?php echo $current_lang === 'fr' ? 'La taille maximale des fichiers est de 10MB' : 'Maximum file size is 10MB' ?>`);
      return false;
    }
  }
  
  return true;
}

/* ===== FORM SUBMISSION ===== */
async function saveStep(step) {
  const form = document.getElementById('creditForm');
  const formData = new FormData(form);
  formData.append('step', step);
  
  const button = document.querySelector(`button[onclick*="${step}"]`);
  if (button) button.disabled = true;
  
  showProgress('<?php echo ct('saving_info'); ?>', '<?php echo ct('saving_details'); ?>');
  updateProgress(30, 2, '<?php echo $current_lang === 'fr' ? 'Validation de vos informations...' : 'Validating your information...' ?>');
  
  try {
    const response = await fetch(form.getAttribute('data-save'), {
      method: 'POST',
      body: formData
    });
    
    const data = await response.json();
    
    if (data.status === 'success') {
      updateProgress(100, 3, '<?php echo $current_lang === 'fr' ? 'Informations personnelles sauvegardées avec succès !' : 'Personal information saved successfully!' ?>');
      
      setTimeout(() => {
        hideProgress();
        const stepNum = parseInt(step.replace('step', ''));
        showStep(stepNum + 1);
      }, 500);
    } else {
      hideProgress();
      alert(`❌ ${data.message}`);
    }
  } catch (error) {
    hideProgress();
    alert(`❌ <?php echo $current_lang === 'fr' ? 'Erreur réseau :' : 'Network error:' ?> ${error.message}`);
  } finally {
    if (button) button.disabled = false;
  }
}

/* ===== FINAL SUBMISSION ===== */
document.getElementById('creditForm').addEventListener('submit', async function(e) {
  e.preventDefault();
  
  // Validate files
  if (!validateStep2()) {
    return;
  }
  
  const form = this;
  const uni = document.getElementById('university').value;
  const proposed = document.getElementById('proposed_program').value.trim();
  
  // Validation
  if (!uni) {
    alert('<?php echo $current_lang === 'fr' ? 'Veuillez sélectionner une université.' : 'Please select a University.' ?>');
    return;
  }
  
  // Check if program exists in selected university
  let programExists = false;
  const programs = PROGRAMS[uni];
  
  if (Array.isArray(programs)) {
    programExists = programs.includes(proposed);
  } else if (programs && typeof programs === 'object') {
    for (const category in programs) {
      if (programs[category].includes(proposed.replace(` (${category})`, ''))) {
        programExists = true;
        break;
      }
    }
  }
  
  if (!programExists) {
    alert('<?php echo $current_lang === 'fr' ? 'Veuillez sélectionner un programme proposé valide parmi les suggestions.' : 'Please select a valid Proposed Program from the suggestions.' ?>');
    return;
  }
  
  // Prepare form data
  const formData = new FormData(form);
  formData.append('step', 'step2');
  
  const submitBtn = document.getElementById('submitButton');
  if (submitBtn) submitBtn.disabled = true;
  
  // Show detailed progress overlay
  showProgress('<?php echo ct('submitting_application'); ?>', '<?php echo ct('may_take_moment'); ?>');
  
  // Real progress updates
  const progressUpdates = [
    {percent: 10, step: 1, message: '<?php echo $current_lang === 'fr' ? 'Validation des données du formulaire...' : 'Validating form data...' ?>'},
    {percent: 25, step: 1, message: '<?php echo $current_lang === 'fr' ? 'Vérification des champs requis...' : 'Checking required fields...' ?>'},
    {percent: 40, step: 2, message: '<?php echo $current_lang === 'fr' ? 'Préparation des téléchargements...' : 'Preparing file uploads...' ?>'},
    {percent: 60, step: 2, message: '<?php echo $current_lang === 'fr' ? 'Téléchargement des documents...' : 'Uploading documents...' ?>'},
    {percent: 75, step: 3, message: '<?php echo $current_lang === 'fr' ? 'Sauvegarde des informations académiques...' : 'Saving academic information...' ?>'},
    {percent: 90, step: 3, message: '<?php echo $current_lang === 'fr' ? 'Finalisation de la soumission...' : 'Finalizing submission...' ?>'},
    {percent: 95, step: 4, message: '<?php echo $current_lang === 'fr' ? 'Envoi de la confirmation...' : 'Sending confirmation...' ?>'},
    {percent: 100, step: 4, message: '<?php echo $current_lang === 'fr' ? 'Soumission terminée !' : 'Submission complete!' ?>'}
  ];
  
  // Animate progress
  for (let i = 0; i < progressUpdates.length; i++) {
    setTimeout(() => {
      const update = progressUpdates[i];
      updateProgress(update.percent, update.step, update.message);
    }, i * 300);
  }
  
  try {
    console.log('Submitting form with files...');
    
    const response = await fetch(form.getAttribute('data-save'), {
      method: 'POST',
      body: formData
    });
    
    const data = await response.json();
    
    // Final delay for animation
    setTimeout(() => {
      if (data.status === 'success') {
        updateProgress(100, 4, '<?php echo $current_lang === 'fr' ? 'Demande soumise avec succès !' : 'Application submitted successfully!' ?>');
        
        setTimeout(() => {
          hideProgress();
          alert(`✅ ${data.message}\n🆔 <?php echo $current_lang === 'fr' ? 'ID de la demande :' : 'Application ID:' ?> ${data.user_id}`);
          window.location.href = 'index.php';
        }, 1000);
      } else {
        hideProgress();
        alert(`❌ ${data.message}`);
        if (submitBtn) submitBtn.disabled = false;
      }
    }, 2500);
    
  } catch (error) {
    hideProgress();
    alert(`❌ <?php echo $current_lang === 'fr' ? 'Échec de la soumission :' : 'Submission failed:' ?> ${error.message}`);
    if (submitBtn) submitBtn.disabled = false;
  }
});

/* ===== INITIALIZATION ===== */
document.addEventListener('DOMContentLoaded', () => {
  // Initialize step
  showStep(1);
  
  // Setup university program listener
  document.getElementById('university').addEventListener('change', function(e) {
    populatePrograms(e.target.value);
  });
  
  // Setup file upload handlers - CRITICAL FIX
  setupFileUploadHandlers();
  
  // Auto-populate programs if university is pre-selected
  const universitySelect = document.getElementById('university');
  if (universitySelect.value) {
    populatePrograms(universitySelect.value);
  }
  
  console.log('Form initialized with file upload handlers ready.');
});
</script>

</body>
</html>