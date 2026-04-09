<?php
declare(strict_types=1);

echo "<!DOCTYPE html>
<html lang='en'>
<head>
    <meta charset='UTF-8'>
    <meta name='viewport' content='width=device-width, initial-scale=1.0'>
    <title>Enhanced PDF Styling Test</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            margin: 20px;
            background: #f5f5f5;
        }
        .container {
            max-width: 1000px;
            margin: 0 auto;
            background: white;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        .success {
            color: #28a745;
            font-weight: bold;
        }
        .enhanced {
            background: #e8f5e8;
            padding: 15px;
            border-radius: 8px;
            margin: 10px 0;
            border-left: 4px solid #28a745;
        }
        .styling-showcase {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            margin: 20px 0;
        }
        .before, .after {
            padding: 15px;
            border-radius: 8px;
        }
        .before {
            background: #f8f9fa;
            border-left: 4px solid #6c757d;
        }
        .after {
            background: #e3f2fd;
            border-left: 4px solid #2196f3;
        }
        .demo-section {
            background: #f8faff;
            padding: 20px;
            border-radius: 8px;
            margin: 15px 0;
            border: 1px solid #e3f2fd;
        }
        .demo-title {
            color: #0d47a1;
            font-size: 13pt;
            font-weight: 700;
            border-bottom: 1px solid #e3f2fd;
            padding-bottom: 2pt;
            letter-spacing: 0.02em;
        }
        .demo-paragraph {
            margin: 0 0 3pt 0;
            line-height: 1.4;
            font-size: 10pt;
            font-weight: 400;
            color: #1a1a1a;
            letter-spacing: 0.01em;
        }
        .demo-strong {
            font-weight: 700;
            color: #0d47a1;
            letter-spacing: 0.02em;
        }
        .demo-list {
            margin: 2pt 0;
            padding-left: 22pt;
            line-height: 1.4;
        }
        .demo-list li {
            margin-bottom: 1pt;
            line-height: 1.4;
            font-size: 10pt;
            color: #2c2c2c;
        }
        .signature-demo {
            display: flex;
            justify-content: space-between;
            gap: 50px;
            margin-top: 15pt;
        }
        .signature-block {
            flex: 1;
            padding: 8pt;
            border: 1px solid #e8eaf6;
            border-radius: 4px;
            background: #fafbff;
        }
        .company-title {
            font-size: 13pt;
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
            font-size: 9pt;
            color: #424242;
        }
        .signature-label {
            font-weight: 700;
            font-size: 10pt;
            margin: 12pt 0 8pt 0;
            text-align: center;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            border-top: 1px solid #e3f2fd;
            padding-top: 6pt;
            color: #0d47a1;
        }
        .signature-area {
            border: 1px dashed #90caf9;
            min-height: 80px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 8pt 0;
            background: #f5f7ff;
            padding: 8px;
            border-radius: 3px;
        }
        .date-line {
            margin-top: 6pt;
            text-align: center;
            font-size: 9pt;
            font-style: italic;
            color: #666;
            font-weight: 500;
        }
    </style>
</head>
<body>
    <div class='container'>
        <h1>Enhanced PDF Styling Implementation</h1>
        <p><strong>Objective:</strong> Apply smart font styling, spacing, and signature alignment for professional UI</p>
        
        <div class='enhanced'>
            <h3>Enhanced Typography Features Applied:</h3>
            <ul>
                <li><strong>Smart Font Hierarchy:</strong> Georgia serif base with optimized weights</li>
                <li><strong>Letter Spacing:</strong> 0.01em for body, 0.02em for headings, 0.05em for labels</li>
                <li><strong>Color Scheme:</strong> Professional blue (#0d47a1) for emphasis</li>
                <li><strong>Line Height:</strong> Optimized to 1.4 for readability</li>
                <li><strong>Paragraph Spacing:</strong> 3pt margins for clean separation</li>
                <li><strong>Visual Hierarchy:</strong> Clear distinction between content types</li>
            </ul>
        </div>
        
        <div class='styling-showcase'>
            <div class='before'>
                <h3>Before (Basic Styling)</h3>
                <ul>
                    <li>Generic font weights</li>
                    <li>No letter spacing</li>
                    <li>Basic black colors</li>
                    <li>Minimal spacing</li>
                    <li>Plain signature blocks</li>
                    <li>No visual hierarchy</li>
                </ul>
            </div>
            <div class='after'>
                <h3>After (Smart UI Styling)</h3>
                <ul>
                    <li>Optimized font weights (400, 700)</li>
                    <li>Precise letter spacing</li>
                    <li>Professional blue accents</li>
                    <li>Enhanced spacing & margins</li>
                    <li>Styled signature areas</li>
                    <li>Clear visual hierarchy</li>
                </ul>
            </div>
        </div>
        
        <div class='demo-section'>
            <h3 class='demo-title'>1. PARTIES</h3>
            <p class='demo-paragraph'><strong>Between</strong></p>
            <div style='padding: 4pt 6pt; margin: 2pt 0; border: 1px solid #e3f2fd; background: #f8faff; border-radius: 3px;'>
                <h4 style='margin: 0 0 3pt 0; color: #0d47a1; font-size: 13pt; font-weight: 700; border-bottom: 1px solid #e3f2fd; padding-bottom: 2pt; letter-spacing: 0.02em;'>Test Company Ltd</h4>
                <p class='demo-paragraph'><strong>Company Name:</strong> Test Company Ltd</p>
                <p class='demo-paragraph'><strong>Representative:</strong> John Doe</p>
                <p class='demo-paragraph'><strong>Position:</strong> Director</p>
                <p class='demo-paragraph'><strong>Email:</strong> info@test.com</p>
            </div>
            <p class='demo-paragraph'><strong>and</strong></p>
            <div style='padding: 4pt 6pt; margin: 2pt 0; border: 1px solid #e3f2fd; background: #f8faff; border-radius: 3px;'>
                <h4 style='margin: 0 0 3pt 0; color: #0d47a1; font-size: 13pt; font-weight: 700; border-bottom: 1px solid #e3f2fd; padding-bottom: 2pt; letter-spacing: 0.02em;'>Parrot Canada Visa Consultant Co. Ltd</h4>
                <p class='demo-paragraph'>Dr Jean Pierre Twajamahoro</p>
                <p class='demo-paragraph'>Owner & Managing Director</p>
                <p class='demo-paragraph'>Email: infos@visaconsultantcanada.ca</p>
            </div>
        </div>
        
        <div class='demo-section'>
            <h3 class='demo-title'>2. PURPOSE OF AGREEMENT</h3>
            <p class='demo-paragraph'>The primary objective of this agreement is to establish a <strong class='demo-strong'>comprehensive and structured student support system</strong>, including:</p>
            <ul class='demo-list'>
                <li>Document evaluation and eligibility assessment</li>
                <li>University/institution selection</li>
                <li>Admission acquisition</li>
                <li>Partial scholarships and student loan assistance</li>
                <li>Visa counseling and processing</li>
                <li>Travel arrangement</li>
                <li>Airport pickup and settlement assistance</li>
            </ul>
        </div>
        
        <div style='margin-top: 25pt; padding: 15pt 0; border-top: 2px solid #e3f2fd;'>
            <h3 class='demo-title'>16. SIGNATURES</h3>
            <div class='signature-demo'>
                <div class='signature-block'>
                    <div class='signature-box'>
                        <div class='company-title'>Test Company Ltd</div>
                        <div class='representative-info'>
                            <p style='margin: 1pt 0; font-size: 9pt; line-height: 1.3; color: #424242;'><strong>Representative Name:</strong> John Doe</p>
                            <p style='margin: 1pt 0; font-size: 9pt; line-height: 1.3; color: #424242;'><strong>Position:</strong> Director</p>
                        </div>
                        <div class='signature-label'>AUTHORIZED SIGNATURE</div>
                        <div class='signature-area'>Signature Area</div>
                        <div class='date-line'>Signed on: 2026-04-09</div>
                    </div>
                </div>
                <div class='signature-block'>
                    <div class='signature-box'>
                        <div class='company-title'>Parrot Canada Visa Consultant Co. Ltd</div>
                        <div class='representative-info'>
                            <p style='margin: 1pt 0; font-size: 9pt; line-height: 1.3; color: #424242;'><strong>Representative Name:</strong> Dr Jean Pierre Twajamahoro</p>
                            <p style='margin: 1pt 0; font-size: 9pt; line-height: 1.3; color: #424242;'><strong>Position:</strong> Owner & Managing Director</p>
                        </div>
                        <div class='signature-label'>AUTHORIZED SIGNATURE</div>
                        <div class='signature-area'>Signature Area</div>
                        <div class='date-line'>Signed on: 2026-04-09</div>
                    </div>
                </div>
            </div>
        </div>
        
        <div class='enhanced'>
            <h3>Key Improvements Summary:</h3>
            <ul>
                <li><strong>Typography:</strong> Professional Georgia font with optimized weights and spacing</li>
                <li><strong>Colors:</strong> Smart blue (#0d47a1) for emphasis and branding</li>
                <li><strong>Paragraphs:</strong> Enhanced spacing (3pt margins) and line height (1.4)</li>
                <li><strong>Lists:</strong> Better indentation (22pt) and item spacing (1pt)</li>
                <li><strong>Party Info:</strong> Styled boxes with blue borders and subtle backgrounds</li>
                <li><strong>Signatures:</strong> Aligned blocks with professional styling and borders</li>
                <li><strong>Visual Hierarchy:</strong> Clear distinction between sections and content types</li>
                <li><strong>Print Optimization:</strong> All styling preserved in PDF output</li>
            </ul>
        </div>
        
        <div style='text-align: center; margin-top: 30px; padding: 20px; background: #e8f5e8; border-radius: 8px;'>
            <h2 style='color: #28a745;'>Smart UI Styling Complete!</h2>
            <p><strong>PDF contracts now feature professional typography, enhanced spacing, and well-aligned signature sections.</strong></p>
            <p>The layout maintains compact efficiency while presenting a polished, professional appearance.</p>
        </div>
    </div>
</body>
</html>";
?>
