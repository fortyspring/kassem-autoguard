<?php
/**
 * Class News_Analyzer
 * 
 * Implements the 5Ws & 1H Gate for strategic news analysis.
 * Ensures only complete news items covering Who, What, Why, How, When, Where are published.
 */
class News_Analyzer {

    /**
     * Analyzes news content against the 5Ws & 1H criteria.
     * 
     * @param string $content The news text to analyze.
     * @return array Result with 'passed' boolean and 'missing' elements list.
     */
    public function analyze_news_gate($content) {
        if (empty($content)) {
            return ['passed' => false, 'missing' => ['all'], 'reason' => 'Empty content'];
        }

        $missing = [];
        $content_lower = strtolower($content);
        
        // 1. WHO (مَن) - Check for names, titles, organizations
        // Looks for capitalized words, known titles, or organization indicators
        $who_pattern = '/([A-Z][a-z]+(?:\s+[A-Z][a-z]+)+|President|Minister|General|Army|Force|Group|Organization|Authority|Council)/i';
        if (!preg_match($who_pattern, $content)) {
            $missing[] = 'who';
        }

        // 2. WHAT (ماذا) - Check for action verbs and event indicators
        $what_pattern = '/(launched|attacked|announced|deployed|signed|declared|operation|strike|exercise|agreement|meeting|summit)/i';
        if (!preg_match($what_pattern, $content)) {
            $missing[] = 'what';
        }

        // 3. WHERE (أين) - Check for locations, countries, cities, regions
        $where_pattern = '/(in\s+[A-Z][a-z]+|at\s+[A-Z][a-z]+|Syria|Iraq|Iran|Israel|Lebanon|Gaza|West Bank|Jerusalem|Damascus|Baghdad|Tehran|Tel Aviv|Beirut|Middle East|Region|Zone|Area|Border|Coast)/i';
        if (!preg_match($where_pattern, $content)) {
            $missing[] = 'where';
        }

        // 4. WHEN (متى) - Check for dates, times, temporal references
        $when_pattern = '/(\d{1,2}\/\d{1,2}\/\d{2,4}|\d{1,2}-\d{1,2}-\d{2,4}|today|yesterday|morning|evening|night|hour|recently|last\s+(week|month|year)|next\s+(week|month)|January|February|March|April|May|June|July|August|September|October|November|December)/i';
        if (!preg_match($when_pattern, $content)) {
            $missing[] = 'when';
        }

        // 5. WHY (لماذا) - Check for reasons, causes, objectives
        $why_pattern = '/(because|due to|in response|to counter|aiming to|objective|goal|reason|retaliation|escalation|tension|threat|security|strategy)/i';
        if (!preg_match($why_pattern, $content)) {
            $missing[] = 'why';
        }

        // 6. HOW (كيف) - Check for methods, means, tactics
        $how_pattern = '/(using|via|through|by means|method|tactic|missile|drone|artillery|infantry|cyber|electronic|blockade|sanctions|diplomacy|negotiation)/i';
        if (!preg_match($how_pattern, $content)) {
            $missing[] = 'how';
        }

        $passed = empty($missing);
        
        return [
            'passed' => $passed,
            'missing' => $missing,
            'reason' => $passed ? 'All 5Ws & 1H covered' : 'Missing: ' . implode(', ', $missing)
        ];
    }

    /**
     * Processes a news item: analyzes, extracts entities, and determines status.
     * 
     * @param array $news_data News data array with 'content', 'title', etc.
     * @return array Processing result with status and extracted data.
     */
    public function process_news_item($news_data) {
        $content = $news_data['content'] ?? '';
        $title = $news_data['title'] ?? '';
        $full_text = $title . ' ' . $content;

        // Run 5Ws & 1H Gate
        $analysis = $this->analyze_news_gate($full_text);

        if (!$analysis['passed']) {
            return [
                'status' => 'draft',
                'reason' => $analysis['reason'],
                'entities' => []
            ];
        }

        // Extract entities if passed
        $entities = $this->extract_entities($full_text);

        return [
            'status' => 'publish',
            'reason' => 'Approved',
            'entities' => $entities
        ];
    }

    /**
     * Extracts strategic entities from news content.
     * 
     * @param string $text The news text.
     * @return array Extracted entities categorized by type.
     */
    private function extract_entities($text) {
        $entities = [
            'persons' => [],
            'organizations' => [],
            'locations' => [],
            'weapons' => [],
            'tactics' => []
        ];

        // Extract potential persons (capitalized words patterns)
        preg_match_all('/\b([A-Z][a-z]+\s+[A-Z][a-z]+)\b/', $text, $person_matches);
        $entities['persons'] = array_unique($person_matches[1] ?? []);

        // Extract organizations
        $org_keywords = ['Army', 'Force', 'Group', 'Organization', 'Council', 'Ministry', 'Department', 'Agency'];
        foreach ($org_keywords as $keyword) {
            if (stripos($text, $keyword) !== false) {
                $entities['organizations'][] = $keyword;
            }
        }
        $entities['organizations'] = array_unique($entities['organizations']);

        // Extract locations
        $location_keywords = ['Syria', 'Iraq', 'Iran', 'Israel', 'Lebanon', 'Gaza', 'Jerusalem', 'Damascus', 'Baghdad', 'Tehran', 'Tel Aviv', 'Beirut', 'Middle East'];
        foreach ($location_keywords as $location) {
            if (stripos($text, $location) !== false) {
                $entities['locations'][] = $location;
            }
        }
        $entities['locations'] = array_unique($entities['locations']);

        // Extract weapons
        $weapon_keywords = ['missile', 'drone', 'artillery', 'tank', 'fighter jet', 'bomb', 'rifle', 'cyber weapon'];
        foreach ($weapon_keywords as $weapon) {
            if (stripos($text, $weapon) !== false) {
                $entities['weapons'][] = $weapon;
            }
        }
        $entities['weapons'] = array_unique($entities['weapons']);

        // Extract tactics
        $tactic_keywords = ['blockade', 'sanctions', 'diplomacy', 'negotiation', 'ambush', 'raid', 'surveillance', 'electronic warfare'];
        foreach ($tactic_keywords as $tactic) {
            if (stripos($text, $tactic) !== false) {
                $entities['tactics'][] = $tactic;
            }
        }
        $entities['tactics'] = array_unique($entities['tactics']);

        return $entities;
    }
}
