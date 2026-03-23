<?php
require_once __DIR__ . '/../config/settings.php';

class EmailSender {
    private $settings;
    private $smtpTimeout = 8;
    private $lastSmtpResponse = '';
    
    public function __construct() {
        $this->settings = include(__DIR__ . '/../config/settings.php');
    }
    
    public function send($to, $subject, $body, $isHtml = true) {
        // Validate email address
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            error_log("Invalid email address: $to");
            return false;
        }

        // Normalize and sanitize fields
        $subject = trim(preg_replace('/[\r\n]+/', ' ', (string)$subject));
        $fromEmail = filter_var($this->settings['mail_from'] ?? '', FILTER_VALIDATE_EMAIL);
        $fromName = trim(preg_replace('/[\r\n]+/', ' ', (string)($this->settings['mail_from_name'] ?? '')));
        if ($fromName === '') {
            $fromName = 'Project Management System';
        }

        if (!$fromEmail) {
            error_log("Invalid from email address in settings");
            return false;
        }

        // Prefer SMTP for shared hosting reliability.
        if ($this->isSmtpConfigured()) {
            try {
                return $this->sendViaSmtp($to, $subject, $body, $isHtml, $fromEmail, $fromName);
            } catch (Throwable $e) {
                error_log('SMTP send failed: ' . $e->getMessage());
                // When SMTP is explicitly configured, do not fall back to localhost mail().
                return false;
            }
        } else {
            error_log('EmailSender: SMTP not configured; falling back to mail()');
        }

        // Fallback to mail() if SMTP is unavailable.
        try {
            $headers = [];
            $headers[] = 'MIME-Version: 1.0';
            $headers[] = $isHtml ? 'Content-type: text/html; charset=utf-8' : 'Content-type: text/plain; charset=utf-8';
            $headers[] = 'From: ' . $fromName . ' <' . $fromEmail . '>';
            $headers[] = 'Reply-To: ' . $fromEmail;
            $headers[] = 'X-Mailer: PHP/' . phpversion();
            $headers[] = 'X-Priority: 3';
            $fullHeaders = implode("\r\n", $headers);
            $ok = mail($to, $subject, $body, $fullHeaders);
            if (!$ok) {
                $lastError = error_get_last();
                $lastMessage = is_array($lastError) ? (string)($lastError['message'] ?? '') : '';
                error_log('mail() send failed for ' . $to . ($lastMessage !== '' ? (': ' . $lastMessage) : ''));
            }
            return $ok;
        } catch (Throwable $e) {
            error_log('Mail send failed: ' . $e->getMessage());
            return false;
        }
    }

    private function isSmtpConfigured() {
        $host = trim((string)($this->settings['smtp_host'] ?? ''));
        $username = trim((string)($this->settings['smtp_username'] ?? ''));
        $password = trim((string)($this->settings['smtp_password'] ?? ''));
        return $host !== '' && $username !== '' && $password !== '';
    }

    private function sendViaSmtp($to, $subject, $body, $isHtml, $fromEmail, $fromName) {
        $host = trim((string)$this->settings['smtp_host']);
        $port = (int)($this->settings['smtp_port'] ?? 587);
        $secure = strtolower(trim((string)($this->settings['smtp_secure'] ?? 'tls')));
        $authValue = $this->settings['smtp_auth'] ?? true;
        if (is_string($authValue)) {
            $auth = !in_array(strtolower(trim($authValue)), ['0', 'false', 'no', 'off'], true);
        } else {
            $auth = (bool)$authValue;
        }
        $username = trim((string)($this->settings['smtp_username'] ?? ''));
        $password = trim((string)($this->settings['smtp_password'] ?? ''));

        $remoteHost = ($secure === 'ssl') ? ('ssl://' . $host) : $host;
        $fp = @stream_socket_client($remoteHost . ':' . $port, $errno, $errstr, $this->smtpTimeout, STREAM_CLIENT_CONNECT);
        if (!$fp) {
            throw new Exception('SMTP connect failed: ' . $errstr . ' (' . $errno . ')');
        }

        stream_set_timeout($fp, $this->smtpTimeout);
        $this->expectCode($fp, [220]);

        $hostname = preg_replace('/[^a-zA-Z0-9\.\-]/', '', ($_SERVER['HTTP_HOST'] ?? 'localhost'));
        if ($hostname === '') $hostname = 'localhost';

        $this->sendCommand($fp, 'EHLO ' . $hostname, [250]);

        if ($secure === 'tls') {
            $this->sendCommand($fp, 'STARTTLS', [220]);
            if (!@stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                fclose($fp);
                throw new Exception('Unable to enable STARTTLS');
            }
            $this->sendCommand($fp, 'EHLO ' . $hostname, [250]);
        }

        if ($auth) {
            $authed = false;
            $lastAuthError = null;

            try {
                $code = $this->sendCommand($fp, 'AUTH LOGIN', [235, 334, 503]);
                if ($code === 235 || $code === 503) {
                    $authed = true;
                } else {
                    $code = $this->sendCommand($fp, base64_encode($username), [334, 235, 535]);
                    if ($code === 334) {
                        $code = $this->sendCommand($fp, base64_encode($password), [235, 334, 535]);
                    }
                    if ($code === 235) {
                        $authed = true;
                    } else {
                        throw new Exception('AUTH LOGIN rejected by server');
                    }
                }
            } catch (Exception $loginEx) {
                $lastAuthError = $loginEx;
            }

            if (!$authed) {
                // Some servers prefer AUTH PLAIN over LOGIN.
                // Cancel any half-open AUTH exchange before trying another mechanism.
                try { $this->sendCommand($fp, '*', [501, 503, 504, 535]); } catch (Exception $ignore) {}

                try {
                    $authPlain = base64_encode("\0" . $username . "\0" . $password);
                    $plainCode = $this->sendCommand($fp, 'AUTH PLAIN ' . $authPlain, [235, 334, 503]);
                    if ((int)$plainCode === 334) {
                        // Some SMTP servers return challenge 334 and expect payload in next frame.
                        $plainCode = $this->sendCommand($fp, $authPlain, [235, 334, 535]);
                    }
                    if ((int)$plainCode === 235 || (int)$plainCode === 503) {
                        $authed = true;
                    }
                } catch (Exception $plainEx) {
                    $msg = 'SMTP authentication failed';
                    if ($lastAuthError) {
                        $msg .= ' (LOGIN: ' . $lastAuthError->getMessage() . ')';
                    }
                    $msg .= ' (PLAIN: ' . $plainEx->getMessage() . ')';
                    throw new Exception($msg);
                }
            }
            if (!$authed) {
                $msg = 'SMTP authentication failed';
                if ($lastAuthError) {
                    $msg .= ': ' . $lastAuthError->getMessage();
                }
                throw new Exception($msg);
            }
        }

        $this->sendCommand($fp, 'MAIL FROM:<' . $fromEmail . '>', [250]);
        $this->sendCommand($fp, 'RCPT TO:<' . $to . '>', [250, 251]);
        $this->sendCommand($fp, 'DATA', [354]);

        $encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
        $headers = [];
        $headers[] = 'Date: ' . date('r');
        $headers[] = 'From: ' . $fromName . ' <' . $fromEmail . '>';
        $headers[] = 'To: <' . $to . '>';
        $headers[] = 'Subject: ' . $encodedSubject;
        $headers[] = 'MIME-Version: 1.0';
        $headers[] = $isHtml ? 'Content-Type: text/html; charset=UTF-8' : 'Content-Type: text/plain; charset=UTF-8';
        $headers[] = 'Content-Transfer-Encoding: 8bit';
        $headers[] = 'X-Mailer: PMS SMTP';

        $normalizedBody = str_replace(["\r\n", "\r"], "\n", (string)$body);
        $normalizedBody = preg_replace('/^\./m', '..', $normalizedBody); // dot-stuffing
        $data = implode("\r\n", $headers) . "\r\n\r\n" . str_replace("\n", "\r\n", $normalizedBody) . "\r\n.\r\n";
        fwrite($fp, $data);
        $this->expectCode($fp, [250]);

        $this->sendCommand($fp, 'QUIT', [221]);
        fclose($fp);
        return true;
    }

    private function sendCommand($fp, $command, $expectedCodes) {
        fwrite($fp, $command . "\r\n");
        return $this->expectCode($fp, $expectedCodes);
    }

    private function expectCode($fp, $expectedCodes) {
        $response = '';
        while (($line = fgets($fp, 515)) !== false) {
            $response .= $line;
            if (preg_match('/^\d{3}\s/', $line)) {
                break;
            }
        }
        $this->lastSmtpResponse = $response;

        if ($response === '') {
            $meta = @stream_get_meta_data($fp);
            if (is_array($meta) && !empty($meta['timed_out'])) {
                throw new Exception('SMTP read timed out');
            }
            throw new Exception('Empty SMTP response');
        }

        $code = (int)substr($response, 0, 3);
        if (!in_array($code, $expectedCodes, true)) {
            throw new Exception('SMTP error ' . $code . ': ' . trim($response));
        }
        return $code;
    }
    
    public function sendWelcomeEmail($userEmail, $userName) {
        $subject = "Welcome to Project Management System";
        $body = "
            <!DOCTYPE html>
            <html>
            <head>
                <style>
                    body { font-family: Arial, sans-serif; line-height: 1.6; }
                    .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                    .header { background-color: #007bff; color: white; padding: 20px; text-align: center; }
                    .content { padding: 20px; background-color: #f8f9fa; }
                    .footer { text-align: center; padding: 20px; color: #6c757d; }
                </style>
            </head>
            <body>
                <div class='container'>
                    <div class='header'>
                        <h1>Welcome to Project Management System</h1>
                    </div>
                    <div class='content'>
                        <h2>Hello $userName,</h2>
                        <p>Your account has been successfully created.</p>
                        <p>You can now login to the system and start managing your projects.</p>
                        <p><strong>Important:</strong> Please change your password after first login.</p>
                        <p>If you have any questions, please contact your system administrator.</p>
                    </div>
                    <div class='footer'>
                        <p>&copy; " . date('Y') . " Project Management System. All rights reserved.</p>
                    </div>
                </div>
            </body>
            </html>
        ";
        
        return $this->send($userEmail, $subject, $body, true);
    }
    
    public function sendAssignmentNotification($userEmail, $userName, $projectTitle, $role) {
        $subject = "New Assignment: $projectTitle";
        $body = "
            <!DOCTYPE html>
            <html>
            <head>
                <style>
                    body { font-family: Arial, sans-serif; line-height: 1.6; }
                    .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                    .header { background-color: #28a745; color: white; padding: 20px; text-align: center; }
                    .content { padding: 20px; background-color: #f8f9fa; }
                    .button { display: inline-block; padding: 10px 20px; background-color: #007bff; 
                              color: white; text-decoration: none; border-radius: 5px; }
                </style>
            </head>
            <body>
                <div class='container'>
                    <div class='header'>
                        <h1>New Assignment Notification</h1>
                    </div>
                    <div class='content'>
                        <h2>Hello $userName,</h2>
                        <p>You have been assigned a new role in the project:</p>
                        <div style='background-color: white; padding: 15px; border-radius: 5px; margin: 20px 0;'>
                            <p><strong>Project:</strong> $projectTitle</p>
                            <p><strong>Role:</strong> " . ucfirst(str_replace('_', ' ', $role)) . "</p>
                        </div>
                        <p>Please login to the system to view your assignments and start working.</p>
                        <p style='text-align: center; margin-top: 30px;'>
                            <a href='" . $this->settings['app_url'] . "' class='button'>Go to System</a>
                        </p>
                    </div>
                </div>
            </body>
            </html>
        ";
        
        return $this->send($userEmail, $subject, $body, true);
    }
    
    public function sendMentionNotification($userEmail, $userName, $mentionedBy, $message, $link) {
        $subject = "You were mentioned in a conversation";
        $body = "
            <!DOCTYPE html>
            <html>
            <head>
                <style>
                    body { font-family: Arial, sans-serif; line-height: 1.6; }
                    .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                    .header { background-color: #ffc107; color: white; padding: 20px; text-align: center; }
                    .content { padding: 20px; background-color: #f8f9fa; }
                    .message { background-color: white; padding: 15px; border-left: 4px solid #ffc107; 
                              margin: 20px 0; }
                </style>
            </head>
            <body>
                <div class='container'>
                    <div class='header'>
                        <h1>Mention Notification</h1>
                    </div>
                    <div class='content'>
                        <h2>Hello $userName,</h2>
                        <p>You were mentioned by <strong>$mentionedBy</strong> in a conversation.</p>
                        <div class='message'>
                            <p><em>$message</em></p>
                        </div>
                        <p>Click the link below to view the conversation:</p>
                        <p><a href='$link'>$link</a></p>
                    </div>
                </div>
            </body>
            </html>
        ";
        
        return $this->send($userEmail, $subject, $body, true);
    }

    public function send2FAReminderEmail($userEmail, $userName) {
        $subject = "Security Update: Enable Two-Factor Authentication (2FA)";
        $appUrl = $this->settings['app_url'] ?? '';
        $profileUrl = rtrim($appUrl, '/') . '/modules/profile.php';
        
        $body = "
            <!DOCTYPE html>
            <html>
            <head>
                <style>
                    body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; line-height: 1.6; color: #333; }
                    .container { max-width: 600px; margin: 20px auto; border: 1px solid #e1e1e1; border-radius: 8px; overflow: hidden; }
                    .header { background-color: #1a73e8; color: white; padding: 30px; text-align: center; }
                    .content { padding: 30px; background-color: #ffffff; }
                    .button { display: inline-block; padding: 12px 25px; background-color: #1a73e8; 
                              color: white !important; text-decoration: none; border-radius: 4px; font-weight: bold; margin-top: 20px; }
                    .footer { text-align: center; padding: 20px; font-size: 12px; color: #777; background-color: #f9f9f9; }
                    .shield-icon { font-size: 48px; margin-bottom: 10px; }
                </style>
            </head>
            <body>
                <div class='container'>
                    <div class='header'>
                        <div class='shield-icon'>🛡️</div>
                        <h1>Security Reminder</h1>
                    </div>
                    <div class='content'>
                        <h2>Hi $userName,</h2>
                        <p>To ensure the security of your account and the project data, we highly recommend enabling <strong>Two-Factor Authentication (2FA)</strong>.</p>
                        <p>2FA adds an extra layer of protection by requiring a verification code from your mobile device when you log in.</p>
                        <p>It only takes a minute to set up using Google Authenticator or any other TOTP app.</p>
                        <p style='text-align: center;'>
                            <a href='$profileUrl' class='button'>Enable 2FA Now</a>
                        </p>
                        <p style='margin-top: 25px;'><strong>Steps to Enable:</strong></p>
                        <ol>
                            <li>Go to your Profile settings.</li>
                            <li>Find the Security section.</li>
                            <li>Click 'Setup 2FA' and scan the QR code.</li>
                        </ol>
                    </div>
                    <div class='footer'>
                        <p>This is an automated security reminder from the Project Management System.</p>
                    </div>
                </div>
            </body>
            </html>
        ";
        
        return $this->send($userEmail, $subject, $body, true);
    }
}
