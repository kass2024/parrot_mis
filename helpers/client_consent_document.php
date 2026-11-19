<?php
declare(strict_types=1);

function cc_h(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function cc_raw(array $data, string $key): string
{
    return trim((string) ($data[$key] ?? ''));
}

function cc_fill(array $data, string $key, string $mode, string $kind = 'text', bool $required = false): string
{
    $value = cc_raw($data, $key);
    if ($mode === 'pdf') {
        return $value !== '' ? cc_h($value) : '________________________________';
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
        return '<textarea class="cc-field" name="' . cc_h($key) . '" rows="2"' . $auto . $req . '>' . cc_h($value) . '</textarea>';
    }

    $type = $kind === 'email' ? 'email' : ($kind === 'date' ? 'date' : 'text');

    return '<input class="cc-field" type="' . $type . '" name="' . cc_h($key) . '" value="' . cc_h($value) . '"' . $auto . $req . '>';
}

function client_consent_document_html(array $data, string $mode = 'pdf'): string
{
    $name = cc_fill($data, 'client_name', $mode, 'text', true);
    $passport = cc_fill($data, 'passport_number', $mode, 'text', true);
    $nationality = cc_fill($data, 'nationality', $mode, 'text', true);
    $dob = cc_fill($data, 'date_of_birth', $mode, 'date', true);
    $address = cc_fill($data, 'residential_address', $mode, 'textarea', true);
    $phone = cc_fill($data, 'phone', $mode, 'text', true);
    $email = cc_fill($data, 'email', $mode, 'email', true);
    $service = cc_fill($data, 'service_type', $mode, 'text', true);
    $agreementDate = cc_fill($data, 'agreement_date', $mode, 'date', true);
    $agreementDateRepeat = $mode === 'pdf'
        ? $agreementDate
        : '<span class="repeat-note">Same as the date of agreement above</span>';
    $initials = cc_fill($data, 'client_initials', $mode, 'text', true);
    $law = cc_fill($data, 'governing_law', $mode);
    $forum = cc_fill($data, 'dispute_jurisdiction', $mode);
    $clientNameText = cc_raw($data, 'client_name');
    $authorizationName = $mode === 'pdf'
        ? ($clientNameText !== '' ? cc_h($clientNameText) : '________________________________')
        : '<strong id="authClientName">' . ($clientNameText !== '' ? cc_h($clientNameText) : '________________') . '</strong>';

    return <<<HTML
<p class="doc-company">PARROT CANADA VISA CONSULTANT CO. LTD.</p>
<h1>Client Consent, Referral, and File-Transfer Agreement</h1>
<p class="doc-kicker">Transfer to a Regulated Canadian Immigration Consultant</p>
<p>This Client Consent, Referral, and File-Transfer Agreement (&ldquo;Agreement&rdquo;) is entered into between:</p>
<p><strong>Parrot Canada Visa Consultant Co. Ltd.</strong>, an education consulting and administrative-support company (&ldquo;Parrot Canada&rdquo;); and the person identified below (&ldquo;the Client&rdquo;).</p>

<h2>1. Parrot Canada Visa Consultant Co. Ltd. details</h2>
<p><strong>Company name:</strong> Parrot Canada Visa Consultant Co. Ltd.<br>
<strong>Managing Director/Owner:</strong> Dr. Jean Pierre Twajamahoro<br>
<strong>ICEF Accredited Agency Number:</strong> 6971<br>
<strong>Rwanda head office:</strong> 141 KG 14 Avenue, Gisozi, near ULK Campus, Kigali, Rwanda<br>
<strong>Musanze office:</strong> Rubanda House, near the Bus Station, Third Floor, NM 153 Street, Musanze, Rwanda<br>
<strong>Canada office:</strong> 294 Rue Vézina, Apartment 202, LaSalle, Quebec H8R 3M9, Canada<br>
<strong>General email:</strong> infos@visaconsultantcanada.com<br>
<strong>Employment email:</strong> jobs@visaconsultantcanada.ca<br>
<strong>Telephone/WhatsApp:</strong> +1 (431) 302-0226<br>
<strong>Website:</strong> https://visaconsultantcanada.com</p>

<h2>2. Client details</h2>
<p class="field-line"><span>Client&rsquo;s full legal name</span>{$name}</p>
<p class="field-line"><span>Passport/ID number</span>{$passport}</p>
<p class="field-line"><span>Nationality</span>{$nationality}</p>
<p class="field-line"><span>Date of birth</span>{$dob}</p>
<p class="field-line"><span>Residential address</span>{$address}</p>
<p class="field-line"><span>Telephone/WhatsApp</span>{$phone}</p>
<p class="field-line"><span>Email address</span>{$email}</p>
<p class="field-line"><span>Type of service requested</span>{$service}</p>
<p class="field-line"><span>Date of agreement</span>{$agreementDate}</p>

<h2>3. Regulated Canadian Immigration Consultant</h2>
<p>The Client&rsquo;s file will be transferred to a Regulated Canadian Immigration Consultant (RCIC) duly licensed by the College of Immigration and Citizenship Consultants.</p>
<p><strong>Name:</strong> Ms. Heena Kishanlal Verma<br>
<strong>RCIC License Number:</strong> R707012<br>
<strong>Regulatory body:</strong> College of Immigration and Citizenship Consultants (CICC)<br>
<strong>Office name:</strong> Sharkish Immigration Inc.<br>
<strong>Office location:</strong> 605, 2250 Bovaird Drive E, Brampton, ON, L6R 0W3, Canada</p>
<p>The transfer is for regulated Canadian immigration services that Parrot Canada Visa Consultant Co. Ltd. does not provide.</p>
<p>The RCIC&rsquo;s professional responsibilities, contact information, and separate retainer agreement shall be provided directly to the Client before regulated Canadian immigration services begin.</p>

<h2>4. Purpose of this Agreement</h2>
<p>The purpose of this Agreement is to:</p>
<ul>
<li>Clearly explain the limited role of Parrot Canada Visa Consultant Co. Ltd.;</li>
<li>Inform the Client that Parrot Canada Visa Consultant Co. Ltd. is an education consultant and administrative-support company only;</li>
<li>Obtain the Client&rsquo;s written consent before transferring the Client&rsquo;s information and documents;</li>
<li>Transfer the Client to the RCIC holding License Number R707012, whose office is located in Brampton, Ontario, Canada;</li>
<li>Allow the RCIC to provide regulated Canadian immigration services that Parrot Canada Visa Consultant Co. Ltd. does not provide;</li>
<li>Protect the Client&rsquo;s right to know who will provide regulated Canadian immigration advice or representation; and</li>
<li>Confirm that no transfer will take place before the Client accepts and signs this Agreement.</li>
</ul>

<h2>5. Role of Parrot Canada Visa Consultant Co. Ltd.</h2>
<p>The Client understands that Parrot Canada Visa Consultant Co. Ltd. is an education consultant and administrative-support company only.</p>
<p>Parrot Canada Visa Consultant Co. Ltd. may provide the following non-regulated services:</p>
<ul>
<li>General client communication and intake;</li>
<li>General information about education, employment, and application processes;</li>
<li>Marketing available programs or opportunities;</li>
<li>Preliminary collection of the Client&rsquo;s information and documents;</li>
<li>Administrative review of documents for apparent completeness;</li>
<li>Identification of visibly missing documents;</li>
<li>General document organization;</li>
<li>Administrative follow-up;</li>
<li>Communication support between the Client and the licensed professional; and</li>
<li>Secure transfer of the Client&rsquo;s file after obtaining written consent.</li>
</ul>
<p>Parrot Canada Visa Consultant Co. Ltd. does not provide regulated Canadian immigration advice or legal representation.</p>
<p>Parrot Canada Visa Consultant Co. Ltd. will not:</p>
<ul>
<li>Make a final assessment of the Client&rsquo;s eligibility under Canadian immigration law;</li>
<li>Select an immigration program for the Client based on legal advice;</li>
<li>Provide legal opinions about Canadian immigration matters;</li>
<li>Prepare or submit an immigration application as the Client&rsquo;s authorized representative;</li>
<li>Communicate with Canadian immigration authorities as the Client&rsquo;s authorized representative; or</li>
<li>Represent itself as a Regulated Canadian Immigration Consultant.</li>
</ul>

<h2>6. Services to be provided by the RCIC</h2>
<p>The Client is being transferred to the RCIC holding License Number R707012, regulated by the College of Immigration and Citizenship Consultants (CICC) and located in Brampton, Ontario, Canada.</p>
<p>The transfer is for services that Parrot Canada Visa Consultant Co. Ltd. is not legally authorized to provide.</p>
<p>Subject to accepting the Client and signing any required separate retainer agreement, the RCIC may provide:</p>
<ul>
<li>A Professional Canadian immigration consultation;</li>
<li>A final assessment of the Client&rsquo;s immigration eligibility;</li>
<li>Advice concerning an appropriate Canadian immigration or work-permit program;</li>
<li>Review of the Client&rsquo;s immigration history and supporting evidence;</li>
<li>Identification of legal or procedural risks;</li>
<li>Preparation and review of immigration forms and supporting documents;</li>
<li>Submission of an application where formally authorized;</li>
<li>Communication with employers, educational institutions, or other relevant parties where required;</li>
<li>Communication with Canadian immigration authorities where authorized;</li>
<li>Responses to immigration requests or concerns; and</li>
<li>Other regulated Canadian immigration services included in the separate RCIC retainer agreement.</li>
</ul>

<h2>Additional services and applicable fees</h2>
<p>This agreement also covers the following services:</p>
<ul>
<li>Express Entry applications, including eligible spouses and dependent children.</li>
<li>Spousal open work permit applications.</li>
<li>Spousal sponsorship for permanent residence.</li>
<li>Provincial Nominee Program (PNP) applications, including eligible family members.</li>
<li>Canadian work permit applications through the Francophone Mobility Program.</li>
<li>Work opportunities and application support in Europe.</li>
</ul>
<p>Canadian immigration advice and representation are provided through our licensed Canadian immigration consultant.</p>
<p>The applicable fees for each service will be displayed on Parrot&rsquo;s official website and Parrot MIS. The selected service, total fees, payment schedule and refund conditions must be confirmed in writing before services begin. Any subsequent price changes will not alter fees already agreed upon in writing.</p>

<h2>7. Client&rsquo;s consent to transfer</h2>
<p>By signing this Agreement, the Client voluntarily authorizes Parrot Canada Visa Consultant Co. Ltd. to transfer the Client&rsquo;s information and documents to:</p>
<p><strong>Regulated Canadian Immigration Consultant (RCIC)</strong> Ms. Heena Verma<br>
<strong>RCIC License Number:</strong> R707012<br>
<strong>Regulatory body:</strong> College of Immigration and Citizenship Consultants (CICC)<br>
<strong>Office location:</strong> 605, 2250 Bovaird Drive E, Brampton, ON, L6R 0W3, Canada</p>
<p>The transferred information may include:</p>
<ul>
<li>Passport and identification documents;</li>
<li>Contact information;</li>
<li>Résumé or curriculum vitae;</li>
<li>Education records;</li>
<li>Employment records;</li>
<li>Language-test results;</li>
<li>Immigration forms;</li>
<li>Previous visa applications or refusal information;</li>
<li>Travel and immigration history;</li>
<li>Financial documents;</li>
<li>Police and medical documents, where applicable;</li>
<li>Family information; and</li>
<li>Other documents reasonably required to assess or process the Client&rsquo;s matter.</li>
</ul>
<p>Parrot Canada Visa Consultant Co. Ltd. shall transfer only information reasonably necessary for the requested services.</p>

<h2>8. Client acceptance before transfer</h2>
<p>The Client&rsquo;s file will be transferred only after the Client:</p>
<ul>
<li>Reads this Agreement;</li>
<li>Receives an explanation of the separate roles of Parrot Canada Visa Consultant Co. Ltd. and the RCIC;</li>
<li>Is informed that the RCIC holds License Number R707012;</li>
<li>Is informed that the RCIC is regulated by the College of Immigration and Citizenship Consultants;</li>
<li>Is informed that the RCIC&rsquo;s office is located in Brampton, Ontario, Canada;</li>
<li>Has an opportunity to ask questions;</li>
<li>Voluntarily accepts the transfer; and</li>
<li>Signs this Agreement.</li>
</ul>
<p>The Client is not required to accept the transfer and may decline before signing this Agreement.</p>

<h2>9. Separate RCIC retainer agreement</h2>
<p>This Agreement authorizes the referral and transfer of the Client&rsquo;s file. It does not, by itself, appoint the RCIC as the Client&rsquo;s authorized Canadian immigration representative.</p>
<p>Before regulated Canadian immigration services begin, the Client may be required to sign a separate retainer agreement with the RCIC holding License Number R707012, regulated by the College of Immigration and Citizenship Consultants, with an office located in Brampton, Ontario, Canada.</p>
<p>The separate retainer agreement will describe:</p>
<ul>
<li>The regulated services to be provided;</li>
<li>The responsibilities of the Client and the RCIC;</li>
<li>The terms of professional representation;</li>
<li>Communication and document requirements;</li>
<li>Applicable service conditions; and</li>
<li>Other information required under Canadian professional rules.</li>
</ul>
<p>The Client should carefully read and understand the separate retainer agreement before signing it.</p>

<h2>10. No financial terms</h2>
<p>This Agreement contains no service-fee, payment, commission, or refund provisions.</p>
<p>Its only purpose is to document:</p>
<ul>
<li>The Client&rsquo;s informed consent;</li>
<li>The Client&rsquo;s referral to the RCIC holding License Number R707012;</li>
<li>The Client&rsquo;s authorization to transfer information and documents; and</li>
<li>The division of responsibilities between Parrot Canada Visa Consultant Co. Ltd. and the RCIC.</li>
</ul>
<p>Any financial terms contained in another agreement are separate and must be reviewed and accepted independently by the Client.</p>

<h2>11. Client responsibilities</h2>
<p>The Client agrees to:</p>
<ul>
<li>Provide genuine, accurate, complete, consistent, and current information;</li>
<li>Submit genuine and valid documents;</li>
<li>Disclose all previous visa refusals, immigration violations, criminal matters, medical issues, and other relevant information;</li>
<li>Review all forms and documents before signing or submission;</li>
<li>Immediately report any error or change in circumstances;</li>
<li>Respond to reasonable requests for documents or clarification;</li>
<li>Communicate honestly with Parrot Canada Visa Consultant Co. Ltd. and the RCIC; and</li>
<li>Comply with all lawful requirements of the immigration process.</li>
</ul>

<h2>12. False or misleading information</h2>
<p>Parrot Canada Visa Consultant Co. Ltd. and the RCIC will not create, alter, use, or submit false, fraudulent, or misleading information or documents.</p>
<p>If any document or statement appears false, altered, inconsistent, or incomplete, the Client may be asked to provide clarification or replacement evidence.</p>
<p>The transfer or regulated services may be suspended if serious concerns about the authenticity or accuracy of the Client&rsquo;s information remain unresolved.</p>

<h2>13. Personal-information authorization</h2>
<p>The Client authorizes Parrot Canada Visa Consultant Co. Ltd. to:</p>
<ul>
<li>Collect the Client&rsquo;s personal information and documents;</li>
<li>Store and organize the information securely;</li>
<li>Review the information for administrative purposes;</li>
<li>Communicate with the Client about the transfer;</li>
<li>Transfer necessary information and documents to the RCIC holding License Number R707012; and</li>
<li>Communicate with the RCIC concerning the transferred file.</li>
</ul>
<p>The RCIC is regulated by the College of Immigration and Citizenship Consultants (CICC) and has an office in Brampton, Ontario, Canada.</p>
<p>The information shall be used only for legitimate administrative, referral, immigration-service, record-keeping, and legal-compliance purposes.</p>
<p>Parrot Canada Visa Consultant Co. Ltd. and the RCIC shall take reasonable measures to protect the Client&rsquo;s personal information.</p>

<h2>14. Withdrawal of consent</h2>
<p>Before the file is transferred to the RCIC holding License Number R707012, the Client may withdraw consent by notifying Parrot Canada Visa Consultant Co. Ltd. in writing.</p>
<p>After the file has been transferred, the Client may request that future sharing stop. However, information already lawfully transferred may be retained where required for legal, professional, regulatory, or record-keeping purposes.</p>
<p>Withdrawal of this consent does not automatically terminate a separate retainer agreement already signed between the Client and the RCIC.</p>

<h2>15. Communication</h2>
<p>The Client agrees to receive notices, document requests, transfer updates, and other communications through:</p>
<ul>
<li>Email;</li>
<li>Telephone;</li>
<li>SMS;</li>
<li>WhatsApp;</li>
<li>Parrot MIS; or</li>
<li>Another contact method approved by the Client.</li>
</ul>
<p>The Client must promptly inform Parrot Canada Visa Consultant Co. Ltd. and the RCIC of any change in contact information.</p>

<h2>16. No guarantee</h2>
<p>The Client understands that:</p>
<ul>
<li>Referral or transfer does not guarantee that the RCIC will accept the Client&rsquo;s matter;</li>
<li>Acceptance by the RCIC does not guarantee eligibility for a Canadian immigration program;</li>
<li>No employment opportunity, admission, work permit, study permit, visa, permanent residence, or entry into Canada is guaranteed;</li>
<li>Employers and educational institutions make their own independent decisions; and</li>
<li>Canadian immigration authorities make all final immigration and entry decisions.</li>
</ul>

<h2>17. Independent professional responsibilities</h2>
<p>Parrot Canada Visa Consultant Co. Ltd. and the RCIC have separate responsibilities.</p>
<p>Parrot Canada Visa Consultant Co. Ltd. is responsible for its education-consulting, administrative-support, communication, and referral activities.</p>
<p>The RCIC holding CICC Licence Number R707012, regulated by the College of Immigration and Citizenship Consultants, is independently responsible for any regulated Canadian immigration advice or representation provided under the RCIC&rsquo;s professional licence and separate retainer agreement.</p>

<h2>18. Confidentiality and record keeping</h2>
<p>Parrot Canada Visa Consultant Co. Ltd. and the RCIC may maintain accurate records relating to the Client&rsquo;s file.</p>
<p>Records shall be stored and handled in accordance with applicable privacy, professional, and record-keeping requirements.</p>
<p>The Client&rsquo;s personal information shall not be disclosed to unrelated third parties without authorization, except where disclosure is required by law.</p>

<h2>19. Fair resolution</h2>
<p>The Client and Parrot Canada Visa Consultant Co. Ltd. agree to communicate respectfully and attempt to resolve any concern through written communication and management review.</p>
<p>Any amendment to this Agreement must be made in writing and accepted by the Client and Parrot Canada Visa Consultant Co. Ltd.</p>

<h2>20. Governing law</h2>
<p>This Agreement shall be governed by the applicable laws of:</p>
<p class="field-line"><span>Governing law</span>{$law}</p>
<p>The courts or competent tribunals of the following jurisdiction may address unresolved disputes:</p>
<p class="field-line"><span>Jurisdiction</span>{$forum}</p>

<h2>21. Client acknowledgment</h2>
<p>By signing this Agreement, the Client confirms that the Client:</p>
<ul>
<li>Has read and understood the entire Agreement;</li>
<li>Understands that Parrot Canada Visa Consultant Co. Ltd. is an education consultant and administrative-support company only;</li>
<li>Understands that Parrot Canada Visa Consultant Co. Ltd. does not provide regulated Canadian immigration advice or representation;</li>
<li>Understands that the Client will be transferred to a Regulated Canadian Immigration Consultant;</li>
<li>Understands that the RCIC holds License Number R707012;</li>
<li>Understands that the RCIC is regulated by the College of Immigration and Citizenship Consultants (CICC);</li>
<li>Understands that the RCIC&rsquo;s office is located in Brampton, Ontario, Canada;</li>
<li>Understands that the Client is being transferred for services that Parrot Canada Visa Consultant Co. Ltd. does not provide;</li>
<li>Has received an explanation of the separate roles of Parrot Canada Visa Consultant Co. Ltd. and the RCIC;</li>
<li>Voluntarily accepts the referral and transfer;</li>
<li>Authorizes the transfer of the Client&rsquo;s necessary information and documents;</li>
<li>Understands that a separate retainer agreement with the RCIC may be required;</li>
<li>Understands that this consent Agreement contains no financial terms;</li>
<li>Confirms that all information and documents supplied are genuine and accurate;</li>
<li>Understands that no employment, admission, immigration, visa, permit, permanent-residence, or entry outcome is guaranteed; and</li>
<li>Has received or will receive a copy of the signed Agreement.</li>
</ul>

<h2>Client transfer authorization</h2>
<p>I, {$authorizationName}, voluntarily authorize Parrot Canada Visa Consultant Co. Ltd. to refer me and transfer my necessary personal information and documents to:</p>
<p><strong>Regulated Canadian Immigration Consultant (RCIC):</strong> Heena Kishanlal Verma<br>
<strong>RCIC License Number:</strong> R707012<br>
<strong>Regulatory body:</strong> College of Immigration and Citizenship Consultants (CICC)<br>
<strong>Office location:</strong> Brampton, Ontario, Canada<br>
<strong>Office name:</strong> Sharkish Immigration Inc.</p>
<p>I understand that Parrot Canada Visa Consultant Co. Ltd. is an education consultant and administrative-support company only and does not provide regulated Canadian immigration advice or representation.</p>
<p>I understand that the regulated services will be provided under a separate retainer agreement with the RCIC.</p>
<p class="field-line"><span>Client&rsquo;s initials</span>{$initials}</p>
<p class="field-line"><span>Date</span>{$agreementDateRepeat}</p>
HTML;
}
