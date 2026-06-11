<?php

namespace App\Utility;

use Illuminate\Http\Request;

class NrcHelper
{
    private const MYANMAR_DIGITS = [
        '၀' => '0',
        '၁' => '1',
        '၂' => '2',
        '၃' => '3',
        '၄' => '4',
        '၅' => '5',
        '၆' => '6',
        '၇' => '7',
        '၈' => '8',
        '၉' => '9',
    ];

    private const CITIZENS = [
        'N' => 'နိုင်',
        'E' => 'ဧည့်',
        'P' => 'ပြု',
        'T' => 'သီ',
    ];

    public static function decomposeNRC(?string $nrc)
    {
        if (empty($nrc) || $nrc===null) {
            return null;
        }

        preg_match(
            '/^([^\/]+)\/([^\(]+)\(([^\)]+)\)(.+)$/',
            $nrc,
            $matches
        );

        if (count($matches) !== 5) {
            return null;
        }

        return [
            'state' => $matches[1],
            'township' => $matches[2],
            'citizen' => $matches[3],
            'number' => $matches[4],
        ];
    }

    public static function buildNRC(string $state,string $township,string $citizen,string $number) : string
    {
        $stateCode = self::normalizeStateToEnglish($state) ?? $state;
        $townshipCode = self::normalizeTownshipToEnglish($stateCode, $township) ?? $township;
        $citizenCode = self::normalizeCitizenToEnglish($citizen) ?? $citizen;
        $numberCode = self::normalizeNumberToEnglish($number);

        return $stateCode.'/'.$townshipCode.'('.$citizenCode.')'.$numberCode;
    }

    public static function buildNrcFromRequest(Request $request): ?string
    {
        $state = $request->input('nrc_state');
        $township = $request->input('nrc_township');
        $citizen = $request->input('nrc_citizen');
        $number = $request->input('nrc_number');

        if (blank($state) && blank($township) && blank($citizen) && blank($number)) {
            return null;
        }

        return self::buildNRC($state, $township, $citizen, $number);
    }

    public static function buildCustomerNrc(array $customer): ?string
    {
        $state = $customer['nrc_state'] ?? null;
        $township = $customer['nrc_township'] ?? null;
        $citizen = $customer['nrc_citizen'] ?? null;
        $number = $customer['nrc_number'] ?? null;

        if (blank($state) && blank($township) && blank($citizen) && blank($number)) {
            return null;
        }

        return self::buildNRC($state, $township, $citizen, $number);
    }

    public static function normalizeStateToEnglish(?string $state): ?string
    {
        $region = self::findRegion($state);

        if ($region === null) {
            return null;
        }

        return (string) ($region['code_en'] ?? $region['id']);
    }

    public static function normalizeTownshipToEnglish(?string $state, ?string $township): ?string
    {
        $township = self::normalizeInput($township);

        if ($township === null) {
            return null;
        }

        $regions = self::findRegion($state) !== null
            ? [self::findRegion($state)]
            : config('nrc', []);

        foreach ($regions as $region) {
            if (! is_array($region)) {
                continue;
            }

            foreach ($region['townships'] ?? [] as $codeEng => $townshipData) {
                if (self::sameCode($township, (string) $codeEng)) {
                    return (string) $codeEng;
                }

                if (self::sameCode($township, (string) ($townshipData['code_eng'] ?? ''))) {
                    return (string) $townshipData['code_eng'];
                }

                if (self::sameCode($township, (string) ($townshipData['code_mm'] ?? ''))) {
                    return (string) ($townshipData['code_eng'] ?? $codeEng);
                }
            }
        }

        return null;
    }

    public static function normalizeCitizenToEnglish(?string $citizen): ?string
    {
        $citizen = self::normalizeInput($citizen);

        if ($citizen === null) {
            return null;
        }

        foreach (self::CITIZENS as $codeEng => $codeMm) {
            if (self::sameCode($citizen, $codeEng) || self::sameCode($citizen, $codeMm)) {
                return $codeEng;
            }
        }

        return null;
    }

    public static function normalizeNumberToEnglish(?string $number): ?string
    {
        $number = self::normalizeInput($number);

        if ($number === null) {
            return null;
        }

        return strtr($number, self::MYANMAR_DIGITS);
    }

    public static function findRegion(?string $state): ?array
    {
        $state = self::normalizeInput($state);

        if ($state === null) {
            return null;
        }

        $stateInEnglishDigits = self::normalizeNumberToEnglish($state);

        foreach (config('nrc', []) as $region) {
            if (! is_array($region) || ! array_key_exists('id', $region)) {
                continue;
            }

            if ($stateInEnglishDigits !== null && ctype_digit($stateInEnglishDigits) && (int) $region['id'] === (int) $stateInEnglishDigits) {
                return $region;
            }

            if (self::sameCode($state, (string) ($region['code_mm'] ?? ''))) {
                return $region;
            }

            if (self::sameCode($stateInEnglishDigits ?? $state, (string) ($region['code_en'] ?? ''))) {
                return $region;
            }
        }

        return null;
    }

    public static function sameCode(string $input, string $expected): bool
    {
        return mb_strtolower($input) === mb_strtolower($expected);
    }

    private static function normalizeInput(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
