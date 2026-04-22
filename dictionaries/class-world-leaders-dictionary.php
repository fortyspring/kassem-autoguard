<?php
/**
 * World Leaders Dictionary Loader
 * نسخة 2026.1 - محرك OSINT Pro
 * 
 * يقوم بتحميل وتنظيم القاموس العالمي للقادة والمسؤولين
 */

class WorldLeadersDictionary {
    
    private static $instance = null;
    private $dictionary = [];
    private $cache_key = 'world_leaders_dict_2026';
    private $cache_expiry = 86400; // 24 ساعة
    
    /**
     * Singleton Pattern
     */
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * تحميل القاموس من الملف أو الذاكرة المؤقتة
     */
    public function load() {
        // محاولة التحميل من الذاكرة المؤقتة أولاً
        $cached = get_transient($this->cache_key);
        if ($cached !== false) {
            $this->dictionary = $cached;
            return true;
        }
        
        // التحميل من الملف
        $file_path = dirname(__FILE__) . '/world_leaders_2026.json';
        if (!file_exists($file_path)) {
            error_log('World Leaders Dictionary file not found: ' . $file_path);
            return false;
        }
        
        $json_content = file_get_contents($file_path);
        $this->dictionary = json_decode($json_content, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            error_log('JSON Error in World Leaders Dictionary: ' . json_last_error_msg());
            return false;
        }
        
        // حفظ في الذاكرة المؤقتة
        set_transient($this->cache_key, $this->dictionary, $this->cache_expiry);
        
        return true;
    }
    
    /**
     * البحث عن قائد بالاسم (عربي أو إنجليزي)
     */
    public function searchByName($name) {
        $name = trim(strtolower($name));
        $results = [];
        
        // البحث في مناطق الصراع
        if (!empty($this->dictionary['conflict_zones'])) {
            foreach ($this->dictionary['conflict_zones'] as $region_key => $region) {
                $found = $this->searchInLeadersArray($region['leaders'], $name);
                if (!empty($found)) {
                    $results[$region_key] = [
                        'region' => $region['region_name'],
                        'leaders' => $found
                    ];
                }
            }
        }
        
        // البحث في الجامعة العربية
        if (!empty($this->dictionary['arab_league'])) {
            foreach ($this->dictionary['arab_league'] as $country_key => $country) {
                $found = $this->searchInLeadersArray($country['leaders'], $name);
                if (!empty($found)) {
                    $results[$country_key] = [
                        'region' => strtoupper($country_key),
                        'leaders' => $found
                    ];
                }
            }
        }
        
        return $results;
    }
    
    /**
     * البحث في مصفوفة القادة
     */
    private function searchInLeadersArray($leaders, $search_term) {
        $matches = [];
        
        foreach ($leaders as $leader) {
            $score = 0;
            
            // البحث بالاسم العربي
            if (!empty($leader['name_ar']) && stripos($leader['name_ar'], $search_term) !== false) {
                $score += 100;
            }
            
            // البحث بالاسم الإنجليزي
            if (!empty($leader['name_en']) && stripos(strtolower($leader['name_en']), $search_term) !== false) {
                $score += 100;
            }
            
            // البحث في الأسماء البديلة (Aliases)
            if (!empty($leader['alias'])) {
                foreach ($leader['alias'] as $alias) {
                    if (stripos(strtolower($alias), $search_term) !== false) {
                        $score += 80;
                        break;
                    }
                }
            }
            
            // البحث في نظام الأسماء البديلة العامة
            if (!empty($this->dictionary['metadata']['search_aliases'])) {
                foreach ($this->dictionary['metadata']['search_aliases'] as $key => $aliases) {
                    foreach ($aliases as $alias) {
                        if (stripos(strtolower($alias), $search_term) !== false) {
                            // محاولة مطابقة مع القادة المعروفين
                            if ($this->leaderMatchesKey($leader, $key)) {
                                $score += 90;
                            }
                            break;
                        }
                    }
                }
            }
            
            if ($score > 0) {
                $leader['match_score'] = $score;
                $matches[] = $leader;
            }
        }
        
        // ترتيب النتائج حسب درجة المطابقة
        usort($matches, function($a, $b) {
            return $b['match_score'] - $a['match_score'];
        });
        
        return $matches;
    }
    
    /**
     * التحقق من مطابقة القائد لمفتاح معين
     */
    private function leaderMatchesKey($leader, $key) {
        $key_map = [
            'trump' => ['ترامب', 'دونالد'],
            'netanyahu' => ['نتنياهو', 'بنيامين'],
            'khamenei' => ['خامنئي', 'علي'],
            'putin' => ['بوتين', 'فلاديمير'],
            'mbs' => ['محمد بن سلمان'],
            'mbz' => ['محمد بن زايد']
        ];
        
        if (!isset($key_map[$key])) {
            return false;
        }
        
        foreach ($key_map[$key] as $name_part) {
            if (strpos($leader['name_ar'], $name_part) !== false) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * الحصول على قادة منطقة معينة
     */
    public function getRegionLeaders($region_key) {
        if (isset($this->dictionary['conflict_zones'][$region_key])) {
            return $this->dictionary['conflict_zones'][$region_key]['leaders'];
        }
        
        if (isset($this->dictionary['arab_league'][$region_key])) {
            return $this->dictionary['arab_league'][$region_key]['leaders'];
        }
        
        return [];
    }
    
    /**
     * الحصول على أولوية الدور
     */
    public function getRolePriority($role) {
        if (!empty($this->dictionary['metadata']['roles_priority'])) {
            foreach ($this->dictionary['metadata']['roles_priority'] as $role_name => $priority) {
                if (stripos($role, $role_name) !== false) {
                    return $priority;
                }
            }
        }
        return 50; // أولوية افتراضية
    }
    
    /**
     * الحصول على جميع القادة حسب الأولوية
     */
    public function getLeadersByPriority($min_priority = 0) {
        $all_leaders = [];
        
        // جمع جميع القادة
        foreach ($this->dictionary['conflict_zones'] as $region) {
            foreach ($region['leaders'] as $leader) {
                if ($leader['priority'] >= $min_priority) {
                    $all_leaders[] = $leader;
                }
            }
        }
        
        foreach ($this->dictionary['arab_league'] as $country) {
            foreach ($country['leaders'] as $leader) {
                if ($leader['priority'] >= $min_priority) {
                    $all_leaders[] = $leader;
                }
            }
        }
        
        // الترتيب حسب الأولوية
        usort($all_leaders, function($a, $b) {
            return $b['priority'] - $a['priority'];
        });
        
        return $all_leaders;
    }
    
    /**
     * تحديث الذاكرة المؤقتة
     */
    public function refreshCache() {
        delete_transient($this->cache_key);
        return $this->load();
    }
    
    /**
     * الحصول على إحصائيات القاموس
     */
    public function getStats() {
        $stats = [
            'conflict_zones' => 0,
            'arab_countries' => 0,
            'total_leaders' => 0,
            'version' => $this->dictionary['meta']['version'] ?? 'unknown'
        ];
        
        if (!empty($this->dictionary['conflict_zones'])) {
            $stats['conflict_zones'] = count($this->dictionary['conflict_zones']);
            foreach ($this->dictionary['conflict_zones'] as $region) {
                $stats['total_leaders'] += count($region['leaders']);
            }
        }
        
        if (!empty($this->dictionary['arab_league'])) {
            $stats['arab_countries'] = count($this->dictionary['arab_league']);
            foreach ($this->dictionary['arab_league'] as $country) {
                $stats['total_leaders'] += count($country['leaders']);
            }
        }
        
        return $stats;
    }
}

// دوال مساعدة للاستخدام السريع

function sod_search_leader($name) {
    $dict = WorldLeadersDictionary::getInstance();
    $dict->load();
    return $dict->searchByName($name);
}

function sod_get_region_leaders($region) {
    $dict = WorldLeadersDictionary::getInstance();
    $dict->load();
    return $dict->getRegionLeaders($region);
}

function sod_get_high_priority_leaders($min_priority = 90) {
    $dict = WorldLeadersDictionary::getInstance();
    $dict->load();
    return $dict->getLeadersByPriority($min_priority);
}

function sod_get_dictionary_stats() {
    $dict = WorldLeadersDictionary::getInstance();
    $dict->load();
    return $dict->getStats();
}
