<?php
declare(strict_types=1);

// Enable all error reporting
ini_set('display_errors', 1);
ini_set('log_errors', 1);
error_reporting(E_ALL);

// Increase limits for cPanel
ini_set('memory_limit', '512M');
set_time_limit(300);

header("Content-Type: application/json");

function logDebug(string $message, array $data = []): void {
    $logFile = __DIR__ . "/logs/debug-" . date("Y-m-d") . ".log";
    $logDir = dirname($logFile);
    if (!is_dir($logDir)) {
        mkdir($logDir, 0755, true);
    }
    file_put_contents(
        $logFile,
        "[" . date("Y-m-d H:i:s") . "] DEBUG: $message " . json_encode($data) . PHP_EOL,
        FILE_APPEND
    );
}

function respond(array $payload, int $code = 200): void {
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function fail(string $message, int $code = 400, array $debug = []): void {
    logDebug("FAILED: $message", $debug);
    respond(["success" => false, "error" => $message, "debug" => $debug], $code);
}

logDebug("Submit script started", ["timestamp" => date("Y-m-d H:i:s")]);

try {
    // Test 1: Check if required files exist
    if (!file_exists(__DIR__ . '/db1.php')) {
        fail("Database file not found", 500, ["file" => "db1.php"]);
    }
    
    if (!file_exists(__DIR__ . '/vendor/autoload.php')) {
        fail("Vendor autoload not found", 500, ["file" => "vendor/autoload.php"]);
    }
    
    logDebug("Required files check passed");
    
    // Test 2: Load dependencies
    require_once __DIR__ . '/db1.php';
    require_once __DIR__ . '/vendor/autoload.php';
    
    logDebug("Dependencies loaded");
    
    // Test 3: Database connection
    $conn = new mysqli('premium120.web-hosting.com', 'visaeofi_mis_user', 'Petero@1981', 'visaeofi_mis');
    
    if ($conn->connect_error) {
        fail("Database connection failed", 500, [
            "error" => $conn->connect_error,
            "host" => "premium120.web-hosting.com",
            "user" => "visaeofi_mis_user"
        ]);
    }
    
    logDebug("Database connected successfully");
    
    // Test 4: Check input
    $raw = file_get_contents("php://input");
    if (empty($raw)) {
        fail("No input data received", 400);
    }
    
    logDebug("Raw input received", ["length" => strlen($raw)]);
    
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        fail("Invalid JSON format", 400, ["raw" => substr($raw, 0, 200)]);
    }
    
    logDebug("JSON parsed successfully", ["keys" => array_keys($data)]);
    
    // Test 5: Validate required fields
    $required = ['token', 'representative_name', 'representative_email', 'signed_date', 'signature', 'company_name'];
    $missing = [];
    
    foreach ($required as $field) {
        if (empty(trim($data[$field] ?? ''))) {
            $missing[] = $field;
        }
    }
    
    if (!empty($missing)) {
        fail("Missing required fields", 400, ["missing" => $missing]);
    }
    
    logDebug("Required fields validated");
    
    // Test 6: Check contract exists
    $token = trim($data['token']);
    $stmt = $conn->prepare("SELECT id, status FROM partner_contracts WHERE contract_token = ? LIMIT 1");
    $stmt->bind_param("s", $token);
    $stmt->execute();
    $contract = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    if (!$contract) {
        fail("Contract not found", 404, ["token" => $token]);
    }
    
    logDebug("Contract found", ["contract_id" => $contract['id'], "status" => $contract['status']]);
    
    // Test 7: Check if already signed
    if ($contract['status'] === 'signed') {
        respond([
            "success" => true,
            "status" => "already_signed",
            "message" => "This contract has already been signed."
        ]);
    }
    
    logDebug("Contract ready for signing");
    
    // Test 8: Begin transaction
    $conn->begin_transaction();
    logDebug("Transaction started");
    
    try {
        // Insert signature
        $contractId = (int) $contract['id'];
        $stmt = $conn->prepare("
            INSERT INTO partner_signatures
            (contract_id, company_name, representative_name, representative_email, signed_date, signature_image, created_at)
            VALUES (?, ?, ?, ?, ?, ?, NOW())
        ");
        
        $stmt->bind_param("isssss", 
            $contractId, 
            $data['company_name'], 
            $data['representative_name'], 
            $data['representative_email'], 
            $data['signed_date'], 
            $data['signature']
        );
        
        $stmt->execute();
        $stmt->close();
        
        logDebug("Signature inserted");
        
        // Update contract
        $updateSql = "UPDATE partner_contracts SET 
            company_name = ?, company_email = ?, company_phone = ?, company_address = ?,
            representative_name = ?, representative_title = ?, representative_email = ?,
            signed_date = ?, status = 'signed', signed_at = NOW(), signature_image = ?
            WHERE id = ? AND contract_token = ?";

        $stmt = $conn->prepare($updateSql);
        $stmt->bind_param("sssssssssis", 
            $data['company_name'], 
            $data['representative_email'], 
            $data['company_phone'] ?? '', 
            $data['company_address'] ?? '',
            $data['representative_name'], 
            $data['representative_title'] ?? '', 
            $data['representative_email'],
            $data['signed_date'], 
            $data['signature'], 
            $contractId, 
            $token
        );
        
        $stmt->execute();
        $stmt->close();
        
        logDebug("Contract updated");
        
        $conn->commit();
        logDebug("Transaction committed");
        
        // Test 9: Generate PDF (optional - may fail without affecting submission)
        $pdfPath = null;
        try {
            $stmt = $conn->prepare("SELECT language FROM partner_contracts WHERE id = ?");
            $stmt->bind_param("i", $contractId);
            $stmt->execute();
            $result = $stmt->get_result();
            $contractData = $result->fetch_assoc();
            $stmt->close();
            
            $language = $contractData['language'] ?? 'english';
            
            if ($language === 'french') {
                if (file_exists(__DIR__ . '/generate-partner-contract-pdf-french-professional.php')) {
                    require_once __DIR__ . '/generate-partner-contract-pdf-french-professional.php';
                    $pdfPath = generatePartnerContractPDFFrench($contractId);
                }
            } else {
                if (file_exists(__DIR__ . '/generate-partner-contract-pdf-professional.php')) {
                    require_once __DIR__ . '/generate-partner-contract-pdf-professional.php';
                    $pdfPath = generatePartnerContractPDF($contractId);
                }
            }
            
            logDebug("PDF generation attempted", ["success" => !empty($pdfPath), "path" => $pdfPath]);
            
        } catch (Exception $pdfError) {
            logDebug("PDF generation failed", ["error" => $pdfError->getMessage()]);
            // Don't fail the entire submission if PDF generation fails
        }
        
        respond([
            "success" => true,
            "status" => "signed",
            "contract_id" => $contractId,
            "pdf_generated" => !empty($pdfPath),
            "debug_info" => "Debug version with enhanced logging"
        ]);
        
    } catch (Exception $e) {
        $conn->rollback();
        fail("Transaction failed: " . $e->getMessage(), 500, [
            "exception" => $e->getMessage(),
            "file" => $e->getFile(),
            "line" => $e->getLine()
        ]);
    }
    
} catch (Exception $e) {
    fail("System error: " . $e->getMessage(), 500, [
        "exception" => $e->getMessage(),
        "file" => $e->getFile(),
        "line" => $e->getLine(),
        "trace" => explode("\n", $e->getTraceAsString())
    ]);
}
?>
