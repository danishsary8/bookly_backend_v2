<?php

namespace App\Services\Orders;

use App\Models\CustomerAddress;
use Carbon\Carbon;

/**
 * Which delivery area an address is in, province detection, and what delivery costs there.
 * Phnom Penh is matched on the address's city or province, ignoring case, spaces and dashes
 * ("Phnom Penh", "phnom-penh", "PhnomPenh", "ភ្នំពេញ"); everywhere else counts as the provinces.
 */
class DeliveryArea
{
    public const PHNOM_PENH = 'phnom_penh';

    public const PROVINCES = 'provinces';

    /**
     * Complete list of Cambodia's 25 provinces/capital with standardized metadata.
     */
    public const PROVINCES_LIST = [
        [
            'code' => 'phnom_penh',
            'name_en' => 'Phnom Penh',
            'name_km' => 'ភ្នំពេញ',
            'area' => self::PHNOM_PENH,
            'days_min' => 1,
            'days_max' => 2,
            'delivery_time_en' => '1 working day',
            'delivery_time_km' => '១ ថ្ងៃធ្វើការ',
            'keywords' => ['phnompenh', 'ភ្នំពេញ'],
        ],
        [
            'code' => 'kandal',
            'name_en' => 'Kandal',
            'name_km' => 'កណ្តាល',
            'area' => self::PROVINCES,
            'days_min' => 1,
            'days_max' => 2,
            'delivery_time_en' => '1 to 2 working days',
            'delivery_time_km' => '១ ទៅ ២ ថ្ងៃធ្វើការ',
            'keywords' => ['kandal', 'កណ្តាល', 'កណ្ដាល'],
        ],
        [
            'code' => 'siem_reap',
            'name_en' => 'Siem Reap',
            'name_km' => 'សៀមរាប',
            'area' => self::PROVINCES,
            'days_min' => 2,
            'days_max' => 3,
            'delivery_time_en' => '2 to 3 working days',
            'delivery_time_km' => '២ ទៅ ៣ ថ្ងៃធ្វើការ',
            'keywords' => ['siemreap', 'សៀមរាប'],
        ],
        [
            'code' => 'battambang',
            'name_en' => 'Battambang',
            'name_km' => 'បាត់ដំបង',
            'area' => self::PROVINCES,
            'days_min' => 2,
            'days_max' => 3,
            'delivery_time_en' => '2 to 3 working days',
            'delivery_time_km' => '២ ទៅ ៣ ថ្ងៃធ្វើការ',
            'keywords' => ['battambang', 'បាត់ដំបង'],
        ],
        [
            'code' => 'preah_sihanouk',
            'name_en' => 'Preah Sihanouk',
            'name_km' => 'ព្រះសីហនុ',
            'area' => self::PROVINCES,
            'days_min' => 2,
            'days_max' => 3,
            'delivery_time_en' => '2 to 3 working days',
            'delivery_time_km' => '២ ទៅ ៣ ថ្ងៃធ្វើការ',
            'keywords' => ['preahsihanouk', 'sihanoukville', 'sihanouk', 'kampongsom', 'ព្រះសីហនុ', 'កំពង់សោម'],
        ],
        [
            'code' => 'kampot',
            'name_en' => 'Kampot',
            'name_km' => 'កំពត',
            'area' => self::PROVINCES,
            'days_min' => 2,
            'days_max' => 3,
            'delivery_time_en' => '2 to 3 working days',
            'delivery_time_km' => '២ ទៅ ៣ ថ្ងៃធ្វើការ',
            'keywords' => ['kampot', 'កំពត'],
        ],
        [
            'code' => 'kep',
            'name_en' => 'Kep',
            'name_km' => 'កែប',
            'area' => self::PROVINCES,
            'days_min' => 2,
            'days_max' => 3,
            'delivery_time_en' => '2 to 3 working days',
            'delivery_time_km' => '២ ទៅ ៣ ថ្ងៃធ្វើការ',
            'keywords' => ['kep', 'កែប'],
        ],
        [
            'code' => 'kampong_cham',
            'name_en' => 'Kampong Cham',
            'name_km' => 'កំពង់ចាម',
            'area' => self::PROVINCES,
            'days_min' => 2,
            'days_max' => 3,
            'delivery_time_en' => '2 to 3 working days',
            'delivery_time_km' => '២ ទៅ ៣ ថ្ងៃធ្វើការ',
            'keywords' => ['kampongcham', 'កំពង់ចាម'],
        ],
        [
            'code' => 'kampong_chhnang',
            'name_en' => 'Kampong Chhnang',
            'name_km' => 'កំពង់ឆ្នាំង',
            'area' => self::PROVINCES,
            'days_min' => 2,
            'days_max' => 3,
            'delivery_time_en' => '2 to 3 working days',
            'delivery_time_km' => '២ ទៅ ៣ ថ្ងៃធ្វើការ',
            'keywords' => ['kampongchhnang', 'កំពង់ឆ្នាំង'],
        ],
        [
            'code' => 'kampong_speu',
            'name_en' => 'Kampong Speu',
            'name_km' => 'កំពង់ស្ពឺ',
            'area' => self::PROVINCES,
            'days_min' => 2,
            'days_max' => 3,
            'delivery_time_en' => '2 to 3 working days',
            'delivery_time_km' => '២ ទៅ ៣ ថ្ងៃធ្វើការ',
            'keywords' => ['kampongspeu', 'កំពង់ស្ពឺ'],
        ],
        [
            'code' => 'kampong_thom',
            'name_en' => 'Kampong Thom',
            'name_km' => 'កំពង់ធំ',
            'area' => self::PROVINCES,
            'days_min' => 2,
            'days_max' => 3,
            'delivery_time_en' => '2 to 3 working days',
            'delivery_time_km' => '២ ទៅ ៣ ថ្ងៃធ្វើការ',
            'keywords' => ['kampongthom', 'កំពង់ធំ'],
        ],
        [
            'code' => 'koh_kong',
            'name_en' => 'Koh Kong',
            'name_km' => 'កោះកុង',
            'area' => self::PROVINCES,
            'days_min' => 2,
            'days_max' => 3,
            'delivery_time_en' => '2 to 3 working days',
            'delivery_time_km' => '២ ទៅ ៣ ថ្ងៃធ្វើការ',
            'keywords' => ['kohkong', 'កោះកុង'],
        ],
        [
            'code' => 'kratie',
            'name_en' => 'Kratie',
            'name_km' => 'ក្រចេះ',
            'area' => self::PROVINCES,
            'days_min' => 2,
            'days_max' => 3,
            'delivery_time_en' => '2 to 3 working days',
            'delivery_time_km' => '២ ទៅ ៣ ថ្ងៃធ្វើការ',
            'keywords' => ['kratie', 'ក្រចេះ'],
        ],
        [
            'code' => 'preah_vihear',
            'name_en' => 'Preah Vihear',
            'name_km' => 'ព្រះវិហារ',
            'area' => self::PROVINCES,
            'days_min' => 2,
            'days_max' => 3,
            'delivery_time_en' => '2 to 3 working days',
            'delivery_time_km' => '២ ទៅ ៣ ថ្ងៃធ្វើការ',
            'keywords' => ['preahvihear', 'ព្រះវិហារ'],
        ],
        [
            'code' => 'pursat',
            'name_en' => 'Pursat',
            'name_km' => 'ពោធិ៍សាត់',
            'area' => self::PROVINCES,
            'days_min' => 2,
            'days_max' => 3,
            'delivery_time_en' => '2 to 3 working days',
            'delivery_time_km' => '២ ទៅ ៣ ថ្ងៃធ្វើការ',
            'keywords' => ['pursat', 'ពោធិ៍សាត់'],
        ],
        [
            'code' => 'prey_veng',
            'name_en' => 'Prey Veng',
            'name_km' => 'ព្រៃវែង',
            'area' => self::PROVINCES,
            'days_min' => 2,
            'days_max' => 3,
            'delivery_time_en' => '2 to 3 working days',
            'delivery_time_km' => '២ ទៅ ៣ ថ្ងៃធ្វើការ',
            'keywords' => ['preyveng', 'ព្រៃវែង'],
        ],
        [
            'code' => 'svay_rieng',
            'name_en' => 'Svay Rieng',
            'name_km' => 'ស្វាយរៀង',
            'area' => self::PROVINCES,
            'days_min' => 2,
            'days_max' => 3,
            'delivery_time_en' => '2 to 3 working days',
            'delivery_time_km' => '២ ទៅ ៣ ថ្ងៃធ្វើការ',
            'keywords' => ['svayrieng', 'ស្វាយរៀង'],
        ],
        [
            'code' => 'takeo',
            'name_en' => 'Takeo',
            'name_km' => 'តាកែវ',
            'area' => self::PROVINCES,
            'days_min' => 2,
            'days_max' => 3,
            'delivery_time_en' => '2 to 3 working days',
            'delivery_time_km' => '២ ទៅ ៣ ថ្ងៃធ្វើការ',
            'keywords' => ['takeo', 'តាកែវ'],
        ],
        [
            'code' => 'tboung_khmum',
            'name_en' => 'Tboung Khmum',
            'name_km' => 'ត្បូងឃ្មុំ',
            'area' => self::PROVINCES,
            'days_min' => 2,
            'days_max' => 3,
            'delivery_time_en' => '2 to 3 working days',
            'delivery_time_km' => '២ ទៅ ៣ ថ្ងៃធ្វើការ',
            'keywords' => ['tboungkhmum', 'ត្បូងឃ្មុំ'],
        ],
        [
            'code' => 'banteay_meanchey',
            'name_en' => 'Banteay Meanchey',
            'name_km' => 'បន្ទាយមានជ័យ',
            'area' => self::PROVINCES,
            'days_min' => 2,
            'days_max' => 3,
            'delivery_time_en' => '2 to 3 working days',
            'delivery_time_km' => '២ ទៅ ៣ ថ្ងៃធ្វើការ',
            'keywords' => ['banteaymeanchey', 'បន្ទាយមានជ័យ'],
        ],
        [
            'code' => 'oddar_meanchey',
            'name_en' => 'Oddar Meanchey',
            'name_km' => 'ឧត្តរមានជ័យ',
            'area' => self::PROVINCES,
            'days_min' => 2,
            'days_max' => 3,
            'delivery_time_en' => '2 to 3 working days',
            'delivery_time_km' => '២ ទៅ ៣ ថ្ងៃធ្វើការ',
            'keywords' => ['oddarmeanchey', 'ឧត្តរមានជ័យ'],
        ],
        [
            'code' => 'pailin',
            'name_en' => 'Pailin',
            'name_km' => 'ប៉ៃលិន',
            'area' => self::PROVINCES,
            'days_min' => 2,
            'days_max' => 3,
            'delivery_time_en' => '2 to 3 working days',
            'delivery_time_km' => '២ ទៅ ៣ ថ្ងៃធ្វើការ',
            'keywords' => ['pailin', 'ប៉ៃលិន'],
        ],
        [
            'code' => 'stung_treng',
            'name_en' => 'Stung Treng',
            'name_km' => 'ស្ទឹងត្រែង',
            'area' => self::PROVINCES,
            'days_min' => 2,
            'days_max' => 3,
            'delivery_time_en' => '2 to 3 working days',
            'delivery_time_km' => '២ ទៅ ៣ ថ្ងៃធ្វើការ',
            'keywords' => ['stungtreng', 'ស្ទឹងត្រែង'],
        ],
        [
            'code' => 'mondulkiri',
            'name_en' => 'Mondulkiri',
            'name_km' => 'មណ្ឌលគិរី',
            'area' => self::PROVINCES,
            'days_min' => 3,
            'days_max' => 4,
            'delivery_time_en' => '3 to 4 working days',
            'delivery_time_km' => '៣ ទៅ ៤ ថ្ងៃធ្វើការ',
            'keywords' => ['mondulkiri', 'មណ្ឌលគិរី'],
        ],
        [
            'code' => 'ratanakiri',
            'name_en' => 'Ratanakiri',
            'name_km' => 'រតនគិរី',
            'area' => self::PROVINCES,
            'days_min' => 3,
            'days_max' => 4,
            'delivery_time_en' => '3 to 4 working days',
            'delivery_time_km' => '៣ ទៅ ៤ ថ្ងៃធ្វើការ',
            'keywords' => ['ratanakiri', 'រតនគិរី'],
        ],
    ];

    public static function for(?CustomerAddress $address): string
    {
        if ($address === null) {
            return self::PROVINCES;
        }

        return self::forPlace($address->city, $address->state);
    }

    /** The same rule for a city and province given separately (e.g. an order's saved shipping address). */
    public static function forPlace(?string $city, ?string $state): string
    {
        foreach ([$city, $state] as $part) {
            $flat = mb_strtolower(preg_replace('/[\s\-_.]+/u', '', (string) $part));
            if (str_contains($flat, 'phnompenh') || str_contains($flat, 'ភ្នំពេញ')) {
                return self::PHNOM_PENH;
            }
        }

        return self::PROVINCES;
    }

    /**
     * Identify which province an address is in by matching against known aliases.
     * Returns the province record array, or null if no province matched.
     */
    public static function detectProvince(?string $city, ?string $state): ?array
    {
        $candidates = [];
        foreach ([$state, $city] as $part) {
            if ($part !== null && trim((string) $part) !== '') {
                $candidates[] = mb_strtolower(preg_replace('/[\s\-_.]+/u', '', (string) $part));
            }
        }

        if ($candidates === []) {
            return null;
        }

        foreach (self::PROVINCES_LIST as $prov) {
            foreach ($prov['keywords'] as $kw) {
                $flatKw = mb_strtolower(preg_replace('/[\s\-_.]+/u', '', $kw));
                foreach ($candidates as $cand) {
                    if (str_contains($cand, $flatKw)) {
                        return $prov;
                    }
                }
            }
        }

        return null;
    }

    /**
     * Delivery fee for an area or province, as a decimal string in USD.
     * Checks province-specific rate override in config('shop.shipping_fees.province_rates'),
     * falling back to the area fee (e.g. 1.50 for phnom_penh, 3.00 for provinces).
     */
    public static function fee(string $area, ?string $provinceCode = null): string
    {
        if ($provinceCode !== null) {
            $override = config("shop.shipping_fees.province_rates.{$provinceCode}");
            if ($override !== null && $override !== '') {
                return (string) $override;
            }
        }

        return (string) config("shop.shipping_fees.{$area}", $area === self::PHNOM_PENH ? '1.50' : '3.00');
    }

    /**
     * Free delivery threshold in USD, or null if disabled.
     */
    public static function freeShippingThreshold(): ?string
    {
        $val = config('shop.free_shipping_threshold');
        if ($val === null || $val === '' || (float) $val <= 0) {
            return null;
        }

        return (string) $val;
    }

    /**
     * Add working days (Monday-Saturday, excluding Sunday).
     */
    public static function addWorkingDays(Carbon $date, int $days): Carbon
    {
        $current = $date->copy();
        while ($days > 0) {
            $current->addDay();
            if ($current->isSunday()) {
                continue;
            }
            $days--;
        }

        return $current;
    }

    /**
     * Calculate estimated delivery timeframe and dates.
     */
    public static function estimate(?Carbon $placedAt, ?string $city, ?string $state): array
    {
        $tz = config('shop.timezone', 'Asia/Phnom_Penh');
        $base = $placedAt ? $placedAt->copy()->setTimezone($tz) : now($tz);

        // If placed after 16:00 (shop closing / courier cutoff), processing starts the next calendar day.
        $start = $base->copy();
        if ($start->hour >= 16) {
            $start->addDay();
        }
        // If start lands on Sunday, move to Monday
        if ($start->isSunday()) {
            $start->addDay();
        }

        $prov = self::detectProvince($city, $state);
        $area = self::forPlace($city, $state);

        $daysMin = $prov ? $prov['days_min'] : ($area === self::PHNOM_PENH ? 1 : 2);
        $daysMax = $prov ? $prov['days_max'] : ($area === self::PHNOM_PENH ? 2 : 3);

        $earliest = self::addWorkingDays($start, $daysMin);
        $latest = self::addWorkingDays($start, $daysMax);

        $timeEn = $prov ? $prov['delivery_time_en'] : ($area === self::PHNOM_PENH ? '1 working day' : '2 to 3 working days');
        $timeKm = $prov ? $prov['delivery_time_km'] : ($area === self::PHNOM_PENH ? '១ ថ្ងៃធ្វើការ' : '២ ទៅ ៣ ថ្ងៃធ្វើការ');

        $formatted = $daysMin === $daysMax
            ? sprintf('%s (%s)', $timeEn, $earliest->format('D, j M'))
            : sprintf('%s (%s – %s)', $timeEn, $earliest->format('D, j M'), $latest->format('D, j M'));

        return [
            'days_min' => $daysMin,
            'days_max' => $daysMax,
            'delivery_time_en' => $timeEn,
            'delivery_time_km' => $timeKm,
            'earliest_date' => $earliest->format('Y-m-d'),
            'latest_date' => $latest->format('Y-m-d'),
            'formatted' => $formatted,
        ];
    }

    /**
     * Return all 25 provinces with computed fees.
     */
    public static function allProvinces(): array
    {
        return array_map(function (array $p) {
            $fee = self::fee($p['area'], $p['code']);

            return [
                'code' => $p['code'],
                'name_en' => $p['name_en'],
                'name_km' => $p['name_km'],
                'area' => $p['area'],
                'fee_usd' => $fee,
                'days_min' => $p['days_min'],
                'days_max' => $p['days_max'],
                'delivery_time_en' => $p['delivery_time_en'],
                'delivery_time_km' => $p['delivery_time_km'],
            ];
        }, self::PROVINCES_LIST);
    }
}
