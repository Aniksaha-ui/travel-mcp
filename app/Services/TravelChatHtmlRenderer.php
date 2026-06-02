<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Arr;

class TravelChatHtmlRenderer
{
    /**
     * @param  array<string, mixed>  $overview
     * @param  array<int, string>  $requestedResources
     * @return array<string, string>
     */
    public function render(string $location, array $overview, array $requestedResources): array
    {
        return [
            'summary' => $this->summaryHtml($location, $overview, $requestedResources),
            'trips' => $this->sectionHtml('Trips', $location, $overview['trips'] ?? null),
            'packages' => $this->sectionHtml('Packages', $location, $overview['packages'] ?? null),
            'hotels' => $this->sectionHtml('Hotels', $location, $overview['hotels'] ?? null),
        ];
    }

    /**
     * @param  array<string, mixed>  $overview
     * @param  array<int, string>  $requestedResources
     */
    private function summaryHtml(string $location, array $overview, array $requestedResources): string
    {
        $successful = collect($requestedResources)
            ->filter(fn (string $resource): bool => (($overview[$resource]['error'] ?? true) === false))
            ->map(fn (string $resource): string => ucfirst($resource))
            ->values()
            ->all();

        $failed = collect($requestedResources)
            ->filter(fn (string $resource): bool => (($overview[$resource]['error'] ?? false) === true))
            ->map(fn (string $resource): string => ucfirst($resource))
            ->values()
            ->all();

        $html = '<section class="travel-summary" style="margin-bottom:24px;">';
        $html .= '<div style="background:linear-gradient(135deg,#0f172a,#1e293b);color:#f8fafc;border-radius:20px;padding:24px;box-shadow:0 20px 45px rgba(15,23,42,0.18);">';
        $html .= '<div style="display:flex;flex-wrap:wrap;justify-content:space-between;gap:16px;align-items:flex-start;">';
        $html .= '<div>';
        $html .= '<p style="margin:0 0 8px;font-size:12px;letter-spacing:0.14em;text-transform:uppercase;color:#93c5fd;">Travel Snapshot</p>';
        $html .= '<h2 style="margin:0 0 12px;font-size:30px;line-height:1.15;">'.$this->e($location).'</h2>';
        $html .= '<p style="margin:0;color:#cbd5e1;font-size:15px;max-width:640px;">Travel cards are ready for your frontend. Each section below can be rendered directly for the customer chat interface.</p>';
        $html .= '</div>';
        $html .= '<div style="display:flex;flex-wrap:wrap;gap:10px;">';

        foreach ($successful as $resource) {
            $html .= $this->badge($resource, '#dcfce7', '#166534');
        }

        if ($failed !== []) {
            foreach ($failed as $resource) {
                $html .= $this->badge($resource.' unavailable', '#fee2e2', '#991b1b');
            }
        }

        $html .= '</div></div></div></section>';

        return $html;
    }

    /**
     * @param  array<string, mixed>|null  $section
     */
    private function sectionHtml(string $title, string $location, ?array $section): string
    {
        if ($section === null) {
            return '<section class="travel-section" style="margin-bottom:24px;"><div style="border:1px solid #e2e8f0;border-radius:18px;padding:20px;background:#f8fafc;"><h3 style="margin:0 0 8px;">'.$this->e($title).'</h3><p style="margin:0;color:#475569;">No '.$this->e(strtolower($title)).' data was requested.</p></div></section>';
        }

        if (($section['error'] ?? false) === true) {
            $message = (string) ($section['message'] ?? 'Unable to load this section.');

            return '<section class="travel-section error" style="margin-bottom:24px;"><div style="border:1px solid #fecaca;background:#fef2f2;border-radius:18px;padding:20px;"><h3 style="margin:0 0 8px;color:#991b1b;">'.$this->e($title).' in '.$this->e($location).'</h3><p style="margin:0;color:#7f1d1d;">'.$this->e($message).'</p></div></section>';
        }

        $data = Arr::get($section, 'data', []);
        $items = $this->extractItems($data);
        $count = count($items);

        $html = '<section class="travel-section" style="margin-bottom:28px;">';
        $html .= '<div style="display:flex;flex-wrap:wrap;justify-content:space-between;gap:12px;align-items:end;margin-bottom:14px;">';
        $html .= '<div>';
        $html .= '<p style="margin:0 0 6px;font-size:12px;letter-spacing:0.12em;text-transform:uppercase;color:#64748b;">'.$this->e($title).'</p>';
        $html .= '<h3 style="margin:0;font-size:24px;color:#0f172a;">'.$this->e($location).'</h3>';
        $html .= '</div>';
        $html .= '<div style="padding:8px 12px;border-radius:999px;background:#e0f2fe;color:#075985;font-size:13px;font-weight:600;">'.$count.' result'.($count === 1 ? '' : 's').'</div>';
        $html .= '</div>';

        if ($count === 0) {
            $html .= '<div style="border:1px dashed #cbd5e1;border-radius:18px;padding:20px;background:#f8fafc;color:#475569;">No matching '.$this->e(strtolower($title)).' were found for '.$this->e($location).'.</div>';
            $html .= '</section>';

            return $html;
        }

        $html .= '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:18px;">';

        foreach ($items as $index => $item) {
            $html .= $this->itemCard($title, $item, $index);
        }

        $html .= '</div></section>';

        return $html;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<int, array<string, mixed>>
     */
    private function extractItems(array $data): array
    {
        $items = Arr::get($data, 'data');

        if (is_array($items) && array_is_list($items)) {
            return array_values(array_filter($items, 'is_array'));
        }

        if (array_is_list($data)) {
            return array_values(array_filter($data, 'is_array'));
        }

        return [];
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function itemCard(string $title, array $item, int $index): string
    {
        $headline = $this->displayValue(
            $item['name'] ?? $item['title'] ?? $item['package_name'] ?? $item['trip_name'] ?? null,
            $title.' '.($index + 1),
        );

        $subtitleParts = array_filter([
            $this->displayValue($item['location'] ?? null),
            $this->displayValue($item['city'] ?? null),
            $this->displayValue($item['country'] ?? null),
        ]);

        $description = $this->displayValue($item['description'] ?? $item['details'] ?? null);
        $chips = array_filter([
            $this->chipValue('Rating', $item['star_rating'] ?? null),
            $this->chipValue('Status', $item['status'] ?? null),
            $this->chipValue('Price', $item['price'] ?? $item['amount'] ?? null),
        ]);

        $html = '<article style="height:100%;display:flex;flex-direction:column;border:1px solid #dbeafe;border-radius:22px;padding:18px;background:linear-gradient(180deg,#ffffff,#f8fbff);box-shadow:0 16px 32px rgba(15,23,42,0.08);">';
        $html .= '<div style="display:flex;justify-content:space-between;gap:12px;align-items:flex-start;margin-bottom:12px;">';
        $html .= '<div>';
        $html .= '<h4 style="margin:0 0 6px;font-size:20px;line-height:1.2;color:#0f172a;">'.$this->e($headline).'</h4>';

        if ($subtitleParts !== []) {
            $html .= '<p style="margin:0;color:#475569;font-size:14px;">'.$this->e(implode(' | ', $subtitleParts)).'</p>';
        }

        $html .= '</div>';

        if ($chips !== []) {
            $html .= '<div style="display:flex;flex-wrap:wrap;justify-content:flex-end;gap:8px;">'.implode('', $chips).'</div>';
        }

        $html .= '</div>';

        if ($description !== null) {
            $html .= '<p style="margin:0 0 14px;color:#334155;font-size:14px;line-height:1.55;">'.$this->e($this->truncate($description, 220)).'</p>';
        }

        $details = $this->detailRows($item);

        if ($details !== []) {
            $html .= '<dl style="display:grid;grid-template-columns:minmax(90px,120px) 1fr;gap:10px 12px;margin:0;">'.implode('', $details).'</dl>';
        }

        $html .= '</article>';

        return $html;
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<int, string>
     */
    private function detailRows(array $item): array
    {
        $rows = [];
        $excluded = ['name', 'title', 'package_name', 'trip_name', 'description', 'details'];

        foreach ($item as $key => $value) {
            if (in_array($key, $excluded, true)) {
                continue;
            }

            $rendered = $this->renderFieldValue($value);

            if ($rendered === null) {
                continue;
            }

            $rows[] = '<dt style="margin:0;color:#64748b;font-size:13px;font-weight:600;">'.$this->e($this->labelize($key)).'</dt>';
            $rows[] = '<dd style="margin:0;color:#0f172a;font-size:14px;">'.$rendered.'</dd>';
        }

        return $rows;
    }

    private function renderFieldValue(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_bool($value)) {
            return $this->e($value ? 'Yes' : 'No');
        }

        if (is_scalar($value)) {
            $string = trim((string) $value);

            if ($string === '') {
                return null;
            }

            if (filter_var($string, FILTER_VALIDATE_EMAIL)) {
                return '<a href="mailto:'.$this->e($string).'" style="color:#0369a1;text-decoration:none;">'.$this->e($string).'</a>';
            }

            if (filter_var($string, FILTER_VALIDATE_URL)) {
                return '<a href="'.$this->e($string).'" target="_blank" rel="noopener noreferrer" style="color:#0369a1;text-decoration:none;">'.$this->e($string).'</a>';
            }

            return $this->e($this->truncate($string, 140));
        }

        if (is_array($value)) {
            $flattened = array_filter(array_map(
                fn (mixed $entry): ?string => is_scalar($entry) ? trim((string) $entry) : null,
                $value,
            ));

            if ($flattened === []) {
                return null;
            }

            return $this->e(implode(', ', $flattened));
        }

        return null;
    }

    private function badge(string $label, string $background, string $color): string
    {
        return '<span style="display:inline-flex;align-items:center;padding:8px 12px;border-radius:999px;background:'.$background.';color:'.$color.';font-size:13px;font-weight:700;">'.$this->e($label).'</span>';
    }

    private function chipValue(string $label, mixed $value): ?string
    {
        $display = $this->displayValue($value);

        if ($display === null) {
            return null;
        }

        return '<span style="display:inline-flex;align-items:center;padding:6px 10px;border-radius:999px;background:#eff6ff;color:#1d4ed8;font-size:12px;font-weight:700;">'.$this->e($label.': '.$display).'</span>';
    }

    private function displayValue(mixed $value, ?string $default = null): ?string
    {
        if (is_scalar($value)) {
            $string = trim((string) $value);

            return $string !== '' ? $string : $default;
        }

        return $default;
    }

    private function labelize(string $key): string
    {
        return (string) preg_replace('/\s+/', ' ', ucwords(str_replace('_', ' ', $key)));
    }

    private function truncate(string $value, int $limit): string
    {
        if (mb_strlen($value) <= $limit) {
            return $value;
        }

        return rtrim(mb_substr($value, 0, $limit - 1)).'…';
    }

    private function e(?string $value): string
    {
        return e($value ?? '');
    }
}
