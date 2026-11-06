<?php

namespace App\Services\Admin;

use Carbon\CarbonImmutable;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Cell\EmptyCell;
use OpenSpout\Common\Entity\Cell\NumericCell;
use OpenSpout\Common\Entity\Cell\StringCell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Entity\SheetView;
use OpenSpout\Writer\XLSX\Options;
use OpenSpout\Writer\XLSX\Writer;

/**
 * Admin → Reports → "Download Excel" (owner 2026-11-03): one .xlsx with every report for the chosen dates, built from
 * the same ReportsService numbers the Reports page shows. Text always goes in as text cells (never formulas), money as
 * real numbers with a money format, so the file is safe to open and easy to sum. Written row by row to a temp file
 * (OpenSpout), so memory stays small. The "Top customers" sheet holds names and emails: admins only.
 */
class ReportsWorkbook
{
    private const TOP = 20;

    private Style $title;

    private Style $head;

    private Style $usd;

    private Style $khr;

    private Style $percent;

    public function __construct(private readonly ReportsService $reports, private readonly DashboardInsightsService $insights)
    {
        $this->title = (new Style)->withFontBold(true)->withFontSize(14);
        $this->head = (new Style)->withFontBold(true)->withBackgroundColor('E8ECF4');
        $this->usd = (new Style)->withFormat('"$"#,##0.00');
        $this->khr = (new Style)->withFormat('#,##0" ៛"');
        $this->percent = (new Style)->withFormat('0.00"%"');
    }

    /** Writes the workbook to $path. $from and $to are shop-time dates (Y-m-d), as on the Reports page. */
    public function write(string $path, string $from, string $to): void
    {
        [$start, $end] = $this->reports->range($from, $to);

        $options = new Options;
        $options->setColumnWidth(30, 1);
        $options->setColumnWidthForRange(16, 2, 12);
        $writer = new Writer($options);
        $writer->openToFile($path);

        $sheets = [
            'Summary' => fn () => $this->summary($start, $end, $from, $to),
            'Sales by day' => fn () => $this->salesByDay($start, $end),
            'Top books' => fn () => $this->topBooks($start, $end),
            'Top customers' => fn () => $this->topCustomers($start, $end),
            'Orders' => fn () => $this->orders($start, $end),
            'Coupons and refunds' => fn () => $this->coupons($start, $end),
            'Offers' => fn () => $this->offers($start, $end),
            'Analysis' => fn () => $this->analysis($start, $end),
        ];

        $first = true;
        foreach ($sheets as $name => $rows) {
            $sheet = $first ? $writer->getCurrentSheet() : $writer->addNewSheetAndMakeItCurrent();
            $first = false;
            $sheet->setName($name);
            if ($name !== 'Summary') {
                $sheet->setSheetView((new SheetView)->withFreezeRow(3)); // title and column names stay in view
            }
            foreach ($rows() as $row) {
                $writer->addRow(new Row($row));
            }
        }

        $writer->close();
    }

    /** @return list<list<Cell>> */
    private function analysis(CarbonImmutable $start, CarbonImmutable $end): array
    {
        $data = $this->insights->insights($start, $end);
        $recovery = $data['cart_reminder_recovery'];
        $promotions = $data['promotions'];
        $rows = [
            [$this->text('Dashboard analysis', $this->title)],
            $this->header(['Measure', 'Value']),
            [$this->text('Repeat customer rate'), $this->number($data['repeat_customer_rate']['percent'], $this->percent)],
            [$this->text('Matured reminders sent'), $this->number($recovery['sent'])],
            [$this->text('Reminders followed by an order'), $this->number($recovery['recovered'])],
            [$this->text('Reminder recovery rate'), $this->number($recovery['percent'], $this->percent)],
            [$this->text('Orders with offers'), $this->number($promotions['offer_orders'])],
            [$this->text('Orders with coupons'), $this->number($promotions['coupon_orders'])],
            [$this->text('Orders with free gifts'), $this->number($promotions['gift_orders'])],
            [$this->text('Average order rating'), $this->number($data['order_ratings']['average'])],
            [$this->text('Order rating count'), $this->number($data['order_ratings']['count'])],
            [$this->text('Phnom Penh orders'), $this->number($data['delivery_areas']['phnom_penh'])],
            [$this->text('Province orders'), $this->number($data['delivery_areas']['provinces'])],
            [$this->text('Unknown location orders'), $this->number($data['delivery_areas']['unknown'])],
            [],
            $this->header(['Payment method', 'Orders', 'Placed order total USD', 'Share %']),
        ];
        foreach ($data['payment_methods'] as $payment) {
            $rows[] = [$this->text($payment['method']), $this->number($payment['orders']), $this->number($payment['total_usd'], $this->usd), $this->number($payment['percent'], $this->percent)];
        }
        $rows[] = [];
        $rows[] = $this->header(['Province', 'Orders', 'Placed order total USD']);
        foreach ($data['provinces'] as $province) {
            $rows[] = [$this->text($province['name']), $this->number($province['orders']), $this->number($province['total_usd'], $this->usd)];
        }

        return $rows;
    }

    /** @return list<list<Cell>> */
    private function summary(CarbonImmutable $start, CarbonImmutable $end, string $from, string $to): array
    {
        $sales = $this->reports->sales($start, $end, 'day')['totals'];
        $orders = $this->reports->orders($start, $end);
        $customers = $this->reports->customers($start, $end);

        return [
            [$this->text('Bookly sales report', $this->title)],
            [$this->text("{$from} to {$to} (shop time, {$start->timezoneName})"), $this->text('Made '.now($start->timezone)->format('Y-m-d H:i'))],
            [],
            $this->header(['Money', 'USD', 'KHR']),
            $this->moneyRow('Gross revenue (delivered orders)', $sales['gross_revenue_usd'], $sales['gross_revenue_khr']),
            $this->moneyRow('Refunds', $sales['refunds_usd'], $sales['refunds_khr']),
            $this->moneyRow('Net revenue', $sales['net_revenue_usd'], $sales['net_revenue_khr']),
            $this->moneyRow('Discounts given', $sales['discount_usd'], $sales['discount_khr']),
            $this->moneyRow('Shipping charged', $sales['shipping_usd'], $sales['shipping_khr']),
            $this->moneyRow('Average order', $sales['average_order_value_usd'], $sales['average_order_value_khr']),
            [],
            $this->header(['Counts', 'Value']),
            [$this->text('Orders placed'), $this->number($orders['total_orders'])],
            [$this->text('Orders delivered'), $this->number($sales['orders_count'])],
            [$this->text('Books sold'), $this->number($sales['items_sold'])],
            [$this->text('Cancellation rate'), $this->number($orders['cancellation_rate'], $this->percent)],
            [$this->text('Average delivery time (hours)'), $this->number($orders['average_delivery_hours'])],
            [$this->text('New customers'), $this->number($customers['total_new_customers'])],
            [$this->text('Returning customer rate'), $this->number($customers['returning_customer_rate'], $this->percent)],
        ];
    }

    /** @return list<list<Cell>> */
    private function salesByDay(CarbonImmutable $start, CarbonImmutable $end): array
    {
        $rows = [
            [$this->text('Sales by day (delivered orders)', $this->title)],
            $this->header(['Date', 'Orders delivered', 'Gross USD', 'Gross KHR', 'Refunds USD', 'Refunds KHR', 'Net USD', 'Net KHR', 'Books sold', 'Average order USD']),
        ];
        foreach ($this->reports->sales($start, $end, 'day')['series'] as $day) {
            $rows[] = [
                $this->text($day['date']),
                $this->number($day['orders_count']),
                $this->number($day['gross_revenue_usd'], $this->usd),
                $this->number($day['gross_revenue_khr'] ?? null, $this->khr),
                $this->number($day['refunds_usd'], $this->usd),
                $this->number($day['refunds_khr'] ?? null, $this->khr),
                $this->number($day['net_revenue_usd'], $this->usd),
                $this->number($day['net_revenue_khr'] ?? null, $this->khr),
                $this->number($day['items_sold']),
                $this->number($day['average_order_value_usd'], $this->usd),
            ];
        }

        return $rows;
    }

    /** @return list<list<Cell>> */
    private function topBooks(CarbonImmutable $start, CarbonImmutable $end): array
    {
        $report = $this->reports->products($start, $end, self::TOP);
        $rows = [[$this->text('Best sellers by revenue', $this->title)], $this->header(['Title', 'Format', 'Copies sold', 'Revenue USD', 'Revenue KHR'])];
        $book = fn (array $b) => [
            $this->text($b['title']), $this->text(ucfirst((string) $b['format'])), $this->number($b['units_sold']),
            $this->number($b['revenue_usd'], $this->usd), $this->number($b['revenue_khr'] ?? null, $this->khr),
        ];
        foreach ($report['best_sellers_by_revenue'] as $b) {
            $rows[] = $book($b);
        }
        $rows[] = [];
        $rows[] = [$this->text('Best sellers by copies', $this->title)];
        $rows[] = $this->header(['Title', 'Format', 'Copies sold', 'Revenue USD', 'Revenue KHR']);
        foreach ($report['best_sellers_by_units'] as $b) {
            $rows[] = $book($b);
        }
        $rows[] = [];
        $rows[] = [$this->text('Revenue by category', $this->title)];
        $rows[] = $this->header(['Category', 'Copies sold', 'Revenue USD', 'Revenue KHR']);
        foreach ($report['revenue_by_category'] as $c) {
            $rows[] = [$this->text($c['name']), $this->number($c['units_sold']), $this->number($c['revenue_usd'], $this->usd), $this->number($c['revenue_khr'] ?? null, $this->khr)];
        }

        return $rows;
    }

    /** @return list<list<Cell>> */
    private function topCustomers(CarbonImmutable $start, CarbonImmutable $end): array
    {
        $rows = [[$this->text('Top customers by money spent (personal data: keep this file private)', $this->title)], $this->header(['Name', 'Email', 'Orders', 'Spent USD', 'Spent KHR'])];
        foreach ($this->reports->customers($start, $end)['top_customers'] as $c) {
            $rows[] = [
                $this->text($c['name']), $this->text($c['email']), $this->number($c['orders_count']),
                $this->number($c['total_spent_usd'], $this->usd), $this->number($c['total_spent_khr'] ?? null, $this->khr),
            ];
        }

        return $rows;
    }

    /** @return list<list<Cell>> */
    private function orders(CarbonImmutable $start, CarbonImmutable $end): array
    {
        $report = $this->reports->orders($start, $end);
        $rows = [[$this->text('Orders placed in the period', $this->title)], $this->header(['By status', 'Orders'])];
        foreach ($report['orders_by_status'] as $status => $count) {
            $rows[] = [$this->text(ucfirst((string) $status)), $this->number($count)];
        }
        $rows[] = [];
        $rows[] = $this->header(['By payment method', 'Orders']);
        $methods = ['cod' => 'Cash on delivery', 'khqr' => 'KHQR', 'card' => 'Card'];
        foreach ($report['orders_by_payment_method'] as $method => $count) {
            $rows[] = [$this->text($methods[$method] ?? ucfirst((string) $method)), $this->number($count)];
        }
        $rows[] = [];
        $rows[] = $this->header(['By delivery area', 'Orders']);
        $rows[] = [$this->text('Phnom Penh'), $this->number($report['orders_by_delivery_area']['phnom_penh'] ?? 0)];
        $rows[] = [$this->text('Provinces'), $this->number($report['orders_by_delivery_area']['provinces'] ?? 0)];

        return $rows;
    }

    /** @return list<list<Cell>> */
    private function coupons(CarbonImmutable $start, CarbonImmutable $end): array
    {
        $report = $this->reports->coupons($start, $end);
        $rows = [[$this->text('Coupons', $this->title)], $this->header(['Code', 'Type', 'Value', 'Times used', 'Discount USD', 'Discount KHR', 'Order revenue USD', 'Order revenue KHR'])];
        foreach ($report['coupons'] as $c) {
            $percentage = $c['type'] === 'percentage';
            $rows[] = [
                $this->text($c['code']), $this->text($percentage ? 'Percent off' : 'Fixed amount'),
                $this->number($c['value'], $percentage ? $this->percent : $this->usd), $this->number($c['times_used']),
                $this->number($c['discount_usd'], $this->usd), $this->number($c['discount_khr'] ?? null, $this->khr),
                $this->number($c['revenue_usd'], $this->usd), $this->number($c['revenue_khr'] ?? null, $this->khr),
            ];
        }
        $refunds = $report['refunds'];
        $rows[] = [];
        $rows[] = [$this->text('Refunds', $this->title)];
        $rows[] = $this->header(['Reason', 'Refunds', 'Amount USD']);
        foreach ($refunds['by_reason'] as $r) {
            $rows[] = [$this->text($r['reason'] ?? 'Not given'), $this->number($r['count']), $this->number($r['amount_usd'], $this->usd)];
        }
        $rows[] = [$this->text('All refunds', $this->head), $this->number($refunds['count']), $this->number($refunds['amount_usd'], $this->usd)];

        return $rows;
    }

    /** @return list<list<Cell>> */
    private function offers(CarbonImmutable $start, CarbonImmutable $end): array
    {
        $report = $this->reports->offers($start, $end);
        $rows = [[$this->text('Offers ("buy N, save %")', $this->title)], $this->header(['Offer', 'Rule', 'Orders', 'Books', 'Books at offer price', 'Revenue USD', 'Discount USD', 'Average order USD', 'Books per order'])];
        $line = fn (string $name, string $rule, array $o) => [
            $this->text($name), $this->text($rule), $this->number($o['orders']), $this->number($o['units']), $this->number($o['units_with_offer']),
            $this->number($o['revenue_usd'], $this->usd), $this->number($o['discount_usd'], $this->usd),
            $this->number($o['average_order_usd'], $this->usd), $this->number($o['books_per_order']),
        ];
        foreach ($report['offers'] as $o) {
            $rows[] = $line($o['name'], 'Buy '.$o['min_quantity'].', save '.rtrim(rtrim((string) $o['percent_off'], '0'), '.').'%', $o);
        }
        if (isset($report['without_offer'])) {
            $rows[] = $line('Orders without an offer', '', $report['without_offer']);
        }

        return $rows;
    }

    /** @return list<Cell> */
    private function header(array $labels): array
    {
        return array_map(fn (string $label) => $this->text($label, $this->head), $labels);
    }

    /** @return list<Cell> */
    private function moneyRow(string $label, mixed $usd, mixed $khr): array
    {
        return [$this->text($label), $this->number($usd, $this->usd), $this->number($khr, $this->khr)];
    }

    /** Always a text cell: a name like "=SUM(…)" or "+855…" stays plain text, never a formula. */
    private function text(?string $value, ?Style $style = null): Cell
    {
        return ($value === null || $value === '') ? new EmptyCell(null, $style) : new StringCell($value, $style);
    }

    /** A real number (sums and sorts in Excel); empty when there is no value (e.g. riel without an exchange rate). */
    private function number(mixed $value, ?Style $style = null): Cell
    {
        if ($value === null || $value === '' || ! is_numeric($value)) {
            return new EmptyCell(null, $style);
        }

        return new NumericCell(str_contains((string) $value, '.') ? (float) $value : (int) $value, $style);
    }
}
