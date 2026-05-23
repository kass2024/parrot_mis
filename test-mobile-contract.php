<?php
declare(strict_types=1);

/**
 * Mobile Responsiveness Test for Student Contract
 * This file helps test the mobile responsiveness fixes
 */

require_once __DIR__ . '/db.php';

// Create a test token for demonstration
$testToken = 'test-mobile-' . time();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Mobile Contract Test</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<style>
/* Test styles for mobile verification */
body {
  margin: 0;
  padding: 8px;
  background: linear-gradient(180deg, #f8fafc, #e2e8f0);
  font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
}

.test-container {
  max-width: 100%;
  margin: 0 auto;
  background: #ffffff;
  padding: 20px 16px;
  box-shadow: 0 4px 20px rgba(0,0,0,0.1);
  border-radius: 12px;
}

.test-section {
  margin-bottom: 24px;
  padding: 16px;
  border: 1px solid #e5e7eb;
  border-radius: 8px;
}

.test-title {
  font-size: 18px;
  font-weight: 700;
  margin-bottom: 12px;
  color: #111827;
}

.test-description {
  font-size: 14px;
  color: #6b7280;
  margin-bottom: 16px;
}

.test-button {
  background: #3b82f6;
  color: #ffffff;
  border: none;
  padding: 12px 24px;
  border-radius: 8px;
  font-size: 16px;
  font-weight: 600;
  cursor: pointer;
  margin: 8px 4px;
  min-height: 48px;
  width: 100%;
  max-width: 300px;
}

.test-input {
  width: 100%;
  padding: 14px 16px;
  border: 2px solid #e5e7eb;
  border-radius: 8px;
  font-size: 16px;
  margin-bottom: 12px;
  box-sizing: border-box;
}

.test-input:focus {
  outline: none;
  border-color: #3b82f6;
  box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
}

.signature-test {
  border: 2px dashed #cbd5e1;
  height: 150px;
  border-radius: 8px;
  background: #ffffff;
  display: flex;
  align-items: center;
  justify-content: center;
  margin: 16px 0;
}

.responsive-info {
  background: #f0f9ff;
  border: 1px solid #3b82f6;
  border-radius: 8px;
  padding: 12px;
  margin-bottom: 16px;
}

.responsive-info strong {
  color: #1e40af;
}

@media (min-width: 768px) {
  .test-container {
    padding: 40px 48px;
  }
  
  .test-button {
    width: auto;
    margin: 8px 4px;
  }
  
  .signature-test {
    height: 180px;
  }
}

@media (min-width: 1024px) {
  .test-container {
    padding: 64px 72px;
  }
  
  .signature-test {
    height: 200px;
  }
}
</style>
</head>
<body>

<div class="test-container">

<h1 style="text-align:center; font-size:24px; margin-bottom:8px;">
  📱 Mobile Responsiveness Test
</h1>
<p style="text-align:center; color:#6b7280; margin-bottom:32px;">
  Student Contract Submission System
</p>

<div class="responsive-info">
  <strong>🔍 Testing Mobile Features:</strong><br>
  • Signature canvas responsiveness<br>
  • Input field sizing (16px to prevent iOS zoom)<br>
  • Button touch targets (48px min height)<br>
  • Text wrapping and stacking prevention<br>
  • Grid layout adaptation
</div>

<div class="test-section">
  <div class="test-title">📝 Form Input Test</div>
  <div class="test-description">
    Test input fields should be large enough for mobile use and prevent zoom on iOS
  </div>
  <input type="email" class="test-input" placeholder="Email address (16px font)">
  <input type="text" class="test-input" placeholder="Full name (16px font)">
  <input type="tel" class="test-input" placeholder="Phone number (16px font)">
</div>

<div class="test-section">
  <div class="test-title">✍️ Signature Area Test</div>
  <div class="test-description">
    Signature canvas should adapt to screen size with proper touch handling
  </div>
  <div class="signature-test">
    <span style="color:#9ca3af;">Signature Canvas Area</span>
  </div>
</div>

<div class="test-section">
  <div class="test-title">🔘 Button Test</div>
  <div class="test-description">
    Buttons should have minimum 48px height for touch targets
  </div>
  <button class="test-button">Clear Signature</button>
  <button class="test-button" style="background:#10b981;">Sign & Submit</button>
</div>

<div class="test-section">
  <div class="test-title">📱 Viewport Information</div>
  <div class="test-description">
    Current viewport dimensions for testing
  </div>
  <div id="viewport-info" style="font-family:monospace; font-size:14px;">
    Loading...
  </div>
</div>

<div class="test-section">
  <div class="test-title">🔗 Test Links</div>
  <div class="test-description">
    Test the actual contract with different screen sizes
  </div>
  <a href="student-contract.php?token=<?= htmlspecialchars($testToken) ?>" 
     style="display:inline-block; background:#3b82f6; color:#ffffff; padding:12px 24px; 
            text-decoration:none; border-radius:8px; margin:8px 4px;">
    Test Contract Page
  </a>
</div>

</div>

<script>
// Display viewport information
function updateViewportInfo() {
  const info = document.getElementById('viewport-info');
  info.innerHTML = `
    Screen Width: ${window.screen.width}px<br>
    Screen Height: ${window.screen.height}px<br>
    Viewport Width: ${window.innerWidth}px<br>
    Viewport Height: ${window.innerHeight}px<br>
    Device Pixel Ratio: ${window.devicePixelRatio || 1}<br>
    User Agent: ${navigator.userAgent.substring(0, 50)}...
  `;
}

updateViewportInfo();
window.addEventListener('resize', updateViewportInfo);

// Test signature canvas interaction
document.querySelector('.signature-test').addEventListener('click', function() {
  this.style.background = '#f0f9ff';
  setTimeout(() => {
    this.style.background = '#ffffff';
  }, 200);
});

// Test button interactions
document.querySelectorAll('.test-button').forEach(button => {
  button.addEventListener('click', function() {
    const originalText = this.textContent;
    this.textContent = '✓ Clicked!';
    this.style.background = '#10b981';
    setTimeout(() => {
      this.textContent = originalText;
      this.style.background = originalText.includes('Clear') ? '#3b82f6' : '#10b981';
    }, 1000);
  });
});
</script>

</body>
</html>
