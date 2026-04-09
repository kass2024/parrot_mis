<?php
declare(strict_types=1);

echo "<!DOCTYPE html>
<html lang='en'>
<head>
    <meta charset='UTF-8'>
    <meta name='viewport' content='width=device-width, initial-scale=1.0'>
    <title>Maximum Print Size Test</title>
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
        .maximized {
            background: #fff3cd;
            padding: 15px;
            border-radius: 8px;
            margin: 10px 0;
            border-left: 4px solid #ffc107;
        }
        .size-evolution {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 15px;
            margin: 20px 0;
        }
        .size-stage {
            padding: 15px;
            border-radius: 8px;
            text-align: center;
        }
        .stage-1 { background: #f8f9fa; border-left: 4px solid #6c757d; }
        .stage-2 { background: #e3f2fd; border-left: 4px solid #2196f3; }
        .stage-3 { background: #e8f5e8; border-left: 4px solid #28a745; }
        .stage-4 { background: #fff3cd; border-left: 4px solid #ffc107; }
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
            min-height: 250px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 8pt 0;
            background: #ffffff;
            padding: 20px;
            position: relative;
        }
        .signature-img {
            max-height: 250px;
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
            background: #ffc107;
            color: #212529;
            padding: 2px 6px;
            border-radius: 3px;
            font-size: 9pt;
            font-weight: bold;
        }
        .print-optimized {
            background: linear-gradient(45deg, #ffc107, #ff9800);
            color: #212529;
            padding: 20px;
            border-radius: 8px;
            font-weight: bold;
            text-align: center;
            box-shadow: 0 4px 8px rgba(0,0,0,0.2);
            margin: 15px 0;
        }
        .comparison-box {
            background: #f8faff;
            border: 2px solid #ffc107;
            padding: 20px;
            border-radius: 8px;
            text-align: center;
            margin: 15px 0;
        }
    </style>
</head>
<body>
    <div class='container'>
        <h1>Maximum Print Size Implementation</h1>
        <p><strong>Objective:</strong> Make employer image as visible as possible for optimal printing</p>
        
        <div class='maximized'>
            <h3>Maximum Size Enhancements Applied:</h3>
            <ul>
                <li><strong>Signature Area Height:</strong> Increased from 180px to 250px (39% larger)</li>
                <li><strong>Image Max Height:</strong> Increased from 200px to 250px (25% larger)</li>
                <li><strong>Padding:</strong> Increased from 15px to 20px (more breathing room)</li>
                <li><strong>Print Optimization:</strong> Maximum dimensions for print clarity</li>
                <li><strong>Aspect Ratio:</strong> Preserved with object-fit: contain</li>
            </ul>
        </div>
        
        <div class='size-evolution'>
            <div class='size-stage stage-1'>
                <h4>Stage 1</h4>
                <div style='font-size: 24px; font-weight: bold; color: #6c757d;'>80px</div>
                <div style='font-size: 11px; color: #666;'>Original</div>
            </div>
            <div class='size-stage stage-2'>
                <h4>Stage 2</h4>
                <div style='font-size: 28px; font-weight: bold; color: #2196f3;'>120px</div>
                <div style='font-size: 11px; color: #666;'>First Increase</div>
            </div>
            <div class='size-stage stage-3'>
                <h4>Stage 3</h4>
                <div style='font-size: 32px; font-weight: bold; color: #28a745;'>180px</div>
                <div style='font-size: 11px; color: #666;'>Second Increase</div>
            </div>
            <div class='size-stage stage-4'>
                <h4>Stage 4</h4>
                <div style='font-size: 36px; font-weight: bold; color: #ffc107;'>250px</div>
                <div style='font-size: 11px; color: #666;'>MAXIMUM</div>
            </div>
        </div>
        
        <div class='comparison-box'>
            <h3>Size Comparison Visualization</h3>
            <div style='display: flex; justify-content: space-around; align-items: center; margin: 20px 0;'>
                <div style='text-align: center;'>
                    <div style='width: 80px; height: 80px; background: #f0f0f0; border: 2px solid #ccc; display: flex; align-items: center; justify-content: center; margin-bottom: 5px;'>
                        <div style='font-size: 10px; color: #666;'>80px</div>
                    </div>
                </div>
                <div style='font-size: 18px; color: #666;'>+</div>
                <div style='text-align: center;'>
                    <div style='width: 120px; height: 120px; background: #e3f2fd; border: 2px solid #2196f3; display: flex; align-items: center; justify-content: center; margin-bottom: 5px;'>
                        <div style='font-size: 11px; color: #2196f3;'>120px</div>
                    </div>
                </div>
                <div style='font-size: 18px; color: #666;'>+</div>
                <div style='text-align: center;'>
                    <div style='width: 180px; height: 180px; background: #e8f5e8; border: 2px solid #28a745; display: flex; align-items: center; justify-content: center; margin-bottom: 5px;'>
                        <div style='font-size: 12px; color: #28a745;'>180px</div>
                    </div>
                </div>
                <div style='font-size: 18px; color: #666;'>=</div>
                <div style='text-align: center;'>
                    <div style='width: 250px; height: 250px; background: #fff3cd; border: 3px solid #ffc107; display: flex; align-items: center; justify-content: center; margin-bottom: 5px;'>
                        <div style='font-size: 14px; color: #ffc107; font-weight: bold;'>250px MAX</div>
                    </div>
                </div>
            </div>
            <div style='font-weight: bold; color: #212529; margin-top: 10px;'>312% Size Increase from Original!</div>
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
                            <div class='size-indicator'>250px × 100%</div>
                            <div style='color: #999; font-style: italic; text-align: center;'>
                                <div style='font-size: 18px; margin-bottom: 10px;'>Client Signature</div>
                                <div style='font-size: 12px; color: #666;'>(Standard signature area)</div>
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
                            <div class='size-indicator'>250px × 100%</div>
                            <div class='print-optimized signature-img'>
                                <div style='font-size: 28px; margin-bottom: 15px; font-weight: bold;'>PARROT CANADA</div>
                                <div style='font-size: 20px; margin-bottom: 10px; font-weight: bold;'>VISA CONSULTANT</div>
                                <div style='font-size: 16px; border-top: 3px solid #212529; padding-top: 10px; margin-top: 10px; font-weight: bold;'>Dr. Jean Pierre Twajamahoro</div>
                                <div style='font-size: 14px; margin-top: 8px;'>Owner & Managing Director</div>
                                <div style='font-size: 12px; margin-top: 5px; font-style: italic;'>MAXIMUM SIZE FOR PRINTING</div>
                            </div>
                        </div>
                        <div class='date-line'>Signed on: 2026-04-09</div>
                    </div>
                </div>
            </div>
        </div>
        
        <div class='maximized'>
            <h3>Maximum Print Specifications:</h3>
            <div style='display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 15px; margin: 15px 0;'>
                <div style='background: #f8f9fa; padding: 15px; border-radius: 4px;'>
                    <h4 style='color: #ffc107; margin-top: 0;'>Signature Area</h4>
                    <ul style='margin: 10px 0; padding-left: 20px;'>
                        <li><strong>Height:</strong> 250px (MAXIMUM)</li>
                        <li><strong>Width:</strong> 100% of container</li>
                        <li><strong>Padding:</strong> 20px all around</li>
                        <li><strong>Background:</strong> Clean white</li>
                        <li><strong>Total Space:</strong> 290px with padding</li>
                    </ul>
                </div>
                <div style='background: #f8f9fa; padding: 15px; border-radius: 4px;'>
                    <h4 style='color: #ffc107; margin-top: 0;'>Employer Image</h4>
                    <ul style='margin: 10px 0; padding-left: 20px;'>
                        <li><strong>Max Height:</strong> 250px (MAXIMUM)</li>
                        <li><strong>Max Width:</strong> 100% (full width)</li>
                        <li><strong>Object Fit:</strong> contain (proportional)</li>
                        <li><strong>Vertical Fill:</strong> 100% of area</li>
                        <li><strong>Print Quality:</strong> Optimal resolution</li>
                    </ul>
                </div>
                <div style='background: #f8f9fa; padding: 15px; border-radius: 4px;'>
                    <h4 style='color: #ffc107; margin-top: 0;'>Print Benefits</h4>
                    <ul style='margin: 10px 0; padding-left: 20px;'>
                        <li><strong>Visibility:</strong> 312% larger than original</li>
                        <li><strong>Clarity:</strong> Maximum detail visibility</li>
                        <li><strong>Professional:</strong> Ultimate impact</li>
                        <li><strong>Authority:</strong> Maximum presence</li>
                        <li><strong>Quality:</strong> Best print reproduction</li>
                    </ul>
                </div>
            </div>
        </div>
        
        <div class='maximized'>
            <h3>Impact on Printing:</h3>
            <ul>
                <li><strong>Maximum Visibility:</strong> Employer signature now utilizes 250px of vertical space - the maximum practical size for PDF printing</li>
                <li><strong>Optimal Print Quality:</strong> Large image size ensures excellent resolution and clarity when printed</li>
                <li><strong>Professional Authority:</strong> Maximum size conveys the highest level of importance and authenticity</li>
                <li><strong>Enhanced Readability:</strong> All stamp details, text, and signature elements are clearly visible in print</li>
                <li><strong>Document Impact:</strong> Creates a strong, professional impression in printed contracts</li>
                <li><strong>Consistent Quality:</strong> Both English and French contracts maintain the same maximum size standards</li>
            </ul>
        </div>
        
        <div style='text-align: center; margin-top: 30px; padding: 20px; background: linear-gradient(45deg, #ffc107, #ff9800); border-radius: 8px; color: #212529;'>
            <h2 style='color: #212529;'>MAXIMUM PRINT SIZE IMPLEMENTED!</h2>
            <p><strong>The employer signature image is now at maximum size (250px) for optimal printing visibility and impact.</strong></p>
            <p>This represents a 312% increase from the original size, ensuring the Parrot Canada signature is prominently visible in all printed documents.</p>
        </div>
    </div>
</body>
</html>";
?>
