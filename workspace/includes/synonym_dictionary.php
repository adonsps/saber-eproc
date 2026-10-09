<?php
/**
 * Synonym Dictionary for E-Commerce Style SKU Search
 * Maps common procurement terms between English and Indonesian,
 * allowing cross-language search and autocomplete suggestions.
 */

// A robust map of common procurement items (English <-> Indonesian)
$synonym_map = [
    // Office Supplies
    'pen' => ['pulpen', 'pena', 'bolpen'],
    'pencil' => ['pensil'],
    'eraser' => ['penghapus', 'setip'],
    'paper' => ['kertas', 'hvs'],
    'notebook' => ['buku catatan', 'notes', 'buku tulis'],
    'envelope' => ['amplop'],
    'folder' => ['map', 'ordner'],
    'ruler' => ['penggaris', 'mistar'],
    'scissors' => ['gunting'],
    'glue' => ['lem', 'perekat'],
    'tape' => ['lakban', 'selotip', 'isolasi'],
    'stapler' => ['jegrekan', 'hekter'],
    'staples' => ['isi stapler', 'isi hekter'],
    'marker' => ['spidol'],
    'whiteboard' => ['papan tulis'],
    'stamp' => ['stempel', 'cap'],
    'clip' => ['klip kertas', 'penjepit kertas'],
    'calculator' => ['kalkulator', 'mesin hitung'],
    'ink' => ['tinta'],
    
    // IT & Electronics
    'laptop' => ['komputer jinjing', 'notebook'],
    'computer' => ['komputer', 'pc'],
    'mouse' => ['tetikus'],
    'keyboard' => ['papan ketik'],
    'monitor' => ['layar'],
    'printer' => ['mesin cetak', 'pencetak'],
    'cable' => ['kabel'],
    'charger' => ['casan', 'pengisi daya'],
    'battery' => ['baterai', 'batre'],
    'flashdrive' => ['flashdisk', 'fd'],
    'harddrive' => ['hardisk', 'hdd'],
    
    // Cleaning & Facilities
    'broom' => ['sapu'],
    'mop' => ['pel', 'alat pel'],
    'dustpan' => ['pengki', 'serokan'],
    'bucket' => ['ember'],
    'soap' => ['sabun'],
    'detergent' => ['detergen', 'sabun cuci'],
    'tissue' => ['tisu'],
    'trash bin' => ['tempat sampah', 'tong sampah'],
    'plastic bag' => ['kantong plastik', 'kresek'],
    'sponge' => ['spons', 'busa'],
    'duster' => ['kemoceng'],
    
    // Furniture
    'chair' => ['kursi', 'bangku'],
    'table' => ['meja'],
    'desk' => ['meja kerja'],
    'cabinet' => ['lemari', 'kabinet'],
    'shelf' => ['rak'],
    
    // Pantry / FNB
    'water' => ['air', 'air mineral'],
    'coffee' => ['kopi'],
    'tea' => ['teh'],
    'sugar' => ['gula'],
    'milk' => ['susu'],
    'cup' => ['gelas', 'cangkir'],
    'plate' => ['piring'],
    'spoon' => ['sendok'],
    'fork' => ['garpu'],
    
    // Tools
    'hammer' => ['palu', 'martil'],
    'screwdriver' => ['obeng'],
    'wrench' => ['kunci inggris'],
    'nails' => ['paku'],
    'screws' => ['sekrup']
];

/**
 * Expands a search query into an array of its synonyms.
 * Includes the original terms.
 * 
 * @param string $query The raw search input
 * @return array Array of expanded keywords
 */
function expand_search_synonyms($query) {
    global $synonym_map;
    
    $query = strtolower(trim($query));
    if (empty($query)) return [];
    
    $words = explode(' ', $query);
    $expanded = [];
    
    foreach ($words as $word) {
        $word = trim($word);
        if (empty($word)) continue;
        
        $expanded[] = $word;
        
        // Check if word is an English key
        if (isset($synonym_map[$word])) {
            foreach ($synonym_map[$word] as $syn) {
                $expanded[] = $syn;
            }
        }
        
        // Check if word is an Indonesian value
        foreach ($synonym_map as $en_key => $id_values) {
            if (in_array($word, $id_values)) {
                $expanded[] = $en_key;
                // Add siblings as well
                foreach ($id_values as $syn) {
                    if ($syn !== $word) {
                        $expanded[] = $syn;
                    }
                }
            }
        }
    }
    
    // Return unique values to avoid redundant queries
    return array_values(array_unique($expanded));
}

/**
 * Given a misspelled word, find the closest match in the dictionary.
 * 
 * @param string $word The misspelled word
 * @return string|null The corrected word or null if nothing is close enough
 */
function get_did_you_mean_suggestion($word) {
    global $synonym_map;
    $word = strtolower(trim($word));
    if (strlen($word) < 3) return null; // Too short to guess
    
    $closest = null;
    $shortest = -1;
    $threshold = 2; // Maximum edit distance allowed
    
    // Build a flat list of all valid terms
    $all_terms = array_keys($synonym_map);
    foreach ($synonym_map as $vals) {
        $all_terms = array_merge($all_terms, $vals);
    }
    $all_terms = array_unique($all_terms);
    
    foreach ($all_terms as $term) {
        $lev = levenshtein($word, $term);
        if ($lev == 0) return null; // Exact match found, no suggestion needed
        
        if ($lev <= $threshold) {
            if ($lev < $shortest || $shortest < 0) {
                $closest  = $term;
                $shortest = $lev;
            }
        }
    }
    
    return $closest;
}
?>
