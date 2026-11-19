<?php
declare(strict_types=1);

require_once __DIR__ . '/irs_agreement_schema.php';

function irs_e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function irs_show(?string $value, string $blank = '____________________'): string
{
    $value = trim((string) $value);
    return $value !== '' ? irs_e($value) : irs_e($blank);
}

function irs_date(?string $value): string
{
    $value = trim((string) $value);
    if ($value === '') {
        return '____________________';
    }
    $ts = strtotime($value);
    return $ts ? date('F j, Y', $ts) : $value;
}

function irs_fill(string $name, string $value, bool $fillable, string $type = 'text', bool $required = false, string $placeholder = ''): string
{
    if (!$fillable) {
        return $type === 'date' ? irs_e(irs_date($value)) : irs_show($value);
    }
    $req = $required ? ' required' : '';
    return '<input class="irs-in" type="' . irs_e($type) . '" name="' . irs_e($name) . '" id="' . irs_e($name) . '" value="' . irs_e($value) . '" placeholder="' . irs_e($placeholder) . '"' . $req . '>';
}

/**
 * Full agreement HTML. $signed contains the saved client fields and optional signature image src.
 *
 * @param array<string, mixed> $signed
 */
function irs_agreement_document_html(array $signed, bool $forPdf = false, bool $fillable = false): string
{
    $c = irs_agreement_company();
    $ref = irs_show($signed['agreement_reference'] ?? '', '__________________');
    $effectiveRaw = trim((string) ($signed['effective_date'] ?? ''));
    $signedRaw = trim((string) ($signed['signed_date'] ?? ''));
    $nameRaw = trim((string) ($signed['client_name'] ?? ''));
    $effective = $fillable
        ? irs_fill('effective_date', $effectiveRaw, true, 'date', true)
        : irs_e(irs_date($effectiveRaw));
    $name = irs_fill('client_name', $nameRaw, $fillable, 'text', true, 'Full legal name');
    $dob = irs_fill('client_dob', trim((string) ($signed['client_dob'] ?? '')), $fillable, 'date', true);
    $nationality = irs_fill('client_nationality', trim((string) ($signed['client_nationality'] ?? '')), $fillable, 'text', true, 'Nationality');
    $address = irs_fill('client_address', trim((string) ($signed['client_address'] ?? '')), $fillable, 'text', true, 'Address and country');
    $email = irs_fill('client_email', trim((string) ($signed['client_email'] ?? '')), $fillable, 'email', true, 'Email');
    $phone = irs_fill('client_phone', trim((string) ($signed['client_phone'] ?? '')), $fillable, 'tel', true, 'Telephone');
    $passport = irs_fill('client_passport', trim((string) ($signed['client_passport'] ?? '')), $fillable, 'text', false, 'Passport, if known');
    $appType = irs_fill('application_type', trim((string) ($signed['application_type'] ?? '')), $fillable, 'text', true, 'Application type and country');
    $uci = irs_fill('uci_application_number', trim((string) ($signed['uci_application_number'] ?? '')), $fillable, 'text', false, 'UCI and application number, if known');
    $dates = irs_fill('submission_refusal_dates', trim((string) ($signed['submission_refusal_dates'] ?? '')), $fillable, 'text', false, 'Approximate submission and refusal dates');
    $travel = irs_fill('travel_history_period', trim((string) ($signed['travel_history_period'] ?? '')), $fillable, 'text', false, 'Travel history period, if requested');
    $signedDate = $fillable
        ? irs_fill('signed_date', $signedRaw !== '' ? $signedRaw : $effectiveRaw, true, 'date', true)
        : irs_e(irs_date($signedRaw !== '' ? $signedRaw : $effectiveRaw));
    $signName = $fillable ? '<span id="irsMirrorName" class="irs-mirror">' . ($nameRaw !== '' ? irs_e($nameRaw) : '—') . '</span>' : $name;
    $signClientDate = $fillable ? $signedDate : irs_e(irs_date($signedRaw !== '' ? $signedRaw : $effectiveRaw));
    $signParrotDate = $fillable ? '<span id="irsMirrorParrotDate" class="irs-mirror">' . irs_e(irs_date($effectiveRaw)) . '</span>' : $effective;
    $clientSig = trim((string) ($signed['client_signature_src'] ?? ''));
    $parrotSig = trim((string) ($signed['parrot_signature_src'] ?? ''));
    $stamp = trim((string) ($signed['company_stamp_src'] ?? ''));

    $clientImg = $clientSig !== '' ? '<img src="' . irs_e($clientSig) . '" alt="Client signature" style="height:54px;max-width:220px;">' : '';
    $parrotImg = $parrotSig !== '' ? '<img src="' . irs_e($parrotSig) . '" alt="Parrot signature" style="height:42px;max-width:200px;">' : '';
    $stampImg = $stamp !== '' ? '<img src="' . irs_e($stamp) . '" alt="Company stamp" style="height:78px;max-width:78px;">' : '';
    $font = $forPdf ? ' style="font-family:DejaVu Sans,sans-serif;font-size:11.5px;line-height:1.45;color:#111;"' : '';
    $h1 = $forPdf ? ' style="text-align:center;font-size:16px;margin:8px 0 12px;"' : '';

    $html = '<div class="irs-doc"' . $font . '>';
    $html .= '<p class="irs-brand"' . ($forPdf ? ' style="text-align:center;font-weight:700;letter-spacing:.04em;margin:0;"' : '') . '>PARROT CANADA VISA CONSULTANT CO. LTD</p>';
    $html .= '<h1' . $h1 . '>Immigration Application Records Recovery and Review Support Agreement</h1>';
    $html .= '<p><strong>Agreement reference:</strong> ' . $ref . ' &nbsp; <strong>Effective date:</strong> ' . $effective . '</p>';
    $html .= '<p>This agreement appoints Parrot, a BorderPass Verified Agent and partner, to coordinate assistance through a Canadian immigration lawyer engaged through BorderPass to seek official immigration records and explain records obtained. It covers withheld records, missing application details and suspected false or altered refusal letters. The total client price is <strong>' . irs_e($c['price']) . '</strong>, including Parrot services, partner legal services, government fees and applicable taxes for the scope below.</p>';

    $html .= '<h2>1. Parties and contact information</h2>';
    $html .= '<p><strong>Service coordinator:</strong> ' . irs_e($c['name']) . ' (Parrot).<br>';
    $html .= '<strong>Registered address:</strong> ' . irs_e($c['address']) . '<br>';
    $html .= '<strong>Company registration number:</strong> ' . irs_e($c['registration']) . '<br>';
    $html .= '<strong>Authorized signatory:</strong> ' . irs_e($c['signatory']) . ', ' . irs_e($c['title']) . '<br>';
    $html .= '<strong>Email:</strong> ' . irs_e($c['email']) . '<br>';
    $html .= '<strong>WhatsApp:</strong> ' . irs_e($c['whatsapp']) . '</p>';
    $html .= '<p><strong>Client full legal name:</strong> ' . $name . '<br>';
    $html .= '<strong>Date of birth:</strong> ' . $dob . ' &nbsp; <strong>Nationality:</strong> ' . $nationality . '<br>';
    $html .= '<strong>Address and country:</strong> ' . $address . '<br>';
    $html .= '<strong>Current email:</strong> ' . $email . ' &nbsp; <strong>Telephone:</strong> ' . $phone . '<br>';
    $html .= '<strong>Passport number used for the application, if known:</strong> ' . $passport . '</p>';

    $sections = [
        '2. Professional responsibilities' => [
            'Parrot provides intake, document organization, referral, scheduling and administrative coordination within the agreed scope. Canadian immigration advice, legal interpretation and representation are provided only by the identified lawyer under the lawyer\'s accepted retainer. Parrot does not hold itself out under this agreement as a law firm or as the Client\'s Canadian legal representative.',
            'Parrot holds a BorderPass Verified Agent Certificate bearing a 2026 verification seal, awarded to Parrot Canada Visa Consultant Company Ltd. and signed by J. Sherman, Vice President of Sales and Partnerships. The certificate evidences collaboration with BorderPass; it is not a licence for Parrot to practise law. Before legal work starts, BorderPass must confirm the assigned lawyer, responsible legal practice, law society, membership number, contact details and accepted retainer. This agreement does not bind BorderPass or a lawyer before acceptance.',
            'The Client authorizes Parrot to arrange intake through BorderPass and transmit the necessary documents. Legal work is subject to the lawyer\'s acceptance, conflict checks and required consents. The Client may verify the lawyer\'s professional standing and communicate directly with the lawyer. Any replacement lawyer requires the Client\'s written acceptance. If the engagement cannot be arranged, clause 11 applies.',
        ],
        '3. Records and assistance within the service' => [
            'For the application identified in the service particulars, Parrot will coordinate with the lawyer to seek the complete available IRCC application record and relevant Canada Border Services Agency (CBSA) records. The following are included where held and lawfully releasable:',
            'Official decision and refusal letters, decision dates, recorded reasons for refusal, and available evidence of when and where decisions or correspondence were sent.',
            'Application identifiers, including the UCI and application number; recorded application type, submission date, processing history and status.',
            'Copies of application forms, supporting documents and explanations submitted in the Client\'s name, including available passport, financial, education, employment and travel information.',
            'Officer assessments, case history, case entries and decision records, including recorded reasons for refusal and available information about eligibility, admissibility, biometrics, medical examinations and processing steps.',
            'Correspondence, requests for further documents, procedural fairness letters, responses and recorded representative or contact information associated with the application.',
            'Available fee payment records and receipts, and electronic CBSA records concerning the application, checks and security screening, subject to lawful exemptions and redactions.',
            'Available CBSA travel history showing entries and exits is included when relevant and requested by the Client. These records may help review application status, refusal reasons, information submitted in the Client\'s name, and travel dates relevant to residence or physical presence. They do not guarantee a complete day count or establish eligibility for citizenship or a permanent resident card. A travel history report is distinct from security screening records and is not automatically required for those applications.',
            'The lawyer may compare official records with documents supplied by the Client and explain material discrepancies within the accepted retainer. A suspected false or altered letter will be described as unverified unless reliable evidence establishes its status. This service does not automatically constitute forensic authentication or a finding that a particular person committed fraud.',
        ],
        '4. Work process and deliverables' => [
            'Parrot will collect intake details, identify gaps, arrange the lawyer referral and keep the Client informed. The lawyer will determine the appropriate lawful request process and obtain required authority. Where a request is submitted, the Client will receive the submission acknowledgement or reference when available, material requests for clarification, and the records released, subject to legal restrictions.',
            'The final delivery consists of records or official responses, an explanation of material refusal reasons and discrepancies through the lawyer, and a list of missing or outstanding items. The fixed price includes intake, the necessary IRCC and CBSA requests within this scope, routine clarification and follow-up through final responses, organization and secure delivery of records, and the lawyer\'s written explanation or consultation. Parrot will forward material updates and records it receives within five business days unless legally restricted. Client records will not be withheld solely over a fee dispute, subject to applicable law.',
        ],
        '5. Services requiring a separate written agreement' => [
            'A new visa application, reconsideration, appeal, judicial review, formal complaint, police report, recovery of money from a former agent, forensic examination, translation, litigation and representation beyond the agreed records request are excluded unless separately accepted in writing with scope and fees. No contact with an alleged wrongdoer or complaint submission is authorized by this agreement alone.',
        ],
        '6. Missing identifiers and limits on recovery' => [
            'A forgotten UCI, missing application number or unavailable original email does not establish that no application exists. The Client must provide the best available alternative identifying details, including names used, birth details, passport details, approximate dates, application type, country of submission and copies of any correspondence. The request will explain information that is unknown; neither Parrot nor the lawyer will invent identifiers or certify unverified facts.',
            'If a file cannot be located, the parties will review the official response and identifying details. An unsuccessful search does not conclusively prove that no application was submitted. Routine clarification and follow-up to the agreed requests remain included in CAD 650. Unrelated applications or materially expanded work require a separate written agreement. The service cannot retrieve records never held by the authority, private messages or bank records held only by a former agent, or information exempt from disclosure.',
            'No promise is made that every record will be released, that a document will be proven false, that a former agent will cooperate, or that a visa, reopening, refund, compensation or favourable decision will result. Parrot and the lawyer do not control government decisions or processing times. Any estimate is an estimate only; official extensions and delays may occur.',
            'A records request does not extend immigration, court, appeal or complaint deadlines and does not restore immigration status. The Client must promptly disclose any deadline or urgent status issue to the lawyer and obtain separate advice where needed.',
        ],
        '7. Client cooperation and accuracy' => [
            'The Client will provide truthful information, complete requested consents, supply legible copies, disclose all known applications and refusals relevant to the requested records, and update current contact details. The Client should preserve original documents and messages without editing or deleting them, and identify copies believed to be false or altered. Unknown details must be marked unknown.',
            'The Client will not request impersonation, unauthorized account access, fabricated evidence, alteration of official records, or concealment of a refusal. Parrot may pause an unlawful instruction and refer the issue to the lawyer. Suspension or termination must follow clause 11 and applicable law.',
        ],
        '8. Consent and authority' => [
            'By signing, the Client consents to necessary information exchange among Parrot, BorderPass and the assigned lawyer for this service. Separate government consent forms and identity evidence must also be completed, including IMM 5744 for IRCC where required and the appropriate CBSA authorization, such as BSF745 for a travel history request. This contract does not replace those forms or authorize another person\'s records without the required consent.',
            'Any appointment to represent the Client in an immigration application requires the appropriate separate authorization. This agreement does not itself cancel a previous representative, transfer an online account, or authorize use of the Client\'s password. Consent to future disclosures may be withdrawn in writing, subject to lawful retention and steps already taken; the parties will explain any effect on pending work.',
        ],
        '9. Confidentiality and handling of personal information' => [
            'Parrot will use Client information only for this engagement, billing, required compliance and authorized communications. Necessary information may be shared with BorderPass, the assigned lawyer and relevant government authorities. Staff and providers must observe appropriate confidentiality obligations. Information may be transmitted to Canada and the Client\'s country through secure service systems. No marketing use or public disclosure of the case is authorized.',
            'The Client selects the delivery channel in the service particulars. Sensitive records should use secure email or a protected link. Messaging services are for scheduling and updates unless the Client expressly chooses them for documents after considering their privacy risks. Parrot will verify material changes to delivery details before releasing records and notify affected parties of a security incident as required by law.',
            'Parrot is not a law firm. Communications with Parrot are not automatically protected by solicitor-client privilege. The lawyer will explain confidentiality and privilege arrangements. Records are retained only as long as reasonably necessary for this service, legal obligations and lawful claims, then securely deleted or anonymized. The Client may request access, correction or deletion subject to lawful exceptions. No retention period overrides a mandatory professional or statutory rule.',
        ],
        '10. Fees and payment' => [
            'The Client\'s fixed total price is CAD 650, inclusive of Parrot coordination, BorderPass partner service charges and the assigned lawyer\'s professional work for this scope, all government request fees, routine administrative expenses and applicable taxes. No separate fee is payable by the Client to Parrot, BorderPass, the lawyer or government for this agreed service. Parrot will arrange settlement with the partner and bear any difference between its internal costs and the agreed price. Internal allocations do not increase the Client\'s total.',
            'Payment is due in one instalment after the lawyer accepts the engagement and before requests are submitted, to Parrot against an official invoice. The Client will receive a receipt. A different payment schedule requires written agreement without changing the CAD 650 total. The lawyer\'s retainer must reflect that fees for this scope are covered by this package, with no duplicate client charge, subject to professional trust and billing rules. Work outside this scope needs a separate signed agreement; no extra charge is authorized for completing the included service.',
        ],
        '11. Cancellation, refunds and suspension' => [
            'The Client may cancel in writing. If no lawyer accepts the engagement, Parrot will refund all amounts paid for this package. After work starts, only reasonable, evidenced charges for work actually completed and nonrecoverable government fees actually paid may be deducted, without double counting and never above CAD 650. Parrot will arrange any partner reconciliation and return the unearned balance to the Client. No blanket nonrefundable deposit applies; mandatory rights and lawyer professional rules remain effective.',
            'Parrot will provide an itemized account and pay any undisputed refund due from Parrot within 15 business days after termination or confirmation of an overpayment. A government delay or limited release does not alone make properly completed work refundable. Parrot may suspend work for missing consent, nonpayment or unlawful instructions after written reasons and a reasonable opportunity to remedy, unless immediate action is required by law. On termination, it will explain outstanding steps and transfer available Client records securely.',
        ],
        '12. Accountability, disputes and governing terms' => [
            'Each party remains responsible for its own acts and omissions. Parrot is responsible for its agreed coordination obligations; the lawyer is responsible for professional services under the accepted retainer. Nothing excludes responsibility that cannot lawfully be excluded, including fraud, wilful misconduct, negligence, unlawful disclosure or mandatory consumer rights. No blanket release for a partner\'s conduct is created.',
            'Concerns should first be sent to infos@visaconsultantcanada.com for a written response within 15 business days. This does not restrict urgent court relief or regulator complaints or pause deadlines. This agreement is governed by the laws mandatorily applicable to the Parrot-client transaction, with disputes heard by a court having lawful jurisdiction. No exclusive forum is imposed. The lawyer\'s accepted retainer governs professional services subject to mandatory law. Written acceptance is required for changes; lawful electronic signatures and counterparts are permitted.',
        ],
    ];

    foreach ($sections as $title => $paragraphs) {
        $html .= '<h2>' . irs_e($title) . '</h2>';
        foreach ($paragraphs as $paragraph) {
            $html .= '<p>' . irs_e($paragraph) . '</p>';
        }
    }

    $html .= '<h2>13. Service particulars and agreed charges</h2>';
    $html .= '<p><strong>Application type and country of submission:</strong> ' . $appType . '<br>';
    $html .= '<strong>UCI and application number, if known:</strong> ' . $uci . '<br>';
    $html .= '<strong>Approximate submission and refusal dates:</strong> ' . $dates . '<br>';
    $html .= '<strong>Included scope:</strong> the identified application; IRCC records, relevant CBSA records and requested available travel history; routine follow-up, delivery and lawyer explanation.<br>';
    $html .= '<strong>Travel history period, if requested:</strong> ' . $travel . '</p>';
    $html .= '<p>Partner intake provider: BorderPass, through Parrot\'s verified agent partnership. Assigned lawyer, legal practice, law society, licence number and professional contact: to be confirmed in writing by BorderPass before legal work begins. Retainer reference and acceptance date: recorded in the Client\'s accepted BorderPass engagement confirmation, which must be supplied to the Client before submission.</p>';
    $html .= '<table style="width:100%;border-collapse:collapse;margin:8px 0 12px;"><thead><tr>'
        . '<th style="border:1px solid #999;padding:6px;text-align:left;">Charge</th>'
        . '<th style="border:1px solid #999;padding:6px;text-align:left;">Amount and currency</th>'
        . '<th style="border:1px solid #999;padding:6px;text-align:left;">Due date and payee</th></tr></thead><tbody>'
        . '<tr><td style="border:1px solid #999;padding:6px;">Parrot coordination</td><td style="border:1px solid #999;padding:6px;">Included</td><td style="border:1px solid #999;padding:6px;">No separate charge</td></tr>'
        . '<tr><td style="border:1px solid #999;padding:6px;">BorderPass and lawyer services</td><td style="border:1px solid #999;padding:6px;">Included</td><td style="border:1px solid #999;padding:6px;">Settled by Parrot</td></tr>'
        . '<tr><td style="border:1px solid #999;padding:6px;">Government fees and routine expenses</td><td style="border:1px solid #999;padding:6px;">Included</td><td style="border:1px solid #999;padding:6px;">Settled within package</td></tr>'
        . '<tr><td style="border:1px solid #999;padding:6px;">Applicable taxes</td><td style="border:1px solid #999;padding:6px;">Included</td><td style="border:1px solid #999;padding:6px;">Within fixed total</td></tr>'
        . '<tr><td style="border:1px solid #999;padding:6px;"><strong>Total client payment</strong></td><td style="border:1px solid #999;padding:6px;"><strong>CAD 650</strong></td><td style="border:1px solid #999;padding:6px;">To Parrot before submission</td></tr>'
        . '</tbody></table>';
    $html .= '<p>Payment schedule: one instalment of CAD 650 after lawyer acceptance and before submission. Any cancellation deduction must be itemized under clause 11. Delivery: secure email or protected link to the Client\'s current email on page 1. Complaint contact: infos@visaconsultantcanada.com. Governing terms and forum: clause 12; no mandatory rights are waived.</p>';

    $html .= '<h2>14. Acceptance and signatures</h2>';
    $html .= '<p>The Client and Parrot accept the scope, fixed CAD 650 total, consent and refund terms. The Client receives this agreement and the partner engagement confirmation. Legal work begins only after the lawyer accepts the retainer. The lawyer\'s identity and licence must be confirmed, not inferred from Parrot\'s partnership certificate.</p>';
    $clientSignSlot = $fillable ? '<div id="irsSignSlot"></div>' : $clientImg;
    $signTable = $forPdf ? ' style="width:100%;border-collapse:collapse;margin-top:12px;"' : '';
    $signCell = $forPdf ? ' style="width:50%;vertical-align:top;border:1px solid #ccc;padding:10px;"' : '';
    $html .= '<table class="irs-sign"' . $signTable . '><tr>'
        . '<td' . $signCell . '>'
        . '<strong>Client</strong><br>Name: ' . $signName . '<br>Signature:<br>' . $clientSignSlot . '<br>Date: ' . $signClientDate
        . '</td><td' . $signCell . '>'
        . '<strong>For Parrot</strong><br>Name and title: ' . irs_e($c['signatory']) . ', ' . irs_e($c['title']) . '<br>Signature:<br>' . $parrotImg . ' ' . $stampImg . '<br>Date: ' . $signParrotDate
        . '</td></tr></table>';
    $html .= '</div>';

    return $html;
}
