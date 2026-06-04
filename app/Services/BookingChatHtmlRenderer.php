<?php

declare(strict_types=1);

namespace App\Services;

class BookingChatHtmlRenderer
{
    /**
     * @param  array<string, scalar|null>  $details
     * @return array{full: string, summary: string}
     */
    public function renderCreated(string $title, string $message, array $details, ?string $redirectUrl = null): array
    {
        $summary = '<section class="booking-chat-summary" style="margin-bottom:18px;">'
            .'<div style="background:linear-gradient(135deg,#0f766e 0%,#0ea5a4 100%);color:#f0fdfa;border-radius:24px;padding:22px;box-shadow:0 20px 45px rgba(15,118,110,0.18);">'
            .'<p style="margin:0 0 8px;font-size:12px;letter-spacing:0.12em;text-transform:uppercase;color:#ccfbf1;">Authenticated Booking</p>'
            .'<h2 style="margin:0 0 10px;font-size:28px;line-height:1.15;">'.$this->e($title).'</h2>'
            .'<p style="margin:0;font-size:15px;line-height:1.7;">'.$this->e($message).'</p>'
            .'</div>'
            .'</section>';

        $full = '<div class="booking-chat-response" style="font-family:ui-sans-serif,system-ui,-apple-system,BlinkMacSystemFont,\'Segoe UI\',sans-serif;background:linear-gradient(180deg,#f4fffd 0%,#ffffff 100%);padding:24px;border-radius:28px;">'
            .$summary
            .'<section style="border:1px solid #ccfbf1;border-radius:24px;background:#ffffff;padding:22px;box-shadow:0 12px 30px rgba(15,23,42,0.06);">'
            .'<p style="margin:0 0 14px;color:#334155;font-size:15px;line-height:1.8;">The booking request was submitted using the forwarded customer bearer token.</p>'
            .$this->detailList($details);

        if (is_string($redirectUrl) && $redirectUrl !== '') {
            $full .= '<div style="margin-top:16px;padding:14px 16px;border-radius:18px;background:#ecfeff;border:1px solid #a5f3fc;">'
                .'<p style="margin:0 0 6px;font-size:12px;letter-spacing:0.08em;text-transform:uppercase;color:#155e75;">Payment Redirect</p>'
                .'<p style="margin:0;color:#164e63;font-size:14px;line-height:1.7;">'.$this->e($redirectUrl).'</p>'
                .'</div>';
        }

        $full .= '</section></div>';

        return [
            'full' => $full,
            'summary' => $summary,
        ];
    }

    /**
     * @param  array<string, scalar|null>  $details
     * @return array{full: string, summary: string}
     */
    public function renderNeedsMoreInformation(string $title, string $message, array $details): array
    {
        $summary = '<section class="booking-chat-summary" style="margin-bottom:18px;">'
            .'<div style="background:linear-gradient(135deg,#9a3412 0%,#f97316 100%);color:#fff7ed;border-radius:24px;padding:22px;box-shadow:0 20px 45px rgba(154,52,18,0.18);">'
            .'<p style="margin:0 0 8px;font-size:12px;letter-spacing:0.12em;text-transform:uppercase;color:#fed7aa;">Authenticated Booking</p>'
            .'<h2 style="margin:0 0 10px;font-size:28px;line-height:1.15;">'.$this->e($title).'</h2>'
            .'<p style="margin:0;font-size:15px;line-height:1.7;">'.$this->e($message).'</p>'
            .'</div>'
            .'</section>';

        $full = '<div class="booking-chat-response" style="font-family:ui-sans-serif,system-ui,-apple-system,BlinkMacSystemFont,\'Segoe UI\',sans-serif;background:linear-gradient(180deg,#fffaf5 0%,#ffffff 100%);padding:24px;border-radius:28px;">'
            .$summary
            .'<section style="border:1px solid #fed7aa;border-radius:24px;background:#ffffff;padding:22px;">'
            .$this->detailList($details)
            .'</section></div>';

        return [
            'full' => $full,
            'summary' => $summary,
        ];
    }

    /**
     * @param  array<string, scalar|null>  $details
     * @return array{full: string, summary: string}
     */
    public function renderFailure(string $title, string $message, array $details): array
    {
        $summary = '<section class="booking-chat-summary" style="margin-bottom:18px;">'
            .'<div style="background:linear-gradient(135deg,#7f1d1d 0%,#dc2626 100%);color:#fef2f2;border-radius:24px;padding:22px;box-shadow:0 20px 45px rgba(127,29,29,0.18);">'
            .'<p style="margin:0 0 8px;font-size:12px;letter-spacing:0.12em;text-transform:uppercase;color:#fecaca;">Authenticated Booking</p>'
            .'<h2 style="margin:0 0 10px;font-size:28px;line-height:1.15;">'.$this->e($title).'</h2>'
            .'<p style="margin:0;font-size:15px;line-height:1.7;">'.$this->e($message).'</p>'
            .'</div>'
            .'</section>';

        $full = '<div class="booking-chat-response" style="font-family:ui-sans-serif,system-ui,-apple-system,BlinkMacSystemFont,\'Segoe UI\',sans-serif;background:linear-gradient(180deg,#fff5f5 0%,#ffffff 100%);padding:24px;border-radius:28px;">'
            .$summary
            .'<section style="border:1px solid #fecaca;border-radius:24px;background:#ffffff;padding:22px;">'
            .$this->detailList($details)
            .'</section></div>';

        return [
            'full' => $full,
            'summary' => $summary,
        ];
    }

    /**
     * @param  array<string, scalar|null>  $details
     */
    private function detailList(array $details): string
    {
        $rows = [];

        foreach ($details as $label => $value) {
            if ($value === null || $value === '') {
                continue;
            }

            $rows[] = '<li><strong>'.$this->e($label).':</strong> '.$this->e((string) $value).'</li>';
        }

        if ($rows === []) {
            return '<p style="margin:0;color:#475569;font-size:15px;line-height:1.8;">No additional booking details are available yet.</p>';
        }

        return '<ul style="margin:0;padding-left:18px;color:#0f172a;line-height:1.8;font-size:15px;">'.implode('', $rows).'</ul>';
    }

    private function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
