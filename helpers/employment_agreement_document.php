<?php
declare(strict_types=1);

require_once __DIR__ . '/employment_agreement_schema.php';

function ea_h(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function ea_raw(array $data, string $key): string
{
    return trim((string) ($data[$key] ?? ''));
}

function ea_fill(array $data, string $key, string $mode, string $kind = 'text', bool $required = false): string
{
    $value = ea_raw($data, $key);
    if ($mode === 'pdf') {
        return $value !== '' ? ea_h($value) : '________________________________';
    }

    $autocomplete = [
        'client_name' => 'name',
        'email' => 'email',
        'phone' => 'tel',
        'date_of_birth' => 'bday',
        'residential_address' => 'street-address',
        'nationality' => 'country-name',
    ];
    $auto = isset($autocomplete[$key]) ? ' autocomplete="' . $autocomplete[$key] . '"' : ' autocomplete="off"';
    $req = $required ? ' required data-required="1"' : '';
    if ($kind === 'textarea') {
        return '<textarea class="ea-field" name="' . ea_h($key) . '" rows="2"' . $auto . $req . '>' . ea_h($value) . '</textarea>';
    }
    $type = $kind === 'email' ? 'email' : ($kind === 'date' ? 'date' : 'text');

    return '<input class="ea-field" type="' . $type . '" name="' . ea_h($key) . '" value="' . ea_h($value) . '"' . $auto . $req . '>';
}

function ea_flag_code(string $country): string
{
    $codes = [
        'Finland' => 'fi', 'Malta' => 'mt', 'Cyprus' => 'cy', 'Croatia' => 'hr', 'Greece' => 'gr',
        'Estonia' => 'ee', 'Luxembourg' => 'lu', 'Italy' => 'it', 'Slovenia' => 'si', 'Latvia' => 'lv',
        'Portugal' => 'pt', 'Slovakia' => 'sk', 'Belgium' => 'be', 'Bulgaria' => 'bg', 'Serbia' => 'rs',
        'Germany' => 'de', 'Denmark' => 'dk', 'Canada' => 'ca',
    ];

    return $codes[$country] ?? '';
}

function ea_countries_html(array $data, string $mode): string
{
    $selected = ea_raw($data, 'destination_country');
    $html = '<div class="country-grid" role="radiogroup" aria-label="Destination country">';
    foreach (employment_agreement_countries() as $country) {
        $mark = $selected === $country;
        $code = ea_flag_code($country);
        $flag = $code !== '' ? 'https://flagcdn.com/w80/' . $code . '.png' : '';
        if ($mode === 'pdf') {
            $flagImg = $flag !== '' ? '<img src="' . ea_h($flag) . '" alt="" width="22" height="16"> ' : '';
            $html .= '<div class="country-item">' . $flagImg . ($mark ? '[X] ' : '[ ] ') . ea_h($country) . '</div>';
            continue;
        }
        $checked = $mark ? ' checked' : '';
        $html .= '<label class="country-card">'
            . '<input type="radio" name="destination_country" value="' . ea_h($country) . '"' . $checked . '>'
            . '<img src="' . ea_h($flag) . '" alt="" width="48" height="32">'
            . '<span>' . ea_h($country) . '</span>'
            . '</label>';
    }
    $html .= '</div>';

    return $html;
}

function ea_acknowledgments_html(string $mode): string
{
    $html = '<ul class="ack-list">';
    foreach (employment_agreement_acknowledgments() as $index => $text) {
        if ($mode === 'pdf') {
            $html .= '<li>[X] ' . ea_h($text) . '</li>';
            continue;
        }
        $html .= '<li><label class="ack-item"><input type="checkbox" class="ack-box" name="ack_' . $index . '" value="1"> ' . ea_h($text) . '</label></li>';
    }
    $html .= '</ul>';

    return $html;
}

function employment_agreement_document_html(array $data, string $mode = 'pdf'): string
{
    $name = ea_fill($data, 'client_name', $mode, 'text', true);
    $nationality = ea_fill($data, 'nationality', $mode, 'text', true);
    $passport = ea_fill($data, 'passport_number', $mode, 'text', true);
    $dob = ea_fill($data, 'date_of_birth', $mode, 'date', true);
    $address = ea_fill($data, 'residential_address', $mode, 'textarea', true);
    $phone = ea_fill($data, 'phone', $mode, 'text', true);
    $email = ea_fill($data, 'email', $mode, 'email', true);
    $job = ea_fill($data, 'preferred_job', $mode, 'text', true);
    $city = ea_fill($data, 'preferred_city', $mode, 'text', true);
    $salary = ea_fill($data, 'expected_salary', $mode);
    $employer = ea_fill($data, 'employer_partner', $mode);
    $place = ea_fill($data, 'place_of_signing', $mode);
    $jurisdiction = ea_fill($data, 'contracting_jurisdiction', $mode);
    $date = ea_fill($data, 'agreement_date', $mode, 'date', true);
    $reference = ea_raw($data, 'reference_number');
    $referenceShown = $reference !== '' ? ea_h($reference) : '________________________________';
    $shownName = ea_raw($data, 'client_name');
    $authName = $mode === 'pdf'
        ? ($shownName !== '' ? ea_h($shownName) : '________________________________')
        : '<strong id="authClientName">' . ($shownName !== '' ? ea_h($shownName) : '________________') . '</strong>';
    $countries = ea_countries_html($data, $mode);
    $acks = ea_acknowledgments_html($mode);
    if ($mode === 'pdf') {
        $companyTitle = '<p class="doc-company">PARROT CANADA VISA CONSULTANT CO. LTD.</p>';
        $companyBlock = '<p><strong>Company name:</strong> Parrot Canada Visa Consultant Co. Ltd.<br>
<strong>Represented by:</strong> Dr. Jean Pierre Twajamahoro<br>
<strong>Position:</strong> Managing Director / Owner<br>
<strong>Email:</strong> infos@visaconsultantcanada.com<br>
<strong>Employment applications email:</strong> jobs@visaconsultantcanada.ca<br>
<strong>Website:</strong> www.visaconsultantcanada.com<br>
<strong>WhatsApp:</strong> +1 (431) 302-0226<br>
<strong>Kigali office:</strong> 141 KG 14 Avenue, Gisozi, Near ULK Campus, Kigali, Rwanda<br>
<strong>Musanze office:</strong> Rubanda House, Near Bus Station, 3rd Floor, NM 153 Street, Musanze City, Rwanda</p>';
    } else {
        $companyTitle = '<p class="doc-company"><span>Parrot Canada Visa Consultant</span><span>Co. Ltd.</span></p>';
        $companyBlock = '<dl class="company-facts">
<div><dt>Company name</dt><dd>Parrot Canada Visa Consultant Co. Ltd.</dd></div>
<div><dt>Represented by</dt><dd>Dr. Jean Pierre Twajamahoro</dd></div>
<div><dt>Position</dt><dd>Managing Director / Owner</dd></div>
<div><dt>Email</dt><dd>infos@visaconsultantcanada.com</dd></div>
<div><dt>Employment applications email</dt><dd>jobs@visaconsultantcanada.ca</dd></div>
<div><dt>Website</dt><dd>www.visaconsultantcanada.com</dd></div>
<div><dt>WhatsApp</dt><dd>+1 (431) 302-0226</dd></div>
<div><dt>Kigali office</dt><dd>141 KG 14 Avenue, Gisozi, Near ULK Campus, Kigali, Rwanda</dd></div>
<div><dt>Musanze office</dt><dd>Rubanda House, Near Bus Station, 3rd Floor, NM 153 Street, Musanze City, Rwanda</dd></div>
</dl>';
    }

    return <<<HTML
{$companyTitle}
<h1>International Employment Application and Service Agreement</h1>
<p class="doc-kicker">Between Parrot Canada Visa Consultant Co. Ltd. and the Job Seeker / Client</p>
<p class="field-line"><span>Agreement reference number</span><strong>{$referenceShown}</strong></p>
<p class="field-line"><span>Date</span>{$date}</p>

<h2>Article 1: Parties to the agreement</h2>
<h3>1.1 Service provider</h3>
{$companyBlock}
<p>Hereinafter referred to as &ldquo;PARROT&rdquo; or &ldquo;the Company.&rdquo;</p>
<h3>1.2 Job seeker / client</h3>
<div class="details-grid">
<p class="field-line wide"><span>Full legal name</span>{$name}</p>
<p class="field-line"><span>Nationality</span>{$nationality}</p>
<p class="field-line"><span>Passport number</span>{$passport}</p>
<p class="field-line"><span>Date of birth</span>{$dob}</p>
<p class="field-line"><span>Telephone / WhatsApp</span>{$phone}</p>
<p class="field-line wide"><span>Residential address</span>{$address}</p>
<p class="field-line wide"><span>Email address</span>{$email}</p>
</div>
<p>Hereinafter referred to as &ldquo;the Client.&rdquo;</p>

<h2>Article 2: Purpose of the agreement</h2>
<p>2.1. This Agreement establishes the terms and conditions under which PARROT provides international employment application assistance, job-search coordination, recruitment referral, document preparation support, and administrative follow-up.</p>
<p>2.2. PARROT may collaborate with employers, recruitment agencies, immigration professionals, and other authorized partners in the selected destination country.</p>
<p>2.3. The Client acknowledges that employment opportunities depend on employer requirements, candidate qualifications, applicable immigration laws, and government decisions.</p>
<p>2.4. PARROT does not guarantee employment, a work permit, visa approval, a particular salary, or an employment start date.</p>
<p>2.5. Any immigration advice or representation requiring professional authorization shall be provided through appropriately licensed or authorized professionals.</p>

<h2>Article 3: Selection of destination country</h2>
<p>Choose one destination. The selected card stays highlighted.</p>
{$countries}
<div class="details-grid">
<p class="field-line"><span>Preferred job position</span>{$job}</p>
<p class="field-line"><span>Preferred city / region</span>{$city}</p>
<p class="field-line"><span>Expected salary, if known</span>{$salary}</p>
<p class="field-line"><span>Employer / recruitment partner, if known</span>{$employer}</p>
</div>
<p>The Client understands that selecting a country does not guarantee the availability of employment or eligibility for a work permit.</p>

<h2>Article 4: Scope of services</h2>
<p>PARROT may provide the following services, subject to applicable law:</p>
<ul>
<li>Preliminary assessment of the Client&rsquo;s professional qualifications and employment eligibility.</li>
<li>Assistance with CV and résumé preparation.</li>
<li>Identification of suitable international employment opportunities.</li>
<li>Referral of the Client&rsquo;s profile to prospective employers or recruitment partners.</li>
<li>Coordination of interviews where applicable.</li>
<li>Assistance with employment application documents.</li>
<li>Administrative coordination of work permit documentation through authorized parties.</li>
<li>Follow-up with recruitment partners concerning application progress.</li>
<li>Communication of employer responses and application updates.</li>
<li>General pre-departure orientation following approval.</li>
</ul>
<p>The Company shall perform its obligations professionally and in accordance with applicable laws.</p>

<h2>Article 5: Payment conditions</h2>
<h3>5.1 General payment arrangements</h3>
<p>The Client acknowledges that certain administrative or professional services may involve charges where permitted by applicable law. Any lawful service charges must be explained and agreed upon separately in writing before payment. This Agreement does not specify any service fee amount, payment percentage, total price, or payment plan.</p>
<h3>5.2 Payment before starting the application</h3>
<p>Where permitted by applicable law, payment for agreed preliminary administrative or professional services may be required before those services begin. Any payment made before the application begins shall be non-refundable only to the extent permitted by applicable law and only for services actually performed or properly incurred non-recoverable expenses. The Company must disclose the relevant service and payment conditions before collecting any payment.</p>
<h3>5.3 Payment after work permit approval</h3>
<p>Where a lawful payment has been separately agreed upon, it may become due following genuine work permit approval by the competent immigration authority. Any payment made after work permit approval shall be non-refundable only to the extent permitted by applicable law and once the corresponding agreed services have been completed. A job offer, medical examination request, or preliminary employer decision shall not automatically constitute work permit approval.</p>
<h3>5.4 Prohibited recruitment fees</h3>
<p>No payment shall be requested from the Client where applicable recruitment, employment, immigration, or consumer protection laws prohibit charging the job seeker. Any fee that must legally be paid by an employer shall not be transferred to the Client.</p>
<h3>5.5 Payment receipts</h3>
<p>PARROT shall provide written receipts for any lawful payments collected. Each receipt shall identify the service, amount, currency, payment date, and recipient.</p>

<h2>Article 6: Refund and cancellation policy</h2>
<p><strong>6.1 Initial payments.</strong> Payments made before application commencement are intended to compensate the Company for lawful services agreed upon and provided. Such payments are non-refundable only to the extent legally permitted. The Client retains all mandatory refund rights provided by applicable law.</p>
<p><strong>6.2 Payments following work permit approval.</strong> Payments made after work permit approval are intended to compensate the Company for corresponding agreed services that have been completed. Such payments are non-refundable only to the extent legally permitted.</p>
<p><strong>6.3 Voluntary withdrawal.</strong> If the Client voluntarily withdraws after services have begun, PARROT may retain only amounts lawfully chargeable for services already performed and permitted non-recoverable expenses. Any refundable balance shall be returned in accordance with applicable law.</p>
<p><strong>6.4 Employer withdrawal or application refusal.</strong> If an employer withdraws a job offer, a vacancy becomes unavailable, or a work permit application is refused, the Company shall assess the Client&rsquo;s refund entitlement based on the services actually provided and the applicable legal requirements.</p>
<p><strong>6.5 Company failure to provide services.</strong> If PARROT fails to provide agreed services or materially breaches this Agreement, the Client may be entitled to a refund or other remedies in accordance with applicable law.</p>
<p><strong>6.6 Mandatory legal protections.</strong> No provision of this Agreement shall remove or restrict the Client&rsquo;s non-waivable rights under applicable employment, recruitment, immigration, or consumer protection laws.</p>

<h2>Article 7: Responsibilities of the client</h2>
<p>The Client agrees to:</p>
<ul>
<li>Provide complete, accurate, and truthful personal information.</li>
<li>Submit genuine and valid identification and employment documents.</li>
<li>Provide an updated CV or résumé.</li>
<li>Disclose relevant education, professional qualifications, and employment experience.</li>
<li>Attend scheduled employer interviews where reasonably possible.</li>
<li>Respond promptly to legitimate document requests.</li>
<li>Comply with immigration and employment requirements.</li>
<li>Inform PARROT of any change affecting eligibility.</li>
<li>Pay only lawful charges that have been disclosed and agreed upon.</li>
<li>Avoid submitting forged, altered, or misleading documents.</li>
</ul>

<h2>Article 8: Responsibilities of PARROT</h2>
<p>PARROT agrees to:</p>
<ul>
<li>Provide professional employment application assistance within its authorized scope.</li>
<li>Make reasonable efforts to verify recruitment partners and prospective employers.</li>
<li>Explain the application process and known eligibility requirements.</li>
<li>Assist with preparation and organization of relevant documents.</li>
<li>Coordinate communication with recruitment partners when appropriate.</li>
<li>Inform the Client of significant application updates.</li>
<li>Protect the Client&rsquo;s confidential information.</li>
<li>Provide receipts for lawful payments received.</li>
<li>Avoid misleading statements about job availability, salaries, processing periods, and immigration outcomes.</li>
<li>Respect applicable recruitment, immigration, employment, and consumer protection laws.</li>
</ul>

<h2>Article 9: Employment offer and work permit</h2>
<p>9.1. Employment offers are issued by the relevant employer, not automatically by PARROT.</p>
<p>9.2. A genuine employment offer should identify the employer, job title, workplace, salary, working hours, and employment conditions.</p>
<p>9.3. The Client has the right to review the proposed employment contract before accepting an offer.</p>
<p>9.4. Work permit and visa decisions are made by the competent government authorities.</p>
<p>9.5. PARROT shall not guarantee work permit approval or a particular immigration decision.</p>
<p>9.6. Any employment-related benefits, including accommodation, transportation, insurance, and meals, shall be governed by the employer&rsquo;s written contract.</p>

<h2>Article 10: Application processing period</h2>
<p>10.1. Processing timelines depend on employer availability, recruitment procedures, government requirements, and immigration authority decisions.</p>
<p>10.2. Any estimated processing period communicated to the Client is indicative and not guaranteed.</p>
<p>10.3. PARROT shall provide reasonable application updates when information becomes available.</p>
<p>10.4. Delays caused by employers, government authorities, or incomplete documentation shall be communicated to the Client where known.</p>

<h2>Article 11: Confidentiality and data protection</h2>
<p>11.1. PARROT shall collect and process only personal information reasonably necessary for the agreed services.</p>
<p>11.2. The Client&rsquo;s documents may be shared with identified employers, recruitment partners, or authorized professionals where necessary and legally permitted.</p>
<p>11.3. PARROT shall take reasonable measures to protect the Client&rsquo;s personal information.</p>
<p>11.4. The Client may request information about the handling of personal documents, subject to applicable law.</p>

<h2>Article 12: Termination of the agreement</h2>
<p>12.1. Either party may terminate this Agreement by providing written notice.</p>
<p>12.2. Upon termination, PARROT shall provide an account of any payments received and services performed.</p>
<p>12.3. Any refund entitlement shall be determined under Article 6 and applicable law.</p>
<p>12.4. Client documents shall be returned, retained, or securely disposed of in accordance with applicable legal requirements.</p>
<p>12.5. Termination shall not remove lawful obligations already incurred by either party.</p>

<h2>Article 13: Complaints and dispute resolution</h2>
<p>13.1. Both parties agree to attempt to resolve disagreements through written communication and good-faith discussions.</p>
<p>13.2. Complaints may be submitted to infos@visaconsultantcanada.com.</p>
<p>13.3. If an amicable resolution cannot be reached, either party may pursue available legal remedies.</p>
<p>13.4. Nothing in this Agreement prevents the Client from contacting an appropriate regulatory authority, consumer protection agency, employment standards authority, or court.</p>

<h2>Article 14: Governing law</h2>
<p>14.1. This Agreement shall be interpreted in accordance with applicable laws governing the services and the relevant contracting jurisdiction.</p>
<p>14.2. Mandatory laws of the recruitment country and selected destination country shall apply where legally required.</p>
<p>14.3. Any provision inconsistent with mandatory law shall be unenforceable to the extent of that inconsistency.</p>
<p class="field-line"><span>Place of signing</span>{$place}</p>
<p class="field-line"><span>Applicable contracting jurisdiction</span>{$jurisdiction}</p>

<h2>Article 15: Client declaration and acknowledgment</h2>
<p>By signing this Agreement, the Client, {$authName}, confirms that:</p>
{$acks}
HTML;
}
