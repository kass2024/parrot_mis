<?php
declare(strict_types=1);

echo "<!DOCTYPE html>
<html lang='en'>
<head>
    <meta charset='UTF-8'>
    <meta name='viewport' content='width=device-width, initial-scale=1.0'>
    <title>Signature Updates Test</title>
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
        .updated {
            background: #e3f2fd;
            padding: 15px;
            border-radius: 8px;
            margin: 10px 0;
            border-left: 4px solid #2196f3;
        }
        .changes-grid {
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
            background: #e8f5e8;
            border-left: 4px solid #28a745;
        }
        .demo-signature {
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
        .signature-box {
            border: 1px solid #e3f2fd;
            padding: 12px 15px;
            text-align: center;
            background: #ffffff;
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
            text-align: center;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            border-top: 1px solid #e3f2fd;
            padding-top: 6pt;
            color: #0d47a1;
        }
        .signature-area {
            border: none;
            min-height: 120px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 8pt 0;
            background: #ffffff;
            padding: 10px;
            position: relative;
        }
        .signature-img {
            max-height: 100px;
            max-width: 95%;
            width: auto;
            height: auto;
            object-fit: contain;
            display: block;
            margin: 0 auto;
        }
        .date-line {
            margin-top: 6pt;
            text-align: center;
            font-size: 12pt;
            font-style: italic;
            color: #666;
            font-weight: 500;
        }
        .font-demo {
            background: #f8faff;
            padding: 20px;
            border-radius: 8px;
            margin: 15px 0;
            border: 1px solid #e3f2fd;
        }
        .font-demo h2 {
            font-size: 16pt;
            font-weight: bold;
            margin-top: 2pt;
            margin-bottom: 1pt;
            color: #0d47a1;
            border-bottom: 1px solid #1a1a1a;
            padding-bottom: 0pt;
        }
        .font-demo p {
            margin: 0 0 3pt 0;
            line-height: 1.4;
            font-size: 12pt;
            font-weight: 400;
            color: #1a1a1a;
            letter-spacing: 0.01em;
        }
        .font-demo strong {
            font-weight: 700;
            color: #0d47a1;
            letter-spacing: 0.02em;
        }
        .font-demo ul {
            margin: 2pt 0;
            padding-left: 24pt;
            line-height: 1.4;
        }
        .font-demo li {
            margin-bottom: 1pt;
            line-height: 1.4;
            font-size: 12pt;
            color: #2c2c2c;
        }
    </style>
</head>
<body>
    <div class='container'>
        <h1>Signature Updates & Font Size Improvements</h1>
        <p><strong>Changes Applied:</strong> Removed signature borders, increased signature area size, and increased overall contract font size</p>
        
        <div class='updated'>
            <h3>Key Updates Implemented:</h3>
            <ul>
                <li><strong>Signature Borders:</strong> Completely removed for clean appearance</li>
                <li><strong>Signature Area Size:</strong> Increased from 80px to 120px height</li>
                <li><strong>Signature Image Size:</strong> Increased from 70px to 100px max height</li>
                <li><strong>Overall Font Size:</strong> Increased from 10pt to 12pt for body text</li>
                <li><strong>Heading Sizes:</strong> H1: 20pt22pt, H2: 14pt16pt, H3: 12pt14pt, H4: 11pt13pt</li>
                <li><strong>Print Optimization:</strong> All changes preserved in PDF output</li>
            </ul>
        </div>
        
        <div class='changes-grid'>
            <div class='before'>
                <h3>Before (Small & Bordered)</h3>
                <ul>
                    <li>Signature area: 80px height</li>
                    <li>Dashed borders present</li>
                    <li>Signature image: 70px max</li>
                    <li>Body font: 10pt</li>
                    <li>Headings: Small sizes</li>
                    <li>Less readable text</li>
                </ul>
            </div>
            <div class='after'>
                <h3>After (Large & Clean)</h3>
                <ul>
                    <li>Signature area: 120px height</li>
                    <li>No borders (clean look)</li>
                    <li>Signature image: 100px max</li>
                    <li>Body font: 12pt</li>
                    <li>Headings: Larger sizes</li>
                    <li>Highly readable text</li>
                </ul>
            </div>
        </div>
        
        <div class='font-demo'>
            <h2>2. PURPOSE OF AGREEMENT</h2>
            <p>The primary objective of this agreement is to establish a <strong>comprehensive and structured student support system</strong>, including:</p>
            <ul>
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
            <h2 style='font-size: 16pt; font-weight: bold; margin-top: 2pt; margin-bottom: 1pt; color: #0d47a1; border-bottom: 1px solid #1a1a1a; padding-bottom: 0pt;'>16. SIGNATURES</h2>
            <div class='demo-signature'>
                <div class='signature-block'>
                    <div class='signature-box'>
                        <div class='company-title'>Test Company Ltd</div>
                        <div class='representative-info'>
                            <p><strong>Representative Name:</strong> John Doe</p>
                            <p><strong>Position:</strong> Director</p>
                        </div>
                        <div class='signature-label'>AUTHORIZED SIGNATURE</div>
                        <div class='signature-area'>
                            <div style='color: #999; font-style: italic;'>Signature Area (120px height, no border)</div>
                        </div>
                        <div class='date-line'>Signed on: 2026-04-09</div>
                    </div>
                </div>
                <div class='signature-block'>
                    <div class='signature-box'>
                        <div class='company-title'>Parrot Canada Visa Consultant Co. Ltd</div>
                        <div class='representative-info'>
                            <p><strong>Representative Name:</strong> Dr Jean Pierre Twajamahoro</p>
                            <p><strong>Position:</strong> Owner & Managing Director</p>
                        </div>
                        <div class='signature-label'>AUTHORIZED SIGNATURE</div>
                        <div class='signature-area'>
                            <div style='color: #999; font-style: italic;'>Signature Area (120px height, no border)</div>
                        </div>
                        <div class='date-line'>Signed on: 2026-04-09</div>
                    </div>
                </div>
            </div>
        </div>
        
        <div class='updated'>
            <h3>Font Size Improvements Summary:</h3>
            <div style='display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; margin: 15px 0;'>
                <div style='background: #f8f9fa; padding: 10px; border-radius: 4px; text-align: center;'>
                    <div style='font-size: 22pt; font-weight: bold; color: #0d47a1;'>H1: 22pt</div>
                    <div style='font-size: 11pt; color: #666;'>Main Title</div>
                </div>
                <div style='background: #f8f9fa; padding: 10px; border-radius: 4px; text-align: center;'>
                    <div style='font-size: 16pt; font-weight: bold; color: #0d47a1;'>H2: 16pt</div>
                    <div style='font-size: 11pt; color: #666;'>Section Headers</div>
                </div>
                <div style='background: #f8f9fa; padding: 10px; border-radius: 4px; text-align: center;'>
                    <div style='font-size: 14pt; font-weight: bold; color: #1a1a1a;'>H3: 14pt</div>
                    <div style='font-size: 11pt; color: #666;'>Subsections</div>
                </div>
                <div style='background: #f8f9fa; padding: 10px; border-radius: 4px; text-align: center;'>
                    <div style='font-size: 13pt; font-weight: bold; color: #1a1a1a;'>H4: 13pt</div>
                    <div style='font-size: 11pt; color: #666;'>Party Names</div>
                </div>
                <div style='background: #f8f9fa; padding: 10px; border-radius: 4px; text-align: center;'>
                    <div style='font-size: 12pt; color: #1a1a1a;'>Body: 12pt</div>
                    <div style='font-size: 11pt; color: #666;'>Paragraph Text</div>
                </div>
                <div style='background: #f8f9fa; padding: 10px; border-radius: 4px; text-align: center;'>
                    <div style='font-size: 11pt; color: #424242;'>Info: 11pt</div>
                    <div style='font-size: 11pt; color: #666;'>Representative Info</div>
                </div>
            </div>
        </div>
        
        <div class='updated'>
            <h3>Signature Area Improvements:</h3>
            <ul>
                <li><strong>Border Removal:</strong> Clean, professional appearance without distracting borders</li>
                <li><strong>Size Increase:</strong> 50% larger (80px 120px) for better signature visibility</li>
                <li><strong>Image Scaling:</strong> Signature images now display up to 100px height</li>
                <li><strong>Background:</strong> Clean white background for better contrast</li>
                <li><strong>Padding:</strong> Increased padding (5px 10px) for better spacing</li>
                <li><strong>Alignment:</strong> Perfect centering of signature content</li>
            </ul>
        </div>
        
        <div style='text-align: center; margin-top: 30px; padding: 20px; background: #e8f5e8; border-radius: 8px;'>
            <h2 style='color: #28a745;'>All Updates Complete!</h2>
            <p><strong>Signature areas are now border-free with larger dimensions, and the entire contract features increased font sizes for better readability.</strong></p>
            <p>Both English and French PDF contracts will display with these enhanced styling improvements.</p>
        </div>
    </div>
</body>
</html>";
?>
