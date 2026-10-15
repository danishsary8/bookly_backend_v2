<?php

return [
    // OTP
    'otp_subject_verify_email' => 'Verify your email address',
    'otp_subject_change_email' => 'Confirm your new email address',
    'otp_subject_reset_password' => 'Reset your password',
    'otp_code' => 'Your verification code is: :code',
    'otp_expires' => 'This code expires in :minutes minutes.',
    'otp_ignore' => 'If you did not request this, you can ignore this email.',

    // Welcome
    'welcome_subject' => 'Welcome to Bookly',
    'welcome_greeting' => 'Welcome, :name!',
    'welcome_line1' => 'Your Bookly account is ready. You can now add books to your cart and order with cash on delivery.',
    'welcome_line2' => 'We email you at every step of your order: confirmed, shipped (with the tracking note), delivered, and for any return.',
    'welcome_closing' => 'Happy reading!',

    // Order Placed
    'order_placed_subject' => 'Order confirmed: :number',
    'order_placed_greeting' => 'Thank you for your order, :name!',
    'order_number' => 'Order number: :number',
    'subtotal' => 'Subtotal: :amount USD',
    'discount' => 'Discount: -:amount USD',
    'shipping' => 'Shipping: :amount USD',
    'total' => 'Total: :amount USD',
    'payment_cod' => 'Payment: cash on delivery.',
    'delivery_to' => 'Delivery to: :recipient, :address, :city, :country',

    // Order Status Changed
    'order_processing_subject' => 'We are packing your order :number',
    'order_processing_intro' => 'Good news: your order is confirmed and we are preparing it for shipping.',
    'order_shipped_subject' => 'Your order :number is on its way',
    'order_shipped_intro' => 'Your order has been shipped.',
    'order_delivered_subject' => 'Your order :number was delivered',
    'order_delivered_intro' => 'Your order has been delivered. Thank you for shopping with us!',
    'order_cancelled_subject' => 'Your order :number was cancelled',
    'order_cancelled_intro' => 'Your order has been cancelled.',
    'shop_note' => 'Note from the shop: :note',
    'receipt_delivered' => 'Receipt: :total USD (subtotal :subtotal, shipping :shipping, discount :discount). Paid on delivery.',
    'return_prompt' => 'If something is wrong with your books you can request a return from your account within the return period.',
    'order_cancelled_footer' => 'Any stock or coupon use was released. If you did not ask for this, please contact support.',

    // Return Updated
    'return_requested_subject' => 'We received your return request for :number',
    'return_requested_intro' => 'We received your return request. We will review it and email you the result.',
    'return_approved_subject' => 'Your return for :number was approved',
    'return_approved_intro' => 'Your return was approved. Please send the books back as agreed with the shop.',
    'return_rejected_subject' => 'Your return for :number was rejected',
    'return_rejected_intro' => 'Sorry, your return request was rejected.',
    'return_refunded_subject' => 'Your refund for :number is done',
    'return_refunded_intro' => 'We received your books and paid your refund.',
    'refund_amount' => 'Refund amount: :amount USD (the shipping fee is not refunded).',
    'refund_store_credit' => 'Your refund has been issued as store credit. You can use coupon code :code at checkout (valid for 1 year).',
    'refund_manual_offline' => 'Refund reference / receipt: :reference',

    // Security & Account
    'security_password_reset_subject' => 'Your Bookly password was reset',
    'security_password_reset_body' => 'The password of your Bookly account was just reset with a code sent to this email, and every device was signed out.',
    'security_password_changed_subject' => 'Your Bookly password was changed',
    'security_password_changed_body' => 'The password of your Bookly account was just changed, and other devices were signed out.',
    'security_ignore' => 'If this was you, there is nothing to do.',
    'security_alert' => 'If it was not you, reset your password straight away and contact us.',
    'account_reopened_subject' => 'Your Bookly account is open again',
    'account_reopened_line1' => 'We reopened the Bookly account you closed. Sign in the same way as before.',
    'account_reopened_line2' => 'Your orders are all there. Reviews, wishlist and cart removed when you closed it are not restored.',
    'account_reopened_line3' => 'If you did not ask us to reopen it, reply to this email or contact us and we will close it again.',
];
