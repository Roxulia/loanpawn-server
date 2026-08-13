<?php

namespace Tests\Unit;

use App\Utility\MessageCode;
use App\Utility\Messages;
use Tests\TestCase;

class FinanceMessagesTest extends TestCase
{
    public function test_all_finance_messages_resolve_in_supported_locales(): void
    {
        $messages = app(Messages::class);
        $financeMessageCodes = array_filter(
            MessageCode::cases(),
            fn (MessageCode $messageCode): bool => str_starts_with($messageCode->value, 'finance.')
        );

        foreach (['en', 'mm'] as $locale) {
            app()->setLocale($locale);

            foreach ($financeMessageCodes as $messageCode) {
                $translatedMessage = $messages->responseMessage($messageCode);

                $this->assertNotSame($messageCode->value, $translatedMessage, "{$messageCode->name} is missing for {$locale}.");
                $this->assertNotSame('', trim($translatedMessage), "{$messageCode->name} is empty for {$locale}.");
            }
        }
    }

    public function test_representative_finance_messages_use_locale_values(): void
    {
        $messages = app(Messages::class);

        app()->setLocale('en');
        $this->assertSame('Currency created successfully.', $messages->responseMessage(MessageCode::FinanceTenantCurrencyCreated));
        $this->assertSame('Exchange rate unavailable.', $messages->responseMessage(MessageCode::FinanceTenantExchangeRateUnavailable));

        app()->setLocale('mm');
        $this->assertSame('ငွေကြေးကို အောင်မြင်စွာ ဖန်တီးပြီးပါပြီ။', $messages->responseMessage(MessageCode::FinanceTenantCurrencyCreated));
        $this->assertSame('ငွေလဲနှုန်း မရရှိနိုင်ပါ။', $messages->responseMessage(MessageCode::FinanceTenantExchangeRateUnavailable));
    }
}
