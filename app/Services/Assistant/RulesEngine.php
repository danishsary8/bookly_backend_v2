<?php

namespace App\Services\Assistant;

use App\Http\Controllers\Api\V1\Catalog\BookController;
use App\Models\AssistantFaq;
use App\Models\Customer;
use App\Models\Order;
use App\Services\Orders\DeliveryArea;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class RulesEngine implements AssistantEngine
{
    public function __construct(private readonly QuestionText $text, private readonly BookQueryParser $parser) {}

    public function answer(string $question, ?Customer $customer): array
    {
        $normalized = $this->text->normalize($question);
        if (! $this->matches($normalized, ['cancel', 'return', 'rate', 'បោះបង់', 'ត្រឡប់', 'វាយតម្លៃ'])
            && ($this->matches($normalized, ['order status', 'my order', 'where is my order', 'track order', 'ការបញ្ជាទិញ', 'តាមដាន'])
                || ($this->matches($normalized, ['order']) && $this->matches($normalized, ['status', 'where', 'track'])))) {
            return $this->order($question, $customer);
        }
        foreach (AssistantFaq::where('is_active', true)->cursor() as $faq) {
            foreach ([$faq->question_en, $faq->question_km, ...($faq->keywords ?? [])] as $keyword) {
                $needle = $this->text->normalize((string) $keyword);
                if ($needle !== '' && $this->matches($normalized, [$needle])) {
                    $faq->increment('hits');

                    return $this->result('faq', app()->getLocale() === 'km' ? $faq->answer_km : $faq->answer_en);
                }
            }
        }
        if (($this->matches($normalized, ['search', 'find', 'manga', 'comics', 'grade', 'english file', 'under', 'below', 'ស្វែងរក', 'ថ្នាក់ទី', 'ក្រោម'])
                || preg_match('/\b[abc][12]\b/u', $normalized))
            && ! $this->matches($normalized, ['read ebook', 'ebook help', 'download', 'device', 'អានសៀវភៅ', 'ឧបករណ៍'])) {
            return $this->books($this->text->redact($question));
        }
        if ($this->matches($normalized, ['deliver', 'delivery', 'shipping', 'ដឹកជញ្ជូន'])) {
            $answer = __('assistant.delivery', ['phnom' => config('shop.shipping_fees.phnom_penh'), 'provinces' => config('shop.shipping_fees.provinces')]);
            if ((float) config('shop.free_shipping_threshold') > 0) {
                $answer .= __('assistant.free_delivery', ['threshold' => config('shop.free_shipping_threshold')]);
            }
            $provinces = collect(DeliveryArea::PROVINCES_LIST)->where('area', DeliveryArea::PROVINCES);
            $answer .= __('assistant.delivery_time', ['phnom_days' => DeliveryArea::PROVINCES_LIST[0]['days_min'], 'province_min' => $provinces->min('days_min'), 'province_max' => $provinces->max('days_max')]);

            return $this->result('delivery', $answer, '/shipping');
        }
        foreach ([
            'payment' => [['payment', 'pay', 'khqr', 'bakong', 'cash on delivery', 'ទូទាត់', 'បង់ប្រាក់'], '/checkout'],
            'returns' => [['return', 'refund', 'ត្រឡប់', 'បង្វិលប្រាក់'], '/returns-policy'],
            'ebook' => [['ebook', 'read online', 'download', 'device', 'អេឡិចត្រូនិក', 'ឧបករណ៍'], '/account/ebooks'],
            'register' => [['create account', 'create an account', 'register', 'sign up', 'បង្កើតគណនី', 'ចុះឈ្មោះ'], '/register'],
            'account' => [['password', 'account', 'session', 'ពាក្យសម្ងាត់', 'គណនី'], '/account/security'],
            'contact' => [['contact', 'hours', 'open time', 'ទំនាក់ទំនង', 'ម៉ោង'], '/contact'],
            'thanks' => [['thank', 'thanks', 'អរគុណ'], null],
            'greeting' => [['hello', 'hi', 'hey', 'សួស្តី', 'ជំរាបសួរ'], null],
        ] as $intent => [$keywords, $link]) {
            if ($this->matches($normalized, $keywords)) {
                return $this->result($intent, __('assistant.'.$intent, ['days' => config('shop.return_window_days'), 'opens' => config('shop.support_hours.opens'), 'closes' => config('shop.support_hours.closes')]), $link);
            }
        }
        if ($this->matches($normalized, ['book', 'books', 'សៀវភៅ'])) {
            return $this->books($this->text->redact($question));
        }
        $redacted = $this->text->redact($question);
        if ($redacted !== '') {
            $hash = hash('sha256', $this->text->normalize($redacted));
            DB::statement('INSERT INTO assistant_unanswered (fingerprint, question, count, last_seen) VALUES (?, ?, 1, ?) ON CONFLICT (fingerprint) DO UPDATE SET count = assistant_unanswered.count + 1, last_seen = EXCLUDED.last_seen', [$hash, $redacted, now()]);
        }

        return $this->result('unknown', __('assistant.unknown'), handoff: true);
    }

    private function order(string $question, ?Customer $customer): array
    {
        if (! $customer || ! $customer->isVerified()) {
            return $this->result('order', __('assistant.order_signin'), '/login');
        }
        $query = Order::where('customer_id', $customer->id);
        if (preg_match('/\b(?:BK|ORD)[-\w]*\d[-\w]*\b/i', $question, $match)) {
            $query->where('order_number', $match[0]);
        } elseif (preg_match('/(?:order|ការបញ្ជាទិញ)\s*#?\s*(\d+)\b/iu', $question, $match)) {
            $query->whereKey((int) $match[1]);
        }
        $order = $query->orderByDesc('placed_at')->orderByDesc('id')->first();
        if (! $order) {
            return $this->result('order', __('assistant.order_missing'), '/account/orders');
        }

        return $this->result('order', __('assistant.order_status', ['number' => $order->order_number, 'status' => __('assistant.statuses.'.$order->status->value)]), '/account/orders/'.$order->id);
    }

    private function books(string $question): array
    {
        if (preg_match('/(?:under|below|less than|ក្រោម)\s*\$?\s*0(?:\.0{1,2})?(?![\d.])/iu', $question)) {
            return $this->result('books', __('assistant.books'), '/books');
        }
        $query = Request::create('/api/v1/books', 'GET', $this->parser->parse($question));
        $data = app(BookController::class)->index($query)->response($query)->getData(true);

        return $this->result('books', __('assistant.books'), '/books', $data['data']);
    }

    private function matches(string $text, array $words): bool
    {
        foreach ($words as $word) {
            if (preg_match('/[a-z]/', $word)) {
                if (preg_match('/(?<![a-z])'.preg_quote($word, '/').'(?![a-z])/u', $text)) {
                    return true;
                }
            } elseif (str_contains($text, $word)) {
                return true;
            }
        }

        return false;
    }

    private function result(string $intent, string $text, ?string $link = null, array $books = [], bool $handoff = false): array
    {
        return compact('intent', 'text', 'link', 'books', 'handoff');
    }
}
