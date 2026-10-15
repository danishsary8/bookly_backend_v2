<?php

namespace App\Http\Controllers\Api\V1\Staff;

use App\Http\Controllers\Controller;
use App\Models\Book;
use App\Models\MarketingOutbox;
use App\Models\NewsletterCampaign;
use App\Models\NewsletterSubscriber;
use App\Notifications\NewsletterCampaignNotification;
use App\Services\Admin\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

class StaffNewsletterController extends Controller
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    /**
     * Subscriber counts by status.
     */
    public function summary(): JsonResponse
    {
        $counts = NewsletterSubscriber::query()
            ->select('status', DB::raw('count(*) as count'))
            ->groupBy('status')
            ->pluck('count', 'status')
            ->all();

        $confirmed = (int) ($counts['confirmed'] ?? 0);
        $pending = (int) ($counts['pending'] ?? 0);
        $unsubscribed = (int) ($counts['unsubscribed'] ?? 0);
        $total = $confirmed + $pending + $unsubscribed;

        return response()->json([
            'confirmed' => $confirmed,
            'pending' => $pending,
            'unsubscribed' => $unsubscribed,
            'total' => $total,
        ]);
    }

    /**
     * List newsletter campaigns.
     */
    public function indexCampaigns(Request $request): JsonResponse
    {
        $campaigns = NewsletterCampaign::with('createdByStaff:id,name,email')
            ->orderBy('id', 'desc')
            ->paginate($request->integer('per_page', 20));

        return response()->json($campaigns);
    }

    /**
     * Create a new draft campaign.
     */
    public function storeCampaign(Request $request): JsonResponse
    {
        $data = $request->validate([
            'subject_en' => ['required', 'string', 'max:255'],
            'subject_km' => ['nullable', 'string', 'max:255'],
            'body_en' => ['required', 'string'],
            'body_km' => ['nullable', 'string'],
            'book_ids' => ['nullable', 'array', 'max:12'],
            'book_ids.*' => ['integer', 'exists:books,id'],
        ]);

        $campaign = NewsletterCampaign::create([
            'subject_en' => $data['subject_en'],
            'subject_km' => $data['subject_km'] ?? null,
            'body_en' => $data['body_en'],
            'body_km' => $data['body_km'] ?? null,
            'book_ids' => $data['book_ids'] ?? [],
            'status' => 'draft',
            'created_by_staff_id' => $request->user()->id,
            'recipients_total' => 0,
        ]);

        $this->auditLogger->created($request->user(), $campaign);

        return response()->json($campaign->load('createdByStaff:id,name,email'), 201);
    }

    /**
     * Show a campaign with attached books preview.
     */
    public function showCampaign(NewsletterCampaign $campaign): JsonResponse
    {
        $campaign->load('createdByStaff:id,name,email');

        $books = [];
        if (! empty($campaign->book_ids)) {
            $books = Book::with('variants')->whereIn('id', $campaign->book_ids)
                ->get(['id', 'title']);
        }

        return response()->json([
            'campaign' => $campaign,
            'books' => $books,
        ]);
    }

    /**
     * Update draft campaign.
     */
    public function updateCampaign(Request $request, NewsletterCampaign $campaign): JsonResponse
    {
        if ($campaign->status !== 'draft') {
            return response()->json(['message' => 'Only draft campaigns can be edited.'], 422);
        }

        $data = $request->validate([
            'subject_en' => ['required', 'string', 'max:255'],
            'subject_km' => ['nullable', 'string', 'max:255'],
            'body_en' => ['required', 'string'],
            'body_km' => ['nullable', 'string'],
            'book_ids' => ['nullable', 'array', 'max:12'],
            'book_ids.*' => ['integer', 'exists:books,id'],
        ]);

        $before = $campaign->getAttributes();

        $campaign->update([
            'subject_en' => $data['subject_en'],
            'subject_km' => $data['subject_km'] ?? null,
            'body_en' => $data['body_en'],
            'body_km' => $data['body_km'] ?? null,
            'book_ids' => $data['book_ids'] ?? [],
        ]);

        $this->auditLogger->updated($request->user(), $campaign, $before);

        return response()->json($campaign->load('createdByStaff:id,name,email'));
    }

    /**
     * Delete draft campaign.
     */
    public function destroyCampaign(Request $request, NewsletterCampaign $campaign): JsonResponse
    {
        if ($campaign->status !== 'draft') {
            return response()->json(['message' => 'Only draft campaigns can be deleted.'], 422);
        }

        $this->auditLogger->deleted($request->user(), $campaign);
        $campaign->delete();

        return response()->json(['message' => 'Campaign deleted.']);
    }

    /**
     * Send test campaign email directly to the admin's email.
     */
    public function testSend(Request $request, NewsletterCampaign $campaign): JsonResponse
    {
        $admin = $request->user();
        if (blank($admin->email)) {
            return response()->json(['message' => 'Admin has no email address configured.'], 422);
        }

        $booksData = $this->formatBooksPayload($campaign->book_ids ?? []);

        $payload = [
            'subject' => '[TEST] '.$campaign->subject_en,
            'body' => $campaign->body_en,
            'books' => $booksData,
            'unsubscribe_token' => 'test-preview-token',
        ];

        try {
            Notification::route('mail', $admin->email)
                ->notify((new NewsletterCampaignNotification($payload))->locale('en'));
        } catch (\Throwable $e) {
            report($e);

            return response()->json(['message' => 'Failed to send test email: '.$e->getMessage()], 500);
        }

        $this->auditLogger->custom($admin, 'test_send', $campaign);

        return response()->json([
            'message' => 'Test email sent to '.$admin->email,
        ]);
    }

    /**
     * Send campaign to all confirmed subscribers via marketing outbox.
     */
    public function send(Request $request, NewsletterCampaign $campaign): JsonResponse
    {
        if ($campaign->status !== 'draft') {
            return response()->json(['message' => 'This campaign has already been sent or is currently sending.'], 422);
        }

        if (blank($campaign->body_en)) {
            return response()->json(['message' => 'Campaign requires an English body before sending.'], 422);
        }

        $subscribers = NewsletterSubscriber::where('status', 'confirmed')->get();
        $recipientsTotal = $subscribers->count();

        $campaign->update([
            'status' => 'sending',
            'recipients_total' => $recipientsTotal,
        ]);

        $booksData = $this->formatBooksPayload($campaign->book_ids ?? []);

        foreach ($subscribers as $subscriber) {
            $isKhmer = $subscriber->locale === 'km' && ! empty($campaign->subject_km) && ! empty($campaign->body_km);
            $locale = $isKhmer ? 'km' : 'en';
            $subject = $isKhmer ? $campaign->subject_km : $campaign->subject_en;
            $body = $isKhmer ? $campaign->body_km : $campaign->body_en;

            $rawUnsubToken = hash_hmac('sha256', 'newsletter:unsub:'.$subscriber->email, config('app.key'));

            MarketingOutbox::create([
                'kind' => 'newsletter',
                'customer_id' => $subscriber->customer_id,
                'email' => $subscriber->email,
                'locale' => $locale,
                'payload' => [
                    'campaign_id' => $campaign->id,
                    'subject' => $subject,
                    'body' => $body,
                    'books' => $booksData,
                    'unsubscribe_token' => $rawUnsubToken,
                ],
                'status' => 'pending',
            ]);
        }

        $campaign->update([
            'status' => 'sent',
            'sent_at' => now(),
        ]);

        $this->auditLogger->custom($request->user(), 'send', $campaign);

        $dailyLimit = (int) config('shop.marketing_daily_limit', 60);
        $days = (int) ceil($recipientsTotal / max(1, $dailyLimit));
        $dayEstimate = 'About '.$days.' '.($days === 1 ? 'day' : 'days').' to reach everyone at the '.$dailyLimit.'/day limit';

        return response()->json([
            'message' => $dayEstimate,
            'estimated_days' => $days,
            'daily_limit' => $dailyLimit,
            'recipients_total' => $recipientsTotal,
            'campaign' => $campaign->fresh()->load('createdByStaff:id,name,email'),
        ]);
    }

    /**
     * @param  array<int, int>  $bookIds
     * @return array<int, array{id: int, title: string, price: string, url: string}>
     */
    private function formatBooksPayload(array $bookIds): array
    {
        if (empty($bookIds)) {
            return [];
        }

        $shopUrl = config('shop.url') ?: 'http://localhost:5173';
        $books = Book::with('variants')->whereIn('id', $bookIds)->get();

        return $books->map(function (Book $book) use ($shopUrl) {
            $minPrice = $book->variants->min('price');
            $priceStr = $minPrice !== null ? '$'.number_format((float) $minPrice, 2) : '';
            $url = rtrim($shopUrl, '/').'/books/'.$book->id;

            return [
                'id' => $book->id,
                'title' => $book->title,
                'price' => $priceStr,
                'url' => $url,
            ];
        })->values()->all();
    }
}
