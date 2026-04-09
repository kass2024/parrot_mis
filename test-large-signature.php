<?php
declare(strict_types=1);

echo "<!DOCTYPE html>
<html lang='en'>
<head>
    <meta charset='UTF-8'>
    <meta name='viewport' content='width=device-width, initial-scale=1.0'>
    <title>Large Signature Test</title>
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
        .comparison {
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
            min-height: 180px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 8pt 0;
            background: #ffffff;
            padding: 15px;
            position: relative;
        }
        .signature-img {
            max-height: 150px;
            max-width: 100%;
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
        .size-indicator {
            position: absolute;
            top: 5px;
            right: 5px;
            background: #2196f3;
            color: white;
            padding: 2px 6px;
            border-radius: 3px;
            font-size: 9pt;
            font-weight: bold;
        }
    </style>
</head>
<body>
    <div class='container'>
        <h1>Large Signature Area Implementation</h1>
        <p><strong>Objective:</strong> Make the Parrot Canada signature stamp much bigger and more visible</p>
        
        <div class='enhanced'>
            <h3>Signature Area Enhancements Applied:</h3>
            <ul>
                <li><strong>Area Height:</strong> Increased from 120px to 180px (50% larger)</li>
                <li><strong>Image Max Height:</strong> Increased from 100px to 150px (50% larger)</li>
                <li><strong>Image Width:</strong> Increased from 95% to 100% (full width)</li>
                <li><strong>Padding:</strong> Increased from 10px to 15px (more space)</li>
                <li><strong>Print Optimization:</strong> All changes preserved in PDF output</li>
            </ul>
        </div>
        
        <div class='comparison'>
            <div class='before'>
                <h3>Before (Small Signature)</h3>
                <ul>
                    <li>Signature area: 120px height</li>
                    <li>Image max height: 100px</li>
                    <li>Image width: 95%</li>
                    <li>Padding: 10px</li>
                    <li>Stamp barely visible</li>
                    <li>Limited space for signature</li>
                </ul>
            </div>
            <div class='after'>
                <h3>After (Large Signature)</h3>
                <ul>
                    <li>Signature area: 180px height</li>
                    <li>Image max height: 150px</li>
                    <li>Image width: 100%</li>
                    <li>Padding: 15px</li>
                    <li>Stamp highly visible</li>
                    <li>Ample space for signature</li>
                </ul>
            </div>
        </div>
        
        <div style='margin-top: 25pt; padding: 15pt 0; border-top: 2px solid #e3f2fd;'>
            <h2 style='font-size: 16pt; font-weight: bold; margin-top: 2pt; margin-bottom: 1pt; color: #0d47a1; border-bottom: 1px solid #1a1a1a; padding-bottom: 0pt;'>16. SIGNATURES</h2>
            <div class='signature-demo'>
                <div class='signature-block'>
                    <div class='signature-box'>
                        <div class='company-title'>Test Company Ltd</div>
                        <div class='representative-info'>
                            <p><strong>Representative Name:</strong> John Doe</p>
                            <p><strong>Position:</strong> Director</p>
                        </div>
                        <div class='signature-label'>AUTHORIZED SIGNATURE</div>
                        <div class='signature-area'>
                            <div class='size-indicator'>180px × 100%</div>
                            <div style='color: #999; font-style: italic; text-align: center;'>
                                <div style='font-size: 24px; margin-bottom: 10px;'>Sample Signature</div>
                                <div style='font-size: 14px; color: #666;'>(Large area for clear signature display)</div>
                            </div>
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
                            <div class='size-indicator'>180px × 100%</div>
                            <div style='color: #999; font-style: italic; text-align: center;'>
                                <div style='font-size: 32px; font-weight: bold; color: #0d47a1; margin-bottom: 15px;'>PARROT CANADA</div>
                                <div style='font-size: 18px; color: #666; margin-bottom: 10px;'>Official Stamp</div>
                                <div style='font-size: 14px; color: #999;'>(Now much bigger and visible!)</div>
                            </div>
                        </div>
                        <div class='date-line'>Signed on: 2026-04-09</div>
                    </div>
                </div>
            </div>
        </div>
        
        <div class='enhanced'>
            <h3>Technical Specifications:</h3>
            <div style='display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 15px; margin: 15px 0;'>
                <div style='background: #f8f9fa; padding: 15px; border-radius: 4px;'>
                    <h4 style='color: #0d47a1; margin-top: 0;'>Signature Area Dimensions</h4>
                    <ul style='margin: 10px 0; padding-left: 20px;'>
                        <li><strong>Height:</strong> 180px (was 120px)</li>
                        <li><strong>Padding:</strong> 15px all around</li>
                        <li><strong>Background:</strong> Clean white</li>
                        <li><strong>Borders:</strong> None (clean look)</li>
                    </ul>
                </div>
                <div style='background: #f8f9fa; padding: 15px; border-radius: 4px;'>
                    <h4 style='color: #0d47a1; margin-top: 0;'>Image/Stamp Dimensions</h4>
                    <ul style='margin: 10px 0; padding-left: 20px;'>
                        <li><strong>Max Height:</strong> 150px (was 100px)</li>
                        <li><strong>Max Width:</strong> 100% (was 95%)</li>
                        <li><strong>Object Fit:</strong> contain (proportional)</li>
                        <li><strong>Display:</strong> Block, centered</li>
                    </ul>
                </div>
                <div style='background: #f8f9fa; padding: 15px; border-radius: 4px;'>
                    <h4 style='color: #0d47a1; margin-top: 0;'>Benefits Achieved</h4>
                    <ul style='margin: 10px 0; padding-left: 20px;'>
                        <li><strong>Visibility:</strong> 50% larger stamp display</li>
                        <li><strong>Clarity:</strong> More space for details</li>
                        <li><strong>Professional:</strong> Clean, uncluttered look</li>
                        <li><strong>Accessibility:</strong> Easier to read signature</li>
                    </ul>
                </div>
            </div>
        </div>
        
        <div class='enhanced'>
            <h3>Impact on Parrot Canada Signature:</h3>
            <ul>
                <li><strong>Stamp Visibility:</strong> The official stamp will now be 50% larger and much more prominent</li>
                <li><strong>Signature Clarity:</strong> Dr. Jean Pierre Twajamahoro's signature will be clearly visible</li>
                <li><strong>Professional Appearance:</strong> Larger signature area conveys importance and authority</li>
                <li><strong>Print Quality:</strong> Enhanced resolution and clarity in PDF output</li>
                <li><strong>Consistency:</strong> Both English and French contracts benefit from the same enhancement</li>
            </ul>
        </div>
        
        <div style='text-align: center; margin-top: 30px; padding: 20px; background: #e8f5e8; border-radius: 8px;'>
            <h2 style='color: #28a745;'>Large Signature Implementation Complete!</h2>
            <p><strong>The Parrot Canada signature area is now significantly larger (180px height) with 150px maximum image size,</strong></p>
            <p>ensuring the official stamp and Dr. Jean Pierre Twajamahoro's signature are clearly visible and prominent in the PDF contracts.</p>
        </div>
    </div>
</body>
</html>";
?>
