<?php
/**
 * Class Intelligence_KB
 * 
 * Manages the Strategic Intelligence Knowledge Base.
 * Stores and retrieves entities extracted from news articles.
 */
class Intelligence_KB {

    private $table_entities;
    private $table_relations;

    public function __construct() {
        global $wpdb;
        $this->table_entities = $wpdb->prefix . 'osint_entities';
        $this->table_relations = $wpdb->prefix . 'osint_entity_relations';
    }

    /**
     * Initializes the knowledge base tables if they don't exist.
     * Uses existing DB structure only, no auto-creation of categories.
     */
    public function init_tables() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();

        $sql_entities = "CREATE TABLE IF NOT EXISTS {$this->table_entities} (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            entity_name varchar(255) NOT NULL,
            entity_type varchar(50) NOT NULL,
            entity_category varchar(100) DEFAULT '',
            first_seen datetime DEFAULT CURRENT_TIMESTAMP,
            last_seen datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            mention_count int(11) DEFAULT 1,
            PRIMARY KEY (id),
            UNIQUE KEY unique_entity (entity_name, entity_type),
            KEY idx_type (entity_type),
            KEY idx_category (entity_category)
        ) $charset_collate;";

        $sql_relations = "CREATE TABLE IF NOT EXISTS {$this->table_relations} (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            post_id bigint(20) UNSIGNED NOT NULL,
            entity_id bigint(20) UNSIGNED NOT NULL,
            relation_type varchar(50) DEFAULT 'mentioned',
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_post (post_id),
            KEY idx_entity (entity_id)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql_entities);
        dbDelta($sql_relations);
    }

    /**
     * Stores extracted entities from a news article.
     * 
     * @param int $post_id The WordPress post ID.
     * @param array $entities Extracted entities array.
     * @return bool Success status.
     */
    public function store_entities($post_id, $entities) {
        if (empty($entities) || empty($post_id)) {
            return false;
        }

        foreach ($entities as $type => $items) {
            if (!is_array($items)) continue;

            foreach ($items as $item) {
                if (empty($item)) continue;

                // Insert or update entity
                $this->upsert_entity($item, $type);

                // Get entity ID
                $entity_id = $this->get_entity_id($item, $type);

                if ($entity_id) {
                    // Create relation
                    global $wpdb;
                    $wpdb->insert(
                        $this->table_relations,
                        [
                            'post_id' => $post_id,
                            'entity_id' => $entity_id,
                            'relation_type' => 'mentioned',
                            'created_at' => current_time('mysql')
                        ],
                        ['%d', '%d', '%s', '%s']
                    );
                }
            }
        }

        return true;
    }

    /**
     * Inserts or updates an entity in the KB.
     * 
     * @param string $name Entity name.
     * @param string $type Entity type.
     */
    private function upsert_entity($name, $type) {
        global $wpdb;

        $existing = $wpdb->get_row($wpdb->prepare(
            "SELECT id, mention_count FROM {$this->table_entities} WHERE entity_name = %s AND entity_type = %s",
            $name,
            $type
        ));

        if ($existing) {
            $wpdb->update(
                $this->table_entities,
                [
                    'last_seen' => current_time('mysql'),
                    'mention_count' => $existing->mention_count + 1
                ],
                ['id' => $existing->id],
                ['%s', '%d'],
                ['%d']
            );
        } else {
            $wpdb->insert(
                $this->table_entities,
                [
                    'entity_name' => $name,
                    'entity_type' => $type,
                    'first_seen' => current_time('mysql'),
                    'last_seen' => current_time('mysql'),
                    'mention_count' => 1
                ],
                ['%s', '%s', '%s', '%s', '%d']
            );
        }
    }

    /**
     * Gets entity ID by name and type.
     * 
     * @param string $name Entity name.
     * @param string $type Entity type.
     * @return int|null Entity ID or null.
     */
    private function get_entity_id($name, $type) {
        global $wpdb;

        $result = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$this->table_entities} WHERE entity_name = %s AND entity_type = %s",
            $name,
            $type
        ));

        return $result ? (int)$result : null;
    }

    /**
     * Retrieves entities by type for display or analysis.
     * Ordered by last_seen DESC (newest first).
     * 
     * @param string $type Entity type filter.
     * @param int $limit Max results to return.
     * @return array List of entities.
     */
    public function get_entities($type = '', $limit = 50) {
        global $wpdb;

        $where = '';
        $params = [];

        if (!empty($type)) {
            $where = 'WHERE entity_type = %s';
            $params[] = sanitize_text_field($type);
        }

        $sql = "SELECT * FROM {$this->table_entities} {$where} ORDER BY last_seen DESC LIMIT %d";
        $params[] = (int)$limit;

        if (!empty($params)) {
            $prepared_sql = $wpdb->prepare($sql, $params);
            return $wpdb->get_results($prepared_sql) ?: [];
        }

        return $wpdb->get_results($sql) ?: [];
    }

    /**
     * Gets all entities related to a specific post.
     * 
     * @param int $post_id Post ID.
     * @return array Related entities.
     */
    public function get_post_entities($post_id) {
        global $wpdb;

        $sql = "SELECT e.* FROM {$this->table_entities} e
                INNER JOIN {$this->table_relations} r ON e.id = r.entity_id
                WHERE r.post_id = %d
                ORDER BY e.last_seen DESC";

        return $wpdb->get_results($wpdb->prepare($sql, $post_id)) ?: [];
    }

    /**
     * Searches for entities by name pattern.
     * 
     * @param string $query Search query.
     * @return array Matching entities.
     */
    public function search_entities($query) {
        global $wpdb;

        $search_term = '%' . $wpdb->esc_like($query) . '%';

        $sql = "SELECT * FROM {$this->table_entities}
                WHERE entity_name LIKE %s
                ORDER BY mention_count DESC, last_seen DESC
                LIMIT 50";

        return $wpdb->get_results($wpdb->prepare($sql, $search_term)) ?: [];
    }
}
