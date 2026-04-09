<?php
declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';

use Dompdf\Dompdf;
use Dompdf\Options;

abstract class ProfessionalPDFGenerator {
    protected $conn;
    protected $contract;
    protected $language;
    protected $isFrench;
    
    public function __construct($conn, int $contractId) {
        $this->conn = $conn;
        $this->contract = $this->fetchContract($contractId);
        $this->language = $this->contract['language'] ?? 'english';
        $this->isFrench = $this->language === 'french';
    }
    
    protected function fetchContract(int $contractId): ?array {
        $stmt = $this->conn->prepare("
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
        
        return $contract ?: null;
    }
    
    protected function t(string $english, string $french): string {
        return $this->isFrench ? $french : $english;
    }
    
    protected function esc(?string $v): string {
        return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    }
    
    protected function processSignatureImage(?string $signatureImage): string {
        if (empty($signatureImage)) {
            return '<div class="signature-placeholder"></div>';
        }
        
        if (strpos($signatureImage, 'data:image/') === 0) {
            return '<img src="' . $this->esc($signatureImage) . '" alt="Signature" class="signature-img">';
        } 
        elseif (preg_match('/^[a-zA-Z0-9\/+\r\n=]+$/', $signatureImage)) {
            return '<img src="data:image/png;base64,' . $this->esc($signatureImage) . '" alt="Signature" class="signature-img">';
        }
        elseif (file_exists($signatureImage)) {
            $base64 = base64_encode(file_get_contents($signatureImage));
            return '<img src="data:image/png;base64,' . $this->esc($base64) . '" alt="Signature" class="signature-img">';
        }
        
        return '<div class="signature-placeholder"></div>';
    }
    
    protected function getEmployerSignature(): string {
        $employerSignaturePath = __DIR__ . '/admin/employer-signature.png';
        if (file_exists($employerSignaturePath)) {
            $base64 = base64_encode(file_get_contents($employerSignaturePath));
            return '<img src="data:image/png;base64,' . $this->esc($base64) . '" alt="Employer Signature" class="signature-img">';
        }
        return '<div class="signature-placeholder"></div>';
    }
    
    protected function getProfessionalStyles(): string {
        return '
        @page {
            size: A4;
            margin: 1.5cm 1.5cm 1.5cm 1.5cm; /* ultra-compact margins */
            @bottom-center {
                content: counter(page);
                font-size: 8pt;
                color: #666;
                font-family: "Georgia", serif;
                border-top: 1px solid #ddd;
                padding-top: 2mm;
                width: 100%;
                text-align: center;
            }
            @top-center {
                content: "";
                border-bottom: 1px solid #ddd;
                padding-bottom: 2mm;
                width: 100%;
            }
        }
        
        * {
            box-sizing: border-box;
        }
        
        body { 
            font-family: "Georgia", "Times New Roman", serif; 
            font-size: 12pt; 
            line-height: 1.4; 
            margin: 0;
            padding: 0;
            color: #1a1a1a;
            background: #ffffff;
            text-rendering: optimizeLegibility;
            -webkit-font-smoothing: antialiased;
            -moz-osx-font-smoothing: grayscale;
            letter-spacing: 0.01em;
        }
        
        .document {
            max-width: 100%;
            margin: 0 auto;
        }
        
        .page-break {
            page-break-before: avoid;
            page-break-after: avoid;
            page-break-inside: avoid;
        }
        
                
        .header {
            text-align: center;
            margin-bottom: 0pt;
            padding-bottom: 0pt;
            border-bottom: 2px solid #1a1a1a;
            position: relative;
        }
        
        h1 { 
            text-align: center; 
            font-size: 22pt; 
            margin: 0pt 0 0pt 0;
            font-weight: bold;
            letter-spacing: 1px;
            color: #1a1a1a;
            text-transform: uppercase;
            line-height: 0.9;
        }
        
        .subtitle {
            text-align: center;
            font-size: 13pt;
            font-style: italic;
            color: #555;
            margin-bottom: 2pt;
            letter-spacing: 0.3px;
        }
        
        h2 { 
            font-size: 16pt; 
            font-weight: bold; 
            margin-top: 2pt; 
            margin-bottom: 1pt;
            color: #1a1a1a;
            border-bottom: 1px solid #1a1a1a;
            padding-bottom: 0pt;
            page-break-after: avoid;
            page-break-inside: avoid;
            text-align: left;
        }
        
        h3 { 
            font-size: 14pt; 
            font-weight: bold; 
            margin-top: 8pt; 
            margin-bottom: 4pt;
            color: #1a1a1a;
            page-break-after: avoid;
            text-align: left;
        }
        
        h4 { 
            font-size: 13pt; 
            font-weight: bold; 
            margin: 6pt 0 3pt 0;
            color: #1a1a1a;
            text-align: left;
        }
        
        p { 
            margin: 0 0 3pt 0; 
            text-align: left !important;
            orphans: 2;
            widows: 2;
            text-indent: 0;
            line-height: 1.4;
            font-size: 12pt;
            font-weight: 400;
            color: #1a1a1a;
            letter-spacing: 0.01em;
        }
        
        strong { 
            font-weight: 700; 
            color: #0d47a1;
            letter-spacing: 0.02em;
        }
        
        ul, ol {
            margin: 2pt 0;
            padding-left: 24pt;
            line-height: 1.4;
            text-align: left;
        }
        
        li {
            margin-bottom: 1pt;
            line-height: 1.4;
            text-align: left;
            font-size: 12pt;
            color: #2c2c2c;
        }
        
        .party-info { 
            padding: 4pt 6pt; 
            margin: 2pt 0; 
            border: 1px solid #e3f2fd;
            background: #f8faff;
            border-radius: 3px;
            page-break-inside: avoid;
        }
        
        .party-info h4 { 
            margin: 0 0 3pt 0; 
            color: #0d47a1;
            font-size: 15pt;
            font-weight: 700;
            border-bottom: 1px solid #e3f2fd;
            padding-bottom: 2pt;
            text-align: left;
            letter-spacing: 0.02em;
        }
        
        .signature-section {
            margin-top: 25pt;
            padding: 15pt 0;
            page-break-inside: avoid;
            page-break-before: avoid;
            page-break-after: avoid;
            border-top: 2px solid #e3f2fd;
        }
        
        .signature-container {
            display: flex;
            justify-content: space-between;
            gap: 50px;
            margin-top: 15pt;
            page-break-inside: avoid;
        }
        
        .signature-block {
            flex: 1;
            min-width: 0;
            page-break-inside: avoid;
            padding: 8pt;
            border: 1px solid #e8eaf6;
            border-radius: 4px;
            background: #fafbff;
        }
        
        .signature-box {
            border: 1px solid #e3f2fd;
            padding: 12px 15px;
            text-align: center;
            background: #ffffff;
            page-break-inside: avoid;
            border-radius: 4px;
        }
        
        .company-title {
            font-size: 15pt;
            font-weight: 700;
            margin-bottom: 8pt;
            text-align: center;
            color: #0d47a1;
            border-bottom: 2px solid #0d47a1;
            padding-bottom: 4pt;
            letter-spacing: 0.02em;
        }
        
        .representative-info {
            margin-bottom: 6pt;
            text-align: left;
            font-size: 11pt;
            color: #424242;
        }
        
        .representative-info p {
            margin: 1pt 0;
            text-align: left;
            font-size: 11pt;
            line-height: 1.3;
            color: #424242;
        }
        
        .signature-label {
            font-weight: 700;
            font-size: 12pt;
            margin: 12pt 0 8pt 0;
            text-align: center;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            border-top: 1px solid #e3f2fd;
            padding-top: 6pt;
            color: #0d47a1;
        }
        
        .signature-area {
            border: none;
            min-height: 300px;
            display: flex; 
            align-items: center; 
            justify-content: center; 
            margin: 8pt 0;
            background: #ffffff;
            padding: 25px;
            position: relative;
        }
        
        .signature-img {
            max-height: 280px;
            max-width: 100%;
            width: auto;
            height: auto;
            object-fit: contain;
            display: block;
            margin: 0 auto;
            transform: scale(1.2);
            transform-origin: center;
        }
        
        .date-line {
            margin-top: 6pt;
            text-align: center;
            font-size: 12pt;
            font-style: italic;
            color: #666;
            font-weight: 500;
        }
        
        /* Print optimization - Enhanced for Smart UI */
        @media print {
            body { 
                font-size: 12pt; 
                line-height: 1.4;
                letter-spacing: 0.01em;
                color: #1a1a1a;
            }
            
            .header {
                margin-bottom: 0pt;
                padding-bottom: 0pt;
            }
            
            h1 {
                margin: 0pt 0 0pt 0;
                line-height: 0.9;
                font-size: 22pt;
                letter-spacing: 0.02em;
            }
            
            .subtitle {
                margin-bottom: 2pt;
                font-size: 13pt;
            }
            
            h2 {
                font-size: 16pt;
                margin-top: 2pt;
                margin-bottom: 1pt;
                padding-bottom: 0pt;
                color: #0d47a1;
                font-weight: 700;
                letter-spacing: 0.02em;
            }
            
            h3 {
                font-size: 14pt;
                margin-top: 8pt;
                margin-bottom: 4pt;
                font-weight: 700;
                color: #1a1a1a;
            }
            
            h4 {
                font-size: 13pt;
                margin: 6pt 0 3pt 0;
                font-weight: 700;
                color: #1a1a1a;
            }
            
            p {
                margin: 0 0 3pt 0;
                text-align: left !important;
                line-height: 1.4;
                font-size: 12pt;
                font-weight: 400;
                color: #1a1a1a;
                letter-spacing: 0.01em;
            }
            
            strong {
                font-weight: 700;
                color: #0d47a1;
                letter-spacing: 0.02em;
            }
            
            ul, ol {
                margin: 2pt 0;
                padding-left: 24pt;
                line-height: 1.4;
            }
            
            li {
                margin-bottom: 1pt;
                line-height: 1.4;
                font-size: 12pt;
                color: #2c2c2c;
            }
            
            .party-info {
                border: 1px solid #e3f2fd;
                background: #f8faff;
                padding: 4pt 6pt;
                margin: 2pt 0;
                border-radius: 3px;
            }
            
            .party-info h4 {
                margin: 0 0 3pt 0;
                padding-bottom: 2pt;
                color: #0d47a1;
                font-size: 15pt;
                font-weight: 700;
                border-bottom: 1px solid #e3f2fd;
                letter-spacing: 0.02em;
            }
            
            .signature-section { 
                margin-top: 25pt;
                padding: 15pt 0;
                border-top: 2px solid #e3f2fd;
            }
            
            .signature-container {
                display: flex;
                justify-content: space-between;
                gap: 50px;
                margin-top: 15pt;
            }
            
            .signature-block {
                flex: 1;
                min-width: 0;
                padding: 8pt;
                border: 1px solid #e8eaf6;
                border-radius: 4px;
                background: #fafbff;
            }
            
            .signature-box {
                border: 1px solid #e3f2fd;
                background: #ffffff;
                padding: 12px 15px;
                border-radius: 4px;
            }
            
            .company-title {
                font-size: 15pt;
                font-weight: 700;
                margin-bottom: 8pt;
                color: #0d47a1;
                border-bottom: 2px solid #0d47a1;
                padding-bottom: 4pt;
                letter-spacing: 0.02em;
            }
            
            .representative-info {
                margin-bottom: 6pt;
                font-size: 11pt;
                color: #424242;
            }
            
            .representative-info p {
                margin: 1pt 0;
                font-size: 11pt;
                line-height: 1.3;
                color: #424242;
            }
            
            .signature-label {
                font-weight: 700;
                font-size: 12pt;
                margin: 12pt 0 8pt 0;
                text-transform: uppercase;
                letter-spacing: 0.05em;
                border-top: 1px solid #e3f2fd;
                padding-top: 6pt;
                color: #0d47a1;
            }
            
            .signature-area {
                border: none;
                background: #ffffff;
                min-height: 300px;
                padding: 25px;
                margin: 8pt 0;
            }
            
            .signature-img {
                max-height: 280px;
                max-width: 100%;
                border-radius: 2px;
                transform: scale(1.2);
                transform-origin: center;
            }
            
            .date-line {
                margin-top: 6pt;
                font-size: 12pt;
                font-style: italic;
                color: #666;
                font-weight: 500;
            }
            
            .footer {
                border-top: none;
                margin-top: 20pt;
                padding-top: 10pt;
            }
            
            .page-break {
                page-break-before: avoid;
                page-break-inside: avoid;
            }
        }
        
        /* Ensure no content is cut off */
        .avoid-break {
            page-break-inside: avoid;
            page-break-before: avoid;
            page-break-after: avoid;
        }
        ';
    }
    
    protected function createPDF(string $html): string {
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
        $options->set('debugCss', false);
        $options->set('debugLayout', false);
        
        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();
        
        $pdfDir = __DIR__ . '/contracts/partners';
        if (!is_dir($pdfDir)) {
            mkdir($pdfDir, 0777, true);
        }
        
        $filename = $this->getFilename();
        $pdfPath = $pdfDir . '/' . $filename;
        
        file_put_contents($pdfPath, $dompdf->output());
        
        return $pdfPath;
    }
    
    protected function getFilename(): string {
        $prefix = $this->isFrench ? 'contrat-partenariat' : 'partner-contract';
        return $prefix . '-' . $this->contract['id'] . '-' . date('Y-m-d') . '.pdf';
    }
    
    protected function getContractTitle(): string {
        return $this->t('Strategic Partnership Agreement', 'Accord de Partenariat Stratégique');
    }
    
    protected function getSubtitle(): string {
        return $this->t('A Professional Partnership for Global Education Services', 'Un Partenariat Professionnel pour les Services d\'Éducation Mondiale');
    }
    
    protected function getPartiesSection(): string {
        $partnerName = $this->esc($this->contract['company_name']);
        $repName = $this->esc($this->contract['representative_name']);
        $repTitle = $this->esc($this->contract['representative_title']);
        $repEmail = $this->esc($this->contract['representative_email']);
        $companyEmail = $this->esc($this->contract['company_email']);
        $companyPhone = $this->esc($this->contract['company_phone']);
        $companyAddress = $this->esc($this->contract['company_address']);
        
        $between = $this->t('Between', 'Entre');
        $and = $this->t('and', 'et');
        $companyName = $this->t('Company Name', 'Nom de l\'Entreprise');
        $representative = $this->t('Representative', 'Représentant');
        $position = $this->t('Position', 'Fonction');
        $email = $this->t('Email', 'Email');
        $phone = $this->t('Phone', 'Téléphone');
        $fullAddress = $this->t('Full Address', 'Adresse complète');
        
        $parrotInfo = $this->t(
            'Parrot Canada Visa Consultant Co. Ltd<br>
            Dr Jean Pierre Twajamahoro<br>
            Owner & Managing Director<br>
            Email: infos@visaconsultantcanada.ca<br>
            Phone: +1 (438) 290-6688<br>
            Rwanda Address: Rwanda - Kigali<br>
            Town Center Building (near Simba Supermarket),<br>
            2nd Floor, Door: F2B-022C, Nyarugenge<br>
            Canada Address:<br>
            294 Rue Vezina App 202; Lasalle, Quebec H8R 3M9',
            
            'Parrot Canada Visa Consultant Co. Ltd<br>
            Dr Jean Pierre Twajamahoro<br>
            Propriétaire & Directeur Général<br>
            Adresse courriel: infos@visaconsultantcanada.ca<br>
            Téléphone: +1 (438) 290-6688<br>
            Adresse au Rwanda: Rwanda - Kigali<br>
            Town Center Building (near Simba Supermarket),<br>
            2nd Floor, Door: F2B-022C, Nyarugenge<br>
            Adresse au Canada:<br>
            294 Rue Vezina App 202; Lasalle, Quebec H8R 3M9'
        );
        
        return "<h2>1. " . $this->t('PARTIES', 'PARTIES') . "</h2><p><strong>$between</strong></p><div class='party-info'><h4>$partnerName</h4><p><strong>$companyName:</strong> $partnerName</p><p><strong>$representative:</strong> $repName</p><p><strong>$position:</strong> $repTitle</p><p><strong>$email:</strong> $companyEmail</p><p><strong>$phone:</strong> $companyPhone</p><p><strong>$fullAddress:</strong> $companyAddress</p></div><p><strong>$and</strong></p><div class='party-info'><h4>Parrot Canada Visa Consultant Co. Ltd</h4><p>$parrotInfo</p></div>";
    }
    
    protected function getSignatureSection(): string {
        $partnerName = $this->esc($this->contract['company_name']);
        $repName = $this->esc($this->contract['representative_name']);
        $repTitle = $this->esc($this->contract['representative_title']);
        $signedDate = $this->esc($this->contract['signed_date']);
        
        $partnerSignature = $this->processSignatureImage($this->contract['signature_image']);
        $employerSignature = $this->getEmployerSignature();
        
        $signaturesTitle = $this->t('16. SIGNATURES', '16. SIGNATURES');
        $executedBy = $this->t(
            'This Strategic Partnership Agreement is executed by authorized representatives of both parties on date indicated below:',
            'Cet Accord de Partenariat Stratégique est exécuté par les représentants autorisés des deux parties à la date indiquée ci-dessous :'
        );
        $authorizedSignature = $this->t('AUTHORIZED SIGNATURE', 'SIGNATURE AUTORISÉE');
        $representativeName = $this->t('Representative Name', 'Nom du Représentant');
        $signedOn = $this->t('Signed on', 'Signé le');
        
        $parrotRepName = $this->t('Dr Jean Pierre Twajamahoro', 'Dr Jean Pierre Twajamahoro');
        $parrotRepTitle = $this->t('Owner & Managing Director', 'Propriétaire & Directeur Général');
        
        return "<div class='signature-section'><h2>$signaturesTitle</h2><p>$executedBy</p><div class='signature-container'><div class='signature-block'><div class='signature-box'><div class='company-title'>$partnerName</div><div class='representative-info'><p><strong>$representativeName:</strong> $repName</p><p><strong>" . $this->t('Position', 'Fonction') . ":</strong> $repTitle</p></div><div class='signature-label'>$authorizedSignature</div><div class='signature-area'>$partnerSignature</div><div class='date-line'>$signedOn: $signedDate</div></div></div><div class='signature-block'><div class='signature-box'><div class='company-title'>Parrot Canada Visa Consultant Co. Ltd</div><div class='representative-info'><p><strong>$representativeName:</strong> $parrotRepName</p><p><strong>" . $this->t('Position', 'Fonction') . ":</strong> $parrotRepTitle</p></div><div class='signature-label'>$authorizedSignature</div><div class='signature-area'>$employerSignature</div><div class='date-line'>$signedOn: $signedDate</div></div></div></div></div>";
    }
    
    protected function getFooterSection(): string {
        $footerText = $this->t(
            'This agreement constitutes the entire understanding between parties and supersedes all prior discussions, negotiations, and agreements.<br>IN WITNESS WHEREOF, parties have executed this Strategic Partnership Agreement as of date indicated above.',
            'Cet accord constitue l\'entente complète entre les parties et remplace toutes les discussions, négociations et accords antérieurs.<br>EN FOI DE QUOI, les parties ont exécuté cet Accord de Partenariat Stratégique à la date indiquée ci-dessus.'
        );
        
        return "<div class='footer avoid-break'><p>$footerText</p></div>";
    }
    
    abstract protected function getMainContent(): string;
    
    public function generate(): ?string {
        if (!$this->contract) {
            return null;
        }
        
        $html = '<!DOCTYPE html><html lang="' . ($this->isFrench ? 'fr' : 'en') . '"><head><meta charset="utf-8"><title>' . $this->getContractTitle() . '</title><style>' . $this->getProfessionalStyles() . '</style></head><body><div class="document"><div class="header"><h1>' . $this->getContractTitle() . '</h1><div class="subtitle">' . $this->getSubtitle() . '</div></div>' . $this->getPartiesSection() . $this->getMainContent() . $this->getSignatureSection() . $this->getFooterSection() . '</div></body></html>';
        
        return $this->createPDF($html);
    }
}
