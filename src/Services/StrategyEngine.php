<?php

namespace PostalWarmup\Services;

use PostalWarmup\Admin\Settings;

class StrategyEngine {

    /**
     * Calcule la limite journalière pour un jour donné de la stratégie
     * 
     * @param array $strategy La configuration de la stratégie
     * @param int $day Le jour actuel de warmup (1-indexed)
     * @param string|null $isp L'ISP cible (optionnel) pour override
     * @return int La limite d'envoi pour ce jour
     */
    public static function calculate_daily_limit( $strategy, $day, $isp = null ) {
        // Allow strategy to be null/empty, fallback to global settings
        $config = is_array( $strategy ) ? ($strategy['config'] ?? []) : [];
        
        // Defaults from Settings (Phase 4)
        $default_start = (int) Settings::get( 'warmup_start', 10 );
        $default_max = (int) Settings::get( 'warmup_max', 1000 );
        $default_mode = Settings::get( 'warmup_mode', 'linear' );
        $default_increase = (float) Settings::get( 'warmup_increase_percent', 20 );

        // If strategy config is missing, use global defaults
        $start = isset($config['start_volume']) ? (int) $config['start_volume'] : $default_start;
        $max = isset($config['max_volume']) ? (int) $config['max_volume'] : $default_max;
        $type = isset($config['growth_type']) ? $config['growth_type'] : $default_mode;
        $value = isset($config['growth_value']) ? (float) $config['growth_value'] : $default_increase;

        if ( $day <= 1 ) return $start;

        $limit = $start;

        if ( $type === 'mixed' ) {
            // Mixed: Linear start (conservative) then Exponential (aggressive)
            // Day 1-5: Linear +$value
            // Day 6+: Exponential +$value%
            
            // Phase 1: Linear
            $phase1_days = 5;
            $limit_phase1 = $start + ( min($day, $phase1_days) - 1 ) * $value; // Use linear value (e.g. 5 emails)
            
            if ( $day <= $phase1_days ) {
                $limit = $limit_phase1;
            } else {
                // Phase 2: Exponential from Phase 1 end
                $limit = $limit_phase1;
                $exp_value = $value; // Assume same value used as % (e.g. 10 -> 10%)
                if ( $exp_value > 1 ) $exp_value = $exp_value / 100; // Normalize 30 -> 0.3
                if ( $exp_value == 0 ) $exp_value = 0.10; // Default 10%

                for ( $i = $phase1_days + 1; $i <= $day; $i++ ) {
                    $limit = $limit * ( 1 + $exp_value );
                    if ( $limit >= $max ) break;
                }
            }

        } elseif ( $type === 'exponential' ) {
            // Limit = Start * (1 + Growth%)^(Day-1)
            $rate = $value;
            if ( $rate > 1 ) $rate = $rate / 100; // Normalize if user entered 30 instead of 0.3
            if ( $rate == 0 ) $rate = 0.10;

            // Iterative to avoid float precision issues with large powers
            $limit = $start;
            for ( $i = 2; $i <= $day; $i++ ) {
                $limit = $limit * ( 1 + $rate );
                if ( $limit >= $max ) break;
            }

        } else {
            // Linear: Start + (Day-1)*Value
            $limit = $start + ( ($day - 1) * $value );
        }

        return (int) min( floor( $limit ), $max );
    }

    /**
     * Vérifie les règles de sécurité (bounces, complaints)
     * Retourne [ 'allowed' => bool, 'reason' => string, 'action' => 'pause'|'reduce'|'stop' ]
     */
    public static function check_safety_rules( $strategy, $stats ) {
        $config = is_array($strategy) ? ($strategy['config'] ?? []) : [];
        $rules = $config['safety_rules'] ?? [];
        
        // Defaults from Settings (Phase 4)
        $default_bounce = (float) Settings::get( 'pause_bounce_rate', 5 );
        $default_spam = (float) Settings::get( 'pause_spam_rate', 1 );

        $max_hard_bounce = isset($rules['max_hard_bounce']) ? (float) $rules['max_hard_bounce'] : $default_bounce;
        $max_complaint = isset($rules['max_complaint']) ? (float) $rules['max_complaint'] : $default_spam;
        
        $sent = (int) ($stats['sent_today'] ?? 0);
        if ( $sent < 10 ) return [ 'allowed' => true ]; // Not enough data to judge

        // Hard Bounces (Failures)
        // Note: 'error_count' or 'fails_today' depending on stats source
        $bounces = (int) ($stats['fails_today'] ?? 0); 
        $bounce_rate = $sent > 0 ? ($bounces / $sent) * 100 : 0;
        
        if ( $bounce_rate > 10.0 ) {
             // Critical: Stop
             return [ 'allowed' => false, 'reason' => sprintf( "Critical Bounce Rate: %.1f%% (Max 10%%)", $bounce_rate ), 'action' => 'stop_immediate' ];
        }
        if ( $bounce_rate > $max_hard_bounce ) {
            // Warning: Pause/Reduce
            return [ 'allowed' => false, 'reason' => sprintf( "High Bounce Rate: %.1f%% (Max %.1f%%)", $bounce_rate, $max_hard_bounce ), 'action' => 'pause_24h' ];
        }

        // Complaints (Not always tracked via stats directly, usually via FBL webhooks stored in metrics)
        // Assuming $stats might have 'complaints_today' if aggregated. 
        // If not available, we skip.
        if ( isset( $stats['complaints_today'] ) ) {
            $complaints = (int) $stats['complaints_today'];
            $complaint_rate = $sent > 0 ? ($complaints / $sent) * 100 : 0;
            
            if ( $complaint_rate > 0.5 ) {
                return [ 'allowed' => false, 'reason' => sprintf( "Critical Complaint Rate: %.2f%%", $complaint_rate ), 'action' => 'stop_immediate' ];
            }
            if ( $complaint_rate > $max_complaint ) {
                return [ 'allowed' => false, 'reason' => sprintf( "High Complaint Rate: %.2f%% (Max %.2f%%)", $complaint_rate, $max_complaint ), 'action' => 'reduce_growth' ];
            }
        }

        return [ 'allowed' => true ];
    }
}
