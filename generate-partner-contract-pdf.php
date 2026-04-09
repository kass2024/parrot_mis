<?php
declare(strict_types=1);

use Dompdf\Dompdf;
use Dompdf\Options;
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/vendor/autoload.php';

function esc(?string $v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function generatePartnerContractPDF(int $contractId): ?string {
    global $conn;
    
    $stmt = $conn->prepare("
        SELECT pc.*, ps.representative_name, ps.representative_email, ps.signed_date, ps.signature_image
        FROM partner_contracts pc
        LEFT JOIN partner_signatures ps ON pc.id = ps.contract_id
        WHERE pc.id = ? AND pc.status = 'signed'
        LIMIT 1
    ");
    $stmt->bind_param("i", $contractId);
    $stmt->execute();
    $contract = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    if (!$contract) {
        return null;
    }
    
    // Process partner signature (base64) - IMPROVED RESOLUTION
    $partnerSignatureHtml = '';
    if (!empty($contract['signature_image'])) {
        if (strpos($contract['signature_image'], 'data:image/') === 0) {
            $partnerSignatureHtml = '<img src="' . esc($contract['signature_image']) . '" alt="Company Representative Signature" class="signature-img">';
        } 
        elseif (preg_match('/^[a-zA-Z0-9\/+\r\n=]+$/', $contract['signature_image'])) {
            $partnerSignatureHtml = '<img src="data:image/png;base64,' . esc($contract['signature_image']) . '" alt="Company Representative Signature" class="signature-img">';
        }
        elseif (file_exists($contract['signature_image'])) {
            $base64 = base64_encode(file_get_contents($contract['signature_image']));
            $partnerSignatureHtml = '<img src="data:image/png;base64,' . esc($base64) . '" alt="Company Representative Signature" class="signature-img">';
        } else {
            $partnerSignatureHtml = '<div class="signature-placeholder">_________________________</div>';
        }
    } else {
        $partnerSignatureHtml = '<div class="signature-placeholder">_________________________</div>';
    }
    
    // Process employer signature with absolute path and high quality
    $employerSignaturePath = __DIR__ . '/admin/employer-signature.png';
    $employerSignatureHtml = '';
    if (file_exists($employerSignaturePath)) {
        $base64 = base64_encode(file_get_contents($employerSignaturePath));
        $employerSignatureHtml = '<img src="data:image/png;base64,' . esc($base64) . '" alt="Employer Signature" class="signature-img">';
    } else {
        $employerSignatureHtml = '<div class="signature-placeholder">_________________________</div>';
    }
    
    $html = '
    <!DOCTYPE html>
    <html>
    <head>
    <meta charset="utf-8">
    <title>Partner Contract</title>
    <style>
        @page {
            size: A4;
            margin: 20mm 15mm 20mm 15mm;
        }
        
        body { 
            font-family: "Georgia", serif; 
            font-size: 11pt; 
            line-height: 1.5; 
            margin: 0;
            color: #000;
        }
        
        .page-break {
            page-break-before: avoid;
            page-break-after: avoid;
        }
        
        .header {
            text-align: center;
            margin-bottom: 20pt;
            border-bottom: 3px solid #000;
            padding-bottom: 15pt;
        }
        
        h1 { 
            text-align: center; 
            font-size: 20pt; 
            margin: 0 0 10pt 0;
            font-weight: bold;
            letter-spacing: 1px;
        }
        
        .subtitle {
            text-align: center;
            font-size: 14pt;
            font-style: italic;
            color: #333;
            margin-bottom: 20pt;
        }
        
        h2 { 
            font-size: 14pt; 
            font-weight: bold; 
            margin-top: 24pt; 
            margin-bottom: 12pt;
            color: #000;
            border-bottom: 1px solid #ccc;
            padding-bottom: 4pt;
        }
        
        h3 { 
            font-size: 12pt; 
            font-weight: bold; 
            margin-top: 20pt; 
            margin-bottom: 10pt;
            color: #000;
        }
        
        h4 { 
            font-size: 11pt; 
            font-weight: bold; 
            margin: 15pt 0 8pt 0;
            color: #000;
        }
        
        p { 
            margin: 0 0 10pt 0; 
            text-align: justify;
            orphans: 2;
            widows: 2;
        }
        
        strong { 
            font-weight: bold; 
        }
        
        ul, ol {
            margin: 8pt 0;
            padding-left: 20pt;
        }
        
        li {
            margin-bottom: 4pt;
        }
        
        .company-info { 
            padding: 12pt; 
            margin: 12pt 0; 
            border: 1px solid #ddd;
            background: #f9f9f9;
        }
        
        .company-info h4 { 
            margin: 0 0 8pt 0; 
            color: #000;
        }
        
        .signature-section {
            margin-top: 30pt;
            page-break-inside: avoid;
            page-break-before: avoid;
            page-break-after: avoid;
        }
        
        .signature-grid { 
            display: flex; 
            justify-content: space-between;
            margin-top: 30pt;
            gap: 40px;
            page-break-inside: avoid;
        }
        
        .signature-box { 
            flex: 1;
            text-align: center;
            background: #fff;
            padding: 25px 20px;
            border: 1px solid #d0d0d0;
            border-radius: 8px;
            box-shadow: 0 4px 8px rgba(0,0,0,0.08);
            page-break-inside: avoid;
        }
        
        .signature-box p { 
            margin: 10pt 0; 
            font-size: 10.5pt;
        }
        
        .signature-box strong {
            font-size: 11pt;
        }
        
        .signature-label {
            font-weight: bold;
            font-size: 12pt;
            margin-top: 25pt !important;
            margin-bottom: 15pt !important;
            color: #000;
            letter-spacing: 1px;
            text-transform: uppercase;
            border-top: 1px solid #eee;
            padding-top: 15pt;
        }
        
        .signature-line { 
            border-bottom: 2px solid #000;
            min-height: 200px;
            display: flex; 
            align-items: center; 
            justify-content: center; 
            margin: 15pt 0 20pt 0;
            background: #fafafa;
            padding: 25px 15px;
            border-radius: 8px;
        }
        
        .signature-img {
            max-height: 150px;
            max-width: 95%;
            width: auto;
            height: auto;
            object-fit: contain;
            image-rendering: crisp-edges;
            image-rendering: -moz-crisp-edges;
            image-rendering: pixelated;
            image-rendering: optimize-contrast;
            display: block;
            margin: 0 auto;
        }
        
        .signature-placeholder {
            font-size: 16pt;
            letter-spacing: 3px;
            color: #999;
            font-family: monospace;
        }
        
        .date-line {
            margin-top: 15pt;
            font-size: 10pt;
            font-style: italic;
            color: #555;
        }
        
        .footer {
            margin-top: 50pt;
            text-align: center;
            font-size: 9pt;
            color: #666;
            border-top: 1px solid #ccc;
            padding-top: 15pt;
            page-break-inside: avoid;
        }
        
        .company-name-header {
            font-size: 13pt;
            font-weight: bold;
            margin-bottom: 20pt;
            padding-bottom: 10pt;
            border-bottom: 2px solid #000;
            display: inline-block;
        }
        
        /* Prevent empty pages */
        .content-wrapper {
            page-break-after: avoid;
            page-break-before: avoid;
        }
        
        @media print {
            body { font-size: 10pt; }
            .signature-section { margin-top: 30pt; }
            .signature-img {
                max-height: 150px;
                image-rendering: crisp-edges;
            }
            .signature-box {
                border: 1px solid #ccc;
                box-shadow: none;
                page-break-inside: avoid;
            }
            .page-break {
                page-break-before: avoid;
            }
        }
    </style>
    </head>
    <body>
    
    <div class="header">
        <h1>STRATEGIC PARTNERSHIP AGREEMENT</h1>
        <div class="subtitle">A Professional Partnership for Global Education Services</div>
    </div>
    
    <div>
        <h4>1. PARTIES</h4>
        <p><strong>Between</strong></p>
        
        <p><strong>Company Name:</strong> ' . esc($contract['company_name']) . '</p>
        <p><strong>Company Email:</strong> ' . esc($contract['company_email']) . '</p>
        <p><strong>Company Phone Number:</strong> ' . esc($contract['company_phone']) . '</p>
        <p><strong>Full address:</strong> ' . esc($contract['company_address']) . '</p>
        
        <p><strong>and</strong></p>
        
        <p><strong>Parrot Canada Visa Consultant Co. Ltd</strong><br>
        Company Email: infos@visaconsultantcanada.ca<br>
        Company Phone Number: +1 (438) 290-6688<br>
        294 Rue Vezina App 202<br>
        Lasalle, Quebec H8R 3M9</p>
    </div>
    
    <h2>2. PURPOSE OF AGREEMENT</h2>
    
    <p>The primary purpose of this Agreement is to establish a complete and structured student support system, ensuring professional guidance at every stage, including:</p>
    <ul>
        <li>Document screening and eligibility assessment</li>
        <li>University/college selection</li>
        <li>Admission securing</li>
        <li>Partial scholarship and student loan assistance (where applicable)</li>
        <li>Visa consultation and approval</li>
        <li>Travel arrangements</li>
        <li>Airport pickup and settlement support in destination country</li>
    </ul>
    
    <h2>3. SCOPE OF PARTNERSHIP</h2>
    
    <h3>3.1 Student Recruitment & Counseling</h3>
    <ul>
        <li>Identification and recruitment of qualified students</li>
        <li>Academic and career guidance aligned with global opportunities</li>
    </ul>
    
    <h3>3.2 Document Screening & Admission Process</h3>
    <ul>
        <li>Comprehensive document screening and eligibility verification</li>
        <li>University and program selection worldwide</li>
        <li>Application preparation and submission</li>
        <li>Securing admission offers from institutions</li>
        <li>Support for scholarship opportunities and loan assistance where applicable</li>
    </ul>
    
    <h3>3.3 Visa Processing & Immigration Support</h3>
    <ul>
        <li>Professional visa consultation under Dr. Jean Pierre Twajamahoro</li>
        <li>Documentation review and compliance with destination-country laws</li>
        <li>Visa application processing and follow-up</li>
    </ul>
    
    <h3>3.4 Travel & Pre-Departure Services</h3>
    <ul>
        <li>Travel planning and flight guidance</li>
        <li>Pre-departure orientation</li>
    </ul>
    
    <h3>3.5 Airport Pickup & Settlement (Core Commitment)</h3>
    <ul>
        <li>Guaranteed airport pickup arrangements in student\'s destination country</li>
        <li>Initial accommodation guidance</li>
        <li>Settlement assistance upon arrival abroad</li>
        <li>Coordination with local partners</li>
    </ul>
    
    <h2>4. CORE MISSION STATEMENT</h2>
    
    <p>Both parties agree to operate as a full-service global education consultancy, delivering:</p>
    <ul>
        <li>"From Screening to Settlement" Service Model</li>
        <li>Covering all international destinations worldwide</li>
        <li>Including admission, visa, travel, and arrival support</li>
        <li>Ensuring a seamless transition from initial assessment to full settlement abroad.</li>
    </ul>
    
    <h2>5. ROLES AND RESPONSIBILITIES</h2>
    
    <div>
        <h3>5.1 Company Name: ' . esc($contract['company_name']) . '</h3>
        <ul>
            <li>Recruit and prepare students</li>
            <li>Support document collection and initial screening</li>
            <li>Assist in application preparation</li>
            <li>Provide pre-departure guidance</li>
            <li>Maintain communication with applicants</li>
        </ul>
    </div>
    
    <div>
        <h3>5.2 Parrot Canada Visa Consultant Co. Ltd</h3>
        <p>(Represented by Dr. Jean Pierre Twajamahoro, Owner & Managing Director)</p>
        <ul>
            <li>Conduct initial document screening and eligibility assessment</li>
            <li>Support university/college selection</li>
            <li>Assist in securing admission offers</li>
            <li>Provide partial scholarship and student loan assistance (where applicable)</li>
            <li>Provide expert visa consultation and processing services</li>
            <li>Ensure compliance with immigration laws of destination countries</li>
            <li>Handle visa documentation and application procedures</li>
            <li>Support travel planning coordination</li>
            <li>Facilitate or coordinate airport pickup and settlement support in student\'s destination country</li>
            <li>Provide post-arrival support where applicable</li>
        </ul>
    </div>
    
    <h2>6. FINANCIAL ARRANGEMENT</h2>
    
    <ul>
        <li>Both parties agree that each company retains the right to independently charge service fees to their respective students/clients based on their internal policies.</li>
        <li>Parrot Canada Visa Consultant Co. Ltd shall pay application service fees to Company Name: ' . esc($contract['company_name']) . ' upon successful issuance of an Offer Letter of Admission.</li>
        <li>The agreed university application fees are:</li>
        <li>🇨🇦 Canada: 125 CAD per student</li>
        <li>🇺🇸 United States: 100 USD per student</li>
        <li>🇪🇺 Europe: 100 EUR per student</li>
        <li>🌏 Asia: 100 USD per student</li>
        <li>Payment shall be made immediately after issuance of Offer Letter, using agreed payment methods.</li>
        <li>Both parties agree to maintain full transparency, accountability, and proper financial records.</li>
    </ul>
    
    <h2>7. VALUE PROPOSITION</h2>
    
    <p>This partnership delivers a premium global service model that:</p>
    <ul>
        <li>Covers entire student journey from screening to settlement</li>
        <li>Improves admission and visa success rates</li>
        <li>Provides financial guidance through scholarships and loans</li>
        <li>Ensures safe arrival and integration abroad</li>
    </ul>
    
    <h2>8. COMMUNICATION AND COORDINATION</h2>
    
    <ul>
        <li>Dedicated representatives from both parties</li>
        <li>Continuous monitoring of student progress</li>
        <li>Real-time updates across all stages</li>
    </ul>
    
    <h2>9. CONFIDENTIALITY CLAUSE</h2>
    
    <p>All student and business information shall remain strictly confidential.</p>
    
    <h2>10. COMPLIANCE AND ETHICS</h2>
    
    <ul>
        <li>Full compliance with international education and immigration laws</li>
        <li>Ethical and transparent operations</li>
        <li>Zero tolerance for fraud or misrepresentation</li>
    </ul>
    
    <h2>11. DURATION AND TERMINATION</h2>
    
    <ul>
        <li>Effective upon signing</li>
        <li>Valid for 1 Year</li>
        <li>30-day written termination notice</li>
        <li>Ongoing cases must be completed</li>
    </ul>
    
    <h2>12. DISPUTE RESOLUTION</h2>
    
    <ul>
        <li>Mutual negotiation</li>
        <li>Arbitration if necessary</li>
    </ul>
    
    <h2>13. FORCE MAJEURE</h2>
    
    <p>Neither party shall be liable for uncontrollable events affecting obligations.</p>
    
    <h2>14. CONCLUSION</h2>
    
    <p>This Agreement represents a powerful global partnership, delivering complete international education services from document screening to airport pickup and settlement worldwide.</p>
    
    <h2>15. CONTACT INFORMATION</h2>
    
    <div>
        <h4>Company Name: ' . esc($contract['company_name']) . '</h4>
        <p><strong>Representative:</strong> ' . esc($contract['representative_name']) . '</p>
        <p><strong>Title:</strong> ' . esc($contract['representative_title']) . '</p>
        <p><strong>Email:</strong> ' . esc($contract['representative_email']) . '</p>
        <p><strong>Phone:</strong> ' . esc($contract['company_phone']) . '</p>
        <p><strong>Full address:</strong> ' . esc($contract['company_address']) . '</p>
    </div>
    
    <div>
        <h4>Parrot Canada Visa Consultant Co. Ltd</h4>
        <p>Dr. Jean Pierre Twajamahoro<br>
        Owner & Managing Director<br>
        Company Email: infos@visaconsultantcanada.ca<br>
        Company Phone Number: +1 (438) 290-6688<br>
        294 Rue Vezina App 202<br>
        Lasalle, Quebec H8R 3M9</p>
    </div>
    
    <div class="signature-section">
        <h2>16. SIGNATURES</h2>
        <p>This Strategic Partnership Agreement is executed by the authorized representatives of both parties on the date indicated below:</p>
        
        <div class="signature-grid">
            <div class="signature-box">
                <div class="company-name-header">' . esc($contract['company_name']) . '</div>
                <p><strong>Representative Name:</strong> ' . esc($contract['representative_name']) . '</p>
                <p><strong>Title:</strong> ' . esc($contract['representative_title']) . '</p>
                <p class="signature-label">AUTHORIZED SIGNATURE</p>
                <div class="signature-line">
                    ' . $partnerSignatureHtml . '
                </div>
                <p class="date-line">Signed on: ' . esc($contract['signed_date']) . '</p>
            </div>
            
            <div class="signature-box">
                <div class="company-name-header">Parrot Canada Visa Consultant Co. Ltd</div>
                <p><strong>Representative Name:</strong> Dr. Jean Pierre Twajamahoro</p>
                <p><strong>Title:</strong> Owner & Managing Director</p>
                <p class="signature-label">AUTHORIZED SIGNATURE</p>
                <div class="signature-line">
                    ' . $employerSignatureHtml . '
                </div>
                <p class="date-line">Signed on: ' . esc($contract['signed_date']) . '</p>
            </div>
        </div>
        
        <div class="footer">
            <p>This agreement constitutes the entire understanding between the parties and supersedes all prior discussions, negotiations, and agreements.</p>
            <p>IN WITNESS WHEREOF, the parties hereto have executed this Strategic Partnership Agreement as of the date first above written.</p>
        </div>
    </div>
    
    </body>
    </html>
    ';
    
    $options = new Options();
    $options->set('defaultFont', 'Georgia');
    $options->set('dpi', 300);
    $options->set('isRemoteEnabled', true);
    $options->set('isHtml5ParserEnabled', true);
    $options->set('isPhpEnabled', false);
    $options->set('isJavascriptEnabled', false);
    $options->set('isFontSubsettingEnabled', true);
    $options->set('defaultPaperSize', 'a4');
    $options->set('defaultPaperOrientation', 'portrait');
    $options->set('debugKeepTemp', false);
    
    $dompdf = new Dompdf($options);
    $dompdf->loadHtml($html);
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->render();
    
    $pdfDir = __DIR__ . '/contracts/partners';
    if (!is_dir($pdfDir)) {
        mkdir($pdfDir, 0777, true);
    }
    
    $filename = 'partner-contract-' . $contractId . '-' . date('Y-m-d') . '.pdf';
    $pdfPath = $pdfDir . '/' . $filename;
    
    file_put_contents($pdfPath, $dompdf->output());
    
    return $pdfPath;
}