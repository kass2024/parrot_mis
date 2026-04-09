<?php
declare(strict_types=1);

echo "<!DOCTYPE html>
<html lang='en'>
<head>
    <meta charset='UTF-8'>
    <meta name='viewport' content='width=device-width, initial-scale=1.0'>
    <title>Employer Image Size Test</title>
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
            max-height: 200px;
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
        .image-demo {
            background: #f8faff;
            border: 2px dashed #90caf9;
            padding: 20px;
            border-radius: 8px;
            text-align: center;
            margin: 15px 0;
        }
        .mock-stamp {
            background: linear-gradient(45deg, #0d47a1, #1976d2);
            color: white;
            padding: 20px;
            border-radius: 8px;
            font-weight: bold;
            text-align: center;
            box-shadow: 0 4px 8px rgba(0,0,0,0.2);
        }
    </style>
</head>
<body>
    <div class='container'>
        <h1>Employer Image Size Enhancement</h1>
        <p><strong>Objective:</strong> Increase employer image size to fill vertical parent space and maximize width</p>
        
        <div class='enhanced'>
            <h3>Employer Image Enhancements Applied:</h3>
            <ul>
                <li><strong>Image Max Height:</strong> Increased from 150px to 200px (33% larger)</li>
                <li><strong>Image Max Width:</strong> Maintained at 100% (full width utilization)</li>
                <li><strong>Vertical Fill:</strong> Better utilization of 180px signature area</li>
                <li><strong>Aspect Ratio:</strong> Preserved with object-fit: contain</li>
                <li><strong>Print Optimization:</strong> All changes preserved in PDF output</li>
            </ul>
        </div>
        
        <div class='comparison'>
            <div class='before'>
                <h3>Before (Small Image)</h3>
                <ul>
                    <li>Image max height: 150px</li>
                    <li>Vertical space used: ~83%</li>
                    <li>Stamp visibility: Moderate</li>
                    <li>Details: Hard to see</li>
                    <li>Professional impact: Limited</li>
                </ul>
            </div>
            <div class='after'>
                <h3>After (Large Image)</h3>
                <ul>
                    <li>Image max height: 200px</li>
                    <li>Vertical space used: ~100%</li>
                    <li>Stamp visibility: Excellent</li>
                    <li>Details: Clearly visible</li>
                    <li>Professional impact: Strong</li>
                </ul>
            </div>
        </div>
        
        <div class='image-demo'>
            <h3>Image Size Comparison</h3>
            <div style='display: flex; justify-content: space-around; align-items: center; margin: 20px 0;'>
                <div style='text-align: center;'>
                    <div style='width: 150px; height: 150px; background: #f0f0f0; border: 2px solid #ccc; display: flex; align-items: center; justify-content: center; margin-bottom: 10px;'>
                        <div style='font-size: 12px; color: #666;'>150px × Auto</div>
                    </div>
                    <div style='font-weight: bold; color: #666;'>Before</div>
                </div>
                <div style='font-size: 24px; color: #28a745;'>+</div>
                <div style='text-align: center;'>
                    <div style='width: 200px; height: 200px; background: #e8f5e8; border: 2px solid #28a745; display: flex; align-items: center; justify-content: center; margin-bottom: 10px;'>
                        <div style='font-size: 14px; color: #28a745; font-weight: bold;'>200px × Auto</div>
                    </div>
                    <div style='font-weight: bold; color: #28a745;'>After</div>
                </div>
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
                                <div style='font-size: 20px; margin-bottom: 10px;'>Hand Signature</div>
                                <div style='font-size: 12px; color: #666;'>(Client signature area)</div>
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
                            <div class='mock-stamp signature-img'>
                                <div style='font-size: 24px; margin-bottom: 10px;'>PARROT CANADA</div>
                                <div style='font-size: 16px; margin-bottom: 8px;'>VISA CONSULTANT</div>
                                <div style='font-size: 12px; border-top: 2px solid white; padding-top: 8px; margin-top: 8px;'>Dr. Jean Pierre Twajamahoro</div>
                                <div style='font-size: 10px; margin-top: 5px;'>Owner & Managing Director</div>
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
                    <h4 style='color: #0d47a1; margin-top: 0;'>Signature Area</h4>
                    <ul style='margin: 10px 0; padding-left: 20px;'>
                        <li><strong>Height:</strong> 180px (fixed)</li>
                        <li><strong>Width:</strong> 100% of container</li>
                        <li><strong>Padding:</strong> 15px all around</li>
                        <li><strong>Background:</strong> Clean white</li>
                    </ul>
                </div>
                <div style='background: #f8f9fa; padding: 15px; border-radius: 4px;'>
                    <h4 style='color: #0d47a1; margin-top: 0;'>Employer Image</h4>
                    <ul style='margin: 10px 0; padding-left: 20px;'>
                        <li><strong>Max Height:</strong> 200px (was 150px)</li>
                        <li><strong>Max Width:</strong> 100% (full width)</li>
                        <li><strong>Object Fit:</strong> contain (proportional)</li>
                        <li><strong>Vertical Fill:</strong> ~100% of area</li>
                    </ul>
                </div>
                <div style='background: #f8f9fa; padding: 15px; border-radius: 4px;'>
                    <h4 style='color: #0d47a1; margin-top: 0;'>Benefits</h4>
                    <ul style='margin: 10px 0; padding-left: 20px;'>
                        <li><strong>Visibility:</strong> 33% larger image</li>
                        <li><strong>Clarity:</strong> Better detail visibility</li>
                        <li><strong>Professional:</strong> Stronger impact</li>
                        <li><strong>Authority:</strong> More prominent signature</li>
                    </ul>
                </div>
            </div>
        </div>
        
        <div class='enhanced'>
            <h3>Impact on Employer Signature:</h3>
            <ul>
                <li><strong>Maximum Vertical Utilization:</strong> Image now fills almost the entire 180px signature area height</li>
                <li><strong>Full Width Usage:</strong> Image utilizes 100% of available horizontal space</li>
                <li><strong>Enhanced Professional Appearance:</strong> Larger signature conveys greater authority</li>
                <li><strong>Improved Readability:</strong> Stamp details and text are clearly visible</li>
                <li><strong>Better Print Quality:</strong> Larger image reproduces better in PDF output</li>
                <li><strong>Consistent Scaling:</strong> Aspect ratio maintained for proper proportions</li>
            </ul>
        </div>
        
        <div style='text-align: center; margin-top: 30px; padding: 20px; background: #e8f5e8; border-radius: 8px;'>
            <h2 style='color: #28a745;'>Employer Image Size Enhancement Complete!</h2>
            <p><strong>The employer signature image is now significantly larger (200px max height) and fills the vertical parent space completely,</strong></p>
            <p>while utilizing full width for maximum visibility and professional impact in both English and French PDF contracts.</p>
        </div>
    </div>
</body>
</html>";
?>
