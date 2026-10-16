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
            ->paginate(min(100, max(1, $request->integer('per_page', 20))));

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
     * Queues the campaign for every confirmed subscriber (one outbox row each, in their language, English when the
     * Khmer text is empty). The draft → sending switch is one conditional update, so a double click or two admins
     * can never queue it twice. The outbox marks it `sent` when its last row has gone out.
     */
    public function send(Request $request, NewsletterCampaign $campaign): JsonResponse
    {
        if (blank($campaign->subject_en) || blank($campaign->body_en)) {
            return response()->json(['message' => 'Campaign requires an English subject and body before sending.'], 422);
        }

        $recipientsTotal = DB::transaction(function () use ($campaign) {
            $claimed = NewsletterCampaign::whereKey($campaign->id)->where('status', 'draft')->update(['status' => 'sending']);
            if ($claimed === 0) {
                return null;
            }

            $books = $this->formatBooksPayload($campaign->book_ids ?? []);
            $total = 0;
            NewsletterSubscriber::where('status', 'confirmed')->orderBy('id')->chunkById(500, function ($subscribers) use ($campaign, $books, &$total) {
                $rows = [];
                foreach ($subscribers as $subscriber) {
                    $khmer = $subscriber->locale === 'km' && filled($campaign->subject_km) && filled($campaign->body_km);
                    $rows[] = [
                        'kind' => 'newsletter',
                        'customer_id' => $subscriber->customer_id,
                        'email' => $subscriber->email,
                        'locale' => $khmer ? 'km' : 'en',
                        'payload' => json_encode([
                            'campaign_id' => $campaign->id,
                            'subscriber_id' => $subscriber->id,
                            'subject' => $khmer ? $campaign->subject_km : $campaign->subject_en,
                            'body' => $khmer ? $campaign->body_km : $campaign->body_en,
                            'books' => $books,
                        ]),
                        'status' => 'pending',
                        'attempts' => 0,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                }
                MarketingOutbox::insert($rows);
                $total += count($rows);
            });
            NewsletterCampaign::whereKey($campaign->id)->update(['recipients_total' => $total, 'sent_at' => $total === 0 ? now() : null, 'status' => $total === 0 ? 'sent' : 'sending']);

            return $total;
        });

        if ($recipientsTotal === null) {
            return response()->json(['message' => 'This campaign has already been sent or is currently sending.'], 422);
        }

        $this->auditLogger->custom($request->user(), 'send', $campaign);

        $dailyLimit = (int) config('shop.marketing_daily_limit', 60);
        $days = (int) ceil($recipientsTotal / max(1, $dailyLimit));

        return response()->json([
            'message' => 'About '.$days.' '.($days === 1 ? 'day' : 'days').' to reach everyone at the '.$dailyLimit.'/day limit',
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

        $shopUrl = rtrim(config('shop.url') ?: 'http://localhost:5173', '/');
        $books = Book::visible()
            ->with(['variants' => fn ($q) => $q->where('is_active', true)])
            ->whereIn('id', $bookIds)
            ->get()
            ->sortBy(fn (Book $book) => array_search($book->id, $bookIds, true));

        return $books->map(function (Book $book) use ($shopUrl) {
            $from = $book->variants->min(fn ($v) => (float) $v->price_usd);

            return [
                'id' => $book->id,
                'title' => $book->title,
                'price' => $from !== null ? '$'.number_format($from, 2) : '',
                'cover_url' => $book->variants->pluck('cover_image_url')->filter()->first(),
                'url' => $shopUrl.'/books/'.$book->id,
            ];
        })->values()->all();
    }
}
