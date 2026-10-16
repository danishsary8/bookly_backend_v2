<?php

namespace App\Services\Staff;

class DeviceDetector
{
    public static function detect(?string $userAgent): string
    {
        if (empty($userAgent)) {
            return 'Web Browser';
        }

        $os = self::detectOs($userAgent);
        $browser = self::detectBrowser($userAgent);

        if ($browser && $os) {
            return "{$browser} on {$os}";
        }

        return $browser ?: ($os ?: 'Web Browser');
    }

    private static function detectOs(string $ua): ?string
    {
        if (stripos($ua, 'Windows NT 10.0') !== false || stripos($ua, 'Windows NT 11.0') !== false || stripos($ua, 'Windows') !== false) {
            return 'Windows';
        }
        if (stripos($ua, 'iPhone') !== false) {
            return 'iPhone';
        }
        if (stripos($ua, 'iPad') !== false) {
            return 'iPad';
        }
        if (stripos($ua, 'Mac OS X') !== false || stripos($ua, 'Macintosh') !== false) {
            return 'macOS';
        }
        if (stripos($ua, 'Android') !== false) {
            return 'Android';
        }
        if (stripos($ua, 'Linux') !== false) {
            return 'Linux';
        }

        return null;
    }

    private static function detectBrowser(string $ua): ?string
    {
        if (stripos($ua, 'Edg/') !== false || stripos($ua, 'Edge') !== false) {
            return 'Edge';
        }
        if (stripos($ua, 'Chrome/') !== false || stripos($ua, 'CriOS/') !== false) {
            return 'Chrome';
        }
        if (stripos($ua, 'Firefox/') !== false || stripos($ua, 'FxiOS/') !== false) {
            return 'Firefox';
        }
        if (stripos($ua, 'Safari/') !== false && stripos($ua, 'Chrome') === false) {
            return 'Safari';
        }
        if (stripos($ua, 'Opera') !== false || stripos($ua, 'OPR/') !== false) {
            return 'Opera';
        }

        return null;
    }
}
