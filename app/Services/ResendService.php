<?php

namespace App\Services;

use GuzzleHttp\Client as GuzzleClient;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Resend\Client as ResendClient;
use Resend\Transporters\HttpTransporter;
use Resend\ValueObjects\ApiKey;
use Resend\ValueObjects\Transporter\BaseUri;
use Resend\ValueObjects\Transporter\Headers;

class ResendService
{
    protected string $apiKey;

    protected string $fromName;

    protected string $fromEmail;

    protected ?string $caCertPath;

    public function __construct()
    {
        $this->apiKey = (string) config('services.resend.key', '');
        $this->fromName = (string) config('services.resend.name', 'mrp-electrodesk-dev');
        $this->fromEmail = (string) config('services.resend.from_email', 'onboarding@resend.dev');

        $certPath = storage_path('certs/cacert.pem');
        $this->caCertPath = file_exists($certPath) ? $certPath : null;
    }

    /**
     * Get a configured instance of Resend\Client SDK.
     */
    public function client(): ResendClient
    {
        $options = [];
        if ($this->caCertPath) {
            $options['verify'] = $this->caCertPath;
        }

        $guzzle = new GuzzleClient($options);
        $transporter = new HttpTransporter(
            $guzzle,
            BaseUri::from('api.resend.com'),
            Headers::withAuthorization(ApiKey::from($this->apiKey))
        );

        return new ResendClient($transporter);
    }

    /**
     * Send an OTP email for password reset.
     *
     * @return array{success: bool, id: ?string, error: ?string}
     */
    public function sendPasswordResetOtp(string $toEmail, string $otp, string $userName = 'User'): array
    {
        $html = $this->buildOtpEmailHtml($userName, $otp);

        return $this->deliver($toEmail, 'Your Mr. Pride Password Reset OTP Code', $html, 'password reset code');
    }

    /**
     * Send a customer email (quotation, invoice, delivery notice) built by App\Services\CustomerEmails.
     *
     * @return array{success: bool, id: ?string, error: ?string}
     */
    /**
     * @param  array<int, array{filename: string, content: string}>  $attachments  raw file contents (e.g. PDFs)
     */
    public function sendCustomerEmail(string $toEmail, string $subject, string $html, string $kind, array $attachments = []): array
    {
        return $this->deliver($toEmail, $subject, $html, $kind, $attachments);
    }

    /**
     * Development redirect: in APP_ENV=local, when RESEND_DEV_REDIRECT_TO is set, every email goes
     * to that address instead (the Resend sandbox sender onboarding@resend.dev can only deliver to
     * the Resend account owner). The subject shows who it was really for. Never active elsewhere.
     */
    public function devRedirectAddress(): ?string
    {
        $to = trim((string) config('services.resend.dev_redirect_to', ''));

        return app()->isLocal() && $to !== '' ? $to : null;
    }

    /**
     * Send one email: Resend SDK → Resend HTTP API → Laravel's mailer (only if it really delivers).
     * Never reports success unless the message was handed to a real transport, and never writes
     * the email body (which may hold codes or links) to the log.
     *
     * @return array{success: bool, id: ?string, error: ?string}
     */
    protected function deliver(string $toEmail, string $subject, string $html, string $kind, array $attachments = []): array
    {
        $recipient = $toEmail;
        if ($redirect = $this->devRedirectAddress()) {
            $recipient = $redirect;
            $subject = "[DEV for {$toEmail}] {$subject}";
        }

        $payload = [
            'from' => "{$this->fromName} <{$this->fromEmail}>",
            'to' => [$recipient],
            'subject' => $subject,
            'html' => $html,
        ];
        if ($attachments !== []) {
            $payload['attachments'] = array_map(fn ($a) => ['filename' => $a['filename'], 'content' => base64_encode($a['content'])], $attachments);
        }
        $resendError = 'Resend API key is not configured.';

        if (! empty($this->apiKey)) {
            try {
                $result = $this->client()->emails->send($payload);
                $id = $result->id ?? null;
                Log::info("Resend: {$kind} sent to {$recipient}".($redirect ? " (dev redirect for {$toEmail})" : ''), ['resend_id' => $id]);

                return ['success' => true, 'id' => $id, 'error' => null];
            } catch (\Throwable $sdkError) {
                $resendError = $sdkError->getMessage();
                Log::warning("Resend SDK failed for {$kind} to {$recipient}: {$resendError}");
            }

            // Same request through Laravel's HTTP client (helps when the SDK's SSL setup fails).
            try {
                $http = Http::asJson()->withToken($this->apiKey);
                $http = $this->caCertPath ? $http->withOptions(['verify' => $this->caCertPath]) : $http->withoutVerifying();
                $response = $http->post('https://api.resend.com/emails', $payload);

                if ($response->successful()) {
                    $id = $response->json('id');
                    Log::info("Resend (HTTP): {$kind} sent to {$recipient}", ['resend_id' => $id]);

                    return ['success' => true, 'id' => $id, 'error' => null];
                }

                $resendError = "Resend API error ({$response->status()}): ".($response->json('message') ?? $response->body());
                Log::warning("Resend HTTP failed for {$kind} to {$recipient}: {$resendError}");
            } catch (\Throwable $httpError) {
                $resendError = $httpError->getMessage();
                Log::warning("Resend HTTP failed for {$kind} to {$recipient}: {$resendError}");
            }
        }

        // Laravel's own mailer, but not the "log" driver: that only writes the email (with any
        // code or link in it) to storage/logs and would wrongly count as "sent".
        $mailer = (string) config('mail.default', 'log');
        if ($mailer === 'log') {
            return ['success' => false, 'id' => null, 'error' => $resendError];
        }

        try {
            Mail::html($html, function ($message) use ($recipient, $subject, $attachments) {
                $message->to($recipient)->subject($subject);
                foreach ($attachments as $a) {
                    $message->attachData($a['content'], $a['filename']);
                }
            });
            Log::info("Laravel Mail ({$mailer}): {$kind} sent to {$recipient}");

            return ['success' => true, 'id' => 'mail-'.uniqid(), 'error' => null];
        } catch (\Throwable $mailError) {
            Log::warning("Laravel Mail ({$mailer}) failed for {$kind} to {$recipient}: ".$mailError->getMessage());

            return ['success' => false, 'id' => null, 'error' => $resendError.' / Mail: '.$mailError->getMessage()];
        }
    }

    /**
     * Generate responsive HTML template for OTP email.
     */
    protected function buildOtpEmailHtml(string $userName, string $otp): string
    {
        $safeName = htmlspecialchars($userName, ENT_QUOTES, 'UTF-8');
        $safeOtp = htmlspecialchars($otp, ENT_QUOTES, 'UTF-8');

        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Password Reset OTP</title>
</head>
<body style="margin: 0; padding: 0; background-color: #f4f5f7; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; color: #1f2937;">
  <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background-color: #f4f5f7; padding: 40px 15px;">
    <tr>
      <td align="center">
        <table role="presentation" width="100%" style="max-width: 520px; background-color: #ffffff; border-radius: 12px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05), 0 2px 4px -2px rgba(0,0,0,0.05); overflow: hidden; border: 1px solid #e5e7eb;" cellspacing="0" cellpadding="0">
          <!-- Header -->
          <tr>
            <td style="background-color: #4b5320; padding: 28px 32px; text-align: center;">
              <h1 style="margin: 0; font-size: 22px; font-weight: 700; color: #ffffff; letter-spacing: 0.5px;">MR. PRIDE</h1>
              <p style="margin: 4px 0 0; font-size: 13px; color: #d1d5db; font-weight: 500;">ElectroDesk System</p>
            </td>
          </tr>

          <!-- Body -->
          <tr>
            <td style="padding: 36px 32px 24px;">
              <h2 style="margin: 0 0 16px; font-size: 18px; font-weight: 600; color: #111827;">Password Reset Verification</h2>
              <p style="margin: 0 0 20px; font-size: 14px; line-height: 22px; color: #4b5563;">
                Hello <strong>{$safeName}</strong>,
              </p>
              <p style="margin: 0 0 24px; font-size: 14px; line-height: 22px; color: #4b5563;">
                We received a request to reset your password for your Mr. Pride account. Use the One-Time Password (OTP) below to verify your identity and set a new password.
              </p>

              <!-- OTP Box -->
              <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin: 28px 0; background-color: #f9fafb; border: 2px dashed #4b5320; border-radius: 8px;">
                <tr>
                  <td align="center" style="padding: 24px;">
                    <div style="font-size: 12px; text-transform: uppercase; font-weight: 600; color: #6b7280; letter-spacing: 1.5px; margin-bottom: 8px;">Your Verification Code</div>
                    <div style="font-size: 34px; font-weight: 800; letter-spacing: 8px; color: #4b5320; font-family: monospace;">{$safeOtp}</div>
                    <div style="font-size: 12px; color: #9ca3af; margin-top: 8px;">Valid for 15 minutes</div>
                  </td>
                </tr>
              </table>

              <p style="margin: 0 0 16px; font-size: 13px; line-height: 20px; color: #6b7280;">
                Enter this code on the password reset screen along with your new password.
              </p>
              <p style="margin: 0; font-size: 13px; line-height: 20px; color: #9ca3af;">
                If you did not request this password reset, please ignore this email or contact your administrator immediately.
              </p>
            </td>
          </tr>

          <!-- Footer -->
          <tr>
            <td style="background-color: #f9fafb; padding: 20px 32px; border-top: 1px solid #f3f4f6; text-align: center;">
              <p style="margin: 0; font-size: 11px; color: #9ca3af;">
                &copy; 2026 Mr. Pride ElectroDesk. All rights reserved.
              </p>
            </td>
          </tr>
        </table>
      </td>
    </tr>
  </table>
</body>
</html>
HTML;
    }

    /**
     * Send an inquiry reply to a customer.
     *
     * @return array{success: bool, id: ?string, error: ?string}
     */
    public function sendInquiryReply(
        string $toEmail,
        string $customerName,
        string $subject,
        string $replyContent,
        string $originalMessage = ''
    ): array {
        $emailSubject = 'Re: '.($subject ?: 'Your inquiry with MR-Pride Electrical Supplies');
        $html = $this->buildInquiryReplyEmailHtml($customerName, $emailSubject, $replyContent, $originalMessage);

        return $this->deliver($toEmail, $emailSubject, $html, 'inquiry reply');
    }

    /**
     * Send a device enrollment invitation to an employee.
     *
     * @return array{success: bool, id: ?string, error: ?string}
     */
    public function sendDeviceEnrollmentInvitation(
        string $toEmail,
        string $userName,
        string $enrollmentCode,
        string $enrollmentLink,
        int $expiresInMinutes = 5
    ): array {
        $emailSubject = 'MRP ElectroDesk - Trusted Device Enrollment Invitation (Valid for 5 Minutes)';
        $html = $this->buildDeviceEnrollmentEmailHtml($userName, $toEmail, $enrollmentCode, $enrollmentLink, $expiresInMinutes);

        return $this->deliver($toEmail, $emailSubject, $html, 'device enrollment invitation');
    }

    /**
     * Build device enrollment invitation email HTML template.
     */
    protected function buildDeviceEnrollmentEmailHtml(
        string $userName,
        string $toEmail,
        string $enrollmentCode,
        string $enrollmentLink,
        int $expiresInMinutes
    ): string {
        $safeName = htmlspecialchars($userName, ENT_QUOTES, 'UTF-8');
        $safeEmail = htmlspecialchars($toEmail, ENT_QUOTES, 'UTF-8');
        $safeCode = htmlspecialchars($enrollmentCode, ENT_QUOTES, 'UTF-8');
        $safeLink = htmlspecialchars($enrollmentLink, ENT_QUOTES, 'UTF-8');

        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Workstation Enrollment Invitation</title>
</head>
<body style="margin: 0; padding: 0; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; background-color: #f4f5f7; color: #1f2937;">
  <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background-color: #f4f5f7; padding: 40px 15px;">
    <tr>
      <td align="center">
        <table role="presentation" width="100%" style="max-width: 580px; background-color: #ffffff; border-radius: 12px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05); overflow: hidden; border: 1px solid #e5e7eb;" cellspacing="0" cellpadding="0">
          <tr>
            <td style="background-color: #4b5320; padding: 24px 32px; text-align: left;">
              <h1 style="margin: 0; font-size: 20px; font-weight: 800; color: #F8B803; letter-spacing: 0.5px;">MR. PRIDE</h1>
              <p style="margin: 4px 0 0; font-size: 12px; color: rgba(255,255,255,0.85);">MRP ElectroDesk • Trusted Workstation Security</p>
            </td>
          </tr>
          <tr>
            <td style="padding: 32px;">
              <h2 style="margin: 0 0 16px; font-size: 18px; font-weight: 700; color: #111827;">Workstation Enrollment Invitation</h2>
              <p style="margin: 0 0 16px; font-size: 14px; line-height: 22px; color: #374151;">
                Hello <strong>{$safeName}</strong>,
              </p>
              <p style="margin: 0 0 20px; font-size: 14px; line-height: 22px; color: #374151;">
                Your administrator has generated a single-use invitation to enroll your workstation laptop for access to MRP ElectroDesk.
              </p>

              <!-- Expiry Alert -->
              <div style="margin: 20px 0; padding: 14px 18px; background-color: #fef3c7; border: 1px solid #fde68a; border-radius: 8px; font-size: 13px; color: #92400e;">
                <strong>⏱️ Important:</strong> This link is valid for <strong>{$expiresInMinutes} minutes only</strong> and is strictly bound to your registered email address (<strong>{$safeEmail}</strong>). It cannot be forwarded or used with any other email.
              </div>

              <!-- Button -->
              <div style="margin: 28px 0; text-align: center;">
                <a href="{$safeLink}" style="display: inline-block; background-color: #4b5320; color: #F8B803; padding: 14px 32px; border-radius: 8px; font-size: 15px; font-weight: 700; text-decoration: none; letter-spacing: 0.5px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
                  Open Workstation Enrollment Link
                </a>
              </div>

              <!-- Code Box -->
              <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin: 24px 0; background-color: #f9fafb; border: 2px dashed #4b5320; border-radius: 8px;">
                <tr>
                  <td align="center" style="padding: 20px;">
                    <div style="font-size: 11px; text-transform: uppercase; font-weight: 700; color: #6b7280; letter-spacing: 1.5px; margin-bottom: 6px;">Your Enrollment Code</div>
                    <div style="font-size: 28px; font-weight: 800; letter-spacing: 6px; color: #4b5320; font-family: monospace;">{$safeCode}</div>
                    <div style="font-size: 12px; color: #9ca3af; margin-top: 6px;">Enter this code on the enrollment page</div>
                  </td>
                </tr>
              </table>

              <p style="margin: 20px 0 8px; font-size: 13px; line-height: 20px; color: #6b7280;">
                <strong>Steps to complete registration:</strong>
              </p>
              <ol style="margin: 0 0 24px; padding-left: 20px; font-size: 13px; line-height: 22px; color: #4b5563;">
                <li>Open the link above on your intended laptop within {$expiresInMinutes} minutes.</li>
                <li>Enter the enrollment code (<strong>{$safeCode}</strong>) and your account temporary password.</li>
                <li>Your browser will be securely authorized and you will be directed to the login page.</li>
              </ol>

              <p style="margin: 0; font-size: 12px; line-height: 18px; color: #9ca3af;">
                If you did not expect this invitation or need a new link, please contact your system administrator.
              </p>
            </td>
          </tr>
          <tr>
            <td style="background-color: #f9fafb; padding: 20px 32px; border-top: 1px solid #f3f4f6; text-align: center;">
              <p style="margin: 0; font-size: 11px; color: #9ca3af;">&copy; 2026 MR. PRIDE ElectroDesk. All rights reserved.</p>
            </td>
          </tr>
        </table>
      </td>
    </tr>
  </table>
</body>
</html>
HTML;
    }

    /**
     * Build customer reply email template.
     */
    protected function buildInquiryReplyEmailHtml(string $customerName, string $subject, string $replyContent, string $originalMessage): string
    {
        $safeName = htmlspecialchars($customerName, ENT_QUOTES, 'UTF-8');
        $safeSubject = htmlspecialchars($subject, ENT_QUOTES, 'UTF-8');
        $safeReply = nl2br(htmlspecialchars($replyContent, ENT_QUOTES, 'UTF-8'));
        $safeOriginal = nl2br(htmlspecialchars($originalMessage, ENT_QUOTES, 'UTF-8'));

        $originalSection = $originalMessage ? <<<HTML
          <div style="margin-top: 24px; padding: 16px; background-color: #f9fafb; border-left: 3px solid #4b5320; border-radius: 4px;">
            <div style="font-size: 11px; text-transform: uppercase; font-weight: 700; color: #6b7280; letter-spacing: 1px; margin-bottom: 6px;">Your Original Message:</div>
            <div style="font-size: 13px; line-height: 20px; color: #4b5563;">{$safeOriginal}</div>
          </div>
HTML : '';

        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>{$safeSubject}</title>
</head>
<body style="margin: 0; padding: 0; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; background-color: #f4f5f7; color: #1f2937;">
  <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background-color: #f4f5f7; padding: 40px 15px;">
    <tr>
      <td align="center">
        <table role="presentation" width="100%" style="max-width: 580px; background-color: #ffffff; border-radius: 12px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05); overflow: hidden; border: 1px solid #e5e7eb;" cellspacing="0" cellpadding="0">
          <tr>
            <td style="background-color: #4b5320; padding: 24px 32px; text-align: left;">
              <h1 style="margin: 0; font-size: 20px; font-weight: 800; color: #F8B803; letter-spacing: 0.5px;">MR-PRIDE</h1>
              <p style="margin: 4px 0 0; font-size: 12px; color: rgba(255,255,255,0.85);">Customer Support & Inquiries</p>
            </td>
          </tr>
          <tr>
            <td style="padding: 32px;">
              <p style="margin: 0 0 16px; font-size: 15px; color: #111827;">Hello <strong>{$safeName}</strong>,</p>
              <p style="margin: 0 0 20px; font-size: 14px; line-height: 24px; color: #374151;">
                Thank you for contacting MR-Pride Electrical Supplies. Here is our response to your inquiry:
              </p>
              <div style="margin: 20px 0; padding: 20px; background-color: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; font-size: 14px; line-height: 24px; color: #1e293b;">
                {$safeReply}
              </div>
              {$originalSection}
              <p style="margin: 24px 0 0; font-size: 13px; line-height: 20px; color: #6b7280;">
                If you have further questions, feel free to reply directly to this email or call us at <strong>09397366907</strong> / <strong>022444939</strong>.
              </p>
            </td>
          </tr>
          <tr>
            <td style="background-color: #f9fafb; padding: 20px 32px; border-top: 1px solid #f3f4f6; text-align: center;">
              <p style="margin: 0; font-size: 11px; color: #9ca3af;">&copy; 2026 MR-Pride Electrical Supplies. All rights reserved.</p>
            </td>
          </tr>
        </table>
      </td>
    </tr>
  </table>
</body>
</html>
HTML;
    }
}
