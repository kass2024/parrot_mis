<?php
declare(strict_types=1);

function student_consent_escape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function student_consent_fields(): array
{
    return [
        'student_name' => ['text', 160],
        'date_of_birth' => ['date', 10],
        'passport_id' => ['text', 80],
        'nationality' => ['text', 100],
        'email' => ['email', 190],
        'phone' => ['tel', 64],
        'institution_program' => ['text', 200],
        'country' => ['text', 100],
    ];
}

function student_consent_today(): string
{
    return (new DateTimeImmutable('today', new DateTimeZone('Africa/Nairobi')))->format('Y-m-d');
}

function student_consent_date_is_valid(string $value): bool
{
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    return $date !== false && $date->format('Y-m-d') === $value;
}

function student_consent_png(string $dataUrl): ?string
{
    $prefix = 'data:image/png;base64,';
    if (strlen($dataUrl) > 500000 || !str_starts_with($dataUrl, $prefix)) {
        return null;
    }
    $bytes = base64_decode(substr($dataUrl, strlen($prefix)), true);
    $size = $bytes !== false ? @getimagesizefromstring($bytes) : false;
    return $size && $size[2] === IMAGETYPE_PNG && $size[0] <= 1200 && $size[1] <= 500 ? $bytes : null;
}

function student_consent_copy(string $language): array
{
    if ($language === 'fr') {
        return [
            'title' => 'FORMULAIRE DE CONSENTEMENT ET D’AUTORISATION DE L’ÉTUDIANT',
            'information' => '1. RENSEIGNEMENTS SUR L’ÉTUDIANT',
            'labels' => [
                'student_name' => 'Nom légal complet',
                'date_of_birth' => 'Date de naissance',
                'passport_id' => 'Numéro de passeport ou de pièce d’identité',
                'nationality' => 'Nationalité',
                'email' => 'Adresse courriel',
                'phone' => 'Téléphone / WhatsApp',
                'institution_program' => 'Établissement et programme souhaités',
                'country' => 'Pays',
            ],
            'consent' => '2. CONSENTEMENT ET AUTORISATION',
            'appointment' => 'Je soussigné(e), étudiant(e), désigne volontairement PARROT CANADA VISA CONSULTANT CO. LTD. comme mon agence de recrutement étudiant pour m’aider à présenter des demandes d’admission auprès de collèges et d’universités.',
            'authorize' => 'J’autorise l’Agence à :',
            'permissions' => [
                'Me conseiller sur les établissements d’enseignement, les programmes et les conditions d’admission.',
                'Préparer et soumettre en mon nom des demandes d’admission et les pièces justificatives.',
                'Communiquer avec les établissements d’enseignement au sujet de mes demandes et de mon inscription.',
                'Recevoir et communiquer les renseignements sur l’état de mes demandes, les décisions d’admission, les droits de scolarité et l’inscription.',
            ],
            'privacy' => 'J’autorise les établissements auprès desquels je présente une demande à communiquer à l’Agence les renseignements relatifs à mon admission. Je consens à la collecte, à l’utilisation et à la communication nécessaire de mes renseignements personnels et scolaires à ces fins, sous réserve des lois applicables en matière de protection des renseignements personnels.',
            'withdrawal' => 'Je comprends que l’admission et l’approbation d’un visa ne sont pas garanties. Cette autorisation est volontaire et peut être retirée par avis écrit à l’Agence, sous réserve des exigences légales applicables et des démarches déjà effectuées.',
            'signature_title' => '3. DÉCLARATION ET SIGNATURE DE L’ÉTUDIANT',
            'declaration' => 'Je confirme avoir lu et compris ce formulaire et j’accepte de recevoir l’aide de PARROT CANADA VISA CONSULTANT CO. LTD. pour le recrutement étudiant et les démarches d’admission.',
            'signature_name' => 'Nom complet de l’étudiant',
            'signature' => 'Signature de l’étudiant',
            'date_country' => 'Date et pays',
            'agency' => 'RÉSERVÉ À L’AGENCE',
            'representative' => 'Représentant autorisé',
            'agency_signature' => 'Signature et date',
            'accept' => 'J’accepte la déclaration et l’autorisation ci-dessus.',
            'clear' => 'Effacer la signature',
            'sign' => 'Signer et envoyer',
            'signed' => 'Formulaire signé.',
            'download' => 'Télécharger le PDF signé',
            'missing' => 'Remplissez tous les champs et acceptez la déclaration.',
            'invalid' => 'Vérifiez le courriel et la date de naissance.',
            'draw' => 'Tracez votre signature avant d’envoyer.',
        ];
    }

    return [
        'title' => 'STUDENT CONSENT & AUTHORIZATION FORM',
        'information' => '1. STUDENT INFORMATION',
        'labels' => [
            'student_name' => 'Full legal name',
            'date_of_birth' => 'Date of birth',
            'passport_id' => 'Passport / ID number',
            'nationality' => 'Nationality',
            'email' => 'Email address',
            'phone' => 'Phone / WhatsApp',
            'institution_program' => 'Preferred institution and program',
            'country' => 'Country',
        ],
        'consent' => '2. CONSENT AND AUTHORIZATION',
        'appointment' => 'I, the undersigned student, voluntarily appoint PARROT CANADA VISA CONSULTANT CO. LTD. as my education recruitment agency to assist with applications to colleges and universities.',
        'authorize' => 'I authorize the Agency to:',
        'permissions' => [
            'Advise me on educational institutions, programs and admission requirements.',
            'Prepare and submit admission applications and supporting documents on my behalf.',
            'Communicate with educational institutions about my applications and enrolment.',
            'Receive and share my admission status, decisions, tuition and enrolment information.',
        ],
        'privacy' => 'I authorize the institutions to which I apply to share my admission-related information with the Agency. I consent to the collection, use and necessary disclosure of my personal and academic information for these purposes, subject to applicable privacy laws.',
        'withdrawal' => 'I understand that admission and visa approval are not guaranteed. This authorization is voluntary and may be withdrawn by written notice to the Agency, subject to applicable legal requirements and processing already completed.',
        'signature_title' => '3. STUDENT DECLARATION AND SIGNATURE',
        'declaration' => 'I confirm that I have read and understood this form and agree to receive education recruitment and admission assistance from PARROT CANADA VISA CONSULTANT CO. LTD.',
        'signature_name' => 'Student full name',
        'signature' => 'Student signature',
        'date_country' => 'Date and country',
        'agency' => 'FOR AGENCY USE',
        'representative' => 'Authorized representative',
        'agency_signature' => 'Signature and date',
        'accept' => 'I agree to the declaration and authorization above.',
        'clear' => 'Clear signature',
        'sign' => 'Sign and submit',
        'signed' => 'Form signed.',
        'download' => 'Download signed PDF',
        'missing' => 'Complete all fields and accept the declaration.',
        'invalid' => 'Check the email address and date of birth.',
        'draw' => 'Draw your signature before submitting.',
    ];
}
