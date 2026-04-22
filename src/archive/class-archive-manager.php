<?php
/**
 * Class Archive_Manager
 * 
 * Manages archive cleaning while preserving strategic intelligence data.
 * Deletes old articles but retains extracted entities in the Knowledge Base.
 */
class Archive_Manager {

    private $kb_handler;

    public function __construct() {
        // Initialize Knowledge Base handler to preserve entities
        if (class_exists('Intelligence_KB')) {
            $this->kb_handler = new Intelligence_KB();
        }
    }

    /**
     * Cleans old articles from the archive based on age.
     * PRESERVES all entities in the Knowledge Base before deletion.
     * 
     * @param int $days_old Delete articles older than this many days.
     * @param string $post_type Post type to clean (default: 'post').
     * @return array Result with counts of processed items.
     */
    public function clean_archive_safe($days_old = 90, $post_type = 'post') {
        global $wpdb;

        if ($days_old < 1) {
            return ['success' => false, 'message' => 'Invalid days parameter'];
        }

        $cutoff_date = date('Y-m-d H:i:s', strtotime("-{$days_old} days"));
        $processed_count = 0;
        $preserved_entities = 0;

        // Get posts to be deleted
        $posts_to_delete = $wpdb->get_results($wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts} 
             WHERE post_type = %s 
             AND post_date < %s 
             AND post_status = 'publish'
             ORDER BY post_date ASC",
            $post_type,
            $cutoff_date
        ));

        if (empty($posts_to_delete)) {
            return [
                'success' => true,
                'message' => 'No old posts found for cleanup',
                'deleted' => 0,
                'preserved_entities' => 0
            ];
        }

        foreach ($posts_to_delete as $post) {
            $post_id = (int)$post->ID;

            // Step 1: Preserve entities from this post BEFORE deletion
            if ($this->kb_handler) {
                $entities = $this->kb_handler->get_post_entities($post_id);
                if (!empty($entities)) {
                    $preserved_entities += count($entities);
                }
                // Note: Entities are NOT deleted - they remain in KB permanently
                // Only the relation will be orphaned but entity data stays intact
            }

            // Step 2: Delete the post (content only)
            wp_delete_post($post_id, true); // Force delete
            $processed_count++;
        }

        // Step 3: Clean up orphaned relations (optional optimization)
        $this->cleanup_orphaned_relations();

        return [
            'success' => true,
            'message' => "Archive cleaned successfully",
            'deleted' => $processed_count,
            'preserved_entities' => $preserved_entities,
            'cutoff_date' => $cutoff_date
        ];
    }

    /**
     * Removes relations for posts that no longer exist.
     * Keeps the entities themselves intact.
     */
    private function cleanup_orphaned_relations() {
        global $wpdb;

        if (!$this->kb_handler) return;

        $relations_table = $wpdb->prefix . 'osint_entity_relations';

        // Delete relations where post_id doesn't exist in wp_posts
        $sql = "DELETE r FROM {$relations_table} r
                LEFT JOIN {$wpdb->posts} p ON r.post_id = p.ID
                WHERE p.ID IS NULL";

        $wpdb->query($sql);
    }

    /**
     * Gets archive statistics.
     * 
     * @return array Stats including total posts, oldest post date, entity counts.
     */
    public function get_archive_stats() {
        global $wpdb;

        // Total published posts
        $total_posts = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s AND post_status = 'publish'",
            'post'
        ));

        // Oldest post date
        $oldest_post = $wpdb->get_var("SELECT MIN(post_date) FROM {$wpdb->posts} WHERE post_type = 'post' AND post_status = 'publish'");

        // Total entities in KB
        $total_entities = 0;
        if ($this->kb_handler && method_exists($this->kb_handler, 'get_entities')) {
            $entities_table = $wpdb->prefix . 'osint_entities';
            $total_entities = (int)$wpdb->get_var("SELECT COUNT(*) FROM {$entities_table}");
        }

        // Entity breakdown by type
        $entity_breakdown = [];
        if ($this->kb_handler) {
            $entities_table = $wpdb->prefix . 'osint_entities';
            $breakdown_raw = $wpdb->get_results("SELECT entity_type, COUNT(*) as count FROM {$entities_table} GROUP BY entity_type");
            foreach ($breakdown_raw as $row) {
                $entity_breakdown[$row->entity_type] = (int)$row->count;
            }
        }

        return [
            'total_posts' => (int)$total_posts,
            'oldest_post' => $oldest_post,
            'total_entities' => $total_entities,
            'entity_breakdown' => $entity_breakdown
        ];
    }

    /**
     * Schedules automatic archive cleaning.
     * Can be hooked into WP Cron.
     * 
     * @param int $days_old Articles older than this will be cleaned.
     */
    public function schedule_cleanup($days_old = 90) {
        if (!wp_next_scheduled('osint_archive_cleanup')) {
            wp_schedule_event(time(), 'monthly', 'osint_archive_cleanup', [$days_old]);
        }
    }

    /**
     * Unschedules automatic cleanup.
     */
    public function unschedule_cleanup() {
        $timestamp = wp_next_scheduled('osint_archive_cleanup');
        if ($timestamp) {
            wp_unschedule_event($timestamp, 'osint_archive_cleanup');
        }
    }
}

// Hook for scheduled cleanup
add_action('osint_archive_cleanup', function($days_old) {
    $manager = new Archive_Manager();
    $result = $manager->clean_archive_safe($days_old);
    error_log('OSINT Archive Cleanup: ' . json_encode($result));
}, 10, 1);
