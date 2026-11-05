<?php

namespace Database\Seeders;

use App\Models\AssistantFaq;
use Illuminate\Database\Seeder;

/** Run deliberately; never included in DatabaseSeeder or deployment startup. */
class AssistantFaqSeeder extends Seeder
{
    public function run(): void
    {
        $entries = [
            ['How do I create an account?', 'តើខ្ញុំបង្កើតគណនីដោយរបៀបណា?', 'Open the registration page and verify your email or phone.', 'បើកទំព័រចុះឈ្មោះ ហើយផ្ទៀងផ្ទាត់អ៊ីមែល ឬលេខទូរស័ព្ទ។', 'create account', 'បង្កើតគណនី'],
            ['How do I verify my email?', 'តើខ្ញុំផ្ទៀងផ្ទាត់អ៊ីមែលដោយរបៀបណា?', 'Enter the verification code sent to your email.', 'បញ្ចូលលេខកូដផ្ទៀងផ្ទាត់ដែលបានផ្ញើទៅអ៊ីមែលរបស់អ្នក។', 'verify email', 'ផ្ទៀងផ្ទាត់អ៊ីមែល'],
            ['How can I reset my password?', 'តើខ្ញុំកំណត់ពាក្យសម្ងាត់ឡើងវិញដោយរបៀបណា?', 'Choose Forgot password on the sign-in page.', 'ជ្រើសរើស ភ្លេចពាក្យសម្ងាត់ នៅទំព័រចូលគណនី។', 'reset password', 'កំណត់ពាក្យសម្ងាត់ឡើងវិញ'],
            ['Where are my sessions?', 'តើវគ្គចូលរបស់ខ្ញុំនៅឯណា?', 'Open Account and then Security to manage your sessions.', 'បើក គណនី រួចជ្រើស សុវត្ថិភាព ដើម្បីគ្រប់គ្រងវគ្គចូល។', 'my sessions', 'វគ្គចូល'],
            ['How do I save an address?', 'តើខ្ញុំរក្សាទុកអាសយដ្ឋានដោយរបៀបណា?', 'Open Account and then Addresses.', 'បើក គណនី រួចជ្រើស អាសយដ្ឋាន។', 'save address', 'រក្សាទុកអាសយដ្ឋាន'],
            ['How do I change my address?', 'តើខ្ញុំប្ដូរអាសយដ្ឋានដោយរបៀបណា?', 'Edit your saved address under Account → Addresses.', 'កែសម្រួលអាសយដ្ឋានក្នុង គណនី → អាសយដ្ឋាន។', 'change address', 'ប្ដូរអាសយដ្ឋាន'],
            ['How do I save a book?', 'តើខ្ញុំរក្សាទុកសៀវភៅដោយរបៀបណា?', 'Use the heart button to add a book to your wishlist.', 'ចុចប៊ូតុងបេះដូង ដើម្បីបន្ថែមសៀវភៅទៅបញ្ជីចង់បាន។', 'save a book', 'រក្សាទុកសៀវភៅ'],
            ['Where is my wishlist?', 'តើបញ្ជីចង់បានរបស់ខ្ញុំនៅឯណា?', 'Open Account → Wishlist.', 'បើក គណនី → បញ្ជីចង់បាន។', 'my wishlist', 'បញ្ជីចង់បាន'],
            ['How do reading lists work?', 'តើបញ្ជីអានដំណើរការយ៉ាងដូចម្តេច?', 'Create and manage reading lists in your account; you choose whether each list is public.', 'បង្កើត និងគ្រប់គ្រងបញ្ជីអានក្នុងគណនីរបស់អ្នក។ អ្នកអាចជ្រើសថាតើបញ្ជីនីមួយៗជាសាធារណៈឬអត់។', 'reading lists', 'បញ្ជីអាន'],
            ['How do I apply a coupon?', 'តើខ្ញុំប្រើគូប៉ុងដោយរបៀបណា?', 'Enter your coupon in the cart and review the discount before checkout.', 'បញ្ចូលគូប៉ុងក្នុងកន្ត្រក ហើយពិនិត្យការបញ្ចុះតម្លៃមុនបញ្ជាទិញ។', 'apply coupon', 'ប្រើគូប៉ុង'],
            ['Where can I see my orders?', 'តើខ្ញុំអាចមើលការបញ្ជាទិញនៅឯណា?', 'Open Account → Orders to see your order history.', 'បើក គណនី → ការបញ្ជាទិញ ដើម្បីមើលប្រវត្តិ។', 'order history', 'ប្រវត្តិការបញ្ជាទិញ'],
            ['Can I cancel an order?', 'តើខ្ញុំអាចបោះបង់ការបញ្ជាទិញបានទេ?', 'Open the order page to see whether cancellation is available.', 'បើកទំព័រការបញ្ជាទិញ ដើម្បីមើលថាតើអាចបោះបង់បានឬអត់។', 'cancel order', 'បោះបង់ការបញ្ជាទិញ'],
            ['How do I order again?', 'តើខ្ញុំបញ្ជាទិញម្ដងទៀតដោយរបៀបណា?', 'Open an earlier order and choose Buy again.', 'បើកការបញ្ជាទិញពីមុន ហើយជ្រើស ទិញម្ដងទៀត។', 'buy again', 'ទិញម្ដងទៀត'],
            ['Where is my invoice?', 'តើវិក្កយបត្ររបស់ខ្ញុំនៅឯណា?', 'Open the order page to view its invoice.', 'បើកទំព័រការបញ្ជាទិញ ដើម្បីមើលវិក្កយបត្រ។', 'my invoice', 'វិក្កយបត្រ'],
            ['How do I request a return?', 'តើខ្ញុំស្នើសុំត្រឡប់ទំនិញដោយរបៀបណា?', 'Open a delivered order and choose the return option for eligible items.', 'បើកការបញ្ជាទិញដែលបានប្រគល់ ហើយជ្រើសការត្រឡប់សម្រាប់ទំនិញដែលមានសិទ្ធិ។', 'request a return', 'ស្នើសុំត្រឡប់'],
            ['Where is my store credit?', 'តើសមតុល្យឥណទានហាងរបស់ខ្ញុំនៅឯណា?', 'Your account shows your available store credit.', 'គណនីរបស់អ្នកបង្ហាញឥណទានហាងដែលអាចប្រើបាន។', 'store credit', 'ឥណទានហាង'],
            ['How do I review a book?', 'តើខ្ញុំវាយតម្លៃសៀវភៅដោយរបៀបណា?', 'Open the book page to review an eligible purchase.', 'បើកទំព័រសៀវភៅ ដើម្បីវាយតម្លៃការទិញដែលមានសិទ្ធិ។', 'review a book', 'វាយតម្លៃសៀវភៅ'],
            ['How do I rate an order?', 'តើខ្ញុំវាយតម្លៃការបញ្ជាទិញដោយរបៀបណា?', 'Open a delivered order to leave an order rating.', 'បើកការបញ្ជាទិញដែលបានប្រគល់ ដើម្បីផ្ដល់ការវាយតម្លៃ។', 'rate an order', 'វាយតម្លៃការបញ្ជាទិញ'],
            ['Where are my ebooks?', 'តើសៀវភៅអេឡិចត្រូនិករបស់ខ្ញុំនៅឯណា?', 'Open Account → My ebooks.', 'បើក គណនី → សៀវភៅអេឡិចត្រូនិករបស់ខ្ញុំ។', 'my ebooks', 'សៀវភៅអេឡិចត្រូនិករបស់ខ្ញុំ'],
            ['Can I read a sample?', 'តើខ្ញុំអាចអានគំរូបានទេ?', 'When a preview is available, the book page offers a sample.', 'នៅពេលមានគំរូ ទំព័រសៀវភៅនឹងបង្ហាញជម្រើសអានគំរូ។', 'read a sample', 'អានគំរូ'],
            ['How do I manage ebook devices?', 'តើខ្ញុំគ្រប់គ្រងឧបករណ៍អានដោយរបៀបណា?', 'Open your ebook library to manage your registered devices.', 'បើកបណ្ណាល័យសៀវភៅអេឡិចត្រូនិក ដើម្បីគ្រប់គ្រងឧបករណ៍ដែលបានចុះឈ្មោះ។', 'ebook devices', 'ឧបករណ៍អាន'],
            ['How do pre-orders work?', 'តើការបញ្ជាទិញមុនដំណើរការយ៉ាងដូចម្តេច?', 'A pre-order is for an item with a future release date; see its page for the expected date.', 'ការបញ្ជាទិញមុនគឺសម្រាប់ទំនិញដែលមានថ្ងៃចេញផ្សាយនៅពេលខាងមុខ។ សូមមើលថ្ងៃរំពឹងទុកលើទំព័រទំនិញ។', 'pre order', 'បញ្ជាទិញមុន'],
            ['How do backorders work?', 'តើការបញ្ជាទិញរង់ចាំស្តុកដំណើរការយ៉ាងដូចម្តេច?', 'A backorder is an item ordered while stock is unavailable; its page shows the expected wait.', 'ការបញ្ជាទិញរង់ចាំស្តុកគឺសម្រាប់ទំនិញដែលអស់ស្តុក។ ទំព័រទំនិញបង្ហាញពេលរង់ចាំដែលរំពឹងទុក។', 'backorder', 'រង់ចាំស្តុក'],
            ['How can I contact the team?', 'តើខ្ញុំអាចទាក់ទងក្រុមការងារដោយរបៀបណា?', 'Use the support chat or the contact page to reach our team.', 'ប្រើការជជែកជំនួយ ឬទំព័រទំនាក់ទំនង ដើម្បីទាក់ទងក្រុមការងារ។', 'contact the team', 'ទាក់ទងក្រុមការងារ'],
            ['How do I close my account?', 'តើខ្ញុំបិទគណនីដោយរបៀបណា?', 'Open Account → Security to review the account closure option.', 'បើក គណនី → សុវត្ថិភាព ដើម្បីពិនិត្យជម្រើសបិទគណនី។', 'close my account', 'បិទគណនី'],
        ];
        foreach ($entries as [$en, $km, $answerEn, $answerKm, $keywordEn, $keywordKm]) {
            AssistantFaq::firstOrCreate(['question_en' => $en], ['question_km' => $km, 'answer_en' => $answerEn, 'answer_km' => $answerKm, 'keywords' => [$keywordEn, $keywordKm], 'is_active' => true]);
        }
    }
}
