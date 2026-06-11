<?php

namespace App\Rules;

use App\Utility\MessageCode;
use App\Utility\Messages;
use App\Utility\NrcHelper;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class NrcRules implements ValidationRule
{
    public function __construct(
        private ?string $state,
        private ?string $township,
        private ?string $citizen,
        private ?string $number,
    ) {
    }

    /**
     * Run the validation rule.
     *
     * @param  \Closure(string, ?string=): \Illuminate\Translation\PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $state = $this->normalize($this->state);
        $township = $this->normalize($this->township);
        $citizen = $this->normalize($this->citizen);
        $number = NrcHelper::normalizeNumberToEnglish($this->number);

        $filledCount = collect([$state, $township, $citizen, $number])
            ->filter(fn (?string $value) => $value !== null)
            ->count();

        if ($filledCount === 0) {
            return;
        }

        if ($filledCount !== 4) {
            $fail($this->message(MessageCode::ValidationNrcAllFieldsRequired));
            return;
        }

        $region = NrcHelper::findRegion($state);

        if ($region === null) {
            $fail($this->message(MessageCode::ValidationNrcInvalidState));
            return;
        }

        if (NrcHelper::normalizeTownshipToEnglish($state, $township) === null) {
            $fail($this->message(MessageCode::ValidationNrcInvalidTownship));
            return;
        }

        if (NrcHelper::normalizeCitizenToEnglish($citizen) === null) {
            $fail($this->message(MessageCode::ValidationNrcInvalidCitizen));
            return;
        }

        if (! preg_match('/^[0-9]{6}$/', $number)) {
            $fail($this->message(MessageCode::ValidationNrcInvalidNumber));
        }
    }

    private function normalize(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function message(MessageCode $code): string
    {
        return app(Messages::class)->responseMessage($code);
    }
}
