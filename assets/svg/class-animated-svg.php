<?php

/**
 * Animated SVG Components
 *
 * Lightweight animated SVGs for the RawWire Dashboard.
 * No external libraries needed - pure CSS animations.
 *
 * @package RawWire_Dashboard
 * @since   1.0.28
 */

if (!defined('ABSPATH')) {
    exit;
}

class RawWire_Animated_SVG
{
    /**
     * CSS injection flag
     */
    private static $css_injected = false;

    /**
     * Inject CSS once per page
     */
    private static function inject_css()
    {
        if (self::$css_injected) {
            return;
        }
        self::$css_injected = true;
?>
        <style id="rw-animated-svg-styles">
            /* Spinner Animation */
            @keyframes rw-svg-spin {
                0% {
                    transform: rotate(0deg);
                }

                100% {
                    transform: rotate(360deg);
                }
            }

            @keyframes rw-svg-dash {
                0% {
                    stroke-dasharray: 1, 150;
                    stroke-dashoffset: 0;
                }

                50% {
                    stroke-dasharray: 90, 150;
                    stroke-dashoffset: -35;
                }

                100% {
                    stroke-dasharray: 90, 150;
                    stroke-dashoffset: -124;
                }
            }

            .rw-svg-spinner {
                animation: rw-svg-spin 1.5s linear infinite;
            }

            .rw-svg-spinner-path {
                stroke-linecap: round;
                animation: rw-svg-dash 1.5s ease-in-out infinite;
            }

            /* Checkmark Animation */
            @keyframes rw-svg-check {
                0% {
                    stroke-dashoffset: 30;
                }

                100% {
                    stroke-dashoffset: 0;
                }
            }

            .rw-svg-check-path {
                stroke-dasharray: 30;
                stroke-dashoffset: 30;
                animation: rw-svg-check 0.4s ease forwards 0.2s;
            }

            /* Pulse Animation */
            @keyframes rw-svg-pulse {

                0%,
                100% {
                    transform: scale(1);
                    opacity: 1;
                }

                50% {
                    transform: scale(1.05);
                    opacity: 0.8;
                }
            }

            .rw-svg-pulse {
                animation: rw-svg-pulse 2s ease-in-out infinite;
            }

            /* Data Flow Animation */
            @keyframes rw-svg-flow {
                0% {
                    stroke-dashoffset: 100;
                }

                100% {
                    stroke-dashoffset: 0;
                }
            }

            .rw-svg-flow-path {
                stroke-dasharray: 10 5;
                animation: rw-svg-flow 2s linear infinite;
            }

            /* Bounce Animation */
            @keyframes rw-svg-bounce {

                0%,
                100% {
                    transform: translateY(0);
                }

                50% {
                    transform: translateY(-3px);
                }
            }

            .rw-svg-bounce {
                animation: rw-svg-bounce 1s ease-in-out infinite;
            }

            /* Fade In Animation */
            @keyframes rw-svg-fade-in {
                0% {
                    opacity: 0;
                    transform: scale(0.8);
                }

                100% {
                    opacity: 1;
                    transform: scale(1);
                }
            }

            .rw-svg-fade-in {
                animation: rw-svg-fade-in 0.3s ease forwards;
            }

            /* Progress Ring */
            @keyframes rw-svg-progress {
                0% {
                    stroke-dashoffset: 283;
                }
            }

            .rw-svg-progress-ring {
                transform: rotate(-90deg);
                transform-origin: center;
            }

            /* Glow Effect */
            @keyframes rw-svg-glow {

                0%,
                100% {
                    filter: drop-shadow(0 0 2px currentColor);
                }

                50% {
                    filter: drop-shadow(0 0 8px currentColor);
                }
            }

            .rw-svg-glow {
                animation: rw-svg-glow 2s ease-in-out infinite;
            }
        </style>
<?php
    }

    /**
     * Loading Spinner
     * 
     * @param int    $size  Size in pixels
     * @param string $color Primary color
     * @return string SVG markup
     */
    public static function spinner($size = 48, $color = '#f4b41a')
    {
        self::inject_css();
        return sprintf(
            '<svg class="rw-svg-spinner" width="%1$d" height="%1$d" viewBox="0 0 50 50">
                <circle cx="25" cy="25" r="20" fill="none" stroke="#333" stroke-width="4"/>
                <circle class="rw-svg-spinner-path" cx="25" cy="25" r="20" fill="none" stroke="%2$s" stroke-width="4"/>
            </svg>',
            $size,
            esc_attr($color)
        );
    }

    /**
     * Success Checkmark
     *
     * @param int    $size  Size in pixels
     * @param string $color Circle and check color
     * @return string SVG markup
     */
    public static function checkmark($size = 48, $color = '#27ae60')
    {
        self::inject_css();
        return sprintf(
            '<svg class="rw-svg-fade-in" width="%1$d" height="%1$d" viewBox="0 0 50 50">
                <circle cx="25" cy="25" r="23" fill="%2$s"/>
                <path class="rw-svg-check-path" d="M14 25 L22 33 L36 19" fill="none" stroke="#fff" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"/>
            </svg>',
            $size,
            esc_attr($color)
        );
    }

    /**
     * Error X Mark
     *
     * @param int    $size  Size in pixels
     * @param string $color Circle and X color
     * @return string SVG markup
     */
    public static function error($size = 48, $color = '#e74c3c')
    {
        self::inject_css();
        return sprintf(
            '<svg class="rw-svg-fade-in" width="%1$d" height="%1$d" viewBox="0 0 50 50">
                <circle cx="25" cy="25" r="23" fill="%2$s"/>
                <path d="M17 17 L33 33 M33 17 L17 33" fill="none" stroke="#fff" stroke-width="3" stroke-linecap="round"/>
            </svg>',
            $size,
            esc_attr($color)
        );
    }

    /**
     * Warning Triangle
     *
     * @param int    $size  Size in pixels
     * @param string $color Fill color
     * @return string SVG markup
     */
    public static function warning($size = 48, $color = '#f39c12')
    {
        self::inject_css();
        return sprintf(
            '<svg class="rw-svg-pulse" width="%1$d" height="%1$d" viewBox="0 0 50 50">
                <path d="M25 5 L47 43 H3 Z" fill="%2$s" stroke-linejoin="round"/>
                <line x1="25" y1="18" x2="25" y2="30" stroke="#fff" stroke-width="3" stroke-linecap="round"/>
                <circle cx="25" cy="37" r="2" fill="#fff"/>
            </svg>',
            $size,
            esc_attr($color)
        );
    }

    /**
     * Progress Ring
     *
     * @param int    $percent Progress percentage 0-100
     * @param int    $size    Size in pixels
     * @param string $color   Progress color
     * @param string $bg      Background color
     * @return string SVG markup
     */
    public static function progress_ring($percent = 0, $size = 60, $color = '#f4b41a', $bg = '#333')
    {
        self::inject_css();
        $circumference = 283; // 2 * PI * 45
        $offset = $circumference - (($percent / 100) * $circumference);
        return sprintf(
            '<svg width="%1$d" height="%1$d" viewBox="0 0 100 100">
                <circle cx="50" cy="50" r="45" fill="none" stroke="%4$s" stroke-width="8"/>
                <circle class="rw-svg-progress-ring" cx="50" cy="50" r="45" fill="none" stroke="%3$s" stroke-width="8" stroke-linecap="round" stroke-dasharray="283" stroke-dashoffset="%2$s" style="transition: stroke-dashoffset 0.5s ease;"/>
                <text x="50" y="50" text-anchor="middle" dy="7" fill="%3$s" font-size="24" font-weight="700">%5$d%%</text>
            </svg>',
            $size,
            $offset,
            esc_attr($color),
            esc_attr($bg),
            $percent
        );
    }

    /**
     * Data Pipeline Flow
     *
     * @param int    $width  Width in pixels
     * @param int    $height Height in pixels
     * @param string $color  Flow color
     * @return string SVG markup
     */
    public static function pipeline_flow($width = 200, $height = 40, $color = '#f4b41a')
    {
        self::inject_css();
        return sprintf(
            '<svg width="%1$d" height="%2$d" viewBox="0 0 200 40">
                <defs>
                    <linearGradient id="rw-flow-grad" x1="0%%" y1="0%%" x2="100%%" y2="0%%">
                        <stop offset="0%%" style="stop-color:%3$s;stop-opacity:0.3"/>
                        <stop offset="50%%" style="stop-color:%3$s;stop-opacity:1"/>
                        <stop offset="100%%" style="stop-color:%3$s;stop-opacity:0.3"/>
                    </linearGradient>
                </defs>
                <path d="M0 20 Q50 10 100 20 T200 20" fill="none" stroke="#333" stroke-width="4"/>
                <path class="rw-svg-flow-path" d="M0 20 Q50 10 100 20 T200 20" fill="none" stroke="url(#rw-flow-grad)" stroke-width="4"/>
                <circle cx="180" cy="20" r="6" fill="%3$s" class="rw-svg-pulse"/>
            </svg>',
            $width,
            $height,
            esc_attr($color)
        );
    }

    /**
     * Database Icon with Activity
     *
     * @param int    $size  Size in pixels
     * @param string $color Primary color
     * @return string SVG markup
     */
    public static function database_active($size = 48, $color = '#3498db')
    {
        self::inject_css();
        return sprintf(
            '<svg width="%1$d" height="%1$d" viewBox="0 0 50 50">
                <ellipse cx="25" cy="10" rx="18" ry="6" fill="%2$s" opacity="0.3"/>
                <ellipse cx="25" cy="10" rx="18" ry="6" fill="none" stroke="%2$s" stroke-width="2"/>
                <path d="M7 10 v30 a18 6 0 0 0 36 0 V10" fill="none" stroke="%2$s" stroke-width="2"/>
                <ellipse cx="25" cy="40" rx="18" ry="6" fill="none" stroke="%2$s" stroke-width="2"/>
                <ellipse cx="25" cy="25" rx="18" ry="6" fill="none" stroke="%2$s" stroke-width="2" opacity="0.5"/>
                <circle cx="40" cy="40" r="6" fill="#27ae60" class="rw-svg-pulse"/>
            </svg>',
            $size,
            esc_attr($color)
        );
    }

    /**
     * Stats Up Arrow
     *
     * @param int    $size  Size in pixels
     * @param string $color Arrow color
     * @return string SVG markup
     */
    public static function stats_up($size = 24, $color = '#27ae60')
    {
        self::inject_css();
        return sprintf(
            '<svg class="rw-svg-bounce" width="%1$d" height="%1$d" viewBox="0 0 24 24">
                <path d="M12 4 L20 12 H15 V20 H9 V12 H4 Z" fill="%2$s"/>
            </svg>',
            $size,
            esc_attr($color)
        );
    }

    /**
     * Stats Down Arrow
     *
     * @param int    $size  Size in pixels
     * @param string $color Arrow color
     * @return string SVG markup
     */
    public static function stats_down($size = 24, $color = '#e74c3c')
    {
        self::inject_css();
        return sprintf(
            '<svg class="rw-svg-bounce" width="%1$d" height="%1$d" viewBox="0 0 24 24" style="transform: rotate(180deg);">
                <path d="M12 4 L20 12 H15 V20 H9 V12 H4 Z" fill="%2$s"/>
            </svg>',
            $size,
            esc_attr($color)
        );
    }

    /**
     * Green Leaf (Sustainability Indicator)
     *
     * @param int    $size  Size in pixels
     * @param string $color Leaf color
     * @return string SVG markup
     */
    public static function green_leaf($size = 24, $color = '#27ae60')
    {
        self::inject_css();
        return sprintf(
            '<svg class="rw-svg-glow" width="%1$d" height="%1$d" viewBox="0 0 24 24" style="color: %2$s;">
                <path d="M17 8C14 10 11 13 9 18 C8 15 7 12 4 9 C6 8 9 6 12 6 C14 6 16 7 17 8 Z" fill="%2$s"/>
                <path d="M9 18 Q11 14 15 10" fill="none" stroke="#fff" stroke-width="1" opacity="0.5"/>
            </svg>',
            $size,
            esc_attr($color)
        );
    }

    /**
     * Solar Panel (Green Indicator)
     *
     * @param int    $size  Size in pixels
     * @param string $color Panel color
     * @return string SVG markup
     */
    public static function solar($size = 24, $color = '#f39c12')
    {
        self::inject_css();
        return sprintf(
            '<svg class="rw-svg-glow" width="%1$d" height="%1$d" viewBox="0 0 24 24" style="color: %2$s;">
                <rect x="3" y="8" width="18" height="12" rx="1" fill="%2$s" opacity="0.3" stroke="%2$s" stroke-width="1"/>
                <line x1="3" y1="12" x2="21" y2="12" stroke="%2$s" stroke-width="1"/>
                <line x1="3" y1="16" x2="21" y2="16" stroke="%2$s" stroke-width="1"/>
                <line x1="9" y1="8" x2="9" y2="20" stroke="%2$s" stroke-width="1"/>
                <line x1="15" y1="8" x2="15" y2="20" stroke="%2$s" stroke-width="1"/>
                <circle cx="12" cy="4" r="2" fill="%2$s"/>
                <line x1="12" y1="6" x2="12" y2="8" stroke="%2$s" stroke-width="1"/>
                <line x1="6" y1="2" x2="8" y2="5" stroke="%2$s" stroke-width="1" opacity="0.5"/>
                <line x1="18" y1="2" x2="16" y2="5" stroke="%2$s" stroke-width="1" opacity="0.5"/>
            </svg>',
            $size,
            esc_attr($color)
        );
    }

    /**
     * EV Charging (Green Indicator)
     *
     * @param int    $size  Size in pixels
     * @param string $color Bolt color
     * @return string SVG markup
     */
    public static function ev_charging($size = 24, $color = '#3498db')
    {
        self::inject_css();
        return sprintf(
            '<svg class="rw-svg-glow" width="%1$d" height="%1$d" viewBox="0 0 24 24" style="color: %2$s;">
                <rect x="5" y="4" width="10" height="16" rx="2" fill="none" stroke="%2$s" stroke-width="2"/>
                <rect x="7" y="2" width="2" height="2" fill="%2$s"/>
                <rect x="11" y="2" width="2" height="2" fill="%2$s"/>
                <path d="M13 9 L9 13 H12 L9 17" fill="none" stroke="%2$s" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                <path d="M17 8 Q21 12 17 16" fill="none" stroke="%2$s" stroke-width="1.5" opacity="0.5"/>
                <path d="M19 9 Q22 12 19 15" fill="none" stroke="%2$s" stroke-width="1" opacity="0.3"/>
            </svg>',
            $size,
            esc_attr($color)
        );
    }

    /**
     * Building Construction
     *
     * @param int    $size  Size in pixels
     * @param string $color Building color
     * @return string SVG markup
     */
    public static function construction($size = 48, $color = '#9b59b6')
    {
        self::inject_css();
        return sprintf(
            '<svg width="%1$d" height="%1$d" viewBox="0 0 50 50">
                <rect x="8" y="20" width="34" height="26" fill="%2$s" opacity="0.2" stroke="%2$s" stroke-width="2"/>
                <rect x="14" y="26" width="6" height="8" fill="%2$s" opacity="0.5"/>
                <rect x="30" y="26" width="6" height="8" fill="%2$s" opacity="0.5"/>
                <rect x="20" y="36" width="10" height="10" fill="%2$s"/>
                <polygon points="25,4 45,20 5,20" fill="%2$s" opacity="0.3" stroke="%2$s" stroke-width="2"/>
                <rect x="22" y="2" width="6" height="8" fill="%2$s"/>
                <circle cx="40" cy="10" r="5" fill="#f4b41a" class="rw-svg-pulse" opacity="0.8"/>
            </svg>',
            $size,
            esc_attr($color)
        );
    }

    /**
     * Score Gauge
     *
     * @param float  $score    Score value 0-10
     * @param int    $size     Size in pixels
     * @return string SVG markup
     */
    public static function score_gauge($score = 0, $size = 80)
    {
        self::inject_css();
        $score = max(0, min(10, $score));
        $angle = ($score / 10) * 180;
        $color = $score >= 8 ? '#27ae60' : ($score >= 5 ? '#f39c12' : '#e74c3c');

        $rad = deg2rad($angle - 90);
        $x = 50 + 35 * cos($rad);
        $y = 50 + 35 * sin($rad);

        return sprintf(
            '<svg width="%1$d" height="%2$d" viewBox="0 0 100 60">
                <path d="M10 50 A40 40 0 0 1 90 50" fill="none" stroke="#333" stroke-width="8" stroke-linecap="round"/>
                <path d="M10 50 A40 40 0 0 1 90 50" fill="none" stroke="%3$s" stroke-width="8" stroke-linecap="round" stroke-dasharray="126" stroke-dashoffset="%4$s" style="transition: stroke-dashoffset 0.5s ease;"/>
                <circle cx="50" cy="50" r="6" fill="%3$s"/>
                <line x1="50" y1="50" x2="%5$s" y2="%6$s" stroke="%3$s" stroke-width="3" stroke-linecap="round"/>
                <text x="50" y="45" text-anchor="middle" fill="#fff" font-size="16" font-weight="700">%7$s</text>
            </svg>',
            $size,
            intval($size * 0.75),
            $color,
            126 - (($score / 10) * 126),
            $x,
            $y,
            number_format($score, 1)
        );
    }
}
