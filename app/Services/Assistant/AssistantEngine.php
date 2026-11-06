<?php

namespace App\Services\Assistant;

use App\Models\Customer;

interface AssistantEngine
{
    /** @return array{intent: string, text: string, link: ?string, books: array, handoff: bool} */
    public function answer(string $question, ?Customer $customer): array;
}
