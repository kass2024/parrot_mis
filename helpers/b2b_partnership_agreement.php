<?php
declare(strict_types=1);

/**
 * Body of the Strategic B2B Partnership Agreement (sections 2–15).
 * Section 1 (parties form) and section 16 (signature) stay in the signing pages.
 */
function pcvc_b2b_agreement_body(string $lang = 'en', ?array $partner = null, bool $live = false): string
{
    $lang = $lang === 'fr' ? 'fr' : 'en';
    $partner = is_array($partner) ? $partner : [];
    if (trim((string) ($partner['company_email'] ?? '')) === '' && trim((string) ($partner['representative_email'] ?? '')) !== '') {
        $partner['company_email'] = (string) $partner['representative_email'];
    }

    $labels = $lang === 'fr'
        ? [
            'company_name' => 'Nom de l\'entreprise',
            'representative_name' => 'Représentant',
            'representative_title' => 'Fonction',
            'company_email' => 'Courriel',
            'company_phone' => 'Téléphone',
            'company_address' => 'Adresse complète',
        ]
        : [
            'company_name' => 'Company Name',
            'representative_name' => 'Representative',
            'representative_title' => 'Position',
            'company_email' => 'Email',
            'company_phone' => 'Phone',
            'company_address' => 'Full Address',
        ];

    $partyLines = '';
    foreach ($labels as $key => $label) {
        $raw = trim((string) ($partner[$key] ?? ''));
        $shown = $raw !== '' ? htmlspecialchars($raw, ENT_QUOTES, 'UTF-8') : '—';
        if ($live) {
            $shown = '<span data-mirror="' . htmlspecialchars($key, ENT_QUOTES, 'UTF-8') . '">' . $shown . '</span>';
        }
        $partyLines .= '<p><strong>' . $label . ':</strong> ' . $shown . '</p>';
    }

    $feeHead = $lang === 'fr'
        ? ['Destination', 'Frais de service de demande']
        : ['Destination', 'Application service fee'];
    $feeRows = [
        ['Canada', '125 CAD'],
        [$lang === 'fr' ? 'États-Unis' : 'United States', '100 USD'],
        ['Europe', '100 EUR'],
        ['Asia', '100 USD'],
    ];
    $per = $lang === 'fr' ? ' par étudiant' : ' per student';
    $table = '<table style="width:100%;border-collapse:collapse;margin:8pt 0 14pt;">'
        . '<tr><th style="border:1px solid #d1d5db;padding:8px;text-align:left;background:#f3f4f6;">' . $feeHead[0] . '</th>'
        . '<th style="border:1px solid #d1d5db;padding:8px;text-align:left;background:#f3f4f6;">' . $feeHead[1] . '</th></tr>';
    foreach ($feeRows as $row) {
        $table .= '<tr><td style="border:1px solid #d1d5db;padding:8px;">' . $row[0] . '</td>'
            . '<td style="border:1px solid #d1d5db;padding:8px;">' . $row[1] . $per . '</td></tr>';
    }
    $table .= '</table>';

    if ($lang === 'fr') {
        return pcvc_b2b_agreement_french($partyLines, $table);
    }
    return pcvc_b2b_agreement_english($partyLines, $table);
}

function pcvc_b2b_agreement_english(string $partyLines, string $table): string
{
    return <<<HTML
<h2>2. PURPOSE OF THE AGREEMENT</h2>
<p>This agreement establishes the terms under which the parties cooperate to identify, refer and support eligible students and other clients seeking international education, Canadian immigration services and employment opportunities in Europe.</p>
<p>The partnership covers:</p>
<ul>
<li>Academic document evaluation and eligibility assessment.</li>
<li>University, institution and program selection.</li>
<li>Admission application preparation and follow-up.</li>
<li>Scholarship and student loan assistance, where available.</li>
<li>Coordination of visa and immigration application services.</li>
<li>Canadian immigration services through Parrot’s licensed Canadian immigration consultant.</li>
<li>Work opportunities and application support in Europe.</li>
<li>Travel preparation, airport pickup and initial settlement assistance, as agreed for each client.</li>
</ul>

<h2>3. SCOPE OF SERVICES</h2>
<h3>3.1 Student Recruitment and Counseling</h3>
<p>The parties shall cooperate in:</p>
<ul>
<li>Identifying and recruiting qualified students.</li>
<li>Providing information about available institutions and programs.</li>
<li>Reviewing academic qualifications against published admission requirements.</li>
<li>Helping students select programs consistent with their academic background and career objectives.</li>
</ul>

<h3>3.2 Document Evaluation and Admission Applications</h3>
<p>Services include:</p>
<ul>
<li>Reviewing academic records and supporting documents.</li>
<li>Assessing eligibility for selected programs.</li>
<li>Preparing and submitting applications.</li>
<li>Following up with institutions.</li>
<li>Supporting the acquisition of admission letters.</li>
<li>Assisting with scholarship and student loan applications, where applicable.</li>
</ul>
<p>The Partner shall submit student files through the Parrot Agent Portal and provide complete, authentic and accurate documents.</p>

<h3>3.3 Visa and Immigration Services</h3>
<p>Parrot shall coordinate visa and immigration application support appropriate to the selected service and destination.</p>
<p>Canadian immigration advice and representation are provided through Parrot’s licensed Canadian immigration consultant.</p>
<p>The Partner shall refer requests for Canadian immigration advice to that consultant and shall not present itself as authorized to provide regulated immigration advice solely because of this agreement.</p>

<h3>3.4 Travel and Pre-departure Support</h3>
<p>Services may include:</p>
<ul>
<li>Travel planning and flight assistance.</li>
<li>Pre-departure orientation.</li>
<li>Information about arrival requirements.</li>
<li>Coordination of agreed arrival arrangements.</li>
</ul>

<h3>3.5 Airport Pickup and Initial Settlement</h3>
<p>Where included in the client’s confirmed service package, Parrot shall arrange or coordinate:</p>
<ul>
<li>Airport pickup.</li>
<li>Initial accommodation assistance.</li>
<li>Arrival orientation.</li>
<li>Initial settlement support.</li>
<li>Communication with local service partners.</li>
</ul>
<p>The destination, availability, scope and any associated charges must be confirmed before travel.</p>

<h3>3.6 Additional Services</h3>
<p>This agreement also covers:</p>
<ul>
<li>Express Entry applications, including eligible spouses and dependent children.</li>
<li>Spousal open work permit applications.</li>
<li>Spousal sponsorship for permanent residence.</li>
<li>Provincial Nominee Program (PNP) applications, including eligible family members.</li>
<li>Canadian work permit applications through the Francophone Mobility Program.</li>
<li>Work opportunities and application support in Europe.</li>
</ul>
<p>Canadian immigration advice and representation for these services are provided through Parrot’s licensed Canadian immigration consultant.</p>
<p>European employment support shall follow the requirements applicable to the destination country and the particular opportunity. The job description, employer information, application requirements and service scope must be communicated to the client before the client commits to the service.</p>

<h2>4. PARTNERSHIP PRINCIPLES</h2>
<p>The parties agree to:</p>
<ul>
<li>Provide accurate and transparent information.</li>
<li>Refer clients only for services suited to their qualifications and circumstances.</li>
<li>Communicate clearly about fees, responsibilities and deadlines.</li>
<li>Protect client information.</li>
<li>Maintain appropriate application and payment records.</li>
<li>Avoid misleading statements about admissions, scholarships, employment or immigration outcomes.</li>
</ul>
<p>Admission, scholarship, loan, employment and immigration decisions remain with the relevant institutions, providers, employers and government authorities. Neither party shall guarantee decisions made by those third parties.</p>

<h2>5. ROLES AND RESPONSIBILITIES</h2>
<h3>5.1 Responsibilities of the Partner</h3>
<p>The Partner shall:</p>
<ul>
<li>Recruit and refer eligible students and clients.</li>
<li>Check students’ academic scores against the minimum requirements of their selected institutions and programs.</li>
<li>Avoid submitting applications with scores below the required minimum unless an institution has confirmed an applicable alternative admission route.</li>
<li>Collect and review the required supporting documents.</li>
<li>Take reasonable steps to verify document authenticity and accuracy.</li>
<li>Ensure that no fake, forged, altered or misleading documents are submitted.</li>
<li>Obtain the client’s authorization to share documents for the requested services.</li>
<li>Submit complete student files through the Parrot Agent Portal.</li>
<li>Ensure that the applicable application service fees are paid immediately after portal submission.</li>
<li>Communicate fees and service conditions accurately to clients.</li>
<li>Respond promptly to requests for information and additional documents.</li>
<li>Keep clients informed of application progress and deadlines.</li>
<li>Refer regulated Canadian immigration questions to Parrot’s licensed Canadian immigration consultant.</li>
<li>Use only current, approved information when promoting Parrot’s services.</li>
</ul>

<h3>5.2 Responsibilities of Parrot</h3>
<p>Parrot shall:</p>
<ul>
<li>Review referred files and assess service eligibility.</li>
<li>Assist with institution and program selection.</li>
<li>Prepare, process and follow up on admission applications.</li>
<li>Support scholarship and student loan applications, where available.</li>
<li>Coordinate Canadian immigration services through its licensed Canadian immigration consultant.</li>
<li>Coordinate European employment application support within the agreed service scope.</li>
<li>Communicate material updates and requests for additional documents.</li>
<li>Provide the agreed travel, arrival and settlement support.</li>
<li>Maintain appropriate records of services, payments and refunds.</li>
<li>Apply the refund provisions of this agreement and any separately confirmed service terms.</li>
<li>Display applicable additional-service fees on its official website and Parrot MIS.</li>
</ul>

<h2>6. FINANCIAL ARRANGEMENTS</h2>
<h3>6.1 Each Party’s Service Fees</h3>
<p>Each party may charge its own clearly disclosed service fees in accordance with applicable requirements and the client’s written agreement.</p>
<p>Neither party may collect money on behalf of the other without written authorization. Any authorized collection must be supported by a receipt and an accurate payment record.</p>

<h3>6.2 Student Admission Application Service Fees</h3>
<p>The following application service fees are payable to Parrot for student admission applications submitted through the Parrot Agent Portal:</p>
{$table}
<p>These amounts cover Parrot’s admission application service. Separate institution application fees, tuition deposits and other third-party charges must be disclosed where applicable.</p>

<h3>6.3 Payment Timing</h3>
<p>Payment must be made immediately after submitting the student’s application through the Parrot Agent Portal, according to the agreed terms.</p>
<p>The Partner shall provide payment proof and the relevant student or application reference to enable payment tracking.</p>

<h3>6.4 Admission Application Service Fee Refunds</h3>
<p>The application service fees listed in Section 6.2 are refundable if Parrot fails to obtain an admission letter for a student who:</p>
<ul>
<li>Meets the selected institution’s minimum academic scores and admission requirements.</li>
<li>Provides complete, authentic and accurate documents.</li>
<li>Complies with the applicable application requirements and deadlines.</li>
</ul>
<p>The Partner must verify eligibility before submission and avoid submitting documents with academic scores below the required minimum or documents that are fake, forged, altered or misleading.</p>
<p>Applications that do not satisfy the conditions above do not qualify for a refund under this provision.</p>
<p>The refund procedure, required evidence and payment timeline shall be confirmed in writing. Separate institution and third-party charges remain subject to the relevant provider’s refund policy.</p>

<h3>6.5 Additional Services and Applicable Fees</h3>
<p>The applicable fees for the services listed in Section 3.6 will be displayed on Parrot’s official website and Parrot MIS.</p>
<p>Before services begin, the following must be confirmed in writing:</p>
<ul>
<li>The selected service and scope of work.</li>
<li>The total service fees and currency.</li>
<li>Any separate government, institution, employer-related or third-party charges.</li>
<li>The payment schedule and payment milestones.</li>
<li>The applicable refund and cancellation conditions.</li>
<li>The responsibilities of the client and service provider.</li>
</ul>
<p>Any subsequent price changes will not alter fees already agreed upon in writing.</p>
<p>The admission fee refund provision in Section 6.4 applies specifically to admission application services. Additional services are governed by their separately confirmed written refund conditions.</p>

<h3>6.6 Partner Commissions</h3>
<p>Any commission or referral payment payable to the Partner must be agreed separately in writing, including the amount, payment trigger and payment timeline.</p>
<p>The admission application service fees listed in Section 6.2 are fees payable to Parrot and do not constitute commissions payable to the Partner.</p>

<h3>6.7 Financial Transparency</h3>
<p>Both parties commit to financial transparency and traceability.</p>
<p>Payments, authorized collections, commissions and refunds must be supported by appropriate records. Neither party may impose undisclosed charges on clients.</p>

<h2>7. APPLICATION MANAGEMENT AND COMMUNICATION</h2>
<p>The parties shall designate representatives to coordinate their work.</p>
<p>The Parrot Agent Portal and Parrot MIS, as applicable, shall support file submission, progress monitoring, service information and payment tracking.</p>
<p>Both parties shall:</p>
<ul>
<li>Keep application information accurate and current.</li>
<li>Communicate requests for additional documents promptly.</li>
<li>Monitor relevant deadlines.</li>
<li>Record material client instructions and agreed changes.</li>
<li>Notify each other promptly of circumstances affecting an application.</li>
<li>Confirm changes to service scope or financial terms in writing.</li>
</ul>

<h2>8. CONFIDENTIALITY AND CLIENT INFORMATION</h2>
<p>The parties shall keep client, business, financial and application information confidential.</p>
<p>Information may be shared only:</p>
<ul>
<li>As necessary to provide the authorized services.</li>
<li>With the client’s appropriate consent or authorization.</li>
<li>With institutions, authorized professionals, employers or service providers involved in the application.</li>
<li>Where required by applicable law.</li>
</ul>
<p>Both parties shall take reasonable steps to prevent unauthorized access, disclosure or misuse of client documents and portal credentials.</p>
<p>Confidentiality obligations continue after this agreement ends.</p>

<h2>9. COMPLIANCE, DOCUMENT AUTHENTICITY AND ETHICS</h2>
<p>Both parties shall:</p>
<ul>
<li>Comply with applicable laws and professional requirements.</li>
<li>Provide honest and accurate information.</li>
<li>Reject fraud and document falsification.</li>
<li>Avoid submitting information they know to be false or misleading.</li>
<li>Inform the other party promptly when a material document discrepancy is identified.</li>
<li>Refer regulated services to appropriately authorized professionals.</li>
</ul>
<p>Parrot may suspend the processing of a file where document authenticity or material accuracy is reasonably in question while clarification is sought.</p>

<h2>10. INDEPENDENT STATUS AND AUTHORITY</h2>
<p>The parties remain independent businesses.</p>
<p>Neither party may sign contracts, make guarantees, incur obligations or accept liabilities on behalf of the other without written authorization.</p>
<p>This agreement does not itself authorize the Partner to provide regulated Canadian immigration advice or representation.</p>

<h2>11. DURATION AND TERMINATION</h2>
<p>This agreement becomes effective on the date of the last signature and remains valid for one year, unless terminated earlier or renewed in writing.</p>
<p>Either party may terminate the agreement by giving 30 days’ written notice.</p>
<p>During the notice period, the parties shall coordinate the completion or orderly transfer of ongoing files and keep affected clients informed.</p>
<p>Termination does not cancel obligations already arising concerning:</p>
<ul>
<li>Outstanding payments.</li>
<li>Eligible refunds.</li>
<li>Agreed commissions.</li>
<li>Confidentiality.</li>
<li>Client records and ongoing service commitments.</li>
</ul>

<h2>12. DISPUTE RESOLUTION</h2>
<p>The parties shall first attempt to resolve disputes amicably through direct discussion and written communication.</p>
<p>If no resolution is reached, the parties may refer the dispute to mediation or arbitration under procedures and a location agreed in writing.</p>

<h2>13. FORCE MAJEURE</h2>
<p>Neither party shall be responsible for a failure or delay caused by circumstances beyond its reasonable control.</p>
<p>The affected party shall notify the other party promptly, explain the effect on ongoing services and take reasonable steps to reduce disruption.</p>
<p>Any resulting cancellation or refund shall be addressed under the applicable written service terms.</p>

<h2>14. ENTIRE AGREEMENT AND AMENDMENTS</h2>
<p>This agreement, together with any written service schedules or commission arrangements expressly accepted by the parties, constitutes their entire understanding concerning the partnership.</p>
<p>Amendments must be confirmed in writing by authorized representatives of both parties.</p>
<p>Changes to published prices shall not alter fees already agreed upon in writing.</p>

<h2>15. CONTACT INFORMATION AND NOTICES</h2>
<p><strong>Partner Company</strong></p>
{$partyLines}
<p><strong>Parrot Canada Visa Consultant Co. Ltd</strong><br>
Dr Jean Pierre Twajamahoro<br>
Owner &amp; Managing Director<br>
Email: infos@visaconsultantcanada.ca<br>
Phone: +1 (438) 290-6688</p>
<p><strong>Rwanda Address:</strong><br>
Town Center Building, near Simba Supermarket<br>
2nd Floor, Door F2B-022C, Nyarugenge<br>
Kigali, Rwanda</p>
<p><strong>Canada Address:</strong><br>
294 Rue Vezina, App 202<br>
Lasalle, Quebec H8R 3M9, Canada</p>
<p>Formal notices shall be sent to the email addresses above. Each party shall notify the other in writing of any change to its contact details.</p>
HTML;
}

function pcvc_b2b_agreement_french(string $partyLines, string $table): string
{
    return <<<HTML
<h2>2. OBJET DE L’ACCORD</h2>
<p>Le présent accord fixe les conditions selon lesquelles les parties coopèrent pour identifier, orienter et accompagner les étudiants admissibles et les autres clients qui recherchent une formation internationale, des services d’immigration canadienne et des possibilités d’emploi en Europe.</p>
<p>Le partenariat couvre :</p>
<ul>
<li>L’évaluation des documents académiques et de l’admissibilité.</li>
<li>La sélection des universités, établissements et programmes.</li>
<li>La préparation et le suivi des demandes d’admission.</li>
<li>L’aide aux bourses et aux prêts étudiants, lorsqu’ils sont disponibles.</li>
<li>La coordination des services de demande de visa et d’immigration.</li>
<li>Les services d’immigration canadienne par l’intermédiaire du consultant en immigration canadienne agréé de Parrot.</li>
<li>Les possibilités de travail et le soutien aux candidatures en Europe.</li>
<li>La préparation du voyage, l’accueil à l’aéroport et l’aide initiale à l’installation, selon ce qui est convenu pour chaque client.</li>
</ul>

<h2>3. ÉTENDUE DES SERVICES</h2>
<h3>3.1 Recrutement et conseil aux étudiants</h3>
<p>Les parties coopèrent pour :</p>
<ul>
<li>Identifier et recruter des étudiants qualifiés.</li>
<li>Fournir des informations sur les établissements et programmes disponibles.</li>
<li>Examiner les qualifications académiques au regard des conditions d’admission publiées.</li>
<li>Aider les étudiants à choisir des programmes conformes à leur parcours académique et à leurs objectifs de carrière.</li>
</ul>

<h3>3.2 Évaluation des documents et demandes d’admission</h3>
<p>Les services comprennent :</p>
<ul>
<li>L’examen des dossiers académiques et des pièces justificatives.</li>
<li>L’évaluation de l’admissibilité aux programmes choisis.</li>
<li>La préparation et la soumission des candidatures.</li>
<li>Le suivi auprès des établissements.</li>
<li>Le soutien à l’obtention des lettres d’admission.</li>
<li>L’aide aux demandes de bourse et de prêt étudiant, le cas échéant.</li>
</ul>
<p>Le Partenaire soumet les dossiers des étudiants par le portail agent Parrot et fournit des documents complets, authentiques et exacts.</p>

<h3>3.3 Services de visa et d’immigration</h3>
<p>Parrot coordonne le soutien aux demandes de visa et d’immigration adapté au service et à la destination choisis.</p>
<p>Les conseils et la représentation en immigration canadienne sont fournis par l’intermédiaire du consultant en immigration canadienne agréé de Parrot.</p>
<p>Le Partenaire oriente vers ce consultant les demandes de conseil en immigration canadienne et ne se présente pas comme autorisé à fournir des conseils réglementés en immigration du seul fait du présent accord.</p>

<h3>3.4 Voyage et soutien avant le départ</h3>
<p>Les services peuvent comprendre :</p>
<ul>
<li>La planification du voyage et l’aide aux vols.</li>
<li>L’orientation avant le départ.</li>
<li>L’information sur les exigences à l’arrivée.</li>
<li>La coordination des dispositions d’arrivée convenues.</li>
</ul>

<h3>3.5 Accueil à l’aéroport et installation initiale</h3>
<p>Lorsque ces services sont inclus dans le forfait confirmé du client, Parrot organise ou coordonne :</p>
<ul>
<li>L’accueil à l’aéroport.</li>
<li>L’aide au logement initial.</li>
<li>L’orientation à l’arrivée.</li>
<li>Le soutien initial à l’installation.</li>
<li>La communication avec les partenaires de services locaux.</li>
</ul>
<p>La destination, la disponibilité, l’étendue et les frais associés éventuels doivent être confirmés avant le voyage.</p>

<h3>3.6 Services additionnels</h3>
<p>Le présent accord couvre également :</p>
<ul>
<li>Les demandes Entrée express, y compris le conjoint admissible et les enfants à charge.</li>
<li>Les demandes de permis de travail ouvert pour conjoint.</li>
<li>Le parrainage du conjoint aux fins de résidence permanente.</li>
<li>Les demandes au Programme des candidats des provinces (PCP), y compris les membres de la famille admissibles.</li>
<li>Les demandes de permis de travail canadien dans le cadre du Programme de mobilité francophone.</li>
<li>Les possibilités de travail et le soutien aux candidatures en Europe.</li>
</ul>
<p>Les conseils et la représentation en immigration canadienne pour ces services sont fournis par l’intermédiaire du consultant en immigration canadienne agréé de Parrot.</p>
<p>Le soutien à l’emploi en Europe suit les exigences applicables au pays de destination et à l’opportunité concernée. La description du poste, les informations sur l’employeur, les exigences de candidature et l’étendue du service doivent être communiquées au client avant qu’il ne s’engage.</p>

<h2>4. PRINCIPES DU PARTENARIAT</h2>
<p>Les parties conviennent de :</p>
<ul>
<li>Fournir une information exacte et transparente.</li>
<li>Orienter les clients uniquement vers des services adaptés à leurs qualifications et à leur situation.</li>
<li>Communiquer clairement sur les frais, les responsabilités et les échéances.</li>
<li>Protéger les renseignements des clients.</li>
<li>Tenir des dossiers appropriés de demandes et de paiements.</li>
<li>Éviter toute déclaration trompeuse sur les admissions, les bourses, l’emploi ou les résultats d’immigration.</li>
</ul>
<p>Les décisions d’admission, de bourse, de prêt, d’emploi et d’immigration appartiennent aux établissements, prestataires, employeurs et autorités compétents. Aucune partie ne garantit les décisions de ces tiers.</p>

<h2>5. RÔLES ET RESPONSABILITÉS</h2>
<h3>5.1 Responsabilités du Partenaire</h3>
<p>Le Partenaire doit :</p>
<ul>
<li>Recruter et orienter des étudiants et clients admissibles.</li>
<li>Vérifier les résultats académiques des étudiants par rapport aux exigences minimales des établissements et programmes choisis.</li>
<li>Éviter de soumettre des candidatures dont les résultats sont inférieurs au minimum requis, sauf si un établissement a confirmé une voie d’admission alternative applicable.</li>
<li>Recueillir et examiner les documents justificatifs requis.</li>
<li>Prendre des mesures raisonnables pour vérifier l’authenticité et l’exactitude des documents.</li>
<li>Veiller à ce qu’aucun document faux, falsifié, altéré ou trompeur ne soit soumis.</li>
<li>Obtenir l’autorisation du client pour le partage des documents nécessaires aux services demandés.</li>
<li>Soumettre des dossiers étudiants complets par le portail agent Parrot.</li>
<li>Veiller à ce que les frais de service de demande applicables soient payés immédiatement après la soumission sur le portail.</li>
<li>Communiquer exactement aux clients les frais et les conditions de service.</li>
<li>Répondre rapidement aux demandes d’information et de documents supplémentaires.</li>
<li>Tenir les clients informés de l’avancement des dossiers et des échéances.</li>
<li>Orienter les questions réglementées d’immigration canadienne vers le consultant en immigration canadienne agréé de Parrot.</li>
<li>Utiliser uniquement des informations à jour et approuvées pour promouvoir les services de Parrot.</li>
</ul>

<h3>5.2 Responsabilités de Parrot</h3>
<p>Parrot doit :</p>
<ul>
<li>Examiner les dossiers orientés et évaluer l’admissibilité au service.</li>
<li>Aider à la sélection des établissements et des programmes.</li>
<li>Préparer, traiter et suivre les demandes d’admission.</li>
<li>Soutenir les demandes de bourse et de prêt étudiant, lorsqu’ils sont disponibles.</li>
<li>Coordonner les services d’immigration canadienne par son consultant en immigration canadienne agréé.</li>
<li>Coordonner le soutien aux candidatures d’emploi en Europe dans l’étendue de service convenue.</li>
<li>Communiquer les mises à jour importantes et les demandes de documents supplémentaires.</li>
<li>Fournir le soutien convenu pour le voyage, l’arrivée et l’installation.</li>
<li>Tenir des dossiers appropriés des services, des paiements et des remboursements.</li>
<li>Appliquer les dispositions de remboursement du présent accord et toute condition de service confirmée séparément.</li>
<li>Afficher les frais des services additionnels applicables sur son site officiel et sur Parrot MIS.</li>
</ul>

<h2>6. DISPOSITIONS FINANCIÈRES</h2>
<h3>6.1 Frais de service de chaque partie</h3>
<p>Chaque partie peut facturer ses propres frais de service, clairement indiqués, conformément aux exigences applicables et à l’accord écrit du client.</p>
<p>Aucune partie ne peut encaisser des fonds pour le compte de l’autre sans autorisation écrite. Tout encaissement autorisé doit être appuyé par un reçu et un enregistrement exact du paiement.</p>

<h3>6.2 Frais de service de demande d’admission</h3>
<p>Les frais de service de demande suivants sont payables à Parrot pour les demandes d’admission d’étudiants soumises par le portail agent Parrot :</p>
{$table}
<p>Ces montants couvrent le service de demande d’admission de Parrot. Les frais de demande de l’établissement, les acomptes de scolarité et les autres frais de tiers doivent être indiqués lorsqu’ils s’appliquent.</p>

<h3>6.3 Moment du paiement</h3>
<p>Le paiement doit être effectué immédiatement après la soumission de la demande de l’étudiant par le portail agent Parrot, selon les modalités convenues.</p>
<p>Le Partenaire fournit la preuve de paiement et la référence de l’étudiant ou de la demande afin de permettre le suivi du paiement.</p>

<h3>6.4 Remboursement des frais de service de demande d’admission</h3>
<p>Les frais de service de demande indiqués à la section 6.2 sont remboursables si Parrot n’obtient pas de lettre d’admission pour un étudiant qui :</p>
<ul>
<li>Satisfait aux résultats académiques minimaux et aux conditions d’admission de l’établissement choisi.</li>
<li>Fournit des documents complets, authentiques et exacts.</li>
<li>Respecte les exigences et les échéances de demande applicables.</li>
</ul>
<p>Le Partenaire doit vérifier l’admissibilité avant la soumission et éviter de soumettre des documents dont les résultats académiques sont inférieurs au minimum requis, ou des documents faux, falsifiés, altérés ou trompeurs.</p>
<p>Les demandes qui ne remplissent pas les conditions ci-dessus n’ouvrent pas droit à un remboursement au titre de cette disposition.</p>
<p>La procédure de remboursement, les preuves requises et le délai de paiement sont confirmés par écrit. Les frais d’établissement et de tiers restent soumis à la politique de remboursement du prestataire concerné.</p>

<h3>6.5 Services additionnels et frais applicables</h3>
<p>Les frais applicables aux services énumérés à la section 3.6 sont affichés sur le site officiel de Parrot et sur Parrot MIS.</p>
<p>Avant le début des services, les éléments suivants doivent être confirmés par écrit :</p>
<ul>
<li>Le service choisi et l’étendue du travail.</li>
<li>Le total des frais de service et la devise.</li>
<li>Les frais distincts de gouvernement, d’établissement, d’employeur ou de tiers.</li>
<li>L’échéancier et les étapes de paiement.</li>
<li>Les conditions de remboursement et d’annulation applicables.</li>
<li>Les responsabilités du client et du prestataire.</li>
</ul>
<p>Toute modification ultérieure des prix n’altère pas les frais déjà convenus par écrit.</p>
<p>La disposition de remboursement de la section 6.4 s’applique spécifiquement aux services de demande d’admission. Les services additionnels sont régis par leurs conditions écrites de remboursement confirmées séparément.</p>

<h3>6.6 Commissions du Partenaire</h3>
<p>Toute commission ou rémunération d’orientation payable au Partenaire doit être convenue séparément par écrit, y compris le montant, le fait générateur et le délai de paiement.</p>
<p>Les frais de service de demande d’admission indiqués à la section 6.2 sont des frais payables à Parrot et ne constituent pas des commissions payables au Partenaire.</p>

<h3>6.7 Transparence financière</h3>
<p>Les deux parties s’engagent à la transparence et à la traçabilité financières.</p>
<p>Les paiements, encaissements autorisés, commissions et remboursements doivent être appuyés par des dossiers appropriés. Aucune partie ne peut imposer aux clients des frais non divulgués.</p>

<h2>7. GESTION DES DEMANDES ET COMMUNICATION</h2>
<p>Les parties désignent des représentants pour coordonner leur travail.</p>
<p>Le portail agent Parrot et Parrot MIS, selon le cas, servent à la soumission des dossiers, au suivi de l’avancement, à l’information sur les services et au suivi des paiements.</p>
<p>Les deux parties doivent :</p>
<ul>
<li>Maintenir des informations de demande exactes et à jour.</li>
<li>Communiquer rapidement les demandes de documents supplémentaires.</li>
<li>Surveiller les échéances pertinentes.</li>
<li>Enregistrer les instructions importantes du client et les changements convenus.</li>
<li>S’informer rapidement de toute circonstance qui affecte une demande.</li>
<li>Confirmer par écrit les changements d’étendue de service ou de conditions financières.</li>
</ul>

<h2>8. CONFIDENTIALITÉ ET RENSEIGNEMENTS DES CLIENTS</h2>
<p>Les parties gardent confidentiels les renseignements des clients ainsi que les informations commerciales, financières et de demande.</p>
<p>L’information ne peut être partagée que :</p>
<ul>
<li>Dans la mesure nécessaire à la fourniture des services autorisés.</li>
<li>Avec le consentement ou l’autorisation appropriée du client.</li>
<li>Avec les établissements, professionnels autorisés, employeurs ou prestataires concernés par la demande.</li>
<li>Lorsque la loi applicable l’exige.</li>
</ul>
<p>Les deux parties prennent des mesures raisonnables pour empêcher l’accès, la divulgation ou l’usage non autorisé des documents des clients et des identifiants du portail.</p>
<p>Les obligations de confidentialité se poursuivent après la fin du présent accord.</p>

<h2>9. CONFORMITÉ, AUTHENTICITÉ DES DOCUMENTS ET ÉTHIQUE</h2>
<p>Les deux parties doivent :</p>
<ul>
<li>Respecter les lois et exigences professionnelles applicables.</li>
<li>Fournir une information honnête et exacte.</li>
<li>Rejeter la fraude et la falsification de documents.</li>
<li>Éviter de soumettre une information qu’elles savent fausse ou trompeuse.</li>
<li>Informer rapidement l’autre partie lorsqu’un écart important dans un document est constaté.</li>
<li>Orienter les services réglementés vers des professionnels dûment autorisés.</li>
</ul>
<p>Parrot peut suspendre le traitement d’un dossier lorsque l’authenticité ou l’exactitude importante d’un document est raisonnablement mise en doute, le temps d’obtenir des éclaircissements.</p>

<h2>10. STATUT INDÉPENDANT ET POUVOIR</h2>
<p>Les parties demeurent des entreprises indépendantes.</p>
<p>Aucune partie ne peut signer de contrats, donner de garanties, contracter d’obligations ou accepter des responsabilités au nom de l’autre sans autorisation écrite.</p>
<p>Le présent accord n’autorise pas, en lui-même, le Partenaire à fournir des conseils ou une représentation réglementés en immigration canadienne.</p>

<h2>11. DURÉE ET RÉSILIATION</h2>
<p>Le présent accord prend effet à la date de la dernière signature et demeure valide un an, sauf résiliation anticipée ou renouvellement écrit.</p>
<p>Chaque partie peut résilier l’accord moyennant un préavis écrit de 30 jours.</p>
<p>Pendant le préavis, les parties coordonnent l’achèvement ou le transfert ordonné des dossiers en cours et tiennent les clients concernés informés.</p>
<p>La résiliation n’annule pas les obligations déjà nées concernant :</p>
<ul>
<li>Les paiements en souffrance.</li>
<li>Les remboursements admissibles.</li>
<li>Les commissions convenues.</li>
<li>La confidentialité.</li>
<li>Les dossiers des clients et les engagements de service en cours.</li>
</ul>

<h2>12. RÈGLEMENT DES DIFFÉRENDS</h2>
<p>Les parties tentent d’abord de régler les différends à l’amiable par discussion directe et communication écrite.</p>
<p>À défaut de règlement, les parties peuvent soumettre le différend à la médiation ou à l’arbitrage selon des procédures et un lieu convenus par écrit.</p>

<h2>13. FORCE MAJEURE</h2>
<p>Aucune partie n’est responsable d’un manquement ou d’un retard causé par des circonstances indépendantes de sa volonté raisonnable.</p>
<p>La partie affectée en informe rapidement l’autre, explique l’effet sur les services en cours et prend des mesures raisonnables pour réduire la perturbation.</p>
<p>Toute annulation ou tout remboursement qui en résulte est traité selon les conditions de service écrites applicables.</p>

<h2>14. INTÉGRALITÉ DE L’ACCORD ET MODIFICATIONS</h2>
<p>Le présent accord, ainsi que les annexes de service ou les arrangements de commission acceptés expressément par écrit par les parties, constitue l’intégralité de leur entente concernant le partenariat.</p>
<p>Les modifications doivent être confirmées par écrit par les représentants autorisés des deux parties.</p>
<p>Les changements de prix publiés n’altèrent pas les frais déjà convenus par écrit.</p>

<h2>15. COORDONNÉES ET AVIS</h2>
<p><strong>Entreprise partenaire</strong></p>
{$partyLines}
<p><strong>Parrot Canada Visa Consultant Co. Ltd</strong><br>
Dr Jean Pierre Twajamahoro<br>
Propriétaire et directeur général<br>
Courriel : infos@visaconsultantcanada.ca<br>
Téléphone : +1 (438) 290-6688</p>
<p><strong>Adresse au Rwanda :</strong><br>
Town Center Building, near Simba Supermarket<br>
2nd Floor, Door F2B-022C, Nyarugenge<br>
Kigali, Rwanda</p>
<p><strong>Adresse au Canada :</strong><br>
294 Rue Vezina, App 202<br>
Lasalle, Quebec H8R 3M9, Canada</p>
<p>Les avis formels sont envoyés aux adresses courriel ci-dessus. Chaque partie informe l’autre par écrit de tout changement de ses coordonnées.</p>
HTML;
}
