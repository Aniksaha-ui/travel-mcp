<?php

declare(strict_types=1);

namespace App\Services;

class SupportTicketChatHtmlRenderer
{
    /**
     * @param  array{title: string, description: string, remarks?: string|null}  $payload
     * @return array{full: string, summary: string}
     */
    public function renderCreated(array $payload, string $message): array
    {
        $summary = '<section class="ticket-chat-summary" style="margin-bottom:18px;">'
            .'<div style="background:linear-gradient(135deg,#0f766e 0%,#14b8a6 100%);color:#f0fdfa;border-radius:24px;padding:22px;box-shadow:0 20px 45px rgba(15,118,110,0.18);">'
            .'<p style="margin:0 0 8px;font-size:12px;letter-spacing:0.12em;text-transform:uppercase;color:#ccfbf1;">Support Ticket</p>'
            .'<h2 style="margin:0 0 10px;font-size:28px;line-height:1.15;">Ticket Created</h2>'
            .'<p style="margin:0;font-size:15px;line-height:1.7;">'.$this->e($message).' Your request was submitted through the authenticated customer account.</p>'
            .'</div>'
            .'</section>';

        $full = '<div class="ticket-chat-response" style="font-family:ui-sans-serif,system-ui,-apple-system,BlinkMacSystemFont,\'Segoe UI\',sans-serif;background:linear-gradient(180deg,#f4fffd 0%,#ffffff 100%);padding:24px;border-radius:28px;">'
            .$summary
            .'<section style="border:1px solid #ccfbf1;border-radius:24px;background:#ffffff;padding:22px;box-shadow:0 12px 30px rgba(15,23,42,0.06);">'
            .'<p style="margin:0 0 6px;font-size:12px;letter-spacing:0.1em;text-transform:uppercase;color:#0f766e;">Ticket Title</p>'
            .'<h3 style="margin:0 0 14px;font-size:24px;line-height:1.3;color:#134e4a;">'.$this->e($payload['title']).'</h3>'
            .'<p style="margin:0 0 6px;font-size:12px;letter-spacing:0.1em;text-transform:uppercase;color:#0f766e;">Description</p>'
            .'<p style="margin:0;color:#334155;font-size:15px;line-height:1.8;">'.$this->e($payload['description']).'</p>';

        if (isset($payload['remarks']) && is_string($payload['remarks']) && $payload['remarks'] !== '') {
            $full .= '<div style="margin-top:16px;padding:14px 16px;border-radius:18px;background:#f0fdfa;border:1px solid #99f6e4;">'
                .'<p style="margin:0 0 6px;font-size:12px;letter-spacing:0.08em;text-transform:uppercase;color:#0f766e;">Remarks</p>'
                .'<p style="margin:0;color:#134e4a;font-size:14px;line-height:1.7;">'.$this->e($payload['remarks']).'</p>'
                .'</div>';
        }

        $full .= '</section></div>';

        return [
            'full' => $full,
            'summary' => $summary,
        ];
    }

    /**
     * @param  array{title: string|null, description: string|null, remarks: string|null}  $payload
     * @return array{full: string, summary: string}
     */
    public function renderNeedsMoreInformation(array $payload): array
    {
        $summary = '<section class="ticket-chat-summary" style="margin-bottom:18px;">'
            .'<div style="background:linear-gradient(135deg,#9a3412 0%,#f97316 100%);color:#fff7ed;border-radius:24px;padding:22px;box-shadow:0 20px 45px rgba(154,52,18,0.18);">'
            .'<p style="margin:0 0 8px;font-size:12px;letter-spacing:0.12em;text-transform:uppercase;color:#fed7aa;">Support Ticket</p>'
            .'<h2 style="margin:0 0 10px;font-size:28px;line-height:1.15;">More Detail Needed</h2>'
            .'<p style="margin:0;font-size:15px;line-height:1.7;">Share the problem, booking reference, payment issue, or affected hotel/trip/package so the ticket can be created properly.</p>'
            .'</div>'
            .'</section>';

        $detail = $payload['description'] ?? $payload['title'] ?? 'No issue details were detected in the chat message yet.';

        $full = '<div class="ticket-chat-response" style="font-family:ui-sans-serif,system-ui,-apple-system,BlinkMacSystemFont,\'Segoe UI\',sans-serif;background:linear-gradient(180deg,#fffaf5 0%,#ffffff 100%);padding:24px;border-radius:28px;">'
            .$summary
            .'<section style="border:1px solid #fed7aa;border-radius:24px;background:#ffffff;padding:22px;">'
            .'<p style="margin:0 0 6px;font-size:12px;letter-spacing:0.1em;text-transform:uppercase;color:#c2410c;">Current Detail</p>'
            .'<p style="margin:0;color:#7c2d12;font-size:15px;line-height:1.8;">'.$this->e($detail).'</p>'
            .'</section></div>';

        return [
            'full' => $full,
            'summary' => $summary,
        ];
    }

    /**
     * @param  array{title: string, description: string, remarks?: string|null}  $payload
     * @return array{full: string, summary: string}
     */
    public function renderFailure(array $payload, string $message): array
    {
        $summary = '<section class="ticket-chat-summary" style="margin-bottom:18px;">'
            .'<div style="background:linear-gradient(135deg,#7f1d1d 0%,#dc2626 100%);color:#fef2f2;border-radius:24px;padding:22px;box-shadow:0 20px 45px rgba(127,29,29,0.18);">'
            .'<p style="margin:0 0 8px;font-size:12px;letter-spacing:0.12em;text-transform:uppercase;color:#fecaca;">Support Ticket</p>'
            .'<h2 style="margin:0 0 10px;font-size:28px;line-height:1.15;">Ticket Was Not Created</h2>'
            .'<p style="margin:0;font-size:15px;line-height:1.7;">'.$this->e($message).'</p>'
            .'</div>'
            .'</section>';

        $full = '<div class="ticket-chat-response" style="font-family:ui-sans-serif,system-ui,-apple-system,BlinkMacSystemFont,\'Segoe UI\',sans-serif;background:linear-gradient(180deg,#fff5f5 0%,#ffffff 100%);padding:24px;border-radius:28px;">'
            .$summary
            .'<section style="border:1px solid #fecaca;border-radius:24px;background:#ffffff;padding:22px;">'
            .'<p style="margin:0 0 6px;font-size:12px;letter-spacing:0.1em;text-transform:uppercase;color:#b91c1c;">Attempted Ticket Title</p>'
            .'<p style="margin:0 0 16px;color:#7f1d1d;font-size:15px;line-height:1.8;">'.$this->e($payload['title']).'</p>'
            .'<p style="margin:0 0 6px;font-size:12px;letter-spacing:0.1em;text-transform:uppercase;color:#b91c1c;">Attempted Description</p>'
            .'<p style="margin:0;color:#7f1d1d;font-size:15px;line-height:1.8;">'.$this->e($payload['description']).'</p>'
            .'</section></div>';

        return [
            'full' => $full,
            'summary' => $summary,
        ];
    }

    private function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
