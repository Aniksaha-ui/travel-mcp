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
        $summary = $this->summaryHtml($location, $overview, $requestedResources);
        $trips = $this->sectionHtml('Trips', $location, $overview['trips'] ?? null);
        $packages = $this->sectionHtml('Packages', $location, $overview['packages'] ?? null);
        $hotels = $this->sectionHtml('Hotels', $location, $overview['hotels'] ?? null);

        return [
            'full' => $this->composeFullHtml($requestedResources, $summary, [
                'trips' => $trips,
                'packages' => $packages,
                'hotels' => $hotels,
            ]),
            'summary' => $summary,
            'trips' => $trips,
            'packages' => $packages,
            'hotels' => $hotels,
        ];
    }

    public function presentationInstruction(): string
    {
        return 'Present travel results as attractive, professional HTML. Start with a short natural-language summary like "There are 5 trips available for Cox\'s Bazar", then show a quick comparison table when useful, followed by numbered sections such as "Trip 1" or "Hotel 2". Keep the tone human and polished, highlight important details like price, status, rating, and description, and never dump raw JSON. When trips are shown, add a simple booking hint such as "Reply: book the Beach Flight trip" so the customer knows chat can show available seats next.';
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

        $resourceSnapshots = collect($requestedResources)
            ->map(function (string $resource) use ($overview): array {
                $label = ucfirst($resource);
                $section = $overview[$resource] ?? null;

                if (! is_array($section) || (($section['error'] ?? true) === true)) {
                    return [
                        'label' => $label,
                        'count' => null,
                        'error' => true,
                    ];
                }

                return [
                    'label' => $label,
                    'count' => count($this->extractItems(Arr::get($section, 'data', []))),
                    'error' => false,
                ];
            })
            ->values()
            ->all();

        $summarySentence = $this->overviewSentence($location, $resourceSnapshots, $failed);

        $html = '<section class="travel-summary" style="margin-bottom:24px;">';
        $html .= '<div style="background:linear-gradient(135deg,#0f172a 0%,#1d4ed8 52%,#38bdf8 100%);color:#f8fafc;border-radius:28px;padding:28px;box-shadow:0 24px 55px rgba(15,23,42,0.22);">';
        $html .= '<div style="display:flex;flex-wrap:wrap;justify-content:space-between;gap:20px;align-items:flex-start;">';
        $html .= '<div style="max-width:700px;">';
        $html .= '<p style="margin:0 0 8px;font-size:12px;letter-spacing:0.16em;text-transform:uppercase;color:#bfdbfe;">Travel Overview</p>';
        $html .= '<h2 style="margin:0 0 12px;font-size:32px;line-height:1.1;">'.$this->e($location).'</h2>';
        $html .= '<p style="margin:0 0 18px;color:#e2e8f0;font-size:15px;line-height:1.7;">'.$this->e($summarySentence).'</p>';
        $html .= '<p style="margin:0;color:#dbeafe;font-size:14px;line-height:1.7;">Each section below is structured for customer-facing chat UIs, with a quick comparison table and readable detail blocks.</p>';
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

        $html .= '</div></div>';
        $html .= '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:14px;margin-top:22px;">';

        foreach ($resourceSnapshots as $snapshot) {
            $value = $snapshot['error']
                ? 'Unavailable'
                : $this->formatCount((int) $snapshot['count'], strtolower($this->singularTitle((string) $snapshot['label'])));

            $subtext = $snapshot['error']
                ? 'Temporary issue'
                : 'Ready to show';

            $html .= '<div style="background:rgba(255,255,255,0.14);border:1px solid rgba(255,255,255,0.18);border-radius:20px;padding:16px 18px;backdrop-filter:blur(8px);">';
            $html .= '<p style="margin:0 0 6px;font-size:12px;letter-spacing:0.08em;text-transform:uppercase;color:#dbeafe;">'.$this->e((string) $snapshot['label']).'</p>';
            $html .= '<p style="margin:0 0 4px;font-size:24px;font-weight:700;color:#ffffff;">'.$this->e($value).'</p>';
            $html .= '<p style="margin:0;color:#dbeafe;font-size:13px;">'.$this->e($subtext).'</p>';
            $html .= '</div>';
        }

        $html .= '</div></div></section>';

        return $html;
    }

    /**
     * @param  array<string, mixed>|null  $section
     */
    private function sectionHtml(string $title, string $location, ?array $section): string
    {
        if ($section === null) {
            return '<section class="travel-section" style="margin-bottom:24px;"><div style="border:1px solid #e2e8f0;border-radius:22px;padding:22px;background:linear-gradient(180deg,#ffffff,#f8fafc);"><h3 style="margin:0 0 8px;font-size:22px;color:#0f172a;">'.$this->e($title).'</h3><p style="margin:0;color:#475569;line-height:1.7;">No '.$this->e(strtolower($title)).' data was requested for this reply.</p></div></section>';
        }

        if (($section['error'] ?? false) === true) {
            $message = (string) ($section['message'] ?? 'Unable to load this section.');

            return '<section class="travel-section error" style="margin-bottom:24px;"><div style="border:1px solid #fecaca;background:linear-gradient(180deg,#fff7f7,#fef2f2);border-radius:22px;padding:22px;"><h3 style="margin:0 0 8px;font-size:22px;color:#991b1b;">'.$this->e($title).' in '.$this->e($location).'</h3><p style="margin:0;color:#7f1d1d;line-height:1.7;">'.$this->e($message).'</p></div></section>';
        }

        $data = Arr::get($section, 'data', []);
        $items = $this->extractItems($data);
        $count = count($items);
        $singular = $this->singularTitle($title);

        $html = '<section class="travel-section" style="margin-bottom:28px;">';
        $html .= '<div style="display:flex;flex-wrap:wrap;justify-content:space-between;gap:12px;align-items:end;margin-bottom:14px;">';
        $html .= '<div>';
        $html .= '<p style="margin:0 0 6px;font-size:12px;letter-spacing:0.12em;text-transform:uppercase;color:#64748b;">'.$this->e($title).'</p>';
        $html .= '<h3 style="margin:0;font-size:26px;color:#0f172a;">'.$this->e($location).'</h3>';
        $html .= '</div>';
        $html .= '<div style="padding:8px 12px;border-radius:999px;background:#e0f2fe;color:#075985;font-size:13px;font-weight:700;">'.$this->e($this->formatCount($count, strtolower($singular))).'</div>';
        $html .= '</div>';

        if ($count === 0) {
            $html .= '<div style="border:1px dashed #cbd5e1;border-radius:22px;padding:22px;background:#f8fafc;color:#475569;line-height:1.7;">No matching '.$this->e(strtolower($title)).' were found for '.$this->e($location).'.</div>';
            $html .= '</section>';

            return $html;
        }

        $html .= '<div style="border:1px solid #dbeafe;border-radius:22px;background:linear-gradient(180deg,#ffffff,#f8fbff);padding:20px 22px;margin-bottom:18px;box-shadow:0 12px 32px rgba(15,23,42,0.06);">';
        $html .= '<p style="margin:0;color:#334155;font-size:15px;line-height:1.8;">'.$this->e($this->sectionIntro($count, $singular, $location)).'</p>';
        $html .= '</div>';
        $html .= $this->comparisonTable($title, $items);
        $html .= '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(300px,1fr));gap:18px;">';

        foreach ($items as $index => $item) {
            $html .= $this->itemCard($title, $item, $index, $location);
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
    private function itemCard(string $title, array $item, int $index, string $location): string
    {
        $singular = $this->singularTitle($title);
        $headline = $this->displayValue(
            $item['name'] ?? $item['title'] ?? $item['package_name'] ?? $item['trip_name'] ?? null,
            $singular.' '.($index + 1),
        );

        $subtitleParts = array_filter([
            $this->displayValue($item['location'] ?? null),
            $this->displayValue($item['city'] ?? null),
            $this->displayValue($item['country'] ?? null),
        ]);

        $description = $this->displayValue($item['description'] ?? $item['details'] ?? null);
        $chips = array_filter([
            $this->chipValue('Rating', $item['star_rating'] ?? null, 'star_rating'),
            $this->chipValue('Status', $item['status'] ?? null, 'status'),
            $this->chipValue('Price', $item['price'] ?? $item['amount'] ?? null, 'price'),
        ]);
        $narrative = $this->itemNarrative($title, $item, $location);
        $highlights = $this->highlightListItems($item);

        $html = '<article style="height:100%;display:flex;flex-direction:column;border:1px solid #dbeafe;border-radius:24px;padding:20px;background:linear-gradient(180deg,#ffffff,#f8fbff);box-shadow:0 16px 32px rgba(15,23,42,0.08);">';
        $html .= '<div style="display:flex;flex-wrap:wrap;justify-content:space-between;gap:12px;align-items:flex-start;margin-bottom:14px;">';
        $html .= '<div>';
        $html .= '<span style="display:inline-flex;align-items:center;padding:6px 10px;border-radius:999px;background:#dbeafe;color:#1d4ed8;font-size:12px;font-weight:700;margin-bottom:10px;">'.$this->e($singular.' '.($index + 1)).'</span>';
        $html .= '<h4 style="margin:0 0 6px;font-size:20px;line-height:1.35;color:#0f172a;">'.$this->e($singular.' '.($index + 1).': '.$headline).'</h4>';

        if ($subtitleParts !== []) {
            $html .= '<p style="margin:0;color:#475569;font-size:14px;">'.$this->e(implode(' | ', $subtitleParts)).'</p>';
        } else {
            $html .= '<p style="margin:0;color:#64748b;font-size:14px;">Selected for '.$this->e($location).'</p>';
        }

        $html .= '</div>';

        if ($chips !== []) {
            $html .= '<div style="display:flex;flex-wrap:wrap;justify-content:flex-end;gap:8px;">'.implode('', $chips).'</div>';
        }

        $html .= '</div>';

        $html .= '<p style="margin:0 0 14px;color:#334155;font-size:14px;line-height:1.7;"><strong>Overview:</strong> '.$this->e($narrative).'</p>';

        if ($highlights !== []) {
            $html .= '<div style="margin:0 0 16px;padding:14px 16px;border-radius:18px;background:#f8fafc;border:1px solid #e2e8f0;">';
            $html .= '<p style="margin:0 0 8px;font-size:12px;font-weight:700;letter-spacing:0.08em;text-transform:uppercase;color:#64748b;">Key Details</p>';
            $html .= '<ul style="margin:0;padding-left:18px;color:#0f172a;line-height:1.7;">'.implode('', $highlights).'</ul>';
            $html .= '</div>';
        }

        if ($description !== null) {
            $html .= '<div style="margin:0 0 16px;padding:14px 16px;border-radius:18px;background:#f8fafc;border:1px solid #e2e8f0;">';
            $html .= '<p style="margin:0 0 6px;font-size:12px;font-weight:700;letter-spacing:0.08em;text-transform:uppercase;color:#64748b;">Description</p>';
            $html .= '<p style="margin:0;color:#334155;font-size:14px;line-height:1.65;">'.$this->e($this->truncate($description, 260)).'</p>';
            $html .= '</div>';
        }

        $details = $this->detailRows($item);

        if ($details !== []) {
            $html .= '<div style="padding:14px 16px;border-radius:18px;background:#ffffff;border:1px solid #e2e8f0;">';
            $html .= '<p style="margin:0 0 8px;font-size:12px;font-weight:700;letter-spacing:0.08em;text-transform:uppercase;color:#64748b;">More Details</p>';
            $html .= '<ul style="margin:0;padding-left:18px;color:#0f172a;line-height:1.7;">'.implode('', $details).'</ul>';
            $html .= '</div>';
        }

        $bookingHint = $this->bookingHint($title, $headline);

        if ($bookingHint !== null) {
            $html .= '<div style="margin-top:16px;padding:14px 16px;border-radius:18px;background:#ecfeff;border:1px solid #a5f3fc;">';
            $html .= '<p style="margin:0 0 6px;font-size:12px;font-weight:700;letter-spacing:0.08em;text-transform:uppercase;color:#155e75;">Chat Booking</p>';
            $html .= '<p style="margin:0;color:#164e63;font-size:14px;line-height:1.7;">'.$this->e($bookingHint).'</p>';
            $html .= '</div>';
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
        $excluded = [
            'name',
            'title',
            'package_name',
            'trip_name',
            'description',
            'details',
            'location',
            'city',
            'country',
            'star_rating',
            'status',
            'price',
            'amount',
            'id',
            'image',
            'departure_time',
            'arrival_time',
            'origin',
            'destination',
            'route_name',
            'vehicle_type',
            'vehicle_name',
        ];

        foreach ($item as $key => $value) {
            if (in_array($key, $excluded, true)) {
                continue;
            }

            $rendered = $this->renderFieldValue($value, $key);

            if ($rendered === null) {
                continue;
            }

            $rows[] = '<li><strong>'.$this->e($this->labelize($key)).':</strong> '.$rendered.'</li>';
        }

        return $rows;
    }

    /**
     * @param  array<int, string>  $requestedResources
     * @param  array<string, string>  $sections
     */
    private function composeFullHtml(array $requestedResources, string $summary, array $sections): string
    {
        $orderedSections = array_filter(
            array_map(
                fn (string $resource): ?string => $sections[$resource] ?? null,
                $requestedResources,
            ),
            static fn (?string $section): bool => is_string($section) && $section !== '',
        );

        return '<div class="travel-chat-response" style="font-family:ui-sans-serif,system-ui,-apple-system,BlinkMacSystemFont,\'Segoe UI\',sans-serif;background:linear-gradient(180deg,#f8fbff 0%,#ffffff 100%);padding:24px;border-radius:30px;">'
            .$summary
            .implode('', $orderedSections)
            .'</div>';
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     */
    private function comparisonTable(string $title, array $items): string
    {
        $columns = $this->tableColumns($title, $items);
        $html = '<div style="margin-bottom:18px;border:1px solid #dbeafe;border-radius:22px;overflow:hidden;background:#ffffff;box-shadow:0 10px 28px rgba(15,23,42,0.05);">';
        $html .= '<div style="padding:16px 20px;background:linear-gradient(180deg,#eff6ff,#f8fbff);border-bottom:1px solid #dbeafe;">';
        $html .= '<p style="margin:0;font-size:13px;font-weight:700;letter-spacing:0.08em;text-transform:uppercase;color:#1d4ed8;">Quick Comparison</p>';
        $html .= '</div>';
        $html .= '<div style="overflow-x:auto;">';
        $html .= '<table border="1" cellpadding="10" cellspacing="0" width="100%" style="width:100%;border-collapse:collapse;min-width:640px;">';
        $html .= '<thead><tr style="background:#f8fafc;">';

        foreach ($columns as $column) {
            $html .= '<th style="padding:14px 16px;border-bottom:1px solid #e2e8f0;text-align:left;font-size:12px;letter-spacing:0.08em;text-transform:uppercase;color:#64748b;">'.$this->e($column['label']).'</th>';
        }

        $html .= '</tr></thead><tbody>';

        foreach ($items as $index => $item) {
            $html .= '<tr style="vertical-align:top;">';

            foreach ($columns as $column) {
                $html .= '<td style="padding:14px 16px;border-bottom:1px solid #eef2ff;color:#0f172a;font-size:14px;line-height:1.6;">';
                $html .= $this->e($this->tableCellValue($column, $item, $index));
                $html .= '</td>';
            }

            $html .= '</tr>';
        }

        $html .= '</tbody></table></div></div>';

        return $html;
    }

    /**
     * @param  array<int, array<string, string>>  $columns
     * @param  array<string, mixed>  $item
     */
    private function tableCellValue(array $column, array $item, int $index): string
    {
        return match ($column['type']) {
            'index' => (string) ($index + 1),
            'headline' => $this->displayValue(
                $item['name'] ?? $item['title'] ?? $item['package_name'] ?? $item['trip_name'] ?? null,
                $this->singularTitle((string) $column['resource']).' '.($index + 1),
            ) ?? '-',
            default => $this->tableFieldValue((string) $column['key'], $item[$column['key']] ?? null) ?? '-',
        };
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @return array<int, array<string, string>>
     */
    private function tableColumns(string $title, array $items): array
    {
        $columns = [
            [
                'type' => 'index',
                'label' => '#',
                'resource' => $title,
            ],
            [
                'type' => 'headline',
                'label' => $this->singularTitle($title),
                'resource' => $title,
            ],
        ];

        foreach ($this->preferredTableKeys($title) as $key) {
            if (! $this->itemsContainKey($items, $key)) {
                continue;
            }

            $columns[] = [
                'type' => 'field',
                'key' => $key,
                'label' => $this->labelize($key),
                'resource' => $title,
            ];

            if (count($columns) >= 5) {
                break;
            }
        }

        return $columns;
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     */
    private function itemsContainKey(array $items, string $key): bool
    {
        foreach ($items as $item) {
            if (array_key_exists($key, $item) && $this->tableFieldValue($key, $item[$key]) !== null) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<int, string>
     */
    private function preferredTableKeys(string $title): array
    {
        return match ($title) {
            'Hotels' => ['location', 'city', 'country', 'star_rating', 'price', 'status'],
            'Packages' => ['location', 'duration', 'price', 'status', 'category'],
            default => ['location', 'destination', 'price', 'status', 'departure_time'],
        };
    }

    private function tableFieldValue(string $key, mixed $value): ?string
    {
        $text = $this->displayTextForKey($key, $value);

        return $text === null ? null : $this->truncate($text, 60);
    }

    /**
     * @param  array<int, array{label: string, count: int|null, error: bool}>  $resourceSnapshots
     * @param  array<int, string>  $failed
     */
    private function overviewSentence(string $location, array $resourceSnapshots, array $failed): string
    {
        $available = [];

        foreach ($resourceSnapshots as $snapshot) {
            if ($snapshot['error'] || $snapshot['count'] === null) {
                continue;
            }

            $available[] = $this->formatCount((int) $snapshot['count'], strtolower($this->singularTitle($snapshot['label'])));
        }

        $sentence = $available === []
            ? 'Travel information could not be assembled for '.$location.' right now.'
            : 'Here is a polished travel snapshot for '.$location.', including '.$this->humanJoin($available).'.';

        if ($failed !== []) {
            $failedLabels = array_map(
                fn (string $resource): string => strtolower($resource).' data',
                $failed,
            );

            $sentence .= ' '.ucfirst($this->humanJoin($failedLabels)).' '.(count($failedLabels) === 1 ? 'is' : 'are').' currently unavailable.';
        }

        return $sentence;
    }

    private function sectionIntro(int $count, string $singular, string $location): string
    {
        $resource = strtolower($singular);

        if ($count === 1) {
            return 'There is 1 '.$resource.' available for '.$location.'. The table below gives a quick scan, and the detailed block keeps the response natural and easy to read.';
        }

        return 'There are '.$count.' '.$resource.'s available for '.$location.'. The table below gives a quick scan, and the detailed blocks read more like a polished human response.';
    }

    private function singularTitle(string $title): string
    {
        return match ($title) {
            'Trips' => 'Trip',
            'Packages' => 'Package',
            'Hotels' => 'Hotel',
            default => rtrim($title, 's'),
        };
    }

    private function bookingHint(string $title, string $headline): ?string
    {
        if ($title !== 'Trips' || trim($headline) === '') {
            return null;
        }

        return 'Reply: book the '.$headline.' trip. If you do not know the seats yet, chat will show the available seat numbers first.';
    }

    private function formatCount(int $count, string $resource): string
    {
        return $count.' '.$resource.($count === 1 ? '' : 's');
    }

    /**
     * @param  array<int, string>  $parts
     */
    private function humanJoin(array $parts): string
    {
        $parts = array_values(array_filter($parts, fn (string $part): bool => trim($part) !== ''));

        if ($parts === []) {
            return '';
        }

        if (count($parts) === 1) {
            return $parts[0];
        }

        if (count($parts) === 2) {
            return $parts[0].' and '.$parts[1];
        }

        $last = array_pop($parts);

        return implode(', ', $parts).', and '.$last;
    }

    private function renderFieldValue(mixed $value, ?string $key = null): ?string
    {
        if ($value === null) {
            return null;
        }

        $text = $this->displayTextForKey($key ?? '', $value);

        if ($text === null) {
            return null;
        }

        if (filter_var($text, FILTER_VALIDATE_EMAIL)) {
            return '<a href="mailto:'.$this->e($text).'" style="color:#0369a1;text-decoration:none;">'.$this->e($text).'</a>';
        }

        if (filter_var($text, FILTER_VALIDATE_URL)) {
            return '<a href="'.$this->e($text).'" target="_blank" rel="noopener noreferrer" style="color:#0369a1;text-decoration:none;">'.$this->e($text).'</a>';
        }

        return $this->e($this->truncate($text, 140));
    }

    private function badge(string $label, string $background, string $color): string
    {
        return '<span style="display:inline-flex;align-items:center;padding:8px 12px;border-radius:999px;background:'.$background.';color:'.$color.';font-size:13px;font-weight:700;">'.$this->e($label).'</span>';
    }

    private function chipValue(string $label, mixed $value, ?string $key = null): ?string
    {
        $display = $this->displayTextForKey($key ?? '', $value);

        if ($display === null) {
            return null;
        }

        return '<span style="display:inline-flex;align-items:center;padding:6px 10px;border-radius:999px;background:#eff6ff;color:#1d4ed8;font-size:12px;font-weight:700;">'.$this->e($label.': '.$display).'</span>';
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<int, string>
     */
    private function highlightListItems(array $item): array
    {
        $highlights = [];

        $price = $this->displayTextForKey('price', $item['price'] ?? $item['amount'] ?? null);
        if ($price !== null) {
            $highlights[] = '<li><strong>Price:</strong> '.$this->e($price).'</li>';
        }

        $status = $this->displayTextForKey('status', $item['status'] ?? null);
        if ($status !== null) {
            $highlights[] = '<li><strong>Status:</strong> '.$this->e($status).'</li>';
        }

        $schedule = $this->scheduleSummary($item);
        if ($schedule !== null) {
            $highlights[] = '<li><strong>Schedule:</strong> '.$this->e($schedule).'</li>';
        }

        $route = $this->routeSummary($item);
        if ($route !== null) {
            $highlights[] = '<li><strong>Route:</strong> '.$this->e($route).'</li>';
        }

        $transport = $this->transportSummary($item);
        if ($transport !== null) {
            $highlights[] = '<li><strong>Transport:</strong> '.$this->e($transport).'</li>';
        }

        $rating = $this->displayTextForKey('star_rating', $item['star_rating'] ?? null);
        if ($rating !== null) {
            $highlights[] = '<li><strong>Rating:</strong> '.$this->e($rating).'</li>';
        }

        return $highlights;
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function itemNarrative(string $title, array $item, string $location): string
    {
        $singular = strtolower($this->singularTitle($title));
        $parts = ['This '.$singular.' is listed for '.$location.'.'];

        $route = $this->routeSummary($item);
        if ($route !== null) {
            $parts[] = 'It covers '.$route.'.';
        }

        $schedule = $this->scheduleSummary($item);
        if ($schedule !== null) {
            $parts[] = 'The schedule is '.$schedule.'.';
        }

        $transport = $this->transportSummary($item);
        if ($transport !== null) {
            $parts[] = 'Transport details: '.$transport.'.';
        }

        return implode(' ', $parts);
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function routeSummary(array $item): ?string
    {
        $origin = $this->displayTextForKey('origin', $item['origin'] ?? null);
        $destination = $this->displayTextForKey('destination', $item['destination'] ?? null);
        $routeName = $this->displayTextForKey('route_name', $item['route_name'] ?? null);

        if ($origin !== null && $destination !== null) {
            $summary = $origin.' to '.$destination;

            if ($routeName !== null) {
                $summary .= ' via '.$routeName;
            }

            return $summary;
        }

        if ($routeName !== null) {
            return $routeName;
        }

        return $destination;
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function scheduleSummary(array $item): ?string
    {
        $departure = $this->displayTextForKey('departure_time', $item['departure_time'] ?? $item['departure_date'] ?? null);
        $arrival = $this->displayTextForKey('arrival_time', $item['arrival_time'] ?? null);

        if ($departure !== null && $arrival !== null) {
            return $departure.' to '.$arrival;
        }

        return $departure ?? $arrival;
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function transportSummary(array $item): ?string
    {
        $vehicleType = $this->displayTextForKey('vehicle_type', $item['vehicle_type'] ?? null);
        $vehicleName = $this->displayTextForKey('vehicle_name', $item['vehicle_name'] ?? null);

        if ($vehicleType !== null && $vehicleName !== null) {
            return $vehicleType.' via '.$vehicleName;
        }

        return $vehicleType ?? $vehicleName;
    }

    private function displayTextForKey(string $key, mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_bool($value)) {
            return $value ? 'Yes' : 'No';
        }

        if (is_array($value)) {
            $flattened = array_filter(array_map(
                fn (mixed $entry): ?string => is_scalar($entry) ? trim((string) $entry) : null,
                $value,
            ));

            if ($flattened === []) {
                return null;
            }

            return implode(', ', $flattened);
        }

        if (! is_scalar($value)) {
            return null;
        }

        $string = trim((string) $value);

        if ($string === '') {
            return null;
        }

        return match ($key) {
            'status' => $this->statusText($string),
            'price', 'amount' => $this->formatNumber($string),
            'departure_time', 'arrival_time', 'departure_date' => $this->formatDateTime($string),
            'star_rating' => is_numeric($string) ? $string.' star'.((float) $string === 1.0 ? '' : 's') : $string,
            default => $string,
        };
    }

    private function statusText(string $value): string
    {
        return match (strtolower(trim($value))) {
            '1', 'active', 'available' => 'Available',
            '0', 'inactive', 'unavailable' => 'Unavailable',
            default => ucfirst($value),
        };
    }

    private function formatNumber(string $value): string
    {
        if (! is_numeric($value)) {
            return $value;
        }

        return number_format((float) $value, 2, '.', ',');
    }

    private function formatDateTime(string $value): string
    {
        $timestamp = strtotime($value);

        if ($timestamp === false) {
            return $value;
        }

        return date('d M Y, h:i A', $timestamp);
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
