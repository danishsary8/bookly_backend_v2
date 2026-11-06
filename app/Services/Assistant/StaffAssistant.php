<?php

namespace App\Services\Assistant;

use App\Models\StaffUser;
use App\Models\SupportConversation;
use App\Services\Admin\DashboardInsightsService;
use App\Services\Admin\DashboardService;
use Carbon\CarbonImmutable;

class StaffAssistant
{
    public function __construct(private readonly QuestionText $text, private readonly DashboardService $dashboard, private readonly DashboardInsightsService $insights) {}

    public function answer(string $question, StaffUser $staff): array
    {
        $text = $this->text->normalize($question);
        $topic = match (true) {
            $this->contains($text, ['support', 'chat', 'ជំនួយ', 'សន្ទនា']) => 'support',
            $this->contains($text, ['low stock', 'stock', 'ស្តុក']) => 'stock',
            $this->contains($text, ['return', 'refund', 'ត្រឡប់']) => 'returns',
            $this->contains($text, ['pending order', 'waiting order', 'orders waiting', 'ការបញ្ជាទិញ']) => 'orders',
            $this->contains($text, ['top categories', 'best categories', 'ប្រភេទលក់']) => 'categories',
            $this->contains($text, ['top books', 'best seller', 'bestseller', 'សៀវភៅលក់']) => 'books',
            $this->contains($text, ['average order', 'order value', 'តម្លៃមធ្យម']) => 'average',
            $this->contains($text, ['rating', 'ratings', 'វាយតម្លៃ']) => 'ratings',
            $this->contains($text, ['new customer', 'customers', 'អតិថិជនថ្មី']) => 'customers',
            $this->contains($text, ['sales', 'revenue', 'ចំណូល', 'ការលក់']) => 'sales',
            default => null,
        };
        if (! $topic) {
            return ['intent' => 'unknown', 'text' => __('assistant.staff_unknown'), 'link' => null];
        }
        $permission = match ($topic) {
            'support' => 'support:manage', 'stock' => 'catalog:view', 'orders', 'returns' => 'orders:view',
            default => 'admin',
        };
        if (! $staff->tokenCan($permission) || ($permission === 'admin' ? ! $staff->isAdmin() : ! $staff->hasPermission($permission))) {
            return ['intent' => $topic, 'text' => __('assistant.unavailable'), 'link' => null];
        }
        if (in_array($topic, ['support', 'stock', 'orders', 'returns'], true)) {
            $counts = $topic === 'support' ? null : $this->insights->counts();
            $value = match ($topic) {
                'support' => SupportConversation::whereIn('status', ['open', 'pending'])->count(),
                'stock' => $counts['low_stock'], 'orders' => $counts['pending_orders'], 'returns' => $counts['open_returns'],
            };
            $link = match ($topic) {
                'support' => '/admin/support', 'stock' => '/admin/stock', 'orders' => '/admin/orders', 'returns' => '/admin/returns'
            };

            return ['intent' => $topic, 'text' => __('assistant.staff_count', ['label' => __('assistant.staff_labels.'.$topic), 'value' => $value]), 'link' => $link];
        }
        $today = CarbonImmutable::now(config('shop.timezone'))->startOfDay();
        [$start, $end] = match (true) {
            $this->contains($text, ['today', 'ថ្ងៃនេះ']) => [$today, $today->addDay()],
            $this->contains($text, ['week', 'សប្តាហ៍']) => [$today->startOfWeek(), $today->addDay()],
            $this->contains($text, ['month', 'ខែនេះ']) => [$today->startOfMonth(), $today->addDay()],
            default => $this->dashboard->period('30d'),
        };
        $summary = $this->dashboard->summary($start, $end);
        $label = __('assistant.staff_labels.'.$topic);
        $value = match ($topic) {
            'sales' => '$'.$summary['revenue']['net_usd'],
            'average' => '$'.$summary['average_order_value_usd'],
            'customers' => (string) $summary['new_customers'],
            'ratings' => (string) ($this->insights->insights($start, $end)['order_ratings']['average'] ?? '—'),
            'books' => collect($summary['best_sellers'])->take(5)->map(fn ($row) => $row['title'].' ('.$row['copies_sold'].')')->implode(', '),
            'categories' => collect($summary['top_categories'])->take(5)->map(fn ($row) => $row['name'].' ('.$row['copies_sold'].')')->implode(', '),
        };
        $previous = match ($topic) {
            'sales' => '$'.$summary['comparison']['revenue']['net_usd'],
            'average' => '$'.$summary['comparison']['average_order_value_usd'],
            'customers' => (string) $summary['comparison']['new_customers'],
            default => null,
        };
        $answer = $value === '' ? __('assistant.staff_empty') : ($previous === null
            ? __('assistant.staff_count', compact('label', 'value'))
            : __('assistant.staff_comparison', ['label' => $label, 'current' => $value, 'previous' => $previous]));

        $link = match ($topic) {
            'books' => '/admin/books', 'categories' => '/admin/categories', 'customers' => '/admin/customers', 'ratings' => '/admin/ratings',
            default => '/admin',
        };

        return ['intent' => $topic, 'text' => $answer, 'link' => $link];
    }

    private function contains(string $text, array $phrases): bool
    {
        foreach ($phrases as $phrase) {
            if (str_contains($text, $phrase)) {
                return true;
            }
        }

        return false;
    }
}
