<?php

namespace PostalWarmup\Services;

use PostalWarmup\Models\Database;
use PostalWarmup\Services\Logger;
use PostalWarmup\API\Sender;
use PostalWarmup\Services\LoadBalancer;
use PostalWarmup\Services\ISPDetector;
use PostalWarmup\Models\Stats;
use PostalWarmup\Models\Strategy;
use PostalWarmup\Services\StrategyEngine;
use PostalWarmup\Admin\ISPManager;
use PostalWarmup\Admin\Settings;

class QueueManager {

    /**
     * Ajoute un email à la file d'attente
     */
    public static function add( $server_id, $to, $from, $subject, $meta = [] ) {
        global $wpdb;
        $table = $wpdb->prefix . 'postal_queue';
        
        // Calculate Schedule (Human Randomization)
        $scheduled_at = current_time( 'mysql' );
        
        $delay_min = (int) Settings::get( 'schedule_random_delay_min', 2 );
        $delay_max = (int) Settings::get( 'schedule_random_delay_max', 10 );
        
        // Ensure logical range
        if ( $delay_max < $delay_min ) $delay_max = $delay_min;

        if ( $delay_max > 0 ) {
            $seconds = rand( $delay_min * 60, $delay_max * 60 );
            $scheduled_at = date( 'Y-m-d H:i:s', current_time( 'timestamp' ) + $seconds );
        }

        $data = [
            'server_id'    => $server_id,
            'template_id'  => $meta['template_id'] ?? null,
            'to_email'     => $to,
            'from_email'   => $from,
            'subject'      => $subject,
            'status'       => 'pending',
            'scheduled_at' => $scheduled_at,
            'created_at'   => current_time( 'mysql' ),
            'meta'         => json_encode( $meta ),
            'attempts'     => 0,
            'isp'          => 'Other' // Will be detected on process
        ];
        
        // Pre-detect ISP if possible to save time later
        if ( class_exists( 'PostalWarmup\Services\ISPDetector' ) ) {
            $data['isp'] = ISPDetector::detect( $to );
        }
        
        $result = $wpdb->insert( $table, $data );
        
        if ( $result ) {
            Logger::info( "Queue: Email ajouté (ID: $wpdb->insert_id)", [ 'to' => $to ] );
            return $wpdb->insert_id;
        }
        
        Logger::error( "Queue: Échec ajout DB", [ 'error' => $wpdb->last_error ] );
        return false;
    }

    /**
     * Traite la file d'attente (Appelé par CRON)
     */
    public static function process_queue() {
        // 1. Check Auto-Pause status
        if ( get_transient( 'pw_queue_paused' ) ) {
            return;
        }

        // 2. Check Failure Rate (Auto-Pause Logic)
        $pause_threshold = (int) Settings::get( 'queue_pause_threshold', 50 );
        $health = self::get_health_stats(); // Gets 24h stats
        
        if ( $health['sent_24h'] + $health['failed_24h'] > 10 ) { // Minimum sample size
            $total = $health['sent_24h'] + $health['failed_24h'];
            $failure_rate = ($health['failed_24h'] / $total) * 100;
            
            if ( $failure_rate > $pause_threshold ) {
                $resume_delay = (int) Settings::get( 'queue_resume_delay', 30 );
                set_transient( 'pw_queue_paused', true, $resume_delay * 60 );
                
                Logger::critical( "Queue: Auto-paused for $resume_delay minutes due to high failure rate ({$failure_rate}%)" );
                
                if ( Settings::get( 'notify_stuck_queue', true ) ) {
                    EmailNotifications::send_stuck_queue_alert( $resume_delay );
                }
                return;
            }
        }

        // 3. Queue Locking
        if ( Settings::get( 'queue_locking_enabled', true ) ) {
            if ( get_transient( 'pw_queue_lock' ) ) {
                return; // Locked by another process
            }
            set_transient( 'pw_queue_lock', true, Settings::get( 'queue_lock_timeout', 60 ) );
        }

        try {
            self::do_process_queue();
        } finally {
            // Always release lock
            if ( Settings::get( 'queue_locking_enabled', true ) ) {
                delete_transient( 'pw_queue_lock' );
            }
        }
    }

    private static function do_process_queue() {
        // 1. Global Sending Rules
        if ( ! Settings::get( 'sending_enabled', true ) ) {
            return;
        }

        // Human Behavior Checks
        // 1. Weekend Mode
        $weekend_mode = Settings::get( 'weekend_mode', 'off' );
        $day_of_week = (int) current_time( 'w' ); // 0 (Sun) - 6 (Sat)
        
        if ( $day_of_week === 0 || $day_of_week === 6 ) {
            if ( $weekend_mode === 'off' ) {
                return; // No sending
            } elseif ( $weekend_mode === 'reduced' ) {
                // 20% Chance to process
                if ( rand( 1, 100 ) > 20 ) {
                    return; // Skip this run (simulates reduced volume)
                }
            }
        }

        // 2. Lunch Break
        if ( Settings::get( 'lunch_break_enabled', false ) ) {
            $hour = (int) current_time( 'H' );
            if ( $hour >= 12 && $hour < 14 ) {
                // 80% chance to take a break
                if ( rand( 1, 100 ) > 20 ) {
                    return; 
                }
            }
        }

        global $wpdb;
        $table = $wpdb->prefix . 'postal_queue';
        
        $global_tz = wp_timezone_string();
        
        // Schedule Window with Jitter
        $start_h = (int) Settings::get( 'schedule_start_hour', 8 );
        $end_h = (int) Settings::get( 'schedule_end_hour', 20 );
        
        if ( Settings::get( 'human_jitter_enabled', false ) ) {
            // Jitter is calculated based on the day of the year to be consistent within a day but different each day
            $day_seed = (int) date('z');
            srand($day_seed);
            $offset_start = rand(-30, 30) / 60; // +/- 0.5 hours
            $offset_end = rand(-30, 30) / 60;
            srand(); // Reset random seed
            
            // Adjust hours (simplified integer check, real check is below)
            // Ideally we should check minutes, but our logic checks discrete hours.
            // Let's rely on the probability skip for minute-level precision? 
            // Better: If we are in the "edge" hour, check minutes.
            // For now, let's keep it simple: jitter shifts the integer block
            if ($offset_start > 0.5) $start_h++;
            if ($offset_start < -0.5) $start_h--;
            if ($offset_end < -0.5) $end_h--;
        }

        // Exclude the end hour (stop strictly at X:00)
        // e.g. 08 to 20 means 08:00 to 19:59.
        $slots = range( $start_h, max($start_h, $end_h - 1) ); 

        // 2. Fetch Pending Items
        $now_mysql = current_time( 'mysql' );
        $batch_size = (int) Settings::get( 'queue_batch_size', 20 );
        
        $items = $wpdb->get_results( $wpdb->prepare( 
            "SELECT * FROM $table WHERE status = 'pending' AND scheduled_at <= %s LIMIT %d", 
            $now_mysql,
            $batch_size
        ), ARRAY_A );
        
        if ( empty( $items ) ) return;

        foreach ( $items as $item ) {
            $id = $item['id'];
            $template_id = $item['template_id'];
            $isp = $item['isp'];
            
            // Detect ISP if missing
            if ( empty( $isp ) || $isp === 'Other' ) {
                $isp = ISPDetector::detect( $item['to_email'] );
                if ( $isp !== 'Other' ) {
                    $wpdb->update( $table, [ 'isp' => $isp ], [ 'id' => $id ] );
                    $item['isp'] = $isp;
                }
            }

            // 3. Timezone & Schedule Check
            $timezone = $global_tz;
            // Override with Template Timezone
            if ( $template_id ) {
                $tpl_tz = $wpdb->get_var( $wpdb->prepare( "SELECT timezone FROM {$wpdb->prefix}postal_templates WHERE id = %d", $template_id ) );
                if ( ! empty( $tpl_tz ) ) {
                    $timezone = $tpl_tz;
                }
            }

            // Check if current hour in TARGET timezone is allowed
            // We use the timezone of the template to determine "Is it 9am-6pm THERE?"
            try {
                $dt = new \DateTime( 'now', new \DateTimeZone( $timezone ) );
                $current_hour = (int) $dt->format( 'G' );
                
                if ( ! in_array( $current_hour, $slots, true ) ) {
                    // Not in allowed slots
                    Logger::debug( "Queue: Item $id postponed (Hour $current_hour not allowed in $timezone)" );
                    self::postpone( $id, '+1 hour' );
                    continue;
                }
            } catch ( \Exception $e ) {
                Logger::error( "Queue: Timezone Error for item $id", [ 'error' => $e->getMessage() ] );
            }

            // 4. Strategy & Safety Check (Global for ISP)
            $strategy_id = null;
            if ( $isp !== 'Other' ) {
                $isp_data = ISPManager::get_by_key( $isp );
                
                if ( $isp_data && ! empty( $isp_data['strategy_id'] ) ) {
                    $strategy_id = $isp_data['strategy_id'];
                    // Note: Global ISP safety check could be added here if we had global aggregated fail stats easily available.
                    // For now, we rely on LoadBalancer's per-server safety check which is stricter and more accurate per IP reputation.
                }
            }

            // 5. Select Server (LoadBalancer V3)
            // This handles Per-Server ISP Quotas & Per-Server Safety
            $server = LoadBalancer::select_server( $template_id ?: 'default', [ 
                'ignore_limits' => false,
                'isp' => $isp,
                'strategy_id' => $strategy_id
            ] );
            
            if ( ! $server ) {
                Logger::warning( "Queue: No available server for item $id (Limits or Safety)", [ 'isp' => $isp, 'strategy' => $strategy_id ] );
                self::postpone( $id, '+30 minutes' ); // Try again later
                continue;
            }

            // 6. Send
            $meta = json_decode( $item['meta'], true );
            $prefix = $meta['prefix'] ?? 'contact';
            // Determine "From" address: prefix@server_domain
            $domain = $server['domain'];
            $sender_email = $prefix . '@' . $domain;

            $wpdb->update( $table, [ 
                'status' => 'processing', 
                'server_id' => $server['id'], // Update server ID to the one actually used
                'from_email' => $sender_email // Update sender to match server
            ], [ 'id' => $id ] );

            // Logger::info("Queue: Sending item $id via {$server['domain']}", ['isp'=>$isp]);

            // Use Sender Worker directly to avoid double queuing in ActionScheduler
            $sender_service = new Sender();
            $result = $sender_service->process_queue( 
                $item['to_email'], 
                $domain, 
                $prefix, 
                $server['id'],
                0, // retry_count
                false // handle_retry (We handle it here in DB)
            );
            
            $success = isset( $result['success'] ) && $result['success'];

            if ( $success ) {
                $wpdb->update( $table, [ 
                    'status' => 'sent', 
                    'updated_at' => current_time( 'mysql' ),
                    'attempts' => $item['attempts'] + 1
                ], [ 'id' => $id ] );
                
                Logger::info("Queue: Sent Item $id", [
                    'server' => $domain,
                    'isp' => $isp,
                    'strategy' => $strategy_id,
                    'day' => $server['lb_metrics']['warmup_day'] ?? '?'
                ]);

                do_action( 'pw_queue_item_sent', $id, $item, $server );

            } else {
                // Retry Logic
                $attempts = $item['attempts'] + 1;
                $max_retries = (int) Settings::get( 'max_retries', 3 );
                
                if ( $attempts < $max_retries ) {
                    // Calculate Delay
                    $base = (int) Settings::get( 'retry_delay_base', 60 );
                    $strategy = Settings::get( 'retry_strategy', 'fixed' );
                    $max_delay = (int) Settings::get( 'retry_delay_max', 900 );
                    
                    $delay = $base;
                    if ( $strategy === 'linear' ) {
                        $delay = $base * $attempts;
                    } elseif ( $strategy === 'exponential' ) {
                        $delay = $base * pow( 2, $attempts ); // 60, 120, 240...
                    }
                    
                    if ( $delay > $max_delay ) $delay = $max_delay;
                    
                    $next_try = date( 'Y-m-d H:i:s', current_time( 'timestamp' ) + $delay );
                    
                    $wpdb->update( $table, [
                        'status' => 'pending', // Re-queue
                        'scheduled_at' => $next_try,
                        'updated_at' => current_time( 'mysql' ),
                        'attempts' => $attempts,
                        'error_message' => $result['error'] ?? 'Retry scheduled'
                    ], [ 'id' => $id ] );
                    
                    Logger::warning("Queue: Item $id failed, retrying in {$delay}s (Attempt $attempts/$max_retries)", [
                        'error' => $result['error'] ?? 'Unknown'
                    ]);

                } else {
                    // Hard Fail
                    $wpdb->update( $table, [ 
                        'status' => 'failed', 
                        'error_message' => $result['error'] ?? 'Unknown error',
                        'updated_at' => current_time( 'mysql' ),
                        'attempts' => $attempts
                    ], [ 'id' => $id ] );
                    
                    Logger::error("Queue: Failed Item $id (Max retries reached)", [
                        'server' => $domain,
                        'error' => $result['error'] ?? 'Unknown'
                    ]);

                    do_action( 'pw_queue_item_failed', $id, $item, $result['error'] ?? 'Unknown' );
                }
            }

            // 7. Update V3 Stats (Tracking)
            Stats::increment_server_isp_usage( $server['id'], $isp, $success );
        }
    }

    private static function postpone( $id, $delay = '+1 hour' ) {
        global $wpdb;
        $table = $wpdb->prefix . 'postal_queue';
        $wpdb->update( 
            $table, 
            [ 'scheduled_at' => date( 'Y-m-d H:i:s', strtotime( $delay, current_time( 'timestamp' ) ) ) ], 
            [ 'id' => $id ] 
        );
    }

    /**
     * Nettoyage automatique
     */
    public static function cleanup() {
        global $wpdb;
        $table_queue = $wpdb->prefix . 'postal_queue';
        $table_logs = $wpdb->prefix . 'postal_logs';
        
        $days_queue = (int) get_option('pw_queue_retention_days', 30);
        $date_queue = date('Y-m-d H:i:s', strtotime("-$days_queue days"));
        
        $days_logs = (int) get_option('pw_log_retention_days', 60);
        $date_logs = date('Y-m-d H:i:s', strtotime("-$days_logs days"));
        
        $wpdb->query($wpdb->prepare("DELETE FROM $table_queue WHERE status IN ('sent', 'failed') AND updated_at < %s", $date_queue));
        $wpdb->query($wpdb->prepare("DELETE FROM $table_logs WHERE created_at < %s", $date_logs));
        
        Logger::info("Maintenance: Cleanup done.");
    }

    public static function get_health_stats() {
        global $wpdb;
        $table = $wpdb->prefix . 'postal_queue';
        
        $stats = [
            'pending' => 0,
            'processing' => 0,
            'sent_24h' => 0,
            'failed_24h' => 0,
            'top_isp' => 'N/A'
        ];
        
        $counts = $wpdb->get_results("SELECT status, COUNT(*) as count FROM $table GROUP BY status", ARRAY_A);
        foreach ($counts as $row) {
            if (isset($stats[$row['status']])) $stats[$row['status']] = $row['count'];
        }
        
        $yesterday = date('Y-m-d H:i:s', strtotime('-24 hours'));
        $sent_24 = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table WHERE status = 'sent' AND updated_at >= %s", $yesterday));
        $failed_24 = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table WHERE status = 'failed' AND updated_at >= %s", $yesterday));
        
        $stats['sent_24h'] = $sent_24;
        $stats['failed_24h'] = $failed_24;
        
        $top = $wpdb->get_var("SELECT isp FROM $table WHERE updated_at >= '$yesterday' GROUP BY isp ORDER BY COUNT(*) DESC LIMIT 1");
        if ($top) $stats['top_isp'] = $top;
        
        return $stats;
    }

    /**
     * Récupère les infos sur la prochaine vague d'envois prévue
     */
    public static function get_next_batch_info() {
        global $wpdb;
        $table = $wpdb->prefix . 'postal_queue';
        
        // Trouver la date minimale prévue dans le futur
        $next_time = $wpdb->get_var("
            SELECT MIN(scheduled_at) 
            FROM $table 
            WHERE status = 'pending' 
            AND scheduled_at > NOW()
        ");
        
        if ( ! $next_time ) {
            // Si rien dans le futur, check si on a des pending "maintenant" (ou en retard)
            $pending_now = $wpdb->get_var("SELECT COUNT(*) FROM $table WHERE status = 'pending' AND scheduled_at <= NOW()");
            if ($pending_now > 0) {
                return [
                    'count' => $pending_now,
                    'time' => 'Maintenant',
                    'timestamp' => time(),
                    'is_now' => true
                ];
            }
            return null;
        }
        
        // Compter combien d'emails pour ce créneau précis (ou minute précise)
        // On groupe par minute
        $count = $wpdb->get_var($wpdb->prepare("
            SELECT COUNT(*) 
            FROM $table 
            WHERE status = 'pending' 
            AND scheduled_at BETWEEN %s AND %s
        ", $next_time, date('Y-m-d H:i:59', strtotime($next_time))));
        
        return [
            'count' => $count,
            'time' => $next_time,
            'timestamp' => strtotime($next_time),
            'is_now' => false
        ];
    }
}
