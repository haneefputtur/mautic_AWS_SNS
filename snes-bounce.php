<?php
// AWS SNS/SES Bounce/Complaint handler for Mautic by Haneef Puttur

// 1. Configuration
class Config {
    const MAUTIC_URL = "https://xxxxxxxxxx";
    const API_USERNAME = "xxxxxxxxxxx";
    const API_PASSWORD = "yyyyyyyyyy";
    const LOG_FILE = "sns-log.txt";
    const MAX_LOG_SIZE = 10 * 1024 * 1024; // 10MB
}

// 2. Enhanced logging
class Logger {
    public static function log($message, $level = 'INFO') {
        $timestamp = date("c");
        $logMessage = "[$timestamp][$level] $message\n";
        
        // Rotate log if too large
        if (file_exists(Config::LOG_FILE) && filesize(Config::LOG_FILE) > Config::MAX_LOG_SIZE) {
            rename(Config::LOG_FILE, Config::LOG_FILE . '.old');
        }
        
        file_put_contents(Config::LOG_FILE, $logMessage, FILE_APPEND);
    }
}

// 3. Enhanced error handling
function handleError($errno, $errstr, $errfile, $errline) {
    Logger::log("Error: [$errno] $errstr in $errfile on line $errline", 'ERROR');
    return true;
}
set_error_handler('handleError');

// 4. Main handler class
class SESHandler {
    private $auth;

    public function __construct() {
        $this->auth = base64_encode(Config::API_USERNAME . ":" . Config::API_PASSWORD);
    }

    public function processRequest() {
        try {
            $rawPost = file_get_contents("php://input");
            if (empty($rawPost)) {
                throw new Exception("Empty request received");
            }

            $data = json_decode($rawPost, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new Exception("Invalid JSON received: " . json_last_error_msg());
            }

            Logger::log("Received request: " . substr($rawPost, 0, 1000)); // Log first 1000 chars

            if (isset($data['Type'])) {
                $this->handleSNSMessage($data);
            } else {
                $this->processSESMessage($data);
            }

            return true;
        } catch (Exception $e) {
            Logger::log("Error processing request: " . $e->getMessage(), 'ERROR');
            http_response_code(500);
            return false;
        }
    }

    private function handleSNSMessage($data) {
        switch ($data['Type']) {
            case 'SubscriptionConfirmation':
                $this->confirmSubscription($data['SubscribeURL']);
                break;
            case 'Notification':
                $message = json_decode($data['Message'], true);
                $this->processSESMessage($message);
                break;
            default:
                Logger::log("Unknown SNS message type: " . $data['Type'], 'WARNING');
        }
    }

    private function confirmSubscription($subscribeUrl) {
        Logger::log("Confirming SNS subscription: $subscribeUrl");
        $result = file_get_contents($subscribeUrl);
        if ($result === false) {
            throw new Exception("Failed to confirm SNS subscription");
        }
        Logger::log("SNS subscription confirmed successfully");
    }

    private function processSESMessage($message) {
        if (!isset($message['notificationType'])) {
            throw new Exception("Invalid SES message format");
        }

        switch ($message['notificationType']) {
            case 'Bounce':
                $this->handleBounce($message);
                break;
            case 'Complaint':
                $this->handleComplaint($message);
                break;
            default:
                Logger::log("Unknown notification type: " . $message['notificationType'], 'WARNING');
        }
    }

    private function handleBounce($message) {
        if (isset($message['bounce']['bouncedRecipients'])) {
            foreach ($message['bounce']['bouncedRecipients'] as $recipient) {
                $this->markAsDoNotContact($recipient['emailAddress'], 'bounce', [
                    'bounceType' => $message['bounce']['bounceType'] ?? 'unknown',
                    'diagnosticCode' => $recipient['diagnosticCode'] ?? ''
                ]);
            }
        }
    }

    private function handleComplaint($message) {
        if (isset($message['complaint']['complainedRecipients'])) {
            foreach ($message['complaint']['complainedRecipients'] as $recipient) {
                $this->markAsDoNotContact($recipient['emailAddress'], 'complaint', [
                    'complaintFeedbackType' => $message['complaint']['complaintFeedbackType'] ?? 'unknown'
                ]);
            }
        }
    }

    private function markAsDoNotContact($email, $reason, $details = []) {
        Logger::log("Processing $reason for email: $email");
        
        // Get contact ID
        $contactId = $this->getContactIdByEmail($email);
        if (!$contactId) {
            Logger::log("No contact found for email: $email", 'WARNING');
            return;
        }

        // Prepare DNC data
        $dncData = [
            'reason' => ($reason === 'bounce' ? 1 : 2),
            'channel' => 'email',
            'comments' => $this->formatDNCComment($reason, $details)
        ];

        // Add to DNC list
        $this->addToDNC($contactId, $dncData);
    }

    private function getContactIdByEmail($email) {
        $context = $this->createContext('GET');
        $response = @file_get_contents(
            Config::MAUTIC_URL . "/api/contacts?search=" . urlencode($email),
            false,
            $context
        );

        if ($response === false) {
            Logger::log("API call failed for email search: $email", 'ERROR');
            return null;
        }

        $json = json_decode($response, true);
        return isset($json['contacts']) && !empty($json['contacts']) 
            ? array_key_first($json['contacts']) 
            : null;
    }

    private function addToDNC($contactId, $dncData) {
        $context = $this->createContext('POST', json_encode($dncData));
        $response = @file_get_contents(
            Config::MAUTIC_URL . "/api/contacts/$contactId/dnc/email/add",
            false,
            $context
        );

        if ($response === false) {
            Logger::log("Failed to add contact $contactId to DNC list", 'ERROR');
        } else {
            Logger::log("Successfully added contact $contactId to DNC list");
        }
    }

    private function createContext($method, $content = null) {
        $headers = ["Authorization: Basic {$this->auth}"];
        if ($content) {
            $headers[] = "Content-Type: application/json";
        }

        return stream_context_create([
            "http" => [
                "method" => $method,
                "header" => implode("\r\n", $headers),
                "content" => $content,
                "ignore_errors" => true,
                "timeout" => 30
            ],
            "ssl" => [
                "verify_peer" => true,
                "verify_peer_name" => true
            ]
        ]);
    }

    private function formatDNCComment($reason, $details) {
        $comment = "Automatically added by SES webhook - $reason";
        foreach ($details as $key => $value) {
            $comment .= "\n$key: $value";
        }
        return $comment;
    }
}

// 5. Execute
try {
    $handler = new SESHandler();
    if ($handler->processRequest()) {
        http_response_code(200);
        echo "Request processed successfully";
    } else {
        http_response_code(500);
        echo "Error processing request";
    }
} catch (Exception $e) {
    Logger::log("Fatal error: " . $e->getMessage(), 'FATAL');
    http_response_code(500);
    echo "Fatal error occurred";
}
